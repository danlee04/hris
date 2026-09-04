<?php

use App\Models\Employee;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Employees')] class extends Component {
    use WithPagination;

    #[Url]
    public string $search = '';

    public function mount(): void
    {
        // This is what turns the policy into a 403. Without it the policy
        // exists and protects nothing.
        $this->authorize('viewAny', Employee::class);
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    /**
     * The nested form saved somebody. Nothing to do but render again — the row
     * would otherwise keep showing the name it had before the edit.
     */
    #[On('employee-saved')]
    public function refreshList(): void {}

    /** @return array<string, mixed> */
    public function with(): array
    {
        return [
            'employees' => Employee::query()
                ->with(['position', 'section.division'])
                ->when($this->search !== '', function ($query) {
                    $term = '%'.$this->search.'%';
                    $query->where(function ($q) use ($term) {
                        $q->where('last_name', 'like', $term)
                            ->orWhere('first_name', 'like', $term)
                            ->orWhere('employee_number', 'like', $term);
                    });
                })
                ->orderBy('last_name')
                ->orderBy('first_name')
                ->paginate(25),
        ];
    }
}; ?>

<section class="w-full">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <flux:heading size="xl">{{ __('Employees') }}</flux:heading>
            <flux:subheading>{{ __('The plantilla and everyone on it.') }}</flux:subheading>
        </div>

        @can('create', App\Models\Employee::class)
            {{-- Through the server, not straight to the modal: the form has to
                 be emptied of whatever Edit last put in it. --}}
            <flux:button
                wire:click="$dispatch('add-employee')"
                variant="primary"
                icon="plus"
                size="sm"
            >
                {{ __('Add an employee') }}
            </flux:button>
        @endcan
    </div>

    <div class="mt-6 max-w-sm">
        <flux:input
            wire:model.live.debounce.300ms="search"
            :placeholder="__('Search by name or employee number')"
            icon="magnifying-glass"
            clearable
        />
    </div>

    <flux:table class="mt-6" :paginate="$employees">
        <flux:table.columns>
            <flux:table.column>{{ __('Employee No.') }}</flux:table.column>
            <flux:table.column>{{ __('Name') }}</flux:table.column>
            <flux:table.column>{{ __('Position') }}</flux:table.column>
            <flux:table.column>{{ __('Section') }}</flux:table.column>
            <flux:table.column>{{ __('Account') }}</flux:table.column>
            <flux:table.column>{{ __('Actions') }}</flux:table.column>
        </flux:table.columns>

        <flux:table.rows>
            @forelse ($employees as $employee)
                <flux:table.row wire:key="employee-{{ $employee->id }}">
                    <flux:table.cell>{{ $employee->employee_number }}</flux:table.cell>
                    <flux:table.cell class="font-medium">{{ $employee->fullName() }}</flux:table.cell>
                    <flux:table.cell>{{ $employee->position?->title }}</flux:table.cell>
                    <flux:table.cell>{{ $employee->section?->name }}</flux:table.cell>
                    <flux:table.cell>
                        @if ($employee->user_id)
                            <flux:badge color="green" size="sm">{{ __('Issued') }}</flux:badge>
                        @else
                            <flux:badge color="zinc" size="sm">{{ __('None') }}</flux:badge>
                        @endif
                    </flux:table.cell>
                    <flux:table.cell>
                        {{-- Every link is guarded so none appears to anyone who
                             would only be shown a 403 on the other side.

                             The PDS is not here. Opening or downloading
                             somebody's record is a deliberate act, and a link
                             sitting in a list of 134 rows is an easy one. It
                             lives on the employee's own page, where you have
                             already said whose record you meant. --}}
                        <div class="flex gap-3 text-sm">
                            @can('view', $employee)
                                <flux:link
                                    :href="route('employees.show', ['employee' => $employee->id])"
                                    wire:navigate
                                >
                                    {{ __('View') }}
                                </flux:link>
                            @endcan

                            @can('update', $employee)
                                <flux:link
                                    href="#"
                                    wire:click.prevent="$dispatch('edit-employee', { id: {{ $employee->id }} })"
                                >
                                    {{ __('Edit') }}
                                </flux:link>
                            @endcan

                            @can('leave.manage')
                                <flux:link
                                    :href="route('leave.ledger', ['employee' => $employee->id])"
                                    wire:navigate
                                >
                                    {{ __('Leave') }}
                                </flux:link>
                            @endcan
                        </div>
                    </flux:table.cell>
                </flux:table.row>
            @empty
                <flux:table.row>
                    <flux:table.cell colspan="6" class="text-center">
                        {{ __('No employees yet. Add one, or load a roster with php artisan employees:import.') }}
                    </flux:table.cell>
                </flux:table.row>
            @endforelse
        </flux:table.rows>
    </flux:table>

    @can('create', App\Models\Employee::class)
        {{--
            One form, nested once, serving both Add and Edit for every row. The
            row dispatches an id; the form loads it and opens itself. Nesting
            one per row would mount 25 components to use one.

            Not a second copy of the form either: one set of fields, one set of
            rules, one place a column has to be added. It authorises itself on
            mount and again on save, so nesting it grants nothing.
        --}}
        <flux:modal name="employee-form" class="w-full md:max-w-2xl">
            @livewire('pages::employees.form', ['inModal' => true])
        </flux:modal>
    @endcan
</section>
