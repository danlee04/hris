<?php

namespace App\Enums;

/**
 * Where a person sits in the org chart. Placement rules:
 *   Rank & file / Section Head -> section_id (the division comes from the section)
 *   Division Head              -> division_id only, no section
 *   Chief of Hospital          -> neither, is_chief_of_hospital = true
 */
enum OrgPost: string
{
    case RankAndFile = 'rank_and_file';
    case SectionHead = 'section_head';
    case DivisionHead = 'division_head';
    case ChiefOfHospital = 'chief_of_hospital';

    public function label(): string
    {
        return match ($this) {
            self::RankAndFile => 'Rank and File',
            self::SectionHead => 'Section Head',
            self::DivisionHead => 'Division Head',
            self::ChiefOfHospital => 'Chief of Hospital',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
