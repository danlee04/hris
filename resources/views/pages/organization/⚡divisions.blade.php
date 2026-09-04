<?php

use App\Models\Division;
use App\Models\Employee;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Divisions')] class extends Component {
    use WithPagination;

    /** Null while adding, the id of the row being corrected otherwise. */
    public ?int $editingId = null;

    public string $name = '';

    public string $code = '';

    public ?int $headEmployeeId = null;

    public function mount(): void
    {
        $this->authorize('org.manage');
    }

    public function add(): void
    {
        $this->authorize('org.manage');

        // The same modal serves both jobs, so it has to be emptied on the way
        // in. Without this, Add after Edit silently overwrites the row that was
        // last opened.
        $this->resetForm();

        Flux::modal('division-form')->show();
    }

    public function edit(int $id): void
    {
        $this->authorize('org.manage');

        $division = Division::findOrFail($id);

        $this->resetValidation();

        $this->editingId = $division->id;
        $this->name = $division->name;
        $this->code = (string) $division->code;
        $this->headEmployeeId = $division->division_head_employee_id;

        Flux::modal('division-form')->show();
    }

    public function save(): void
    {
        $this->authorize('org.manage');

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'code' => [
                'nullable', 'string', 'max:20',
                Rule::unique('divisions', 'code')->ignore($this->editingId),
            ],
            'headEmployeeId' => ['nullable', Rule::exists('employees', 'id')->whereNull('deleted_at')],
        ]);

        $attributes = [
            'name' => $validated['name'],
            // The column is unique and nullable. An empty string saved twice
            // would collide with itself; null does not.
            'code' => $validated['code'] ?: null,
            'division_head_employee_id' => $validated['headEmployeeId'],
        ];

        if ($this->editingId) {
            Division::findOrFail($this->editingId)->update($attributes);
        } else {
            Division::create($attributes);
        }

        // Validation throws before this line, so a modal that closes is a modal
        // whose contents were written.
        $this->resetForm();

        Flux::modal('division-form')->close();

        Flux::toast(variant: 'success', text: __('Division saved.'));
    }

    /**
     * There is no delete. Sections restrict it at the database and the
     * employees foreign key nulls on delete, so removing a division would
     * either fail or quietly unassign everyone in it.
     */
    public function toggleActive(int $id): void
    {
        $this->authorize('org.manage');

        $division = Division::findOrFail($id);

        $division->update(['is_active' => ! $division->is_active]);
    }

    private function resetForm(): void
    {
        $this->reset(['editingId', 'name', 'code', 'headEmployeeId']);
        $this->resetValidation();
    }

    /** @return array<string, mixed> */
    public function with(): array
    {
        return [
            'divisions' => Division::with('head')
                ->withCount(['sections', 'employees'])
                ->orderBy('name')
                ->paginate(15),
            'employees' => Employee::active()->orderBy('last_name')->orderBy('first_name')->get(),
        ];
    }
}; ?>

<section class="w-full">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <flux:heading size="xl">{{ __('Divisions') }}</flux:heading>
            <flux:subheading>{{ __('The top level of the organizational chart.') }}</flux:subheading>
        </div>

        <flux:button wire:click="add" variant="primary" icon="plus" size="sm">
            {{ __('Add a division') }}
        </flux:button>
    </div>

    <x-organization.nav class="mt-6" />

    <flux:table :paginate="$divisions">
        <flux:table.columns>
            <flux:table.column>{{ __('Name') }}</flux:table.column>
            <flux:table.column>{{ __('Code') }}</flux:table.column>
            <flux:table.column>{{ __('Head') }}</flux:table.column>
            <flux:table.column>{{ __('Sections') }}</flux:table.column>
            <flux:table.column>{{ __('Staff') }}</flux:table.column>
            <flux:table.column>{{ __('Status') }}</flux:table.column>
            <flux:table.column></flux:table.column>
        </flux:table.columns>

        <flux:table.rows>
            @forelse ($divisions as $division)
                <flux:table.row wire:key="division-{{ $division->id }}">
                    <flux:table.cell class="font-medium">{{ $division->name }}</flux:table.cell>
                    <flux:table.cell>{{ $division->code }}</flux:table.cell>
                    <flux:table.cell>{{ $division->head?->fullName() }}</flux:table.cell>
                    <flux:table.cell>{{ $division->sections_count }}</flux:table.cell>
                    <flux:table.cell>{{ $division->employees_count }}</flux:table.cell>
                    <flux:table.cell>
                        <flux:badge size="sm" :color="$division->is_active ? 'green' : 'zinc'">
                            {{ $division->is_active ? __('Active') : __('Closed') }}
                        </flux:badge>
                    </flux:table.cell>
                    <flux:table.cell>
                        <div class="flex gap-3 text-sm">
                            <flux:link href="#" wire:click.prevent="edit({{ $division->id }})">
                                {{ __('Edit') }}
                            </flux:link>
                            <flux:link href="#" wire:click.prevent="toggleActive({{ $division->id }})">
                                {{ $division->is_active ? __('Close') : __('Reopen') }}
                            </flux:link>
                        </div>
                    </flux:table.cell>
                </flux:table.row>
            @empty
                <flux:table.row>
                    <flux:table.cell colspan="7" class="text-center">
                        {{ __('No divisions yet.') }}
                    </flux:table.cell>
                </flux:table.row>
            @endforelse
        </flux:table.rows>
    </flux:table>

    <flux:modal name="division-form" class="w-full md:max-w-lg">
        <form wire:submit="save" class="space-y-6">
            <flux:heading size="lg">
                {{ $editingId ? __('Edit division') : __('Add a division') }}
            </flux:heading>

            <div class="grid gap-6 sm:grid-cols-2">
                <flux:input wire:model="name" :label="__('Name')" placeholder="Medical Division" />
                <flux:input
                    wire:model="code"
                    :label="__('Code')"
                    :description="__('Optional. The CSV import matches on this.')"
                    placeholder="MED"
                />
            </div>

            <flux:select wire:model="headEmployeeId" :label="__('Division head')" :placeholder="__('None')">
                @foreach ($employees as $employee)
                    <flux:select.option wire:key="head-{{ $employee->id }}" value="{{ $employee->id }}">
                        {{ $employee->fullName() }}
                    </flux:select.option>
                @endforeach
            </flux:select>

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
