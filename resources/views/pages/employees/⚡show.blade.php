<?php

use App\Models\Employee;
use App\Services\Pds\PdsCompleteness;
use Livewire\Attributes\On;
use Livewire\Component;

new class extends Component {
    public int $employeeId;

    public function mount(Employee $employee): void
    {
        $this->authorize('view', $employee);

        $this->employeeId = $employee->id;
    }

    public function title(): string
    {
        return Employee::find($this->employeeId)?->fullName() ?? __('Employee');
    }

    /** The nested form saved this employee; render again with the new values. */
    #[On('employee-saved')]
    public function refresh(): void {}

    /** @return array<string, mixed> */
    public function with(): array
    {
        $employee = Employee::with(['position', 'section.division', 'division', 'user'])
            ->findOrFail($this->employeeId);

        return [
            'employee' => $employee,
            // Only computed for someone allowed to see the PDS at all.
            'sections' => auth()->user()?->can('viewPds', $employee)
                ? app(PdsCompleteness::class)->for($employee)
                : [],
        ];
    }
}; ?>

<section class="w-full">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <flux:heading size="xl">{{ $employee->fullName() }}</flux:heading>
            <flux:subheading>{{ $employee->employee_number }}</flux:subheading>
        </div>

        <div class="flex items-center gap-3">
            @can('update', $employee)
                <flux:button
                    wire:click="$dispatch('edit-employee', { id: {{ $employee->id }} })"
                    variant="filled"
                    icon="pencil-square"
                    size="sm"
                >
                    {{ __('Edit') }}
                </flux:button>
            @endcan

            <flux:button :href="route('employees.index')" variant="ghost" size="sm" wire:navigate>
                {{ __('Back to the list') }}
            </flux:button>
        </div>
    </div>

    <x-auth-session-status class="mt-4" :status="session('status')" />

    {{--
        Two cards, side by side, filling the width. No max-width: a fixed one
        left most of a wide screen blank, and the two cards stretch to the same
        height so the row reads as one band rather than two loose blocks.
    --}}
    <div class="mt-6 grid gap-8 lg:grid-cols-2">
        <flux:card class="space-y-4">
            <flux:heading size="lg">{{ __('Appointment') }}</flux:heading>

            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <flux:subheading>{{ __('Position') }}</flux:subheading>
                    <flux:text>{{ $employee->position?->title ?? '—' }}</flux:text>
                </div>

                <div>
                    <flux:subheading>{{ __('Employment status') }}</flux:subheading>
                    <flux:text>{{ $employee->employment_status?->label() ?? '—' }}</flux:text>
                </div>

                <div>
                    <flux:subheading>{{ __('Division') }}</flux:subheading>
                    <flux:text>{{ $employee->section?->division?->name ?? $employee->division?->name ?? '—' }}</flux:text>
                </div>

                <div>
                    <flux:subheading>{{ __('Section') }}</flux:subheading>
                    <flux:text>{{ $employee->section?->name ?? '—' }}</flux:text>
                </div>

                <div>
                    <flux:subheading>{{ __('Date hired') }}</flux:subheading>
                    <flux:text>{{ $employee->date_hired?->format('d/m/Y') ?? '—' }}</flux:text>
                </div>

                <div>
                    <flux:subheading>{{ __('Biometric ID') }}</flux:subheading>
                    <flux:text>{{ $employee->biometric_id ?? '—' }}</flux:text>
                </div>
            </div>

            <div class="flex flex-wrap gap-2 pt-2">
                <flux:badge size="sm" :color="$employee->is_active ? 'green' : 'zinc'">
                    {{ $employee->is_active ? __('Currently employed') : __('No longer employed') }}
                </flux:badge>

                @if ($employee->is_chief_of_hospital)
                    <flux:badge size="sm" color="blue">{{ __('Chief of Hospital') }}</flux:badge>
                @endif

                <flux:badge size="sm" :color="$employee->user_id ? 'green' : 'zinc'">
                    {{ $employee->user_id ? __('Login issued') : __('No login') }}
                </flux:badge>
            </div>
        </flux:card>

        {{--
            The PDS lives here and nowhere else. Taking somebody's whole record
            out of the system is a deliberate act, and a Download link sitting
            in a list of 134 rows is an easy one. Reaching this page means you
            have already said whose record you meant.
        --}}
        @can('viewPds', $employee)
            <flux:card class="space-y-4">
                <flux:heading size="lg">{{ __('Personal Data Sheet') }}</flux:heading>

                <flux:subheading>
                    {{ __(':filled of :total sections hold something.', [
                        'filled' => collect($sections)->where('complete', true)->count(),
                        'total' => count($sections),
                    ]) }}
                </flux:subheading>

                <div class="flex flex-wrap gap-2">
                    @foreach ($sections as $section)
                        <flux:badge
                            wire:key="pds-{{ $section['key'] }}"
                            size="sm"
                            :color="$section['complete'] ? 'green' : 'zinc'"
                        >
                            {{ $section['label'] }}
                        </flux:badge>
                    @endforeach
                </div>

                <div class="flex flex-wrap items-center gap-3 pt-2">
                    <flux:button
                        :href="route('pds.personal-information', ['employee' => $employee->id])"
                        variant="filled"
                        icon="identification"
                        size="sm"
                        wire:navigate
                    >
                        {{ __('Open the PDS') }}
                    </flux:button>

                    @can('exportPds', $employee)
                        <flux:button
                            :href="route('pds.export', ['employee' => $employee->id])"
                            variant="primary"
                            icon="arrow-down-tray"
                            size="sm"
                            wire:navigate
                        >
                            {{ __('Download the CSC form') }}
                        </flux:button>
                    @endcan
                </div>
            </flux:card>
        @endcan
    </div>

    @can('create', App\Models\Employee::class)
        {{-- The same nested form as the list, so Edit behaves the same in both
             places and there is still only one set of rules. --}}
        <flux:modal name="employee-form" class="w-full md:max-w-2xl">
            @livewire('pages::employees.form', ['inModal' => true])
        </flux:modal>
    @endcan
</section>
