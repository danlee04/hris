<?php

use App\Enums\OtherEntryKind;
use App\Livewire\Concerns\EditsPdsSection;
use App\Models\Pds\OtherEntry;
use App\Services\Pds\RowWriter;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Three lists on one page, so ManagesRepeatingRows does not fit — that trait
 * holds a single $rows. The lists are keyed by the enum value instead, and
 * each is synchronised on its own through RowWriter's scope, so saving one
 * cannot disturb the other two.
 */
new #[Title('Other information')] class extends Component {
    use EditsPdsSection;

    /** @var array<string, list<array{id: ?int, key: string, value: ?string}>> */
    public array $lists = [];

    public int $nextKey = 0;

    public function mount(): void
    {
        $employee = $this->bootSection(request()->integer('employee') ?: null);

        $entries = $employee->otherEntries;

        foreach (OtherEntryKind::cases() as $kind) {
            $this->lists[$kind->value] = $entries
                ->where('kind', $kind)
                ->map(fn (OtherEntry $entry) => [
                    'id' => $entry->id,
                    'key' => 'row-'.$this->nextKey++,
                    'value' => $entry->value,
                ])
                ->values()
                ->all();

            if ($this->lists[$kind->value] === []) {
                $this->addRow($kind->value);
            }
        }
    }

    public function addRow(string $kind): void
    {
        $this->lists[$kind][] = [
            'id' => null,
            'key' => 'row-'.$this->nextKey++,
            'value' => '',
        ];
    }

    public function removeRow(string $kind, int $index): void
    {
        unset($this->lists[$kind][$index]);

        $this->lists[$kind] = array_values($this->lists[$kind]);
    }

    public function save(): void
    {
        $employee = $this->authoriseSave();

        $this->validate([
            'lists.*.*.value' => ['nullable', 'string', 'max:500'],
        ]);

        foreach (OtherEntryKind::cases() as $kind) {
            $rows = array_values(array_filter(
                array_map(
                    fn (array $row) => ['id' => $row['id'], 'value' => trim((string) $row['value'])],
                    $this->lists[$kind->value] ?? []
                ),
                fn (array $row) => $row['value'] !== ''
            ));

            app(RowWriter::class)->sync(
                OtherEntry::class,
                $employee->id,
                $rows,
                ['kind' => $kind->value],
            );
        }

        $this->mount();

        Flux::toast(variant: 'success', text: __('Other information saved.'));
    }
}; ?>

<section class="w-full">
    <flux:heading size="xl">{{ __('Other information') }}</flux:heading>
    <flux:subheading>{{ __('CS Form 212 (Revised 2026), items 31 to 33.') }}</flux:subheading>

    <x-pds.section-nav :employee="request()->integer('employee') ?: null" class="mt-6" />

    <form wire:submit="save" class="mt-6 max-w-3xl space-y-10">
        @foreach (App\Enums\OtherEntryKind::cases() as $kind)
            <div class="space-y-4">
                <flux:heading size="lg">
                    {{ $kind->itemNumber() }}. {{ $kind->label() }}
                </flux:heading>

                <div class="space-y-3">
                    @foreach ($lists[$kind->value] ?? [] as $index => $row)
                        <div wire:key="{{ $row['key'] }}" class="flex items-center gap-3">
                            <flux:input
                                class="flex-1"
                                wire:model="lists.{{ $kind->value }}.{{ $index }}.value"
                            />
                            <flux:button
                                type="button"
                                wire:click="removeRow('{{ $kind->value }}', {{ $index }})"
                                variant="subtle"
                                icon="trash"
                                :aria-label="__('Remove this entry')"
                            />
                        </div>
                    @endforeach
                </div>

                <flux:button
                    type="button"
                    wire:click="addRow('{{ $kind->value }}')"
                    variant="subtle"
                    icon="plus"
                    size="sm"
                >
                    {{ __('Add an entry') }}
                </flux:button>
            </div>
        @endforeach

        <flux:button type="submit" variant="primary">{{ __('Save') }}</flux:button>
    </form>
</section>
