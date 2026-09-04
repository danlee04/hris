<?php

namespace App\Enums;

/**
 * The four signatures on a leave application, in the order they are collected.
 *
 * Three of them are people, read from the organizational chart. HR is an
 * office: whoever holds leave.manage acts, and the person who actually pressed
 * the button is recorded. An office does not go on leave.
 */
enum LeaveStep: string
{
    case SectionHead = 'section_head';
    case Hr = 'hr';
    case DivisionHead = 'division_head';
    case Chief = 'chief';

    public function label(): string
    {
        return match ($this) {
            self::SectionHead => 'Section head',
            self::Hr => 'Human Resource',
            self::DivisionHead => 'Division head',
            self::Chief => 'Chief of Hospital',
        };
    }

    /** What this step does to the application, in the words on CS Form 6. */
    public function action(): string
    {
        return match ($this) {
            self::SectionHead => 'Initials',
            self::Hr => 'Certifies the leave credits',
            self::DivisionHead => 'Recommends',
            self::Chief => 'Approves',
        };
    }
}
