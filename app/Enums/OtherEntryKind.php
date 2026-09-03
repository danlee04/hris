<?php

namespace App\Enums;

/**
 * CS Form 212 items 31, 32 and 33.
 *
 * All three are an ordered list of single-line text, printed side by side on
 * the form. Three identical tables would mean three copies of the same
 * component, the same validation and the same exporter branch; one table with
 * this enum means one of each, used three times.
 */
enum OtherEntryKind: string
{
    case SkillOrHobby = 'skill_hobby';
    case Distinction = 'distinction';
    case Membership = 'membership';

    /** The heading as the form prints it. */
    public function label(): string
    {
        return match ($this) {
            self::SkillOrHobby => 'Special skills and hobbies',
            self::Distinction => 'Non-academic distinctions and recognition',
            self::Membership => 'Membership in association or organization',
        };
    }

    public function itemNumber(): int
    {
        return match ($this) {
            self::SkillOrHobby => 31,
            self::Distinction => 32,
            self::Membership => 33,
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
