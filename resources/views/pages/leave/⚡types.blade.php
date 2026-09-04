<?php

use App\Enums\EmploymentStatus;
use App\Models\LeaveType;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Leave types')] class extends Component {
    use WithPagination;

    /** Null while adding, the id of the row being corrected otherwise. */
    public ?int $editingId = null;

    public string $code = '';

    public string $name = '';

    public string $legalBasis = '';

    public ?string $ledger = null;

    public ?string $accrualDaysPerMonth = null;

    public ?int $grantDaysPerYear = null;

    public ?int $noticeDays = null;

    public ?int $maxConsecutiveDays = null;

    /** @var list<string> */
    public array $appliesTo = [];

    public function mount(): void
    {
        // Nobody owns the leave vocabulary, so there is no ownership question
        // and no policy. The permission is the whole answer.
        $this->authorize('leave.types.manage');
    }

    public function add(): void
    {
        $this->authorize('leave.types.manage');

        // The same modal serves both jobs, so it has to be emptied on the way
        // in. Without this, Add after Edit overwrites the row last opened.
        $this->resetForm();

        Flux::modal('leave-type-form')->show();
    }

    public function edit(int $id): void
    {
        $this->authorize('leave.types.manage');

        $type = LeaveType::findOrFail($id);

        $this->resetValidation();

        $this->editingId = $type->id;
        $this->code = $type->code;
        $this->name = $type->name;
        $this->legalBasis = (string) $type->legal_basis;
        $this->ledger = $type->ledger;
        $this->accrualDaysPerMonth = $type->accrual_days_per_month;
        $this->grantDaysPerYear = $type->grant_days_per_year;
        $this->noticeDays = $type->notice_days;
        $this->maxConsecutiveDays = $type->max_consecutive_days;
        $this->appliesTo = $type->applies_to;

        Flux::modal('leave-type-form')->show();
    }

    public function save(): void
    {
        // Every request after mount() carries whatever the browser sends,
        // including editingId. The save asks again.
        $this->authorize('leave.types.manage');

        $validated = $this->validate([
            'code' => [
                'required', 'string', 'max:20', 'alpha_dash',
                Rule::unique('leave_types', 'code')->ignore($this->editingId),
            ],
            'name' => ['required', 'string', 'max:255'],
            'legalBasis' => ['nullable', 'string', 'max:255'],
            'ledger' => ['nullable', Rule::in(['vacation', 'sick', 'spl', 'solo_parent', 'wellness'])],
            'accrualDaysPerMonth' => ['nullable', 'numeric', 'min:0', 'max:31'],
            'grantDaysPerYear' => ['nullable', 'integer', 'min:0', 'max:365'],
            'noticeDays' => ['nullable', 'integer', 'min:0', 'max:365'],
            'maxConsecutiveDays' => ['nullable', 'integer', 'min:1', 'max:365'],
            // A type nobody may file is a row that looks like a policy and
            // grants nothing.
            'appliesTo' => ['required', 'array', 'min:1'],
            'appliesTo.*' => [Rule::enum(EmploymentStatus::class)],
        ]);

        $attributes = [
            'code' => strtoupper($validated['code']),
            'name' => $validated['name'],
            'legal_basis' => $validated['legalBasis'] ?: null,
            'ledger' => $validated['ledger'] ?: null,
            'accrual_days_per_month' => $validated['accrualDaysPerMonth'] ?: null,
            'grant_days_per_year' => $validated['grantDaysPerYear'],
            'notice_days' => $validated['noticeDays'],
            'max_consecutive_days' => $validated['maxConsecutiveDays'],
            'applies_to' => $validated['appliesTo'],
        ];

        if ($this->editingId) {
            LeaveType::findOrFail($this->editingId)->update($attributes);
        } else {
            LeaveType::create($attributes);
        }

        // Validation throws before this line, so a modal that closes is a modal
        // whose contents were written.
        $this->resetForm();

        Flux::modal('leave-type-form')->close();

        Flux::toast(variant: 'success', text: __('Leave type saved.'));
    }

    /**
     * There is no delete. Applications point at a type for years, and removing
     * one would leave them naming nothing.
     */
    public function toggleActive(int $id): void
    {
        $this->authorize('leave.types.manage');

        $type = LeaveType::findOrFail($id);

        $type->update(['is_active' => ! $type->is_active]);
    }

    private function resetForm(): void
    {
        $this->reset([
            'editingId', 'code', 'name', 'legalBasis', 'ledger',
            'accrualDaysPerMonth', 'grantDaysPerYear', 'noticeDays',
            'maxConsecutiveDays', 'appliesTo',
        ]);
        $this->resetValidation();
    }

    /** @return array<string, mixed> */
    public function with(): array
    {
        return [
            'types' => LeaveType::orderBy('sort_order')->orderBy('code')->paginate(15),
        ];
    }
}; ?>

