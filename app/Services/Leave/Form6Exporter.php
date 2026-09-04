<?php

namespace App\Services\Leave;

use App\Enums\ApprovalAction;
use App\Enums\LeaveStatus;
use App\Enums\LeaveStep;
use App\Models\Employee;
use App\Models\LeaveApplication;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Fills DTRC's CS Form 6 with one leave application.
 *
 * The template is loaded, written to in memory, and saved somewhere else. It is
 * never saved back over itself — that file cannot be regenerated from anything
 * in this repository, and a single wrong path would take it.
 */
class Form6Exporter
{
    public function __construct(
        private readonly Form6Map $map,
        private readonly LeaveLedger $ledger,
    ) {}

    /** @return string the path of the filled workbook */
    public function export(LeaveApplication $application): string
    {
        $application->loadMissing([
            'employee.section.division', 'employee.position',
            'type', 'approvals.approver', 'approvals.actedBy',
        ]);

        $book = IOFactory::load($this->map->path());
        $sheet = $book->getSheetByName($this->map->sheet());

        $this->writeIdentity($sheet, $application);
        $this->writeApplication($sheet, $application);
        $this->writeDetails($sheet, $application);
        $this->writeCertification($sheet, $application);
        $this->writeAction($sheet, $application);

        $path = tempnam(sys_get_temp_dir(), 'form6').'.xlsx';

        IOFactory::createWriter($book, 'Xlsx')->save($path);

        $book->disconnectWorksheets();

        return $path;
    }

    public function filename(LeaveApplication $application): string
    {
        $name = Str::of($application->employee->last_name.' '.$application->employee->first_name)
            ->upper()
            ->replaceMatches('/[^A-Z0-9]+/', '_')
            ->trim('_');

        return "CSForm6_{$name}_".$application->date_from->format('Y-m-d').'.xlsx';
    }

    private function writeIdentity(Worksheet $sheet, LeaveApplication $application): void
    {
        $employee = $application->employee;

        // The section is where the person actually sits; the division is what
        // the form falls back to for somebody who heads one.
        $this->put($sheet, $this->map->cell('office'),
            $employee->section?->name ?? $employee->division?->name ?? '');

        $this->put($sheet, $this->map->cell('name'), $employee->fullName());

        $filed = $application->filed_at ?? now();

        $this->caption($sheet, 'date_of_filing', $filed->format($this->map->dateFormat()));
        $this->caption($sheet, 'position', $employee->position?->title ?? '');

        // The system holds a salary grade, not a peso figure, and "16" in a
        // field labelled SALARY reads as sixteen pesos. The hospital's own
        // sample on this template writes it as "SG 15".
        $grade = $employee->position?->salary_grade;

        $this->caption($sheet, 'salary', $grade === null ? '' : "SG {$grade}");
    }

    private function writeApplication(Worksheet $sheet, LeaveApplication $application): void
    {
        $type = $application->type;

        $this->tickOne($sheet, 'types', $type->code);

        if ($this->map->tick('types', $type->code) === null) {
            // Wellness Leave has no box on this form. Leaving it unticked and
            // unnamed would print an application that does not say what leave
            // it is for.
            $this->put($sheet, $this->map->cell('other_type'), $type->name);
        }

        $this->put($sheet, $this->map->cell('days'), number_format($application->days, 2));

        $format = $this->map->dateFormat();

        $this->put($sheet, $this->map->cell('inclusive_dates'), sprintf(
            '%s to %s',
            $application->date_from->format($format),
            $application->date_to->format($format),
        ));

        $this->tickOne($sheet, 'commutation', $application->commutation);
    }

    /** Item 6.B. */
    private function writeDetails(Worksheet $sheet, LeaveApplication $application): void
    {
        $details = $application->details ?? [];

        foreach (['vacation_where', 'sick_where', 'study_purpose', 'benefit'] as $group) {
            $this->tickOne($sheet, $group, $details[$group] ?? null);
        }

        // Sick leave and the women's benefit each have a blank line of their
        // own; the vacation and study blocks do not, so their text goes into
        // the caption beside the box it belongs to. Writing a destination under
        // the sick-leave question would print it as an illness.
        foreach (['sick_detail', 'women_detail'] as $key) {
            if (($details[$key] ?? '') !== '') {
                $this->put($sheet, $this->map->cell($key), $details[$key]);
            }
        }

        if (($details['vacation_detail'] ?? '') !== '') {
            $this->caption(
                $sheet,
                ($details['vacation_where'] ?? '') === 'abroad' ? 'vacation_abroad' : 'vacation_within',
                $details['vacation_detail'],
            );
        }

        if (($details['study_detail'] ?? '') !== '') {
            $this->caption($sheet, 'study_other', $details['study_detail']);
        }
    }

