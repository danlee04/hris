<?php

use App\Enums\EmploymentStatus;
use App\Models\Division;
use App\Models\Employee;
use App\Models\Position;
use App\Models\Section;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Edit employee')] class extends Component {
    public int $employeeId;

    /** @var array<string, mixed> */
    public array $form = [];

    public function mount(Employee $employee): void
    {
        $this->authorize('update', $employee);

        $this->employeeId = $employee->id;

        $this->form = [
            'employee_number' => $employee->employee_number,
            'first_name' => $employee->first_name,
            'middle_name' => $employee->middle_name,
            'last_name' => $employee->last_name,
            'suffix' => $employee->suffix,
            'position_id' => $employee->position_id,
            'division_id' => $employee->division_id ?? $employee->section?->division_id,
            'section_id' => $employee->section_id,
            // The import filled the section without always filling the
            // division. Deriving it here means the form opens showing where
            // the person actually works instead of "None".
            'employment_status' => $employee->employment_status?->value,
            'date_hired' => $employee->date_hired?->format('Y-m-d'),
            'biometric_id' => $employee->biometric_id,
            'is_active' => (bool) $employee->is_active,
            'is_chief_of_hospital' => (bool) $employee->is_chief_of_hospital,
        ];
    }

    /**
     * Changing the division empties the section. Leaving the old one selected
     * would let HR save a section belonging to a different division, and the
     * two would disagree from then on.
     */
    public function updatedFormDivisionId(): void
    {
        $this->form['section_id'] = null;
    }

    public function save(): void
    {
        // mount() authorised one employee. employeeId is rehydrated from the
        // browser on every later request, so the save asks again.
        $employee = Employee::findOrFail($this->employeeId);

        $this->authorize('update', $employee);

        $validated = $this->validate([
            'form.employee_number' => [
                'required', 'string', 'max:50',
                Rule::unique('employees', 'employee_number')->ignore($employee->id)->withoutTrashed(),
            ],
            'form.first_name' => ['required', 'string', 'max:255'],
            'form.middle_name' => ['nullable', 'string', 'max:255'],
            'form.last_name' => ['required', 'string', 'max:255'],
            'form.suffix' => ['nullable', 'string', 'max:20'],
            'form.position_id' => ['nullable', Rule::exists('positions', 'id')],
            'form.division_id' => ['nullable', Rule::exists('divisions', 'id')],
            // Not checked against the chosen division. The section is the real
            // assignment and the division is derived from it below, so the two
            // cannot disagree however the form arrives. The division select
            // exists to narrow this list, not to constrain it.
            'form.section_id' => ['nullable', Rule::exists('sections', 'id')],
            'form.employment_status' => ['required', Rule::enum(EmploymentStatus::class)],
            'form.date_hired' => ['nullable', 'date', 'before_or_equal:today'],
            'form.biometric_id' => [
                'nullable', 'string', 'max:50',
                Rule::unique('employees', 'biometric_id')->ignore($employee->id)->withoutTrashed(),
            ],
            'form.is_active' => ['boolean'],
            'form.is_chief_of_hospital' => ['boolean'],
        ], [
            'form.biometric_id.unique' => __('Another employee already uses that biometric ID.'),
        ])['form'];

        // The hospital has one Chief. Reassigning the other person silently
        // would rewrite a record HR never opened; naming them lets HR decide.
        if ($validated['is_chief_of_hospital']) {
            $incumbent = Employee::where('is_chief_of_hospital', true)
                ->whereKeyNot($employee->id)
                ->first();

            if ($incumbent) {
                $this->addError('form.is_chief_of_hospital', __(':name is recorded as Chief of Hospital. Clear that first.', [
                    'name' => $incumbent->fullName(),
                ]));

                return;
            }
        }

        // A section always carries its own division, whatever the form said.
        if ($validated['section_id']) {
            $validated['division_id'] = Section::findOrFail($validated['section_id'])->division_id;
        }

        $employee->update($validated);

        Flux::toast(variant: 'success', text: __('Employee saved.'));
    }

    /** @return array<string, mixed> */
    public function with(): array
    {
        return [
            'employee' => Employee::findOrFail($this->employeeId),
            'positions' => Position::orderBy('title')->get(),
            'divisions' => Division::orderBy('name')->get(),
            'sections' => $this->form['division_id']
                ? Section::where('division_id', $this->form['division_id'])->orderBy('name')->get()
                : collect(),
        ];
    }
}; ?>

<section class="w-full">
    <flux:heading size="xl">{{ $employee->fullName() }}</flux:heading>
    <flux:subheading>
        {{ __('The employee master. What the person maintains themselves is their PDS.') }}
    </flux:subheading>

    <form wire:submit="save" class="mt-6 max-w-3xl space-y-8">
        <div class="grid gap-6 sm:grid-cols-2">
            <flux:input wire:model="form.employee_number" :label="__('Employee number')" />
            <flux:input
                wire:model="form.biometric_id"
                :label="__('Biometric ID')"
                :description="__('Optional. Matches the device export.')"
            />

            <flux:input wire:model="form.first_name" :label="__('First name')" />
            <flux:input wire:model="form.middle_name" :label="__('Middle name')" />
            <flux:input wire:model="form.last_name" :label="__('Last name')" />
            <flux:input wire:model="form.suffix" :label="__('Name extension')" placeholder="Jr., III" />
        </div>

        <flux:separator variant="subtle" />

        <div class="grid gap-6 sm:grid-cols-2">
            <flux:select wire:model="form.position_id" :label="__('Position')" :placeholder="__('None')">
                @foreach ($positions as $position)
                    <flux:select.option wire:key="position-{{ $position->id }}" value="{{ $position->id }}">
                        {{ $position->title }}
                    </flux:select.option>
                @endforeach
            </flux:select>

            <flux:select wire:model="form.employment_status" :label="__('Employment status')">
                @foreach (App\Enums\EmploymentStatus::cases() as $status)
                    <flux:select.option wire:key="status-{{ $status->value }}" value="{{ $status->value }}">
                        {{ $status->label() }}
                    </flux:select.option>
                @endforeach
            </flux:select>

            <flux:select wire:model.live="form.division_id" :label="__('Division')" :placeholder="__('None')">
                @foreach ($divisions as $division)
                    <flux:select.option wire:key="division-{{ $division->id }}" value="{{ $division->id }}">
                        {{ $division->name }}
                    </flux:select.option>
                @endforeach
            </flux:select>

            <flux:select wire:model="form.section_id" :label="__('Section')" :placeholder="__('None')">
                @foreach ($sections as $section)
                    <flux:select.option wire:key="section-{{ $section->id }}" value="{{ $section->id }}">
                        {{ $section->name }}
                    </flux:select.option>
                @endforeach
            </flux:select>

            <flux:input wire:model="form.date_hired" type="date" :label="__('Date hired')" />
        </div>

        <flux:separator variant="subtle" />

        <div class="space-y-4">
            <flux:switch
                wire:model="form.is_active"
                :label="__('Currently employed')"
                :description="__('Turn this off when the person leaves. Their record and their PDS stay.')"
            />

            <flux:switch
                wire:model="form.is_chief_of_hospital"
                :label="__('Chief of Hospital')"
                :description="__('One person at a time.')"
            />

            <flux:error name="form.is_chief_of_hospital" />
        </div>

        <div class="flex items-center gap-4">
            <flux:button type="submit" variant="primary">{{ __('Save') }}</flux:button>
            <flux:link :href="route('employees.index')" wire:navigate>{{ __('Back to the list') }}</flux:link>
        </div>
    </form>
</section>
