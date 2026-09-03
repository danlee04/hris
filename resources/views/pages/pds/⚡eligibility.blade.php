<?php

use App\Livewire\Concerns\EditsPdsSection;
use App\Livewire\Concerns\ManagesRepeatingRows;
use App\Models\Pds\Eligibility;
use App\Services\Pds\RowWriter;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Civil service eligibility')] class extends Component {
    use EditsPdsSection;
    use ManagesRepeatingRows;

    /** @var list<string> */
    private const COLUMNS = [
        'eligibility', 'rating', 'examination_date', 'examination_place',
        'license_number', 'license_validity',
    ];

    public function mount(): void
    {
        $employee = $this->bootSection(request()->integer('employee') ?: null);

        $this->loadRows($employee->eligibilities, self::COLUMNS);
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
            'rows.*.eligibility' => ['nullable', 'string', 'max:255'],
            'rows.*.rating' => ['nullable', 'string', 'max:20'],
            'rows.*.examination_date' => ['nullable', 'date', 'before_or_equal:today'],
            'rows.*.examination_place' => ['nullable', 'string', 'max:255'],
            'rows.*.license_number' => ['nullable', 'string', 'max:60'],
            // A licence may validly expire in the past; that is exactly what HR
            // needs to see. No before/after rule here.
            'rows.*.license_validity' => ['nullable', 'date'],
        ]);

        app(RowWriter::class)->sync(
            Eligibility::class,
            $employee->id,
            $this->filledRows('eligibility'),
        );

        $this->loadRows($employee->refresh()->eligibilities, self::COLUMNS);

        Flux::toast(variant: 'success', text: __('Civil service eligibility saved.'));
    }
}; ?>

<section class="w-full">
    <flux:heading size="xl">{{ __('Civil service eligibility') }}</flux:heading>
    <flux:subheading>{{ __('CS Form 212, item 27. List every eligibility and licence you hold.') }}</flux:subheading>

    <x-pds.section-nav :employee="request()->integer('employee') ?: null" class="mt-6" />

    <form wire:submit="save" class="mt-6 space-y-8">
        <x-pds.repeater :add-label="__('Add an eligibility')">
            @foreach ($rows as $index => $row)
                <div wire:key="{{ $row['key'] }}" class="rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
                    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        <flux:input class="lg:col-span-2" wire:model="rows.{{ $index }}.eligibility" :label="__('Eligibility')" />
                        <flux:input wire:model="rows.{{ $index }}.rating" :label="__('Rating')" />

                        <flux:input wire:model="rows.{{ $index }}.examination_date" type="date" :label="__('Date of examination / conferment')" />
                        <flux:input class="lg:col-span-2" wire:model="rows.{{ $index }}.examination_place" :label="__('Place of examination / conferment')" />

                        <flux:input wire:model="rows.{{ $index }}.license_number" :label="__('License number, if applicable')" />
                        <flux:input wire:model="rows.{{ $index }}.license_validity" type="date" :label="__('Date of validity')" />
                    </div>

                    <flux:button
                        class="mt-4"
                        type="button"
                        wire:click="removeRow({{ $index }})"
                        variant="subtle"
                        icon="trash"
                        size="sm"
                    >
                        {{ __('Remove this eligibility') }}
                    </flux:button>
                </div>
            @endforeach
        </x-pds.repeater>

        <flux:button type="submit" variant="primary">{{ __('Save') }}</flux:button>
    </form>
</section>
