<?php

use App\Livewire\Concerns\EditsPdsSection;
use App\Livewire\Concerns\ManagesRepeatingRows;
use App\Models\Pds\WorkExperience;
use App\Services\Pds\RowWriter;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Work experience')] class extends Component {
    use EditsPdsSection;
    use ManagesRepeatingRows;

    /** @var list<string> */
    private const COLUMNS = [
        'date_from', 'date_to', 'position_title', 'department_agency',
        'monthly_salary', 'salary_grade_step', 'status_of_appointment',
        'is_government_service',
    ];

    public function mount(): void
    {
        $employee = $this->bootSection(request()->integer('employee') ?: null);

        $this->loadRows($employee->workExperiences, self::COLUMNS);
    }

    /** @return array<string, mixed> */
    protected function blankRow(): array
    {
        return array_merge(
            array_fill_keys(self::COLUMNS, null),
            ['is_government_service' => false],
        );
    }

    public function save(): void
    {
        $employee = $this->authoriseSave();

        $this->validate([
            'rows.*.date_from' => ['nullable', 'date', 'before_or_equal:today'],
            // Left blank means the person still holds the post — the form
            // prints "PRESENT" there. A date column cannot hold that word, and
            // storing it as text is how the legacy tables became unqueryable.
            'rows.*.date_to' => ['nullable', 'date', 'after_or_equal:rows.*.date_from'],
            'rows.*.position_title' => ['nullable', 'string', 'max:255'],
            'rows.*.department_agency' => ['nullable', 'string', 'max:255'],
            'rows.*.monthly_salary' => ['nullable', 'numeric', 'min:0', 'max:9999999999'],
            'rows.*.salary_grade_step' => ['nullable', 'string', 'max:20'],
            'rows.*.status_of_appointment' => ['nullable', 'string', 'max:60'],
            'rows.*.is_government_service' => ['boolean'],
        ]);

        app(RowWriter::class)->sync(
            WorkExperience::class,
            $employee->id,
            $this->filledRows('position_title'),
        );

        $this->loadRows($employee->refresh()->workExperiences, self::COLUMNS);

        Flux::toast(variant: 'success', text: __('Work experience saved.'));
    }
}; ?>

<section class="w-full">
    <flux:heading size="xl">{{ __('Work experience') }}</flux:heading>
    <flux:subheading>
        {{ __('CS Form 212, item 28. Leave "To" blank for a post you still hold.') }}
    </flux:subheading>

    <x-pds.section-nav :employee="request()->integer('employee') ?: null" class="mt-6" />

    <form wire:submit="save" class="mt-6 space-y-8">
        <x-pds.repeater :add-label="__('Add a position')">
            @foreach ($rows as $index => $row)
                <div wire:key="{{ $row['key'] }}" class="rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
                    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                        <flux:input wire:model="rows.{{ $index }}.date_from" type="date" :label="__('From')" />
                        <flux:input
                            wire:model="rows.{{ $index }}.date_to"
                            type="date"
                            :label="__('To')"
                            :description="__('Blank means present')"
                        />
                        <flux:input class="lg:col-span-2" wire:model="rows.{{ $index }}.position_title" :label="__('Position title')" />

                        <flux:input class="lg:col-span-2" wire:model="rows.{{ $index }}.department_agency" :label="__('Department / agency / office / company')" />
                        <flux:input wire:model="rows.{{ $index }}.monthly_salary" type="number" step="0.01" :label="__('Monthly salary')" />
                        <flux:input wire:model="rows.{{ $index }}.salary_grade_step" :label="__('Salary grade & step')" />

                        <flux:input class="lg:col-span-2" wire:model="rows.{{ $index }}.status_of_appointment" :label="__('Status of appointment')" />

                        <div class="flex items-end lg:col-span-2">
                            <flux:checkbox
                                wire:model="rows.{{ $index }}.is_government_service"
                                :label="__('Government service')"
                            />
                        </div>
                    </div>

                    <flux:button
                        class="mt-4"
                        type="button"
                        wire:click="removeRow({{ $index }})"
                        variant="subtle"
                        icon="trash"
                        size="sm"
                    >
                        {{ __('Remove this position') }}
                    </flux:button>
                </div>
            @endforeach
        </x-pds.repeater>

        <flux:button type="submit" variant="primary">{{ __('Save') }}</flux:button>
    </form>
</section>
