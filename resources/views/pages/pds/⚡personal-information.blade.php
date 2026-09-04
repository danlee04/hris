<?php

use App\Enums\CivilStatus;
use App\Enums\Sex;
use App\Livewire\Concerns\EditsPdsSection;
use App\Models\Pds\PersonalInformation;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Personal information')] class extends Component {
    use EditsPdsSection;

    /** @var array<string, mixed> */
    public array $form = [];

    public function mount(): void
    {
        // `?employee=` is a query string, not a route segment, so Livewire does
        // not hand it to mount(). HR reaches somebody else's PDS through it;
        // the policy is what decides whether that is allowed.
        $requested = request()->integer('employee') ?: null;

        $record = $this->bootSection($requested)->personalInformation;

        $columns = (new PersonalInformation)->getFillable();

        $this->form = array_merge(
            array_fill_keys($columns, null),
            $record?->only($columns) ?? [],
        );

        // Casts hand back objects; the form needs the scalar the input holds.
        $this->form['date_of_birth'] = $record?->date_of_birth?->format('Y-m-d');
        $this->form['sex'] = $record?->sex?->value;
        $this->form['civil_status'] = $record?->civil_status?->value;
        $this->form['permanent_same_as_residential'] = (bool) $record?->permanent_same_as_residential;
    }

    public function save(): void
    {
        $employee = $this->authoriseSave();

        $validated = $this->validate([
            'form.date_of_birth' => ['nullable', 'date', 'before:today'],
            'form.place_of_birth' => ['nullable', 'string', 'max:255'],
            'form.sex' => ['nullable', Rule::enum(Sex::class)],
            'form.civil_status' => ['nullable', Rule::enum(CivilStatus::class)],
            'form.civil_status_other' => ['nullable', 'string', 'max:50'],
            'form.height_m' => ['nullable', 'numeric', 'between:0.5,2.5'],
            'form.weight_kg' => ['nullable', 'numeric', 'between:20,300'],
            'form.blood_type' => ['nullable', 'string', 'max:10'],
            'form.umid_id' => ['nullable', 'string', 'max:40'],
            'form.pagibig_id' => ['nullable', 'string', 'max:40'],
            'form.philhealth_no' => ['nullable', 'string', 'max:40'],
            'form.tin_no' => ['nullable', 'string', 'max:40'],
            'form.agency_employee_no' => ['nullable', 'string', 'max:40'],
            'form.philsys_id' => ['nullable', 'string', 'max:40'],
            'form.citizenship' => ['nullable', 'string', 'max:30'],
            'form.dual_citizenship_by' => ['nullable', 'string', 'max:20'],
            'form.dual_citizenship_country' => ['nullable', 'string', 'max:100'],
            'form.res_house_no' => ['nullable', 'string', 'max:60'],
            'form.res_street' => ['nullable', 'string', 'max:100'],
            'form.res_subdivision' => ['nullable', 'string', 'max:100'],
            'form.res_barangay' => ['nullable', 'string', 'max:100'],
            'form.res_city' => ['nullable', 'string', 'max:100'],
            'form.res_province' => ['nullable', 'string', 'max:100'],
            'form.res_zip_code' => ['nullable', 'string', 'max:10'],
            'form.permanent_same_as_residential' => ['boolean'],
            'form.perm_house_no' => ['nullable', 'string', 'max:60'],
            'form.perm_street' => ['nullable', 'string', 'max:100'],
            'form.perm_subdivision' => ['nullable', 'string', 'max:100'],
            'form.perm_barangay' => ['nullable', 'string', 'max:100'],
            'form.perm_city' => ['nullable', 'string', 'max:100'],
            'form.perm_province' => ['nullable', 'string', 'max:100'],
            'form.perm_zip_code' => ['nullable', 'string', 'max:10'],
            'form.telephone_no' => ['nullable', 'string', 'max:40'],
            'form.mobile_no' => ['nullable', 'string', 'max:40'],
            'form.email_address' => ['nullable', 'email', 'max:255'],
        ])['form'];

        // Only validated fields reach the model, and never these two: the
        // employee is decided by the policy, the photo by its own upload.
        unset($validated['employee_id'], $validated['photo_path']);

        if ($validated['permanent_same_as_residential'] ?? false) {
            foreach (['house_no', 'street', 'subdivision', 'barangay', 'city', 'province', 'zip_code'] as $part) {
                $validated["perm_{$part}"] = $validated["res_{$part}"] ?? null;
            }
        }

        PersonalInformation::updateOrCreate(['employee_id' => $employee->id], $validated);

        $this->form = array_merge($this->form, $validated);

        Flux::toast(variant: 'success', text: __('Personal information saved.'));
    }
}; ?>

