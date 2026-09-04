<?php

use App\Models\Position;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Positions')] class extends Component {
    use WithPagination;

    /** Null while adding, the id of the row being corrected otherwise. */
    public ?int $editingId = null;

    public string $title = '';

    public string $itemNumber = '';

    public ?int $salaryGrade = null;

    public string $description = '';

    public function mount(): void
    {
        // Nobody owns the plantilla, so there is no ownership question and no
        // policy. The permission is the whole answer.
        $this->authorize('org.manage');
    }

    public function add(): void
    {
        $this->authorize('org.manage');

        // The same modal serves both jobs, so it has to be emptied on the way
        // in. Without this, Add after Edit silently overwrites the row that was
        // last opened.
        $this->resetForm();

        Flux::modal('position-form')->show();
    }

    public function edit(int $id): void
    {
        $this->authorize('org.manage');

        $position = Position::findOrFail($id);

        $this->resetValidation();

        $this->editingId = $position->id;
        $this->title = $position->title;
        $this->itemNumber = (string) $position->item_number;
        $this->salaryGrade = $position->salary_grade;
        $this->description = (string) $position->description;

        Flux::modal('position-form')->show();
    }

    public function save(): void
    {
        // Every request after mount() carries whatever the browser sends,
        // including editingId. The save asks again.
        $this->authorize('org.manage');

        $validated = $this->validate([
            'title' => ['required', 'string', 'max:255'],
            'itemNumber' => [
                'nullable', 'string', 'max:50',
                Rule::unique('positions', 'item_number')->ignore($this->editingId),
            ],
            // SG 1 to 33 is the whole Salary Standardization Law range. A typed
            // 330 would otherwise sit in the plantilla unnoticed.
            'salaryGrade' => ['nullable', 'integer', 'min:1', 'max:33'],
            'description' => ['nullable', 'string', 'max:2000'],
        ]);

        $attributes = [
            'title' => $validated['title'],
            'item_number' => $validated['itemNumber'] ?: null,
            'salary_grade' => $validated['salaryGrade'],
            'description' => $validated['description'] ?: null,
        ];

        if ($this->editingId) {
            Position::findOrFail($this->editingId)->update($attributes);
        } else {
            Position::create($attributes);
        }

        // Validation throws before this line, so a modal that closes is a modal
        // whose contents were written.
        $this->resetForm();

        Flux::modal('position-form')->close();

        Flux::toast(variant: 'success', text: __('Position saved.'));
    }

    /**
     * There is no delete. The employees foreign key nulls on delete, so
     * removing a position would quietly blank it on everyone holding it.
     * Retiring one keeps the record and takes it out of the dropdowns.
     */
    public function toggleActive(int $id): void
    {
        $this->authorize('org.manage');

        $position = Position::findOrFail($id);

        $position->update(['is_active' => ! $position->is_active]);
    }

    private function resetForm(): void
    {
        $this->reset(['editingId', 'title', 'itemNumber', 'salaryGrade', 'description']);
        $this->resetValidation();
    }

    /** @return array<string, mixed> */
    public function with(): array
    {
        return [
            'positions' => Position::withCount('employees')->orderBy('title')->paginate(15),
        ];
    }
}; ?>

<section class="w-full">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <flux:heading size="xl">{{ __('Positions') }}</flux:heading>
            <flux:subheading>{{ __('The plantilla. One position per employee.') }}</flux:subheading>
        </div>

        <flux:button wire:click="add" variant="primary" icon="plus" size="sm">
            {{ __('Add a position') }}
        </flux:button>
    </div>

    <x-organization.nav class="mt-6" />

    <flux:table :paginate="$positions">
        <flux:table.columns>
            <flux:table.column>{{ __('Title') }}</flux:table.column>
            <flux:table.column>{{ __('Item no.') }}</flux:table.column>
            <flux:table.column>{{ __('SG') }}</flux:table.column>
            <flux:table.column>{{ __('Staff') }}</flux:table.column>
            <flux:table.column>{{ __('Status') }}</flux:table.column>
            <flux:table.column></flux:table.column>
        </flux:table.columns>

        <flux:table.rows>
            @forelse ($positions as $position)
                <flux:table.row wire:key="position-{{ $position->id }}">
                    <flux:table.cell class="font-medium">{{ $position->title }}</flux:table.cell>
                    <flux:table.cell>{{ $position->item_number }}</flux:table.cell>
                    <flux:table.cell>{{ $position->salary_grade }}</flux:table.cell>
                    {{-- How many people hold it, which is what decides whether
                         it can be retired without anyone noticing. --}}
                    <flux:table.cell>{{ $position->employees_count }}</flux:table.cell>
                    <flux:table.cell>
                        <flux:badge size="sm" :color="$position->is_active ? 'green' : 'zinc'">
                            {{ $position->is_active ? __('Active') : __('Retired') }}
                        </flux:badge>
                    </flux:table.cell>
                    <flux:table.cell>
                        <div class="flex gap-3 text-sm">
                            <flux:link href="#" wire:click.prevent="edit({{ $position->id }})">
                                {{ __('Edit') }}
                            </flux:link>
                            <flux:link href="#" wire:click.prevent="toggleActive({{ $position->id }})">
                                {{ $position->is_active ? __('Retire') : __('Restore') }}
                            </flux:link>
                        </div>
                    </flux:table.cell>
                </flux:table.row>
            @empty
                <flux:table.row>
                    <flux:table.cell colspan="6" class="text-center">
                        {{ __('No positions yet.') }}
                    </flux:table.cell>
                </flux:table.row>
            @endforelse
        </flux:table.rows>
    </flux:table>

    <flux:modal name="position-form" class="w-full md:max-w-lg">
        <form wire:submit="save" class="space-y-6">
            <flux:heading size="lg">
                {{ $editingId ? __('Edit position') : __('Add a position') }}
            </flux:heading>

            <div class="grid gap-6 sm:grid-cols-2">
                <flux:input wire:model="title" :label="__('Title')" placeholder="Nurse II" />
                <flux:input
                    wire:model="itemNumber"
                    :label="__('Plantilla item number')"
                    :description="__('Optional. Unique across the plantilla.')"
                />
                <flux:input
                    wire:model="salaryGrade"
                    type="number"
                    min="1"
                    max="33"
                    :label="__('Salary grade')"
                />
            </div>

            <flux:textarea wire:model="description" :label="__('Description')" rows="3" />

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
