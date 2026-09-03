<?php

use App\Models\Employee;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Issue a login')] class extends Component {
    public ?int $employeeId = null;

    public string $email = '';

    public string $temporaryPassword = '';

    public function mount(): void
    {
        $this->authorize('issueAccount', Employee::class);
    }

    public function issue(): void
    {
        $this->authorize('issueAccount', Employee::class);

        $this->validate([
            'employeeId' => [
                'required',
                Rule::exists('employees', 'id')->whereNull('user_id')->whereNull('deleted_at'),
            ],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
            'temporaryPassword' => ['required', 'string', 'min:8'],
        ], [
            'employeeId.exists' => __('That employee does not exist, or already has a login.'),
        ]);

        DB::transaction(function () {
            $employee = Employee::findOrFail($this->employeeId);

            $user = User::create([
                'name' => $employee->fullName(),
                'email' => $this->email,
                'password' => Hash::make($this->temporaryPassword),
                'must_change_password' => true,
            ]);

            $user->assignRole('employee');

            $employee->update(['user_id' => $user->id]);
        });

        $this->reset(['employeeId', 'email', 'temporaryPassword']);

        session()->flash('status', __('Login issued.'));
    }

    /** @return array<string, mixed> */
    public function with(): array
    {
        return [
            'employees' => Employee::query()
                ->whereNull('user_id')
                ->active()
                ->orderBy('last_name')
                ->orderBy('first_name')
                ->get(),
        ];
    }
}; ?>

<section class="w-full">
    <flux:heading size="xl">{{ __('Issue a login') }}</flux:heading>
    <flux:subheading>
        {{ __('The employee must replace this password the first time they sign in.') }}
    </flux:subheading>

    <x-auth-session-status class="mt-4" :status="session('status')" />

    @if ($employees->isEmpty())
        <flux:callout class="mt-6" icon="information-circle">
            {{ __('Every active employee already has a login. Import more employees first.') }}
        </flux:callout>
    @else
        <form wire:submit="issue" class="mt-6 flex max-w-xl flex-col gap-6">
            <flux:select wire:model="employeeId" :label="__('Employee')" :placeholder="__('Choose an employee')">
                @foreach ($employees as $employee)
                    <flux:select.option wire:key="employee-{{ $employee->id }}" value="{{ $employee->id }}">
                        {{ $employee->employee_number }} — {{ $employee->fullName() }}
                    </flux:select.option>
                @endforeach
            </flux:select>

            <flux:input wire:model="email" type="email" :label="__('Email address')" required />

            <flux:input
                wire:model="temporaryPassword"
                type="text"
                :label="__('Temporary password')"
                :description="__('Write this down and hand it over in person. It is shown only once.')"
                required
            />

            <flux:button type="submit" variant="primary" class="self-start">
                {{ __('Issue login') }}
            </flux:button>
        </form>
    @endif
</section>
