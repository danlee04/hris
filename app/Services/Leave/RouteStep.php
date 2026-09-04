<?php

namespace App\Services\Leave;

use App\Enums\LeaveStep;
use App\Models\Employee;

/**
 * One signature on one application.
 *
 * `approver` is null for the HR step and only for the HR step: that one is held
 * by an office rather than a person.
 */
readonly class RouteStep
{
    public function __construct(
        public LeaveStep $step,
        public ?Employee $approver,
    ) {}
}
