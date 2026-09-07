<?php

use App\Models\Employee;
use App\Services\AuditRecorder;
use App\Services\Employees\CertificateOfEmployment;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Certificate of employment')] class extends Component {
    public int $employeeId;

    public string $salary = '';

    public string $purpose = '';

    public function mount(Employee $employee): void
    {
        $this->authorize('issueCertificate', $employee);

        // Issuing a certificate takes this person's post, status and hire date
        // out of the system on paper. That is a read worth recording.
        app(AuditRecorder::class)->recordRead($employee, 'Issued a certificate of employment');

        $this->employeeId = $employee->id;
    }

    /** @return array<string, mixed> */
    public function with(): array
    {
        // mount() ran once. employeeId is rehydrated from the browser on every
        // later keystroke, so the question is asked again.
        $employee = Employee::with(['position', 'section.division', 'division'])
            ->findOrFail($this->employeeId);

        $this->authorize('issueCertificate', $employee);

        try {
            $certificate = app(CertificateOfEmployment::class)
                ->for($employee, $this->salary ?: null, $this->purpose ?: null);
        } catch (ValidationException $e) {
            // Caught rather than allowed to bubble. A refusal thrown out of
            // with() takes the whole page with it, and HR is left with an
            // error screen that does not say which record is the problem or
            // what to do about it.
            return [
                'employee' => $employee,
                'certificate' => null,
                'problem' => $e->validator->errors()->first(),
            ];
        }

        return [
            'employee' => $employee,
            'certificate' => $certificate,
            'problem' => null,
        ];
    }
}; ?>

<section class="w-full">
    {{-- Everything in here is gone when the page is printed. --}}
    <div class="print:hidden">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <flux:heading size="xl">{{ __('Certificate of employment') }}</flux:heading>
                <flux:subheading>{{ $employee->fullName() }}</flux:subheading>
            </div>

            <div class="flex gap-2">
                <flux:button :href="route('employees.show', $employee)" wire:navigate variant="ghost" size="sm">
                    {{ __('Back to the record') }}
                </flux:button>

                @if (! $problem)
                    <flux:button onclick="window.print()" variant="primary" icon="printer" size="sm">
                        {{ __('Print') }}
                    </flux:button>
                @endif
            </div>
        </div>

        @if ($problem)
            <flux:callout variant="warning" icon="exclamation-triangle" class="mt-6">
                <flux:callout.heading>{{ __('This record cannot be certified') }}</flux:callout.heading>
                <flux:callout.text>{{ $problem }}</flux:callout.text>
            </flux:callout>
        @endif

        <div @class(['mt-6 grid gap-6 sm:grid-cols-2', 'hidden' => $problem])>
            <flux:input
                wire:model.live.debounce.400ms="salary"
                :label="__('Monthly salary')"
                placeholder="39672.00"
                inputmode="decimal"
                :description="__('Typed here, never stored. Salaries move with every tranche, and a figure kept on the record would print last year\'s amount on a document going to a bank.')"
            />

            <flux:input
                wire:model.live.debounce.400ms="purpose"
                :label="__('Issued for')"
                :placeholder="__('whatever legal purpose it may serve')"
                :description="__('A bank often wants its own name in this line.')"
            />
        </div>

        <flux:separator class="my-8" />
    </div>

    {{--
        The paper. Black on white whatever the screen is set to, because that
        is what comes out of the printer — a preview in the application's own
        dark theme would be a preview of something else.
    --}}
    @if (! $problem)
    <article data-print-sheet class="mx-auto max-w-[820px] bg-white px-14 py-16 text-black shadow-sm">
        <header class="text-center leading-tight">
            <p class="text-sm">{{ config('hospital.republic') }}</p>
            <p class="text-sm">{{ config('hospital.department') }}</p>
            <p class="mt-1 text-base font-semibold uppercase">{{ config('hospital.name') }}</p>
            @if (config('hospital.unit'))
                <p class="text-sm">{{ config('hospital.unit') }}</p>
            @endif
            {{-- Omitted rather than filled with a placeholder: an invented
                 address on a document going to a bank is worse than none. --}}
            @if (config('hospital.address'))
                <p class="text-xs">{{ config('hospital.address') }}</p>
            @endif
        </header>

        <hr class="my-8 border-black/40">

        <h2 class="text-center text-lg font-semibold tracking-wide uppercase">
            {{ __('Certificate of Employment') }}
        </h2>

        <p class="mt-10 text-sm font-medium">{{ __('TO WHOM IT MAY CONCERN:') }}</p>

        <div class="mt-6 space-y-5 text-justify text-[15px] leading-7">
            <p class="indent-12">
                {{ __('This is to certify that') }}
                <span class="font-semibold">{{ $certificate['name'] }}</span>
                {{ __('is a bona fide employee of this Centre') }}@if ($certificate['position']),
                    {{ __('holding the position of') }}
                    <span class="font-semibold">{{ $certificate['position'] }}</span>@endif@if ($certificate['status'])
                    {{ __('under') }} {{ strtolower($certificate['status']) }} {{ __('status') }}@endif,
                {{ __('from') }} {{ $certificate['since'] }} {{ __('up to the present.') }}
            </p>

            @if ($certificate['salary'])
                <p class="indent-12">
                    {{ __('The current compensation for this position is') }}
                    {{ $certificate['salary'] }}.
                </p>
            @endif

            <p class="indent-12">
                {{ __('This certification is issued upon request for') }}
                {{ $certificate['purpose'] }}.
            </p>

            <p class="indent-12">
                {{ __('Issued this') }} {{ $certificate['issued_on'] }}@if (config('hospital.unit'))
                    {{ __('at') }} {{ config('hospital.unit') }}@endif.
            </p>
        </div>

        <div class="mt-20 flex justify-end">
            <div class="min-w-[280px] text-center">
                @if ($certificate['signatory'])
                    <p class="font-semibold">{{ $certificate['signatory'] }}</p>
                    <p class="text-sm">{{ $certificate['signatory_title'] ?? __('Human Resource') }}</p>
                @else
                    {{-- A blank line rather than a name the system does not
                         have. Somebody signs it by hand and the document is
                         still true. --}}
                    <div class="mb-1 border-b border-black"></div>
                    <p class="text-sm">{{ __('Human Resource') }}</p>
                @endif
            </div>
        </div>
    </article>
    @endif
</section>
