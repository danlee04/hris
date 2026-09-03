<?php

namespace App\Services\Pds;

use App\Models\Employee;
use Illuminate\Database\Eloquent\Model;

/**
 * Which of the nine PDS sections hold anything.
 *
 * This design has no approval gate — an employee edits their own record and
 * nobody signs it off. That means nothing else tells them their PDS is
 * unfinished, and this is the only thing that will.
 *
 * "Complete" means the section holds at least one saved value, not that every
 * field is filled. A stricter rule would mark almost everyone incomplete
 * forever, and an indicator that is always red stops being read.
 *
 * The nine sections are declared once, here. The tab bar and the dashboard
 * both render from this, so a section cannot appear in one and not the other.
 */
class PdsCompleteness
{
    /**
     * @return list<array{key: string, label: string, route: string, complete: bool}>
     */
    public function for(Employee $employee): array
    {
        $employee->loadMissing([
            'personalInformation', 'familyBackground', 'children', 'educations',
            'eligibilities', 'workExperiences', 'voluntaryWorks',
            'learningDevelopments', 'otherEntries', 'declaration', 'references',
        ]);

        return [
            $this->section('personal-information', __('Personal information'),
                $this->hasAnyValue($employee->personalInformation)),

            $this->section('family-background', __('Family background'),
                $this->hasAnyValue($employee->familyBackground) || $employee->children->isNotEmpty()),

            $this->section('education', __('Education'),
                $employee->educations->isNotEmpty()),

            $this->section('eligibility', __('Eligibility'),
                $employee->eligibilities->isNotEmpty()),

            $this->section('work-experience', __('Work experience'),
                $employee->workExperiences->isNotEmpty()),

            $this->section('voluntary-work', __('Voluntary work'),
                $employee->voluntaryWorks->isNotEmpty()),

            $this->section('learning-development', __('Learning & development'),
                $employee->learningDevelopments->isNotEmpty()),

            $this->section('other-information', __('Other information'),
                $employee->otherEntries->isNotEmpty()),

            $this->section('declarations', __('Declarations'),
                $this->hasAnyValue($employee->declaration) || $employee->references->isNotEmpty()),
        ];
    }

    public function completedCount(Employee $employee): int
    {
        return count(array_filter($this->for($employee), fn (array $s) => $s['complete']));
    }

    public function isComplete(Employee $employee): bool
    {
        return $this->completedCount($employee) === count($this->for($employee));
    }

    /**
     * @return array{key: string, label: string, route: string, complete: bool}
     */
    private function section(string $key, string $label, bool $complete): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'route' => "pds.{$key}",
            'complete' => $complete,
        ];
    }

    /**
     * A one-to-one section counts as started when any column other than the
     * keys and timestamps holds something. A row of nothing but nulls is what
     * `updateOrCreate` leaves behind when someone opens a section and saves
     * without typing, and that is not an answer.
     *
     * A model may name columns that are a setting rather than an answer — see
     * `NOT_AN_ANSWER`. Those carry a database default, so every new row already
     * holds them and counting them would mark the section started immediately.
     *
     * `false` on its own is not excluded: on the declarations page, answering
     * no is answering.
     */
    private function hasAnyValue(?Model $record): bool
    {
        if ($record === null) {
            return false;
        }

        $ignored = array_merge(
            ['id', 'employee_id', 'created_at', 'updated_at'],
            defined($record::class.'::NOT_AN_ANSWER') ? $record::NOT_AN_ANSWER : [],
        );

        foreach ($record->getAttributes() as $column => $value) {
            if (in_array($column, $ignored, true)) {
                continue;
            }

            if ($value !== null && $value !== '') {
                return true;
            }
        }

        return false;
    }
}
