<?php

namespace App\Enums;

/** CS Form 212 item 30 — the four types the form offers. */
enum LearningDevelopmentType: string
{
    case Managerial = 'managerial';
    case Supervisory = 'supervisory';
    case Technical = 'technical';
    case Foundation = 'foundation';

    public function label(): string
    {
        return match ($this) {
            self::Managerial => 'Managerial',
            self::Supervisory => 'Supervisory',
            self::Technical => 'Technical',
            self::Foundation => 'Foundation',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
