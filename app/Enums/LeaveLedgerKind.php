<?php

namespace App\Enums;

/**
 * Why a ledger entry exists.
 *
 * Hold, Release and Commit are written by Phase 2a-2, when there is an
 * application to hold credits for. They are named here because the vocabulary
 * is one thing, and a kind added later would mean a migration to widen a column
 * that could have been wide from the start.
 */
enum LeaveLedgerKind: string
{
    case Opening = 'opening';
    case Accrual = 'accrual';
    case Grant = 'grant';
    case Hold = 'hold';
    case Release = 'release';
    case Commit = 'commit';
    case Adjustment = 'adjustment';

    public function label(): string
    {
        return match ($this) {
            self::Opening => 'Opening balance',
            self::Accrual => 'Monthly accrual',
            self::Grant => 'Yearly grant',
            self::Hold => 'Held for an application',
            self::Release => 'Released',
            self::Commit => 'Used',
            self::Adjustment => 'Adjustment',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
