<?php

namespace App\Enums;

enum LeaveStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Disapproved = 'disapproved';
    case Returned = 'returned';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Approved => 'Approved',
            self::Disapproved => 'Disapproved',
            self::Returned => 'Returned for correction',
            self::Cancelled => 'Cancelled',
        };
    }

    /** The colour a Flux badge uses for it. */
    public function color(): string
    {
        return match ($this) {
            self::Pending => 'amber',
            self::Approved => 'green',
            self::Disapproved => 'red',
            self::Returned => 'orange',
            self::Cancelled => 'zinc',
        };
    }

    /** Credits are held only while one of these is true. */
    public function holdsCredits(): bool
    {
        return $this === self::Pending;
    }
}