<section class="w-full">
    <x-pds.page-header :title="__('Personal information')" :employee="request()->integer('employee') ?: null">
        {{ __('CS Form 212 (Revised 2026), items 1 to 21.') }}
    </x-pds.page-header>

    {{--
        Two columns from `lg` up. Twenty-one items in one narrow column meant
        most of a wide screen was blank and the form ran several screens deep.
        The columns are written out rather than left to the browser to flow,
        because the two addresses have to be able to sit side by side: that is
        the pair a person actually compares while typing.
    --}}
    <form wire:submit="save" class="mt-6 max-w-6xl space-y-8">
        <div class="grid items-start gap-8 lg:grid-cols-2">
            <div class="space-y-8">
                <flux:card class="space-y-6">
                    <flux:heading size="lg">{{ __('Personal details') }}</flux:heading>

                    <div class="grid gap-6 sm:grid-cols-2">
                        <flux:input wire:model="form.date_of_birth" type="date" :label="__('Date of birth')" />
                        <flux:input wire:model="form.place_of_birth" :label="__('Place of birth')" />

                        <flux:select wire:model="form.sex" :label="__('Sex at birth')" :placeholder="__('Choose')">
                            @foreach (App\Enums\Sex::cases() as $case)
                                <flux:select.option value="{{ $case->value }}">{{ $case->label() }}</flux:select.option>
                            @endforeach
                        </flux:select>

                        <flux:select wire:model="form.civil_status" :label="__('Civil status')" :placeholder="__('Choose')">
                            @foreach (App\Enums\CivilStatus::cases() as $case)
                                <flux:select.option value="{{ $case->value }}">{{ $case->label() }}</flux:select.option>
                            @endforeach
                        </flux:select>

                        <flux:input wire:model="form.height_m" type="number" step="0.01" :label="__('Height (m)')" />
                        <flux:input wire:model="form.weight_kg" type="number" step="0.01" :label="__('Weight (kg)')" />
                        <flux:input wire:model="form.blood_type" :label="__('Blood type')" />
                        <flux:input wire:model="form.citizenship" :label="__('Citizenship')" />
                    </div>
                </flux:card>

                <flux:card class="space-y-6">
                    <flux:heading size="lg">{{ __('Identification numbers') }}</flux:heading>

                    <div class="grid gap-6 sm:grid-cols-2">
                        <flux:input wire:model="form.umid_id" :label="__('UMID ID no.')" />
                        <flux:input wire:model="form.pagibig_id" :label="__('PAG-IBIG ID no.')" />
                        <flux:input wire:model="form.philhealth_no" :label="__('PhilHealth no.')" />
                        <flux:input wire:model="form.tin_no" :label="__('TIN no.')" />
                        <flux:input wire:model="form.agency_employee_no" :label="__('Agency employee no.')" />
                        <flux:input wire:model="form.philsys_id" :label="__('PhilSys Card Number (PCN)')" />
                    </div>
                </flux:card>

                <flux:card class="space-y-6">
                    <flux:heading size="lg">{{ __('Contact details') }}</flux:heading>

                    <div class="grid gap-6 sm:grid-cols-2">
                        <flux:input wire:model="form.telephone_no" :label="__('Telephone no.')" />
                        <flux:input wire:model="form.mobile_no" :label="__('Mobile no.')" />
                        <flux:input wire:model="form.email_address" type="email" :label="__('Email address')" />
                    </div>
                </flux:card>
            </div>

            <div class="space-y-8">
                <flux:card class="space-y-6">
                    <flux:heading size="lg">{{ __('Residential address') }}</flux:heading>

                    <div class="grid gap-6 sm:grid-cols-2">
                        <flux:input wire:model="form.res_house_no" :label="__('House/Block/Lot no.')" />
                        <flux:input wire:model="form.res_street" :label="__('Street')" />
                        <flux:input wire:model="form.res_subdivision" :label="__('Subdivision/Village')" />
                        <flux:input wire:model="form.res_barangay" :label="__('Barangay')" />
                        <flux:input wire:model="form.res_city" :label="__('City/Municipality')" />
                        <flux:input wire:model="form.res_province" :label="__('Province')" />
                        <flux:input wire:model="form.res_zip_code" :label="__('ZIP code')" />
                    </div>
                </flux:card>

                <flux:card class="space-y-6">
                    <flux:heading size="lg">{{ __('Permanent address') }}</flux:heading>

                    <flux:checkbox
                        wire:model.live="form.permanent_same_as_residential"
                        :label="__('Same as residential address')"
                    />

                    @unless ($form['permanent_same_as_residential'] ?? false)
                        <div class="grid gap-6 sm:grid-cols-2">
                            <flux:input wire:model="form.perm_house_no" :label="__('House/Block/Lot no.')" />
                            <flux:input wire:model="form.perm_street" :label="__('Street')" />
                            <flux:input wire:model="form.perm_subdivision" :label="__('Subdivision/Village')" />
                            <flux:input wire:model="form.perm_barangay" :label="__('Barangay')" />
                            <flux:input wire:model="form.perm_city" :label="__('City/Municipality')" />
                            <flux:input wire:model="form.perm_province" :label="__('Province')" />
                            <flux:input wire:model="form.perm_zip_code" :label="__('ZIP code')" />
                        </div>
                    @endunless
                </flux:card>
            </div>
        </div>

        <flux:button type="submit" variant="primary">{{ __('Save') }}</flux:button>
    </form>
</section>
