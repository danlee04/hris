<?php

namespace App\Services\Employees;

use App\Models\Employee;
use Illuminate\Validation\ValidationException;

/**
 * The certificate HR is asked for more often than anything else.
 *
 * Written here rather than in the view so the sentences that matter — who,
 * what post, since when — can be tested without rendering a page, and so the
 * one case the document must refuse is refused in one place.
 */
class CertificateOfEmployment
{
    /**
     * @return array<string, string|null>
     *
     * @throws ValidationException when the record cannot support a truthful one
     */
    public function for(Employee $employee, ?string $salary = null, ?string $purpose = null): array
    {
        $this->guard($employee);

        // The same person whose name prints on CS Form 6. Two sources for one
        // signature is two names that can disagree.
        $signatory = Employee::where('is_hr_officer', true)->first();

        return [
            'name' => $employee->signatureName(),
            'position' => $employee->position?->title,
            'status' => $employee->employment_status?->label(),
            'division' => $employee->division?->name ?? $employee->section?->division?->name,
            'section' => $employee->section?->name,
            'since' => $employee->date_hired?->format('j F Y'),
            'salary' => $this->salaryLine($employee, $salary),
            // The stock closing line of a Philippine government certificate. HR
            // replaces it when the person says what the certificate is for,
            // because a bank often wants its own name in it.
            'purpose' => trim((string) $purpose) ?: 'whatever legal purpose it may serve',
            'issued_on' => now()->format('j F Y'),
            'signatory' => $signatory?->signatureName(),
            'signatory_title' => $signatory?->position?->title,
        ];
    }

    /**
     * Two records cannot produce a truthful certificate.
     *
     * A separated employee: the system holds `is_active`, not the date they
     * left, so the document could say when they started and never when they
     * stopped — and a certificate that reads as current employment for somebody
     * who resigned last year is a document the hospital signed and should not
     * have. Their paper is a Service Record, which this system does not issue
     * yet.
     *
     * An employee with no hire date: the whole certificate is the dates.
     */
    private function guard(Employee $employee): void
    {
        if (! $employee->is_active) {
            throw ValidationException::withMessages([
                'employee' => __('This employee is no longer active, and the system does not hold the date they left. A certificate saying they are employed here would not be true.'),
            ]);
        }

        if ($employee->date_hired === null) {
            throw ValidationException::withMessages([
                'employee' => __('There is no date hired on this record. Fill it in first — it is most of what the certificate says.'),
            ]);
        }
    }

    /**
     * The salary sentence, or nothing.
     *
     * The peso figure is typed by HR at the moment of printing and never
     * stored. Salaries move with every tranche of the CSC schedule, and a
     * figure kept on the record would quietly print last year's amount on a
     * document somebody is taking to a bank. The person who knows the current
     * one is the person pressing print.
     *
     * The salary grade comes from the plantilla item and is printed alongside
     * when there is one, because that is the part the system can vouch for.
     */
    private function salaryLine(Employee $employee, ?string $salary): ?string
    {
        $grade = $employee->position?->salary_grade;
        $amount = trim((string) $salary);

        if ($amount === '' && $grade === null) {
            return null;
        }

        if ($amount === '') {
            return "Salary Grade {$grade}";
        }

        $pesos = '₱'.number_format((float) $amount, 2).' per month';

        return $grade === null ? $pesos : "{$pesos} (Salary Grade {$grade})";
    }
}
