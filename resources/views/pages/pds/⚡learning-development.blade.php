<?php

use App\Enums\LearningDevelopmentType;
use App\Livewire\Concerns\EditsPdsSection;
use App\Livewire\Concerns\ManagesRepeatingRows;
use App\Models\Pds\LearningDevelopment;
use App\Services\Pds\RowWriter;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Learning and development')] class extends Component {
    use EditsPdsSection;
    use ManagesRepeatingRows;

    /** @var list<string> */
    private const COLUMNS = [
        'title', 'date_from', 'date_to', 'number_of_hours', 'type', 'conducted_by',
    ];

    public function mount(): void
    {
        $employee = $this->bootSection(request()->integer('employee') ?: null);

        $this->loadRows($employee->learningDevelopments, self::COLUMNS);
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
            'rows.*.title' => ['nullable', 'string', 'max:500'],
            'rows.*.date_from' => ['nullable', 'date', 'before_or_equal:today'],
            'rows.*.date_to' => ['nullable', 'date', 'after_or_equal:rows.*.date_from'],
            'rows.*.number_of_hours' => ['nullable', 'integer', 'min:0', 'max:65535'],
            'rows.*.type' => ['nullable', Rule::enum(LearningDevelopmentType::class)],
            'rows.*.conducted_by' => ['nullable', 'string', 'max:255'],
        ]);

        app(RowWriter::class)->sync(
            LearningDevelopment::class,
            $employee->id,
            $this->filledRows('title'),
        );

        $this->loadRows($employee->refresh()->learningDevelopments, self::COLUMNS);

        Flux::toast(variant: 'success', text: __('Learning and development saved.'));
    }
}; ?>

<section class="w-full">
    <flux:heading size="xl">{{ __('Learning and development') }}</flux:heading>
    <flux:subheading>
        {{ __('CS Form 212, item 30. Training programmes, seminars and workshops attended.') }}
    </flux:subheading>

    <x-pds.section-nav :employee="request()->integer('employee') ?: null" class="mt-6" />

    <form wire:submit="save" class="mt-6 space-y-8">
        <x-pds.repeater :add-label="__('Add an intervention')">
            @foreach ($rows as $index => $row)
                <div wire:key="{{ $row['key'] }}" class="rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
                    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                        <flux:input
                            class="lg:col-span-4"
                            wire:model="rows.{{ $index }}.title"
                            :label="__('Title of learning and development intervention')"
                        />

                        <flux:input wire:model="rows.{{ $index }}.date_from" type="date" :label="__('From')" />
                        <flux:input wire:model="rows.{{ $index }}.date_to" type="date" :label="__('To')" />
                        <flux:input wire:model="rows.{{ $index }}.number_of_hours" type="number" :label="__('Number of hours')" />

                        <flux:select wire:model="rows.{{ $index }}.type" :label="__('Type of LD')" :placeholder="__('Choose')">
                            @foreach (App\Enums\LearningDevelopmentType::cases() as $case)
                                <flux:select.option value="{{ $case->value }}">{{ $case->label() }}</flux:select.option>
                            @endforeach
                        </flux:select>

                        <flux:input
                            class="lg:col-span-4"
                            wire:model="rows.{{ $index }}.conducted_by"
                            :label="__('Conducted or sponsored by')"
                        />
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
