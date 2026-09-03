<?php

use App\Models\Employee;
use App\Services\AuditRecorder;
use App\Services\Pds\PdsCompleteness;
use App\Services\Pds\PdsExporter;
use Livewire\Attributes\Title;
use Livewire\Component;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

new #[Title('Download PDS')] class extends Component {
    public ?int $employeeId = null;

    public function mount(): void
    {
        $employee = $this->resolve(request()->integer('employee') ?: null);

        $this->authorize('exportPds', $employee);

        $this->employeeId = $employee->id;
    }

    public function download(): BinaryFileResponse
    {
        // mount() ran once. employeeId is rehydrated from the browser on every
        // later request, so the download asks again.
        $employee = $this->resolve($this->employeeId);

        $this->authorize('exportPds', $employee);

        // Downloading somebody else's PDS takes the whole record out of the
        // system in one file. That is worth more of a record than reading one
        // section of it on screen.
        if ($employee->user_id !== auth()->id()) {
            app(AuditRecorder::class)->recordRead($employee, 'Downloaded the PDS as a CSC form');
        }

        $exporter = app(PdsExporter::class);

        return response()
            ->download($exporter->export($employee), $exporter->filename($employee))
            ->deleteFileAfterSend();
    }

    private function resolve(?int $employeeId): Employee
    {
        $employee = $employeeId !== null
            ? Employee::find($employeeId)
            : auth()->user()?->employee;

        abort_if($employee === null, 403, 'This account is not linked to an employee record.');

        return $employee;
    }

    /** @return array<string, mixed> */
    public function with(): array
    {
        $employee = $this->resolve($this->employeeId);

        return [
            'employee' => $employee,
            'sections' => app(PdsCompleteness::class)->for($employee),
        ];
    }
}; ?>

<section class="w-full">
    <flux:heading size="xl">{{ __('Download PDS') }}</flux:heading>
    <flux:subheading>
        {{ __('CS Form 212 (Revised 2026), filled from what is stored here.') }}
    </flux:subheading>

    <x-pds.section-nav :employee="request()->integer('employee') ?: null" class="mt-6" />

    @php
        $empty = collect($sections)->where('complete', false);
    @endphp

    <div class="mt-6 max-w-2xl space-y-6">
        <flux:card>
            <flux:heading size="lg">{{ $employee->fullName() }}</flux:heading>
            <flux:subheading>{{ $employee->employee_number }}</flux:subheading>

            @if ($empty->isNotEmpty())
                {{--
                    A warning, not a block. HR asks for a half-filled PDS often
                    enough — for a promotion paper, for a personnel action — and
                    refusing to produce one would send them back to retyping it.
                --}}
                <flux:callout class="mt-4" variant="warning" icon="exclamation-triangle">
                    <flux:callout.heading>
                        {{ __(':count sections are still empty', ['count' => $empty->count()]) }}
                    </flux:callout.heading>
                    <flux:callout.text>
                        {{ $empty->pluck('label')->join(', ', ' and ') }}.
                        {{ __('The form downloads anyway; those parts print blank.') }}
                    </flux:callout.text>
                </flux:callout>
            @else
                <flux:callout class="mt-4" variant="success" icon="check-circle">
                    <flux:callout.text>{{ __('All nine sections hold something.') }}</flux:callout.text>
                </flux:callout>
            @endif

            <form wire:submit="download" class="mt-6">
                <flux:button type="submit" variant="primary" icon="arrow-down-tray">
                    {{ __('Download the CSC form') }}
                </flux:button>
            </form>
        </flux:card>

        <flux:text class="text-sm">
            {{ __('The signature, photograph and thumbmark are left blank. Print the form and sign it by hand.') }}
        </flux:text>
    </div>
</section>