<section class="w-full">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <flux:heading size="xl">{{ __('Leave types') }}</flux:heading>
            <flux:subheading>
                {{ __('Each type carries its own rules, not just a number of days.') }}
            </flux:subheading>
        </div>

        <flux:button wire:click="add" variant="primary" icon="plus" size="sm">
            {{ __('Add a leave type') }}
        </flux:button>
    </div>

    <flux:table class="mt-6" :paginate="$types">
        <flux:table.columns>
            <flux:table.column>{{ __('Code') }}</flux:table.column>
            <flux:table.column>{{ __('Name') }}</flux:table.column>
            <flux:table.column>{{ __('Balance') }}</flux:table.column>
            <flux:table.column>{{ __('Rules') }}</flux:table.column>
            <flux:table.column>{{ __('Who may file') }}</flux:table.column>
            <flux:table.column>{{ __('Status') }}</flux:table.column>
            <flux:table.column></flux:table.column>
        </flux:table.columns>

        <flux:table.rows>
            @forelse ($types as $type)
                <flux:table.row wire:key="leave-type-{{ $type->id }}">
                    <flux:table.cell class="font-medium">{{ $type->code }}</flux:table.cell>
                    <flux:table.cell>{{ $type->name }}</flux:table.cell>
                    <flux:table.cell>{{ $type->ledger ?? '—' }}</flux:table.cell>
                    <flux:table.cell class="text-sm">
                        @if ($type->accrual_days_per_month)
                            {{ __(':days/month', ['days' => $type->accrual_days_per_month]) }}<br>
                        @endif
                        @if ($type->grant_days_per_year)
                            {{ __(':days/year', ['days' => $type->grant_days_per_year]) }}<br>
                        @endif
                        @if ($type->notice_days)
                            {{ __(':days days notice', ['days' => $type->notice_days]) }}<br>
                        @endif
                        @if ($type->max_consecutive_days)
                            {{ __('max :days consecutive', ['days' => $type->max_consecutive_days]) }}
                        @endif
                    </flux:table.cell>
                    <flux:table.cell class="text-sm">
                        {{ collect($type->applies_to)
                            ->map(fn ($value) => App\Enums\EmploymentStatus::from($value)->label())
                            ->join(', ') }}
                    </flux:table.cell>
                    <flux:table.cell>
                        <flux:badge size="sm" :color="$type->is_active ? 'green' : 'zinc'">
                            {{ $type->is_active ? __('Active') : __('Retired') }}
                        </flux:badge>
                    </flux:table.cell>
                    <flux:table.cell>
                        <div class="flex gap-3 text-sm">
                            <flux:link href="#" wire:click.prevent="edit({{ $type->id }})">
                                {{ __('Edit') }}
                            </flux:link>
                            <flux:link href="#" wire:click.prevent="toggleActive({{ $type->id }})">
                                {{ $type->is_active ? __('Retire') : __('Restore') }}
                            </flux:link>
                        </div>
                    </flux:table.cell>
                </flux:table.row>
            @empty
                <flux:table.row>
                    <flux:table.cell colspan="7" class="text-center">
                        {{ __('No leave types yet. Run php artisan db:seed to load the CS Form 6 list.') }}
                    </flux:table.cell>
                </flux:table.row>
            @endforelse
        </flux:table.rows>
    </flux:table>

    <flux:modal name="leave-type-form" class="w-full md:max-w-2xl">
        <form wire:submit="save" class="space-y-6">
            <flux:heading size="lg">
                {{ $editingId ? __('Edit leave type') : __('Add a leave type') }}
            </flux:heading>

            <div class="grid gap-6 sm:grid-cols-2">
                <flux:input wire:model="code" :label="__('Code')" placeholder="VL" />
                <flux:input wire:model="name" :label="__('Name')" placeholder="Vacation Leave" />
            </div>

            <flux:input
                wire:model="legalBasis"
                :label="__('Legal basis')"
                :description="__('Printed on CS Form 6 beside the name.')"
            />

            <flux:select
                wire:model="ledger"
                :label="__('Balance it draws on')"
                :placeholder="__('None — approved but spends nothing')"
            >
                <flux:select.option value="vacation">{{ __('Vacation') }}</flux:select.option>
                <flux:select.option value="sick">{{ __('Sick') }}</flux:select.option>
                <flux:select.option value="spl">{{ __('Special Privilege') }}</flux:select.option>
                <flux:select.option value="solo_parent">{{ __('Solo Parent') }}</flux:select.option>
                <flux:select.option value="wellness">{{ __('Wellness') }}</flux:select.option>
            </flux:select>

            <div class="grid gap-6 sm:grid-cols-2">
                <flux:input wire:model="accrualDaysPerMonth" type="number" step="0.01" :label="__('Days earned per month')" />
                <flux:input wire:model="grantDaysPerYear" type="number" :label="__('Days granted per year')" />
                <flux:input wire:model="noticeDays" type="number" :label="__('Days of notice required')" />
                <flux:input wire:model="maxConsecutiveDays" type="number" :label="__('Maximum consecutive days')" />
            </div>

            <flux:checkbox.group wire:model="appliesTo" :label="__('Who may file it')">
                @foreach (App\Enums\EmploymentStatus::cases() as $status)
                    <flux:checkbox :value="$status->value" :label="$status->label()" />
                @endforeach
            </flux:checkbox.group>

            <flux:error name="appliesTo" />

            <div class="flex justify-end gap-3">
                <flux:modal.close>
                    <flux:button type="button" variant="ghost">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>

                <flux:button type="submit" variant="primary">
                    {{ $editingId ? __('Save') : __('Add') }}
                </flux:button>
            </div>
        </form>
    </flux:modal>
</section>
