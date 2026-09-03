<?php

use App\Models\Employee;
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
    <flux:heading size="xl">{{ __('Employees') }}</flux:heading>
    <flux:subheading>{{ __('The plantilla and everyone on it.') }}</flux:subheading>

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
            <flux:table.column>{{ __('PDS') }}</flux:table.column>
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
                        {{-- Both links are guarded so neither appears to anyone
                             who would only be shown a 403 on the other side.
                             Opening or downloading somebody else's PDS is
                             recorded either way. --}}
                        <div class="flex gap-3 text-sm">
                            @can('viewPds', $employee)
                                <flux:link
                                    :href="route('pds.personal-information', ['employee' => $employee->id])"
                                    wire:navigate
                                >
                                    {{ __('Open') }}
                                </flux:link>
                            @endcan

                            @can('exportPds', $employee)
                                <flux:link
                                    :href="route('pds.export', ['employee' => $employee->id])"
                                    wire:navigate
                                >
                                    {{ __('Download') }}
                                </flux:link>
                            @endcan
                        </div>
                    </flux:table.cell>
                </flux:table.row>
            @empty
                <flux:table.row>
                    <flux:table.cell colspan="6" class="text-center">
                        {{ __('No employees yet. Import them from a CSV to get started.') }}
                    </flux:table.cell>
                </flux:table.row>
            @endforelse
        </flux:table.rows>
    </flux:table>
</section>