    /**
     * Item 7.A, and the split in 7.C.
     *
     * "Total earned" is the balance plus what this application is holding, so
     * the three lines read the way the form intends: earned, less this
     * application, balance. The balance already has the hold taken out of it.
     */
    private function writeCertification(Worksheet $sheet, LeaveApplication $application): void
    {
        $employee = $application->employee;
        $spends = $application->type->ledger;

        foreach (['vacation', 'sick'] as $which) {
            $balance = $this->ledger->balance($employee, $which);
            $less = $spends === $which ? $application->days_with_pay : 0.0;

            $this->put($sheet, $this->map->cell("{$which}_earned"), number_format($balance + $less, 2));
            $this->put($sheet, $this->map->cell("{$which}_less"), number_format($less, 2));
            $this->put($sheet, $this->map->cell("{$which}_balance"), number_format($balance, 2));
        }

        $filed = $application->filed_at ?? now();

        $this->caption($sheet, 'as_of', $filed->format($this->map->dateFormat()));

        $this->caption($sheet, 'days_with_pay', number_format($application->days_with_pay, 2));
        $this->caption($sheet, 'days_without_pay', number_format($application->days_without_pay, 2));
        $this->caption($sheet, 'days_others', number_format(0, 2));
    }

    /**
     * Items 7.A to 7.D. Only what has actually happened: an unsigned step names
     * nobody, because printing a name beside an empty signature line puts words
     * in that person's mouth.
     */
    private function writeAction(Worksheet $sheet, LeaveApplication $application): void
    {
        foreach ($application->approvals as $approval) {
            if ($approval->acted_at === null) {
                continue;
            }

            // The officer recorded on the employee master, not the account
            // that clicked. The HR account can be shared, a stand-in, an
            // administrator covering a vacancy; the name printed under "Human
            // Resource Development Officer" must not change because of any of
            // that. Who actually acted is in acted_by_user_id, and on the
            // screen, where it belongs.
            if ($approval->step === LeaveStep::Hr) {
                $officer = Employee::where('is_hr_officer', true)->first();

                $this->put($sheet, $this->map->cell('hr_name'),
                    $officer?->fullName() ?? $approval->actedBy?->name ?? '');
            }

            if ($approval->step === LeaveStep::DivisionHead) {
                $this->put($sheet, $this->map->cell('division_head_name'),
                    $approval->approver?->fullName() ?? '');
            }

            // The Chief is who approves it, and a form that does not say who
            // approved it is a form the next office sends back. There is no
            // cell for the name: it replaces the ruled line inside the caption,
            // keeping the space above it to sign in.
            if ($approval->step === LeaveStep::Chief && $approval->action === ApprovalAction::Approve) {
                $this->caption($sheet, 'chief', $approval->approver?->fullName() ?? '');
            }

            if ($approval->action === ApprovalAction::Disapprove) {
                $this->put($sheet, $this->map->cell('disapproval_reason'), (string) $approval->remarks);
                $this->put($sheet, $this->map->cell('disapproved_due_to'), (string) $approval->remarks);
            }
        }

        $recommendation = match ($application->status) {
            LeaveStatus::Approved => 'approve',
            LeaveStatus::Disapproved => 'disapprove',
            default => null,
        };

        $this->tickOne($sheet, 'recommendation', $recommendation);
    }

    /** Everything is written as text: the form is read, not calculated. */
    private function put(Worksheet $sheet, string $cell, ?string $value): void
    {
        $sheet->getCell($cell)->setValueExplicit((string) $value, DataType::TYPE_STRING);
    }

    /** A caption whose blank is inside it, rewritten whole. */
    private function caption(Worksheet $sheet, string $key, string $value): void
    {
        $caption = $this->map->caption($key);

        $this->put($sheet, $caption['cell'], str_replace(':value', $value, $caption['format']));
    }

    /**
     * Ticks one box of a group and writes FALSE to the rest.
     *
     * A linked cell nobody wrote to is empty, and Excel reads empty as
     * unticked — but the form is then saying nothing rather than saying "not
     * this". A null option ticks none of them, which is what an unanswered
     * question should look like on a document signed under oath.
     */
    private function tickOne(Worksheet $sheet, string $group, ?string $option): void
    {
        $chosen = $this->map->tick($group, $option);

        foreach (config("form6_template.ticks.{$group}") as $cell) {
            $sheet->getCell($cell)->setValueExplicit($cell === $chosen, DataType::TYPE_BOOL);
        }
    }
}
