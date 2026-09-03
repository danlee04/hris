<?php

use App\Livewire\Concerns\EditsPdsSection;
use App\Models\Pds\Declaration;
use App\Models\Pds\Reference;
use App\Services\Pds\RowWriter;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Declarations')] class extends Component {
    use EditsPdsSection;

    /** @var array<string, mixed> */
    public array $form = [];

    /** @var list<array{id: ?int, key: string, name: ?string, address: ?string, telephone_no: ?string}> */
    public array $references = [];

    public int $nextKey = 0;

    public function mount(): void
    {
        $employee = $this->bootSection(request()->integer('employee') ?: null);

        $columns = (new Declaration)->getFillable();
        $record = $employee->declaration;

        $this->form = array_merge(
            array_fill_keys($columns, null),
            $record?->only($columns) ?? [],
        );

        $this->form['q35_date_filed'] = $record?->q35_date_filed?->format('Y-m-d');
        $this->form['date_accomplished'] = $record?->date_accomplished?->format('Y-m-d');

        $this->references = $employee->references
            ->map(fn (Reference $reference) => [
                'id' => $reference->id,
                'key' => 'ref-'.$this->nextKey++,
                'name' => $reference->name,
                'address' => $reference->address,
                'telephone_no' => $reference->telephone_no,
            ])
            ->values()
            ->all();

        // The form prints three rows.
        while (count($this->references) < 3) {
            $this->addReference();
        }
    }

    public function addReference(): void
    {
        $this->references[] = [
            'id' => null,
            'key' => 'ref-'.$this->nextKey++,
            'name' => '',
            'address' => '',
            'telephone_no' => '',
        ];
    }

    public function removeReference(int $index): void
    {
        unset($this->references[$index]);

        $this->references = array_values($this->references);
    }

    public function save(): void
    {
        $employee = $this->authoriseSave();

        $rules = [
            'form.q35_date_filed' => ['nullable', 'date'],
            'form.q35_case_status' => ['nullable', 'string', 'max:255'],
            'form.q40_indigenous_details' => ['nullable', 'string', 'max:255'],
            'form.q40_pwd_id_no' => ['nullable', 'string', 'max:60'],
            'form.q40_solo_parent_id_no' => ['nullable', 'string', 'max:60'],
            'form.government_id_type' => ['nullable', 'string', 'max:120'],
            'form.government_id_number' => ['nullable', 'string', 'max:60'],
            'form.government_id_issued' => ['nullable', 'string', 'max:255'],
            'form.date_accomplished' => ['nullable', 'date'],
            'references.*.name' => ['nullable', 'string', 'max:255'],
            'references.*.address' => ['nullable', 'string', 'max:500'],
            'references.*.telephone_no' => ['nullable', 'string', 'max:60'],
        ];

        // An unexplained yes cannot be saved. This is a document signed under
        // penalty of perjury; "yes" with nothing after it is not an answer.
        foreach (Declaration::DETAILS_REQUIRED_BY as $question => $details) {
            $rules["form.{$question}"] = ['nullable', 'boolean'];
            $rules["form.{$details}"] = array_unique(array_merge(
                $rules["form.{$details}"] ?? ['nullable', 'string', 'max:500'],
                ["required_if:form.{$question},true"],
            ));
        }

        foreach (['q40_indigenous_group', 'q40_person_with_disability', 'q40_solo_parent'] as $question) {
            $rules["form.{$question}"] = ['nullable', 'boolean'];
        }

        $validated = $this->validate($rules, [], [
            'form.q34_related_details' => __('the details for item 34'),
            'form.q35_administrative_details' => __('the details for item 35a'),
            'form.q35_criminal_details' => __('the details for item 35b'),
            'form.q36_details' => __('the details for item 36'),
            'form.q37_details' => __('the details for item 37'),
            'form.q38_candidate_details' => __('the details for item 38a'),
            'form.q38_resigned_details' => __('the details for item 38b'),
            'form.q39_details' => __('the details for item 39'),
        ]);

        $declaration = $validated['form'];
        unset($declaration['employee_id']);

        Declaration::updateOrCreate(['employee_id' => $employee->id], $declaration);

        $references = array_values(array_filter(
            array_map(fn (array $row) => [
                'id' => $row['id'],
                'name' => trim((string) $row['name']),
                'address' => $row['address'],
                'telephone_no' => $row['telephone_no'],
            ], $this->references),
            fn (array $row) => $row['name'] !== ''
        ));

        app(RowWriter::class)->sync(Reference::class, $employee->id, $references);

        $this->mount();

        Flux::toast(variant: 'success', text: __('Declarations saved.'));
    }
}; ?>

