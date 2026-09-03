<?php

namespace App\Services;

use Illuminate\Database\Eloquent\Model;

/**
 * activitylog records writes on its own. Reads it cannot see — and reading
 * someone else's record is the more common abuse: edits are rare, and looking
 * up a colleague's home address or their answer to PDS item 35 is not.
 *
 * Phase 1a has no screen that opens a single record, so nothing calls this
 * yet. It arrives with the PDS in Phase 1b.
 */
class AuditRecorder
{
    public function recordRead(Model $subject, string $description): void
    {
        activity()
            ->performedOn($subject)
            ->causedBy(auth()->user())
            ->event('read')
            ->log($description);
    }
}
