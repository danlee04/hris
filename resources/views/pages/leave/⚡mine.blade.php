<?php

use App\Models\Employee;
use App\Models\LeaveApplication;
use App\Models\LeaveType;
use App\Services\Leave\LeaveBalance;
use App\Services\Leave\LeaveDecision;
use App\Services\Leave\LeaveFiler;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Title;
use Livewire\Component;
use App\Livewire\Concerns\ViewsLeaveApplications;
use Livewire\WithPagination;

new #[Title('My leave')] class extends Component {
    use ViewsLeaveApplications;
    use WithPagination;

    /** @var array<string, mixed> */
    public array $form = [];

    /** The application being corrected after it was returned, if any. */
    public ?int $refilingId = null;

    public function mount(): void
    {
        // Not a policy question: this screen is about the person signed in, and
        // an account with no employee record has nothing to show.
        abort_if($this->applicant() === null, 403, 'This account is not linked to an employee record.');

        $this->emptyForm();
    }

    public function startApplying(): void
    {
        // The same modal served a correction a moment ago. Without this, the
        // new application would silently overwrite that one.
        $this->refilingId = null;
        $this->emptyForm();
        $this->resetValidation();

        Flux::modal('leave-form')->show();
    }

    public function startRefiling(int $id): void
    {
        $application = LeaveApplication::findOrFail($id);

        // The id arrives from the browser, so it is asked about here.
        $this->authorize('refile', $application);

        $this->refilingId = $application->id;

        $this->form = [
            'leave_type_id' => $application->leave_type_id,
            'date_from' => $application->date_from->toDateString(),
            'date_to' => $application->date_to->toDateString(),
            'days' => $application->days,
            'purpose' => $application->purpose,
            'commutation' => $application->commutation,
            'details' => $application->details ?? [],
        ];

        $this->resetValidation();

        Flux::modal('leave-form')->show();
    }

    public function file(): void
    {
        $applicant = $this->applicant();

        abort_if($applicant === null, 403);

        $filer = app(LeaveFiler::class);

        try {
            if ($this->refilingId) {
                $application = LeaveApplication::findOrFail($this->refilingId);

                // refilingId came back from the browser, so it is asked about
                // again rather than trusted.
                $this->authorize('refile', $application);

                $filer->refile($application, $this->form);
            } else {
                $filer->file($applicant, $this->form);
            }
        } catch (ValidationException $e) {
            // The services speak in their own field names. Show their words
            // against this form's fields rather than inventing a second set
            // that would drift from them. The modal stays open, with whatever
            // was typed still in it.
            foreach ($e->validator->errors()->messages() as $field => $messages) {
                $this->addError("form.{$field}", $messages[0]);
            }

            return;
        }

        $this->refilingId = null;
        $this->emptyForm();

        Flux::modal('leave-form')->close();

        Flux::toast(variant: 'success', text: __('Application filed.'));
    }

    public function cancel(int $id): void
    {
        $application = LeaveApplication::findOrFail($id);

        $this->authorize('cancel', $application);

        app(LeaveDecision::class)->cancel($application);

        Flux::toast(variant: 'success', text: __('Application cancelled.'));
    }

    private function applicant(): ?Employee
    {
        return auth()->user()?->employee;
    }

    /** @return \Illuminate\Support\Collection<int, LeaveType> */
    private function availableTypes()
    {
        $applicant = $this->applicant();

        return $applicant?->employment_status
            ? LeaveType::availableTo($applicant->employment_status)->get()
            : collect();
    }

    private function emptyForm(): void
    {
        $types = $this->availableTypes();

        $this->form = [
            // A dropdown holding one option and a placeholder looks answered
            // and is not. Contract of service and job order staff are offered
            // exactly one type, so every one of them would meet "Choose the
            // type of leave" on their first try. With thirteen on offer, a
            // guess would be worse than the placeholder.
            'leave_type_id' => $types->count() === 1 ? $types->first()->id : null,
            'date_from' => null,
            'date_to' => null,
            'days' => null,
            'purpose' => null,
            'commutation' => 'not_requested',
            'details' => [],
        ];
    }

    /** @return array<string, mixed> */
    public function with(): array
    {
        $applicant = $this->applicant();

        return [
            'balances' => $applicant ? app(LeaveBalance::class)->for($applicant) : [],
            // The same list the form preselects from, so the two cannot differ.
            'types' => $this->availableTypes(),
            'applications' => LeaveApplication::query()
                ->where('employee_id', $applicant?->id)
                ->with(['type', 'approvals.approver'])
                ->latest('id')
                ->paginate(10),
        ];
    }
}; ?>

