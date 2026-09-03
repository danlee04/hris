<?php

namespace App\Enums;

/**
 * How the hospital engages a person. Only these three — the full CSC
 * vocabulary carries statuses the hospital does not hire under, and every one
 * of them is a wrong answer sitting in a dropdown waiting to be picked.
 */
enum EmploymentStatus: string
{
    case Permanent = 'permanent';
    case JobOrder = 'job_order';
    case ContractOfService = 'contract_of_service';

    /** Written the way it appears on the appointment paper. */
    public function label(): string
    {
        return match ($this) {
            self::Permanent => 'Permanent',
            self::JobOrder => 'Job Order',
            self::ContractOfService => 'Contract of Service',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
