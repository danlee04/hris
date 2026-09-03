<?php

use App\Livewire\Concerns\EditsPdsSection;
use App\Livewire\Concerns\ManagesRepeatingRows;
use App\Models\Pds\VoluntaryWork;
use App\Services\Pds\RowWriter;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Voluntary work')] class extends Component {
    use EditsPdsSection;
    use ManagesRepeatingRows;

    /** @var list<string> */
    private const COLUMNS = [
        'organization_name_address', 'date_from', 'date_to',
        'number_of_hours', 'position_nature_of_work',
    ];

    public function mount(): void
    {
        $employee = $this->bootSection(request()->integer('employee') ?: null);

        $this->loadRows($employee->voluntaryWorks, self::COLUMNS);
    }

    /** @return array<string, mixed> */
    protected function blankRow(): array
    {
        return array_fill_keys(self::COLUMNS, null);
    }

    public function save(): void
    {
        $employee = $this->authoriseSave();

        $this->validate([
            'rows.*.organization_name_address' => ['nullable', 'string', 'max:500'],
            'rows.*.date_from' => ['nullable', 'date', 'before_or_equal:today'],
            'rows.*.date_to' => ['nullable', 'date', 'after_or_equal:rows.*.date_from'],
            'rows.*.number_of_hours' => ['nullable', 'integer', 'min:0', 'max:65535'],
            'rows.*.position_nature_of_work' => ['nullable', 'string', 'max:255'],
        ]);

        app(RowWriter::class)->sync(
            VoluntaryWork::class,
            $employee->id,
            $this->filledRows('organization_name_address'),
        );

        $this->loadRows($employee->refresh()->voluntaryWorks, self::COLUMNS);

        Flux::toast(variant: 'success', text: __('Voluntary work saved.'));
    }
}; ?>

<section class="w-full">
    <flux:heading size="xl">{{ __('Voluntary work') }}</flux:heading>
    <flux:subheading>
        {{ __('CS Form 212, item 29. Involvement in civic or non-government organizations.') }}
    </flux:subheading>

    <x-pds.section-nav :employee="request()->integer('employee') ?: null" class="mt-6" />

    <form wire:submit="save" class="mt-6 space-y-8">
        <x-pds.repeater :add-label="__('Add voluntary work')">
            @foreach ($rows as $index => $row)
                <div wire:key="{{ $row['key'] }}" class="rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
                    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                        <flux:input
                            class="lg:col-span-4"
                            wire:model="rows.{{ $index }}.organization_name_address"
                            :label="__('Name and address of organization')"
                        />

                        <flux:input wire:model="rows.{{ $index }}.date_from" type="date" :label="__('From')" />
                        <flux:input wire:model="rows.{{ $index }}.date_to" type="date" :label="__('To')" />
                        <flux:input wire:model="rows.{{ $index }}.number_of_hours" type="number" :label="__('Number of hours')" />
                        <flux:input wire:model="rows.{{ $index }}.position_nature_of_work" :label="__('Position / nature of work')" />
                    </div>

                    <flux:button
                        class="mt-4"
                        type="button"
                        wire:click="removeRow({{ $index }})"
                        variant="subtle"
                        icon="trash"
                        size="sm"
                    >
                        {{ __('Remove this entry') }}
                    </flux:button>
                </div>
            @endforeach
        </x-pds.repeater>

        <flux:button type="submit" variant="primary">{{ __('Save') }}</flux:button>
    </form>
</section>
