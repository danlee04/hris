<?php

use App\Enums\ApprovalAction;
use App\Enums\LeaveStatus;
use App\Enums\LeaveStep;
use App\Models\LeaveApplication;
use App\Models\LeaveApproval;
use App\Services\AuditRecorder;
use App\Services\Leave\LeaveDecision;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Title;
use Livewire\Component;
use App\Livewire\Concerns\ViewsLeaveApplications;
use Livewire\WithPagination;

new #[Title('Approvals')] class extends Component {
    use ViewsLeaveApplications;
    use WithPagination;

    public string $remarks = '';

    public function mount(): void
    {
        // Reading somebody else's leave is recorded, the same as their PDS.
        // One row per application rather than one for the page, because the
        // question an auditor asks is "who saw THIS application", and a row
        // naming only the queue cannot answer it.
        //
        // On mount, not in with(): with() runs on every keystroke of the
        // remarks field, and an audit trail nobody can read is the same as none.
        $ownEmployeeId = auth()->user()?->employee?->id;

        foreach ($this->waiting()->get() as $application) {
            if ($application->employee_id !== $ownEmployeeId) {
                app(AuditRecorder::class)->recordRead(
                    $application,
                    'Read a leave application in the approvals queue'
                );
            }
        }
    }

    public function approve(int $id): void
    {
        $this->decide($id, ApprovalAction::Approve);
    }

    public function disapprove(int $id): void
    {
        $this->decide($id, ApprovalAction::Disapprove);
    }

    public function returnForCorrection(int $id): void
    {
        $this->decide($id, ApprovalAction::Return);
    }

    private function decide(int $id, ApprovalAction $action): void
    {
        $application = LeaveApplication::findOrFail($id);

        // The id came from the browser. Whether this person holds the step it
        // is sitting on right now is the whole question, and it is a policy
        // one: a permission cannot see which application is being asked about.
        $this->authorize('act', $application);

        try {
            app(LeaveDecision::class)->act(
                $application,
                $application->currentApproval(),
                $action,
                $this->remarks ?: null,
            );
        } catch (ValidationException $e) {
            $this->addError('remarks', $e->validator->errors()->first());

            return;
        }

        $this->reset('remarks');

        Flux::toast(variant: 'success', text: __('Recorded.'));
    }

    /**
     * The applications whose *current* step belongs to this person.
     *
     * Two steps rather than one correlated subquery: find the first unsigned
     * approval of every pending application, then keep the ones that are this
     * person's. Written the plain way because a query nobody can read is a
     * query nobody can fix, and this list is at most a few hundred rows.
     */
    private function waiting(): Builder
    {
        $employeeId = auth()->user()?->employee?->id;
        $isHr = auth()->user()?->can('leave.manage') ?? false;

        $applicationIds = LeaveApproval::query()
            ->whereNull('acted_at')
            ->whereHas('application', fn ($q) => $q->where('status', LeaveStatus::Pending))
            ->get(['id', 'leave_application_id', 'sequence', 'step', 'approver_employee_id'])
            ->groupBy('leave_application_id')
            ->map(fn ($approvals) => $approvals->sortBy('sequence')->first())
            ->filter(fn ($approval) => $approval->approver_employee_id !== null
                ? $approval->approver_employee_id === $employeeId
                : ($isHr && $approval->step === LeaveStep::Hr))
            ->pluck('leave_application_id');

        return LeaveApplication::query()
            ->whereIn('id', $applicationIds)
            ->with(['employee', 'type', 'approvals.approver'])
            ->latest('filed_at');
    }

    /** @return array<string, mixed> */
    public function with(): array
    {
        return ['applications' => $this->waiting()->paginate(10)];
    }
}; ?>

<section class="w-full">
    <flux:heading size="xl">{{ __('Approvals') }}</flux:heading>
    <flux:subheading>{{ __('Applications waiting on you.') }}</flux:subheading>

    <flux:input
        wire:model="remarks"
        class="mt-6 max-w-xl"
        :label="__('Remarks')"
        :description="__('Required when you disapprove or return. The applicant sees this.')"
    />

    <flux:error name="remarks" />

    <flux:table class="mt-6" :paginate="$applications">
        <flux:table.columns>
            <flux:table.column>{{ __('Employee') }}</flux:table.column>
            <flux:table.column>{{ __('Type') }}</flux:table.column>
            <flux:table.column>{{ __('Dates') }}</flux:table.column>
            <flux:table.column>{{ __('Days') }}</flux:table.column>
            <flux:table.column>{{ __('Your step') }}</flux:table.column>
            <flux:table.column></flux:table.column>
        </flux:table.columns>

        <flux:table.rows>
            @forelse ($applications as $application)
                <flux:table.row wire:key="approval-{{ $application->id }}">
                    <flux:table.cell class="font-medium">{{ $application->employee->fullName() }}</flux:table.cell>
                    <flux:table.cell>{{ $application->type->name }}</flux:table.cell>
                    <flux:table.cell>
                        {{ $application->date_from->format('d/m/Y') }} –
                        {{ $application->date_to->format('d/m/Y') }}
                    </flux:table.cell>
                    <flux:table.cell>
                        {{ number_format($application->days, 2) }}
                        @if ($application->days_without_pay > 0)
                            <flux:text class="text-xs">
                                {{ __(':days without pay', ['days' => number_format($application->days_without_pay, 2)]) }}
                            </flux:text>
                        @endif
                    </flux:table.cell>
                    <flux:table.cell>{{ $application->currentApproval()?->step->action() }}</flux:table.cell>
                    <flux:table.cell>
                        <div class="flex gap-3 text-sm">
                            <flux:link href="#" wire:click.prevent="open({{ $application->id }})">
                                {{ __('View') }}
                            </flux:link>
                            <flux:link href="#" wire:click.prevent="approve({{ $application->id }})">
                                {{ __('Approve') }}
                            </flux:link>
                            <flux:link href="#" wire:click.prevent="returnForCorrection({{ $application->id }})">
                                {{ __('Return') }}
                            </flux:link>
                            <flux:link href="#" wire:click.prevent="disapprove({{ $application->id }})">
                                {{ __('Disapprove') }}
                            </flux:link>
                        </div>
                    </flux:table.cell>
                </flux:table.row>
            @empty
                <flux:table.row>
                    <flux:table.cell colspan="6" class="text-center">
                        {{ __('Nothing is waiting on you.') }}
                    </flux:table.cell>
                </flux:table.row>
            @endforelse
        </flux:table.rows>
    </flux:table>

    <flux:modal name="leave-detail" class="w-full md:max-w-2xl">
        @if ($detail = $this->viewing())
            <x-leave.application-detail :application="$detail" />

            <div class="mt-6 flex justify-end gap-3">
                @can('export', $detail)
                    <flux:button
                        wire:click="download({{ $detail->id }})"
                        variant="primary"
                        icon="arrow-down-tray"
                        size="sm"
                    >
                        {{ __('CS Form 6') }}
                    </flux:button>
                @endcan

                <flux:modal.close>
                    <flux:button type="button" variant="ghost" size="sm">{{ __('Close') }}</flux:button>
                </flux:modal.close>
            </div>
        @endif
    </flux:modal>
</section>