<section class="w-full">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <flux:heading size="xl">{{ __('My leave') }}</flux:heading>
            <flux:subheading>{{ __('What you hold, and what you have asked for.') }}</flux:subheading>
        </div>

        <flux:button wire:click="startApplying" variant="primary" icon="plus" size="sm">
            {{ __('Apply for leave') }}
        </flux:button>
    </div>

    {{-- The balance already has every pending hold taken out of it, which is
         what makes it the number worth showing. --}}
    <div class="mt-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        @foreach ($balances as $balance)
            <flux:card wire:key="balance-{{ $balance['ledger'] }}">
                <flux:subheading>{{ $balance['label'] }}</flux:subheading>
                <flux:heading size="xl">{{ number_format($balance['days'], 2) }}</flux:heading>
            </flux:card>
        @endforeach
    </div>

    <flux:table class="mt-8" :paginate="$applications">
        <flux:table.columns>
            <flux:table.column>{{ __('Type') }}</flux:table.column>
            <flux:table.column>{{ __('Dates') }}</flux:table.column>
            <flux:table.column>{{ __('Days') }}</flux:table.column>
            <flux:table.column>{{ __('Status') }}</flux:table.column>
            <flux:table.column>{{ __('Waiting on') }}</flux:table.column>
            <flux:table.column></flux:table.column>
        </flux:table.columns>

        <flux:table.rows>
            @forelse ($applications as $application)
                <flux:table.row wire:key="application-{{ $application->id }}">
                    <flux:table.cell class="font-medium">{{ $application->type->name }}</flux:table.cell>
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
                    <flux:table.cell>
                        <flux:badge size="sm" :color="$application->status->color()">
                            {{ $application->status->label() }}
                        </flux:badge>
                    </flux:table.cell>
                    <flux:table.cell>
                        @php $current = $application->currentApproval(); @endphp
                        {{ $current?->approver?->fullName() ?? $current?->step->label() ?? '—' }}
                    </flux:table.cell>
                    <flux:table.cell>
                        <div class="flex gap-3 text-sm">
                            <flux:link href="#" wire:click.prevent="open({{ $application->id }})">
                                {{ __('View') }}
                            </flux:link>

                            @can('cancel', $application)
                                <flux:link href="#" wire:click.prevent="cancel({{ $application->id }})">
                                    {{ __('Cancel') }}
                                </flux:link>
                            @endcan

                            @can('refile', $application)
                                <flux:link href="#" wire:click.prevent="startRefiling({{ $application->id }})">
                                    {{ __('Correct and send again') }}
                                </flux:link>
                            @endcan
                        </div>
                    </flux:table.cell>
                </flux:table.row>
            @empty
                <flux:table.row>
                    <flux:table.cell colspan="6" class="text-center">
                        {{ __('You have not applied for leave.') }}
                    </flux:table.cell>
                </flux:table.row>
            @endforelse
        </flux:table.rows>
    </flux:table>

    <flux:modal name="leave-form" class="w-full md:max-w-2xl">
        <form wire:submit="file" class="space-y-6">
            <flux:heading size="lg">
                {{ $refilingId ? __('Correct and send again') : __('Apply for leave') }}
            </flux:heading>

            {{-- Live, because item 6.B asks a different question of each type
                 and the form has to change with the answer. --}}
            <flux:select wire:model.live="form.leave_type_id" :label="__('Type of leave')" :placeholder="__('Choose')">
                @foreach ($types as $type)
                    <flux:select.option wire:key="type-{{ $type->id }}" value="{{ $type->id }}">
                        {{ $type->name }}
                    </flux:select.option>
                @endforeach
            </flux:select>

            <div class="grid gap-6 sm:grid-cols-3">
                <flux:input wire:model="form.date_from" type="date" :label="__('First day')" />
                <flux:input wire:model="form.date_to" type="date" :label="__('Last day')" />
                <flux:input
                    wire:model="form.days"
                    type="number"
                    step="0.5"
                    :label="__('Working days')"
                    :description="__('Half days allowed.')"
                />
            </div>

            @php
                $chosen = $types->firstWhere('id', (int) ($form['leave_type_id'] ?? 0));
            @endphp

            {{-- Item 6.B of CS Form 6. Only the question this type asks: a sick
                 leave has nothing to say about a destination, and a form
                 offering every question at once is a form nobody reads. --}}
            @if (in_array($chosen?->code, ['VL', 'SPL'], true))
                <div class="grid gap-6 sm:grid-cols-2">
                    <flux:select wire:model="form.details.vacation_where" :label="__('Where')" :placeholder="__('Choose')">
                        <flux:select.option value="within_philippines">{{ __('Within the Philippines') }}</flux:select.option>
                        <flux:select.option value="abroad">{{ __('Abroad') }}</flux:select.option>
                    </flux:select>

                    <flux:input wire:model="form.details.vacation_detail" :label="__('Specify')" />
                </div>
            @elseif ($chosen?->code === 'SL')
                <div class="grid gap-6 sm:grid-cols-2">
                    <flux:select wire:model="form.details.sick_where" :label="__('Where')" :placeholder="__('Choose')">
                        <flux:select.option value="in_hospital">{{ __('In hospital') }}</flux:select.option>
                        <flux:select.option value="out_patient">{{ __('Out patient') }}</flux:select.option>
                    </flux:select>

                    <flux:input wire:model="form.details.sick_detail" :label="__('Illness')" />
                </div>
            @elseif ($chosen?->code === 'STUDY')
                <div class="grid gap-6 sm:grid-cols-2">
                    <flux:select wire:model="form.details.study_purpose" :label="__('Purpose')" :placeholder="__('Choose')">
                        <flux:select.option value="masters">{{ __("Completion of master's degree") }}</flux:select.option>
                        <flux:select.option value="board_review">{{ __('BAR / board examination review') }}</flux:select.option>
                    </flux:select>

                    <flux:input wire:model="form.details.study_detail" :label="__('Other purpose')" />
                </div>
            @elseif ($chosen?->code === 'SLBW')
                <flux:input wire:model="form.details.women_detail" :label="__('Illness')" />
            @endif

            {{-- No box for this on CS Form 6. It is for the people deciding:
                 a section head reading a queue of dates cannot recommend
                 anything without knowing what the days are for. --}}
            <flux:textarea
                wire:model="form.purpose"
                :label="__('Purpose')"
                :description="__('What the days are for. The approvers see this; it does not print on the form.')"
                rows="2"
            />

            <flux:select wire:model="form.commutation" :label="__('Commutation')">
                <flux:select.option value="not_requested">{{ __('Not requested') }}</flux:select.option>
                <flux:select.option value="requested">{{ __('Requested') }}</flux:select.option>
            </flux:select>

            <flux:error name="form.leave_type_id" />
            <flux:error name="form.date_from" />
            <flux:error name="form.date_to" />
            <flux:error name="form.days" />

            <div class="flex justify-end gap-3">
                <flux:modal.close>
                    <flux:button type="button" variant="ghost">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>

                <flux:button type="submit" variant="primary">{{ __('File it') }}</flux:button>
            </div>
        </form>
    </flux:modal>
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
