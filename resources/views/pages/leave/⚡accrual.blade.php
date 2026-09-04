<?php

use App\Services\Leave\AccrualPosting;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Post leave credits')] class extends Component {
    public string $period = '';

    public string $year = '';

    public function mount(): void
    {
        $this->authorize('leave.manage');

        // Last month, because that is the one being closed.
        $this->period = now()->subMonth()->format('Y-m');
        $this->year = now()->format('Y');
    }

    public function post(): void
    {
        // mount() ran once. The period comes back from the browser on every
        // later request, so the post asks again — and validates again.
        $this->authorize('leave.manage');

        $this->validate([
            'period' => ['required', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
        ], [
            'period.regex' => __('A period is a year and a month, like 2026-09.'),
        ]);

        $written = app(AccrualPosting::class)->post($this->period);

        Flux::toast(
            variant: $written > 0 ? 'success' : 'warning',
            text: $written > 0
                ? __(':count entries posted.', ['count' => $written])
                : __('Nothing to post. This month is already recorded.'),
        );
    }

    public function postGrants(): void
    {
        $this->authorize('leave.manage');

        $this->validate([
            'year' => ['required', 'regex:/^\d{4}$/'],
        ], [
            'year.regex' => __('A year is four digits, like 2026.'),
        ]);

        $written = app(AccrualPosting::class)->postGrants($this->year);

        Flux::toast(
            variant: $written > 0 ? 'success' : 'warning',
            text: $written > 0
                ? __(':count grants posted.', ['count' => $written])
                : __('Nothing to post. This year is already granted.'),
        );
    }

    /** @return array<string, mixed> */
    public function with(): array
    {
        $posting = app(AccrualPosting::class);

        // The shape is checked here rather than left to the service, because
        // with() runs on every keystroke of a live field. A service that threw
        // on "2026-0" would take the screen down mid-typing.
        return [
            // The previews write nothing. They are what a person reads before
            // pressing a button that changes 194 balances.
            'rows' => preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $this->period) === 1
                ? $posting->preview($this->period)
                : [],
            'grantRows' => preg_match('/^\d{4}$/', $this->year) === 1
                ? $posting->previewGrants($this->year)
                : [],
        ];
    }
}; ?>

<section class="w-full">
    <flux:heading size="xl">{{ __('Post leave credits') }}</flux:heading>
    <flux:subheading>
        {{ __('Credits are written by hand, on purpose. A schedule that quietly fails to run is a month nobody notices is missing.') }}
    </flux:subheading>

    <div class="mt-6 grid gap-8 lg:grid-cols-2">
        <flux:card class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Monthly credits') }}</flux:heading>
                <flux:subheading>
                    {{ __('1.25 vacation and 1.25 sick, for active permanent and co-terminous staff.') }}
                </flux:subheading>
            </div>

            <flux:input
                wire:model.live.debounce.500ms="period"
                :label="__('Month')"
                placeholder="2026-09"
                :description="__('A year and a month, like 2026-09.')"
            />

            <flux:callout icon="information-circle">
                {{ __('Pressing this twice is safe. A month already recorded is not recorded again.') }}
            </flux:callout>

            @php
                $due = collect($rows)->where('already_posted', false)->count();
            @endphp

            <flux:text class="text-sm">
                {{ __(':due to post, :done already recorded.', [
                    'due' => $due,
                    'done' => count($rows) - $due,
                ]) }}
            </flux:text>

            <flux:button wire:click="post" variant="primary">{{ __('Post the credits') }}</flux:button>
        </flux:card>

        {{--
            The yearly grants are a different event with a different key.
            Wellness Leave arrives only this way, so a job order with no grant
            posted has nothing to file against and the whole type is decoration.
        --}}
        <flux:card class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Yearly grants') }}</flux:heading>
                <flux:subheading>
                    {{ __('Special Privilege 3, Solo Parent 7, Wellness 5. Once a year, per person.') }}
                </flux:subheading>
            </div>

            <flux:input
                wire:model.live.debounce.500ms="year"
                :label="__('Year')"
                placeholder="2026"
                :description="__('Four digits.')"
            />

            <flux:callout icon="information-circle">
                {{ __('Wellness Leave is the only credit job order and contract of service staff receive.') }}
            </flux:callout>

            @php
                $grantsDue = collect($grantRows)->where('already_posted', false)->count();
            @endphp

            <flux:text class="text-sm">
                {{ __(':due to post, :done already granted.', [
                    'due' => $grantsDue,
                    'done' => count($grantRows) - $grantsDue,
                ]) }}
            </flux:text>

            <flux:button wire:click="postGrants" variant="primary">{{ __('Post the grants') }}</flux:button>
        </flux:card>
    </div>

    <flux:heading size="lg" class="mt-10">{{ __('What the month will write') }}</flux:heading>

    <flux:table class="mt-4">
        <flux:table.columns>
            <flux:table.column>{{ __('Employee') }}</flux:table.column>
            <flux:table.column>{{ __('Balance') }}</flux:table.column>
            <flux:table.column>{{ __('Days') }}</flux:table.column>
            <flux:table.column>{{ __('State') }}</flux:table.column>
        </flux:table.columns>

        <flux:table.rows>
            @forelse ($rows as $row)
                <flux:table.row wire:key="accrual-{{ $row['employee']->id }}-{{ $row['ledger'] }}">
                    <flux:table.cell class="font-medium">{{ $row['employee']->fullName() }}</flux:table.cell>
                    <flux:table.cell>{{ $row['ledger'] }}</flux:table.cell>
                    <flux:table.cell>{{ number_format($row['days'], 2) }}</flux:table.cell>
                    <flux:table.cell>
                        <flux:badge size="sm" :color="$row['already_posted'] ? 'zinc' : 'green'">
                            {{ $row['already_posted'] ? __('Already posted') : __('Will be posted') }}
                        </flux:badge>
                    </flux:table.cell>
                </flux:table.row>
            @empty
                <flux:table.row>
                    <flux:table.cell colspan="4" class="text-center">
                        {{ __('Nobody accrues credits for that month.') }}
                    </flux:table.cell>
                </flux:table.row>
            @endforelse
        </flux:table.rows>
    </flux:table>
</section>