<section class="w-full">
    <flux:heading size="xl">{{ __('Declarations') }}</flux:heading>
    <flux:subheading>
        {{ __('CS Form 212, items 34 to 42. Answer yes and the details become required.') }}
    </flux:subheading>

    <x-pds.section-nav :employee="request()->integer('employee') ?: null" class="mt-6" />

    <form wire:submit="save" class="mt-6 max-w-3xl space-y-8">
        <flux:callout icon="exclamation-triangle" variant="warning">
            <flux:callout.text>
                {{ __('This page is signed under penalty of perjury. Every answer here is recorded, and HR opening it is recorded too.') }}
            </flux:callout.text>
        </flux:callout>

        {{-- 34 --}}
        <div class="space-y-3">
            <flux:heading size="lg">{{ __('34. Relation to the appointing or recommending authority') }}</flux:heading>
            <flux:checkbox wire:model.live="form.q34_related_third_degree" :label="__('a. Within the third degree')" />
            <flux:checkbox wire:model.live="form.q34_related_fourth_degree" :label="__('b. Within the fourth degree (Local Government Unit career employees)')" />
            @if (($form['q34_related_third_degree'] ?? false) || ($form['q34_related_fourth_degree'] ?? false))
                <flux:textarea wire:model="form.q34_related_details" :label="__('Give details')" rows="2" />
            @endif
        </div>

        {{-- 35 --}}
        <div class="space-y-3">
            <flux:heading size="lg">{{ __('35. Administrative offense and criminal charge') }}</flux:heading>

            <flux:checkbox wire:model.live="form.q35_administrative_offense" :label="__('a. Have you ever been found guilty of any administrative offense?')" />
            @if ($form['q35_administrative_offense'] ?? false)
                <flux:textarea wire:model="form.q35_administrative_details" :label="__('Give details')" rows="2" />
            @endif

            <flux:checkbox wire:model.live="form.q35_criminally_charged" :label="__('b. Have you been criminally charged before any court?')" />
            @if ($form['q35_criminally_charged'] ?? false)
                <flux:textarea wire:model="form.q35_criminal_details" :label="__('Give details')" rows="2" />
                <div class="grid gap-4 sm:grid-cols-2">
                    <flux:input wire:model="form.q35_date_filed" type="date" :label="__('Date filed')" />
                    <flux:input wire:model="form.q35_case_status" :label="__('Status of case')" />
                </div>
            @endif
        </div>

        {{-- 36 to 39 --}}
        @foreach ([
            'q36_convicted' => ['36. Have you ever been convicted of any crime or violation of any law, decree, ordinance or regulation by any court or tribunal?', 'q36_details'],
            'q37_separated_from_service' => ['37. Have you ever been separated from the service in any of the following modes: resignation, retirement, dropped from the rolls, dismissal, termination, end of term, finished contract or phased out?', 'q37_details'],
            'q38_candidate_in_election' => ['38. a. Have you ever been a candidate in a national or local election held within the last year?', 'q38_candidate_details'],
            'q38_resigned_to_campaign' => ['38. b. Have you resigned from the government service during the three-month period before the last election?', 'q38_resigned_details'],
            'q39_immigrant_or_permanent_resident' => ['39. Have you acquired the status of an immigrant or permanent resident of another country?', 'q39_details'],
        ] as $question => [$label, $details])
            <div class="space-y-3">
                <flux:checkbox wire:model.live="form.{{ $question }}" :label="__($label)" />
                @if ($form[$question] ?? false)
                    <flux:textarea wire:model="form.{{ $details }}" :label="__('Give details')" rows="2" />
                @endif
            </div>
        @endforeach

        {{-- 40 --}}
        <div class="space-y-3">
            <flux:heading size="lg">{{ __('40. Indigenous group, disability and solo parent') }}</flux:heading>

            <flux:checkbox wire:model.live="form.q40_indigenous_group" :label="__('a. Member of an indigenous group (RA 8371)')" />
            @if ($form['q40_indigenous_group'] ?? false)
                <flux:input wire:model="form.q40_indigenous_details" :label="__('Please specify')" />
            @endif

            <flux:checkbox wire:model.live="form.q40_person_with_disability" :label="__('b. Person with disability (RA 7277)')" />
            @if ($form['q40_person_with_disability'] ?? false)
                <flux:input wire:model="form.q40_pwd_id_no" :label="__('PWD ID no.')" />
            @endif

            <flux:checkbox wire:model.live="form.q40_solo_parent" :label="__('c. Solo parent (RA 8972)')" />
            @if ($form['q40_solo_parent'] ?? false)
                <flux:input wire:model="form.q40_solo_parent_id_no" :label="__('Solo Parent ID no.')" />
            @endif
        </div>

        <flux:separator />

        {{-- 41 --}}
        <div class="space-y-4">
            <flux:heading size="lg">{{ __('41. References') }}</flux:heading>
            <flux:subheading>{{ __('Persons not related to you. The form prints three.') }}</flux:subheading>

            <div class="space-y-3">
                @foreach ($references as $index => $reference)
                    <div wire:key="{{ $reference['key'] }}" class="grid items-end gap-3 sm:grid-cols-[2fr_3fr_1fr_auto]">
                        <flux:input wire:model="references.{{ $index }}.name" :label="__('Name')" />
                        <flux:input wire:model="references.{{ $index }}.address" :label="__('Address')" />
                        <flux:input wire:model="references.{{ $index }}.telephone_no" :label="__('Telephone no.')" />
                        <flux:button
                            type="button"
                            wire:click="removeReference({{ $index }})"
                            variant="subtle"
                            icon="trash"
                            :aria-label="__('Remove this reference')"
                        />
                    </div>
                @endforeach
            </div>

            <flux:button type="button" wire:click="addReference" variant="subtle" icon="plus" size="sm">
                {{ __('Add a reference') }}
            </flux:button>
        </div>

        <flux:separator />

        {{-- 42 --}}
        <div class="space-y-4">
            <flux:heading size="lg">{{ __('42. Government issued ID') }}</flux:heading>

            <div class="grid gap-4 sm:grid-cols-3">
                <flux:input wire:model="form.government_id_type" :label="__('ID type')" />
                <flux:input wire:model="form.government_id_number" :label="__('ID / licence / passport no.')" />
                <flux:input wire:model="form.government_id_issued" :label="__('Date and place of issuance')" />
            </div>

            <flux:input class="max-w-xs" wire:model="form.date_accomplished" type="date" :label="__('Date accomplished')" />
        </div>

        <flux:button type="submit" variant="primary">{{ __('Save') }}</flux:button>
    </form>
</section>
