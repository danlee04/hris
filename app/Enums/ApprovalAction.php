<?php

namespace App\Enums;

enum ApprovalAction: string
{
    case Approve = 'approve';
    case Disapprove = 'disapprove';
    case Return = 'return';

    public function label(): string
    {
        return match ($this) {
            self::Approve => 'Approved',
            self::Disapprove => 'Disapproved',
            self::Return => 'Returned for correction',
        };
    }
}
