<?php

namespace App\Livewire\Concerns;

use App\Models\LeaveApplication;
use App\Services\AuditRecorder;
use App\Services\Leave\Form6Exporter;
use Flux\Flux;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Opening one leave application, and printing it.
 *
 * Shared by My leave and Approvals because it is the same application seen
 * from two sides. Two copies would mean one of them keeping a field the other
 * dropped, on a screen somebody decides from.
 */
trait ViewsLeaveApplications
{
    /** The application open in the detail modal, if any. */
    public ?int $viewingId = null;

    public function open(int $id): void
    {
        $application = LeaveApplication::findOrFail($id);

        // The id arrives from the browser. A sick leave says something about a
        // person's health, and the purpose says more.
        $this->authorize('view', $application);

        $this->recordRead($application, 'Opened a leave application');

        $this->viewingId = $application->id;

        // Imported at the top, deliberately: page components sit in the root
        // namespace where a bare Flux:: resolves, but this trait does not, and
        // an unimported Flux:: here is looked for in App\Livewire\Concerns.
        Flux::modal('leave-detail')->show();
    }

    public function download(int $id): BinaryFileResponse
    {
        $application = LeaveApplication::findOrFail($id);

        $this->authorize('export', $application);

        // The whole application leaves the system in one file. That is worth
        // more of a record than reading a row of it on screen.
        $this->recordRead($application, 'Downloaded the CS Form 6');

        $exporter = app(Form6Exporter::class);

        return response()
            ->download($exporter->export($application), $exporter->filename($application))
            ->deleteFileAfterSend();
    }

    /** Reading your own leave is not worth recording; everything else is. */
    private function recordRead(LeaveApplication $application, string $what): void
    {
        if ($application->employee_id === auth()->user()?->employee?->id) {
            return;
        }

        app(AuditRecorder::class)->recordRead($application, $what);
    }

    /** The application the detail modal is showing, reloaded on every render. */
    public function viewing(): ?LeaveApplication
    {
        if ($this->viewingId === null) {
            return null;
        }

        return LeaveApplication::with(['employee', 'type', 'approvals.approver', 'approvals.actedBy'])
            ->find($this->viewingId);
    }
}
