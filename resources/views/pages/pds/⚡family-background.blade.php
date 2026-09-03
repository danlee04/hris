<?php

use App\Livewire\Concerns\EditsPdsSection;
use App\Livewire\Concerns\ManagesRepeatingRows;
use App\Models\Pds\Child;
use App\Models\Pds\FamilyBackground;
use App\Services\Pds\RowWriter;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Family background')] class extends Component {
    use EditsPdsSection;
    use ManagesRepeatingRows;

    /** @var array<string, mixed> */
    public array $form = [];

    public function mount(): void
    {
        $employee = $this->bootSection(request()->integer('employee') ?: null);

        $columns = (new FamilyBackground)->getFillable();

        $this->form = array_merge(
            array_fill_keys($columns, null),
            $employee->familyBackground?->only($columns) ?? [],
        );

        $this->loadRows($employee->children, ['name', 'date_of_birth']);
    }

    /** @return array<string, mixed> */
    protected function blankRow(): array
    {
        return ['name' => '', 'date_of_birth' => null];
    }

    public function save(): void
    {
        $employee = $this->authoriseSave();

        $validated = $this->validate([
            'form.spouse_surname' => ['nullable', 'string', 'max:100'],
            'form.spouse_first_name' => ['nullable', 'string', 'max:100'],
            'form.spouse_middle_name' => ['nullable', 'string', 'max:100'],
            'form.spouse_name_extension' => ['nullable', 'string', 'max:20'],
            'form.spouse_occupation' => ['nullable', 'string', 'max:150'],
            'form.spouse_employer' => ['nullable', 'string', 'max:150'],
            'form.spouse_business_address' => ['nullable', 'string', 'max:255'],
            'form.spouse_telephone_no' => ['nullable', 'string', 'max:40'],
            'form.father_surname' => ['nullable', 'string', 'max:100'],
            'form.father_first_name' => ['nullable', 'string', 'max:100'],
            'form.father_middle_name' => ['nullable', 'string', 'max:100'],
            'form.father_name_extension' => ['nullable', 'string', 'max:20'],
            'form.mother_surname' => ['nullable', 'string', 'max:100'],
            'form.mother_first_name' => ['nullable', 'string', 'max:100'],
            'form.mother_middle_name' => ['nullable', 'string', 'max:100'],
            'rows.*.name' => ['nullable', 'string', 'max:200'],
            'rows.*.date_of_birth' => ['nullable', 'date', 'before:today'],
        ]);

        $family = $validated['form'];
        unset($family['employee_id']);

        FamilyBackground::updateOrCreate(['employee_id' => $employee->id], $family);

        app(RowWriter::class)->sync(Child::class, $employee->id, $this->filledRows('name'));

        $this->loadRows($employee->refresh()->children, ['name', 'date_of_birth']);

        Flux::toast(variant: 'success', text: __('Family background saved.'));
    }
}; ?>

<section class="w-full">
    <flux:heading size="xl">{{ __('Family background') }}</flux:heading>
    <flux:subheading>{{ __('CS Form 212, items 17 to 20.') }}</flux:subheading>

    <x-pds.section-nav :employee="request()->integer('employee') ?: null" class="mt-6" />

    <form wire:submit="save" class="mt-6 max-w-3xl space-y-8">
        <div class="space-y-4">
            <flux:heading size="lg">{{ __('Spouse') }}</flux:heading>

            <div class="grid gap-6 sm:grid-cols-2">
                <flux:input wire:model="form.spouse_surname" :label="__('Surname')" />
                <flux:input wire:model="form.spouse_first_name" :label="__('First name')" />
                <flux:input wire:model="form.spouse_middle_name" :label="__('Middle name')" />
                <flux:input wire:model="form.spouse_name_extension" :label="__('Name extension (Jr., Sr.)')" />
                <flux:input wire:model="form.spouse_occupation" :label="__('Occupation')" />
                <flux:input wire:model="form.spouse_employer" :label="__('Employer / business name')" />
                <flux:input wire:model="form.spouse_business_address" :label="__('Business address')" />
                <flux:input wire:model="form.spouse_telephone_no" :label="__('Telephone no.')" />
            </div>
        </div>

        <flux:separator />

        <x-pds.repeater :heading="__('Children')" :add-label="__('Add a child')">
            @foreach ($rows as $index => $row)
                {{-- wire:key is bound to the row's own key, never to $index. --}}
                <div wire:key="{{ $row['key'] }}" class="flex items-end gap-3">
                    <flux:input class="flex-1" wire:model="rows.{{ $index }}.name" :label="__('Name')" />
                    <flux:input
                        wire:model="rows.{{ $index }}.date_of_birth"
                        type="date"
                        :label="__('Date of birth')"
                    />
                    <flux:button
                        type="button"
                        wire:click="removeRow({{ $index }})"
                        variant="subtle"
                        icon="trash"
                        :aria-label="__('Remove this child')"
                    />
                </div>
            @endforeach
        </x-pds.repeater>

        <flux:separator />

        <div class="space-y-4">
            <flux:heading size="lg">{{ __("Father's name") }}</flux:heading>

            <div class="grid gap-6 sm:grid-cols-2">
                <flux:input wire:model="form.father_surname" :label="__('Surname')" />
                <flux:input wire:model="form.father_first_name" :label="__('First name')" />
                <flux:input wire:model="form.father_middle_name" :label="__('Middle name')" />
                <flux:input wire:model="form.father_name_extension" :label="__('Name extension')" />
            </div>
        </div>

        <div class="space-y-4">
            <flux:heading size="lg">{{ __("Mother's maiden name") }}</flux:heading>

            <div class="grid gap-6 sm:grid-cols-2">
                <flux:input wire:model="form.mother_surname" :label="__('Surname')" />
                <flux:input wire:model="form.mother_first_name" :label="__('First name')" />
                <flux:input wire:model="form.mother_middle_name" :label="__('Middle name')" />
            </div>
        </div>

        <flux:button type="submit" variant="primary">{{ __('Save') }}</flux:button>
    </form>
</section>
