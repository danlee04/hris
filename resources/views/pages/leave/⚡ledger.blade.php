<?php

use App\Models\Employee;
use App\Models\LeaveLedgerEntry;
use App\Services\Leave\LeaveBalance;
use App\Services\Leave\LeaveLedger;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Leave ledger')] class extends Component {
    use WithPagination;

    public int $employeeId;

    public string $ledger = '';

    public string $days = '';

    public string $reason = '';

    public function mount(Employee $employee): void
    {
        $this->authorize('leave.manage');

        $this->employeeId = $employee->id;
    }

    public function openBalance(): void
    {
        // mount() ran once. employeeId is rehydrated from the browser on every
        // later request, so the write asks again.
        $this->authorize('leave.manage');

        $employee = $this->subject();

        $this->validate([
            'ledger' => ['required', ...$this->ledgerRules($employee)],
            'days' => ['required', 'numeric', 'min:0'],
        ]);

        try {
            app(LeaveLedger::class)->open($employee, $this->ledger, (float) $this->days);
        } catch (ValidationException $e) {
            // The service refuses a second opening balance. Show its words
            // rather than a generic failure; they say what to do instead.
            $this->addError('days', $e->validator->errors()->first());

            return;
        }

        $this->reset(['days', 'reason']);

        Flux::toast(variant: 'success', text: __('Opening balance recorded.'));
    }

    public function adjust(): void
    {
        $this->authorize('leave.manage');

        $employee = $this->subject();

        $this->validate([
            'ledger' => ['required', ...$this->ledgerRules($employee)],
            'days' => ['required', 'numeric'],
            // An unexplained change to somebody's leave balance is the entry a
            // person will ask about a year later.
            'reason' => ['required', 'string', 'max:255'],
        ]);

        app(LeaveLedger::class)->adjust($employee, $this->ledger, (float) $this->days, $this->reason);

        $this->reset(['days', 'reason']);

        Flux::toast(variant: 'success', text: __('Adjustment recorded.'));
    }

    /**
     * The select only offers the balances this employee can hold, but the
     * property comes back from the browser as whatever was sent. A job order
     * with a vacation balance holds a number nothing on their form can spend.
     *
     * @return list<mixed>
     */
    private function ledgerRules(Employee $employee): array
    {
        $allowed = collect(app(LeaveBalance::class)->for($employee))->pluck('ledger')->all();

        return [Rule::in($allowed)];
    }

    private function subject(): Employee
    {
        return Employee::findOrFail($this->employeeId);
    }

    /** @return array<string, mixed> */
    public function with(): array
    {
        $employee = $this->subject();

        return [
            'employee' => $employee,
            'balances' => app(LeaveBalance::class)->for($employee),
            'entries' => LeaveLedgerEntry::where('employee_id', $employee->id)
                ->with('createdBy')
                ->latest('id')
                ->paginate(25),
        ];
    }
}; ?>

<section class="w-full">
    <flux:heading size="xl">{{ $employee->fullName() }}</flux:heading>
    <flux:subheading>
        {{ __('Every movement of every credit. Entries are added, never changed.') }}
    </flux:subheading>

    <div class="mt-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        @foreach ($balances as $balance)
            <flux:card wire:key="balance-{{ $balance['ledger'] }}">
                <flux:subheading>{{ $balance['label'] }}</flux:subheading>
                <flux:heading size="xl">{{ number_format($balance['days'], 2) }}</flux:heading>
            </flux:card>
        @endforeach
    </div>

    @if ($balances === [])
        <flux:callout class="mt-6" icon="information-circle">
            {{ __('This employment status holds no leave balances.') }}
        </flux:callout>
    @endif

    <flux:card class="mt-8 max-w-3xl space-y-6">
        <flux:heading size="lg">{{ __('Record an entry') }}</flux:heading>

        <div class="grid gap-6 sm:grid-cols-3">
            <flux:select wire:model="ledger" :label="__('Balance')" :placeholder="__('Choose')">
                @foreach ($balances as $balance)
                    <flux:select.option wire:key="option-{{ $balance['ledger'] }}" value="{{ $balance['ledger'] }}">
                        {{ $balance['label'] }}
                    </flux:select.option>
                @endforeach
            </flux:select>

            <flux:input
                wire:model="days"
                type="number"
                step="0.25"
                :label="__('Days')"
                :description="__('Negative takes credits away.')"
            />

            <flux:input wire:model="reason" :label="__('Reason')" :description="__('Adjustments only.')" />
        </div>

        <div class="flex flex-wrap items-center gap-3">
            <flux:button wire:click="openBalance" variant="primary">
                {{ __('Record the opening balance') }}
            </flux:button>

            <flux:button wire:click="adjust" variant="filled">
                {{ __('Record an adjustment') }}
            </flux:button>
        </div>

        <flux:text class="text-sm">
            {{ __('An opening balance is what was carried in from the spreadsheet, and is recorded once. Everything after it is an adjustment, and an adjustment says why.') }}
        </flux:text>
    </flux:card>

    <flux:table class="mt-8" :paginate="$entries">
        <flux:table.columns>
            <flux:table.column>{{ __('Date') }}</flux:table.column>
            <flux:table.column>{{ __('Balance') }}</flux:table.column>
            <flux:table.column>{{ __('Kind') }}</flux:table.column>
            <flux:table.column>{{ __('Days') }}</flux:table.column>
            <flux:table.column>{{ __('Reason') }}</flux:table.column>
            <flux:table.column>{{ __('Recorded by') }}</flux:table.column>
        </flux:table.columns>

        <flux:table.rows>
            @forelse ($entries as $entry)
                <flux:table.row wire:key="entry-{{ $entry->id }}">
                    <flux:table.cell>{{ $entry->effective_date->format('d/m/Y') }}</flux:table.cell>
                    <flux:table.cell>{{ $entry->ledger }}</flux:table.cell>
                    <flux:table.cell>{{ $entry->kind->label() }}</flux:table.cell>
                    <flux:table.cell class="font-medium">{{ number_format($entry->days, 2) }}</flux:table.cell>
                    <flux:table.cell>{{ $entry->description }}</flux:table.cell>
                    <flux:table.cell>{{ $entry->createdBy?->name }}</flux:table.cell>
                </flux:table.row>
            @empty
                <flux:table.row>
                    <flux:table.cell colspan="6" class="text-center">
                        {{ __('No entries yet. Record the opening balance to start.') }}
                    </flux:table.cell>
                </flux:table.row>
            @endforelse
        </flux:table.rows>
    </flux:table>
</section>
