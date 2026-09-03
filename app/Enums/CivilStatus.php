<?php

namespace App\Enums;

/**
 * CS Form 212 (Revised 2026) item 6, in the order the form prints them.
 *
 * The template's own list holds only Married, Widow/er, Separated, Solo Parent
 * and Others — "Single" does not appear anywhere in the workbook. It is kept
 * here because an employee record without it is unusable, and because the cell
 * takes free text on export.
 *
 * Solo Parent is new in the 2026 revision. It is also still asked separately at
 * item 40c, so the two live side by side.
 */
enum CivilStatus: string
{
    case Single = 'single';
    case Married = 'married';
    case Widowed = 'widowed';
    case Separated = 'separated';
    case SoloParent = 'solo_parent';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Single => 'Single',
            self::Married => 'Married',
            self::Widowed => 'Widow/er',
            self::Separated => 'Separated',
            self::SoloParent => 'Solo Parent',
            self::Other => 'Others',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
