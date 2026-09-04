<?php

use App\Models\Division;
use App\Models\Employee;
use App\Models\Section;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Sections')] class extends Component {
    use WithPagination;

    /** Null while adding, the id of the row being corrected otherwise. */
    public ?int $editingId = null;

    public ?int $divisionId = null;

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

        Flux::modal('section-form')->show();
    }

    public function edit(int $id): void
    {
        $this->authorize('org.manage');

        $section = Section::findOrFail($id);

        $this->resetValidation();

        $this->editingId = $section->id;
        $this->divisionId = $section->division_id;
        $this->name = $section->name;
        $this->code = (string) $section->code;
        $this->headEmployeeId = $section->section_head_employee_id;

        Flux::modal('section-form')->show();
    }

    public function save(): void
    {
        $this->authorize('org.manage');

        $validated = $this->validate([
            // Required, unlike everywhere else: the database column is not
            // nullable, and a section outside a division has nowhere to sit on
            // the chart.
            'divisionId' => ['required', Rule::exists('divisions', 'id')],
            'name' => ['required', 'string', 'max:255'],
            'code' => [
                'nullable', 'string', 'max:20',
                Rule::unique('sections', 'code')->ignore($this->editingId),
            ],
            'headEmployeeId' => ['nullable', Rule::exists('employees', 'id')->whereNull('deleted_at')],
        ]);

        $attributes = [
            'division_id' => $validated['divisionId'],
            'name' => $validated['name'],
            'code' => $validated['code'] ?: null,
            'section_head_employee_id' => $validated['headEmployeeId'],
        ];

        if ($this->editingId) {
            Section::findOrFail($this->editingId)->update($attributes);
        } else {
            Section::create($attributes);
        }

        // Validation throws before this line, so a modal that closes is a modal
        // whose contents were written.
        $this->resetForm();

        Flux::modal('section-form')->close();

        Flux::toast(variant: 'success', text: __('Section saved.'));
    }

    /**
     * There is no delete, for the same reason as the other two: the employees
     * foreign key nulls on delete, so removing a section would silently empty
     * the column for everyone in it.
     */
    public function toggleActive(int $id): void
    {
        $this->authorize('org.manage');

        $section = Section::findOrFail($id);

        $section->update(['is_active' => ! $section->is_active]);
    }

    private function resetForm(): void
    {
        $this->reset(['editingId', 'divisionId', 'name', 'code', 'headEmployeeId']);
        $this->resetValidation();
    }

    /** @return array<string, mixed> */
    public function with(): array
    {
        return [
            'sections' => Section::with(['division', 'head'])
                ->withCount('employees')
                ->orderBy('name')
                ->paginate(15),
            'divisions' => Division::orderBy('name')->get(),
            'employees' => Employee::active()->orderBy('last_name')->orderBy('first_name')->get(),
        ];
    }
}; ?>

<section class="w-full">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <flux:heading size="xl">{{ __('Sections') }}</flux:heading>
            <flux:subheading>{{ __('Every section sits inside exactly one division.') }}</flux:subheading>
        </div>

        {{-- A section has nowhere to sit without a division, so the button that
             would open an unfillable form is not offered at all. --}}
        @if ($divisions->isNotEmpty())
            <flux:button wire:click="add" variant="primary" icon="plus" size="sm">
                {{ __('Add a section') }}
            </flux:button>
        @endif
    </div>

    <x-organization.nav class="mt-6" />

    @if ($divisions->isEmpty())
        <flux:callout class="mb-6" icon="information-circle">
            {{ __('Add a division first. A section has nowhere to sit without one.') }}
        </flux:callout>
    @endif

    <flux:table :paginate="$sections">
        <flux:table.columns>
            <flux:table.column>{{ __('Name') }}</flux:table.column>
            <flux:table.column>{{ __('Division') }}</flux:table.column>
            <flux:table.column>{{ __('Code') }}</flux:table.column>
            <flux:table.column>{{ __('Head') }}</flux:table.column>
            <flux:table.column>{{ __('Staff') }}</flux:table.column>
            <flux:table.column>{{ __('Status') }}</flux:table.column>
            <flux:table.column></flux:table.column>
        </flux:table.columns>

        <flux:table.rows>
            @forelse ($sections as $section)
                <flux:table.row wire:key="section-{{ $section->id }}">
                    <flux:table.cell class="font-medium">{{ $section->name }}</flux:table.cell>
                    <flux:table.cell>{{ $section->division?->name }}</flux:table.cell>
                    <flux:table.cell>{{ $section->code }}</flux:table.cell>
                    <flux:table.cell>{{ $section->head?->fullName() }}</flux:table.cell>
                    <flux:table.cell>{{ $section->employees_count }}</flux:table.cell>
                    <flux:table.cell>
                        <flux:badge size="sm" :color="$section->is_active ? 'green' : 'zinc'">
                            {{ $section->is_active ? __('Active') : __('Closed') }}
                        </flux:badge>
                    </flux:table.cell>
                    <flux:table.cell>
                        <div class="flex gap-3 text-sm">
                            <flux:link href="#" wire:click.prevent="edit({{ $section->id }})">
                                {{ __('Edit') }}
                            </flux:link>
                            <flux:link href="#" wire:click.prevent="toggleActive({{ $section->id }})">
                                {{ $section->is_active ? __('Close') : __('Reopen') }}
                            </flux:link>
                        </div>
                    </flux:table.cell>
                </flux:table.row>
            @empty
                <flux:table.row>
                    <flux:table.cell colspan="7" class="text-center">
                        {{ __('No sections yet.') }}
                    </flux:table.cell>
                </flux:table.row>
            @endforelse
        </flux:table.rows>
    </flux:table>

    <flux:modal name="section-form" class="w-full md:max-w-lg">
        <form wire:submit="save" class="space-y-6">
            <flux:heading size="lg">
                {{ $editingId ? __('Edit section') : __('Add a section') }}
            </flux:heading>

            <div class="grid gap-6 sm:grid-cols-2">
                <flux:select wire:model="divisionId" :label="__('Division')" :placeholder="__('Choose a division')">
                    @foreach ($divisions as $division)
                        <flux:select.option wire:key="division-{{ $division->id }}" value="{{ $division->id }}">
                            {{ $division->name }}
                        </flux:select.option>
                    @endforeach
                </flux:select>

                <flux:input wire:model="name" :label="__('Name')" placeholder="Statistics Unit" />

                <flux:input
                    wire:model="code"
                    :label="__('Code')"
                    :description="__('Optional. The CSV import matches on this.')"
                    placeholder="STAT"
                />
            </div>

            <flux:select wire:model="headEmployeeId" :label="__('Section head')" :placeholder="__('None')">
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
