<?php

namespace App\Enums;

/**
 * How the hospital engages a person.
 *
 * The first three come from the legacy training system, which carried exactly
 * these: Permanent (93 people), Contract of Service (35), Job Order (1).
 * Co-terminous appeared later, in the HR office's own roster.
 *
 * Keep this list to what the hospital actually hires under. The full CSC
 * vocabulary adds Temporary, Casual, Contractual, Substitute and Provisional —
 * every one of them a wrong answer sitting in a dropdown waiting to be picked.
 */
enum EmploymentStatus: string
{
    case Permanent = 'permanent';
    case JobOrder = 'job_order';
    case ContractOfService = 'contract_of_service';
    case Coterminous = 'coterminous';

    /** Written the way it appears on the appointment paper. */
    public function label(): string
    {
        return match ($this) {
            self::Permanent => 'Permanent',
            self::JobOrder => 'Job Order',
            self::ContractOfService => 'Contract of Service',
            self::Coterminous => 'Co-terminous',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /** @return list<string> */
    public static function labels(): array
    {
        return array_map(fn (self $case) => $case->label(), self::cases());
    }

    /**
     * Match however HR wrote it. A spreadsheet exported by a person says
     * "Contract of Service" or "Co-terminous", not "contract_of_service", and
     * refusing that is a defect in the importer rather than in their file.
     */
    public static function fromLoose(string $value): ?self
    {
        $needle = self::normalize($value);

        foreach (self::cases() as $case) {
            if (self::normalize($case->value) === $needle || self::normalize($case->label()) === $needle) {
                return $case;
            }
        }

        return null;
    }

    /** Strips case and every separator, so "Co-terminous" meets "coterminous". */
    private static function normalize(string $value): string
    {
        return preg_replace('/[^a-z0-9]/', '', mb_strtolower(trim($value))) ?? '';
    }
}
