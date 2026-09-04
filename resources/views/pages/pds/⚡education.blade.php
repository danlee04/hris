<?php

use App\Enums\EducationLevel;
use App\Livewire\Concerns\EditsPdsSection;
use App\Livewire\Concerns\ManagesRepeatingRows;
use App\Models\Pds\Education;
use App\Services\Pds\RowWriter;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Educational background')] class extends Component {
    use EditsPdsSection;
    use ManagesRepeatingRows;

    /** @var list<string> */
    private const COLUMNS = [
        'level', 'school_name', 'degree_course', 'period_from', 'period_to',
        'highest_level_units', 'year_graduated', 'honors',
    ];

    public function mount(): void
    {
        $employee = $this->bootSection(request()->integer('employee') ?: null);

        $this->loadRows($employee->educations, self::COLUMNS);
    }

    /** @return array<string, mixed> */
    protected function blankRow(): array
    {
        return array_fill_keys(self::COLUMNS, null);
    }

    public function save(): void
    {
        $employee = $this->authoriseSave();

        // A plausible range, not a guess: nobody in the plantilla was schooled
        // before 1900, and a year a decade out is a typo rather than a plan.
        $earliest = 1900;
        $latest = (int) now()->addYears(10)->format('Y');

        $this->validate([
            'rows.*.level' => ['nullable', Rule::enum(EducationLevel::class)],
            'rows.*.school_name' => ['nullable', 'string', 'max:255'],
            'rows.*.degree_course' => ['nullable', 'string', 'max:255'],
            'rows.*.period_from' => ['nullable', 'integer', "between:{$earliest},{$latest}"],
            'rows.*.period_to' => ['nullable', 'integer', "between:{$earliest},{$latest}"],
            'rows.*.highest_level_units' => ['nullable', 'string', 'max:120'],
            'rows.*.year_graduated' => ['nullable', 'integer', "between:{$earliest},{$latest}"],
            'rows.*.honors' => ['nullable', 'string', 'max:255'],
        ]);

        app(RowWriter::class)->sync(
            Education::class,
            $employee->id,
            $this->filledRows('school_name'),
        );

        $this->loadRows($employee->refresh()->educations, self::COLUMNS);

        Flux::toast(variant: 'success', text: __('Educational background saved.'));
    }
}; ?>

<section class="w-full">
    <x-pds.page-header :title="__('Educational background')" :employee="request()->integer('employee') ?: null">
        {{ __('CS Form 212 (Revised 2026), item 26. Add as many entries per level as you hold.') }}
    </x-pds.page-header>

    <form wire:submit="save" class="mt-6 space-y-8">
        <x-pds.repeater :add-label="__('Add an entry')">
            @foreach ($rows as $index => $row)
                <div wire:key="{{ $row['key'] }}" class="rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
                    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                        <flux:select wire:model="rows.{{ $index }}.level" :label="__('Level')" :placeholder="__('Choose')">
                            @foreach (App\Enums\EducationLevel::cases() as $case)
                                <flux:select.option value="{{ $case->value }}">{{ $case->label() }}</flux:select.option>
                            @endforeach
                        </flux:select>

                        <flux:input class="lg:col-span-3" wire:model="rows.{{ $index }}.school_name" :label="__('Name of school')" />

                        <flux:input class="lg:col-span-2" wire:model="rows.{{ $index }}.degree_course" :label="__('Basic education / degree / course')" />
                        <flux:input wire:model="rows.{{ $index }}.period_from" type="number" :label="__('From (year)')" />
                        <flux:input wire:model="rows.{{ $index }}.period_to" type="number" :label="__('To (year)')" />

                        <flux:input class="lg:col-span-2" wire:model="rows.{{ $index }}.highest_level_units" :label="__('Highest level / units earned, if not graduated')" />
                        <flux:input wire:model="rows.{{ $index }}.year_graduated" type="number" :label="__('Year graduated')" />
                        <flux:input wire:model="rows.{{ $index }}.honors" :label="__('Scholarship / academic honors')" />
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
