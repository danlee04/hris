<?php

namespace App\Enums;

/** CS Form 212 items 21-26, in the order the form prints them. */
enum EducationLevel: string
{
    case Elementary = 'elementary';
    case Secondary = 'secondary';
    case Vocational = 'vocational';
    case College = 'college';
    case Graduate = 'graduate';

    public function label(): string
    {
        return match ($this) {
            self::Elementary => 'Elementary',
            self::Secondary => 'Secondary',
            self::Vocational => 'Vocational / Trade Course',
            self::College => 'College',
            self::Graduate => 'Graduate Studies',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
