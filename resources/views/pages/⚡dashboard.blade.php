<?php

use App\Models\LeaveApplication;
use App\Services\Dashboard\DashboardMetrics;
use App\Services\Leave\ApprovalQueue;
use App\Services\Leave\LeaveBalance;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Dashboard')] class extends Component {
    /**
     * Everything the page draws, gathered in one place.
     *
     * The hospital figures are computed only for the people allowed to see
     * them. Guarding the markup alone would still run the queries, and a count
     * of who cannot sign in is not an employee's business even when it never
     * reaches their screen.
     *
     * @return array<string, mixed>
     */
    public function with(): array
    {
        $user = auth()->user();
        $employee = $user?->employee;
        $seesHr = $user?->canAny(['employees.view', 'leave.manage']) ?? false;
        $queue = app(ApprovalQueue::class);

        return [
            'employee' => $employee,
            'balances' => $employee ? app(LeaveBalance::class)->for($employee) : [],
            'waiting' => $queue->waitingFor($user)->limit(4)->get(),
            'waitingCount' => $queue->countFor($user),
            'recent' => $employee
                ? LeaveApplication::where('employee_id', $employee->id)
                    ->with('type')->latest('id')->limit(4)->get()
                : collect(),
            'seesHr' => $seesHr,
            'metrics' => $seesHr ? app(DashboardMetrics::class) : null,
        ];
    }
}; ?>

<section class="w-full">
    {{--
        Three boxes on this page and no more: the two charts and the rail. A
        card means "this has its own world"; when everything is a card, the
        boxes stop saying anything and the page becomes a stack of equals with
        no way to tell what matters. Everything else here sits on the page
        itself, held apart by space and one hairline.
    --}}
    <div class="grid gap-10 lg:grid-cols-3">
        <div class="space-y-10 lg:col-span-2">
            <div>
                <flux:text class="text-sm">{{ now()->format('l, j F Y') }}</flux:text>

                {{-- The opening line is the one loud thing on the page, and it
                     is a sentence rather than a figure. "Two applications need
                     your signature" is the job; "2" is trivia. --}}
                <h1 class="mt-1 text-2xl font-medium tracking-tight text-zinc-900 sm:text-3xl dark:text-white">
                    @if ($waitingCount > 0)
                        {{ trans_choice(
                            '{1} One application needs your signature.|[2,*] :count applications need your signature.',
                            $waitingCount,
                            ['count' => $waitingCount],
                        ) }}
                    @else
                        {{ __('Nothing needs your signature.') }}
                    @endif
                </h1>

                @if ($waitingCount > 0)
                    <ul class="mt-6 border-t border-zinc-200 dark:border-white/10">
                        @foreach ($waiting as $application)
                            <li wire:key="waiting-{{ $application->id }}"
                                class="flex flex-wrap items-baseline gap-x-4 gap-y-1 border-b border-zinc-200 py-3 dark:border-white/10">
                                <span class="min-w-0 flex-1 truncate font-medium text-zinc-900 dark:text-white">
                                    {{ $application->employee->fullName() }}
                                </span>
                                <span class="text-sm text-zinc-600 dark:text-zinc-400">
                                    {{ $application->type->name }}
                                </span>
                                <span class="w-20 text-right text-sm tabular-nums text-zinc-600 dark:text-zinc-400">
                                    {{ number_format($application->days, 2) }} {{ __('days') }}
                                </span>
                            </li>
                        @endforeach
                    </ul>

                    <flux:button :href="route('leave.approvals')" wire:navigate variant="primary" size="sm" class="mt-6">
                        {{ __('Review them') }}
                    </flux:button>
                @endif
            </div>

            @if ($seesHr)
                {{--
                    One fact and two tasks. The difference is carried by
                    structure — the two that can be acted on have a door under
                    them — rather than by colour, which would say "alarming"
                    when what is meant is "unfinished".
                --}}
                <div class="grid gap-8 border-t border-zinc-200 pt-8 sm:grid-cols-3 dark:border-white/10">
                    <div>
                        <div class="text-4xl font-medium text-zinc-900 dark:text-white">
                            {{ number_format($metrics->activeEmployees()) }}
                        </div>
                        <flux:text class="mt-1 text-sm">{{ __('Employees on the roster') }}</flux:text>
                    </div>

                    <div>
                        <div class="text-4xl font-medium text-zinc-900 dark:text-white">
                            {{ number_format($metrics->withoutLeaveCredits()) }}
                        </div>
                        <flux:text class="mt-1 text-sm">{{ __('Have no leave credits') }}</flux:text>

                        @can('leave.manage')
                            <flux:link :href="route('leave.accrual')" wire:navigate variant="subtle" class="mt-2 block text-sm">
                                {{ __('Post credits') }}
                            </flux:link>
                        @endcan
                    </div>

                    <div>
                        <div class="text-4xl font-medium text-zinc-900 dark:text-white">
                            {{ number_format($metrics->withoutLogin()) }}
                        </div>
                        <flux:text class="mt-1 text-sm">{{ __('Cannot sign in yet') }}</flux:text>

                        @can('issueAccount', App\Models\Employee::class)
                            <flux:link :href="route('employees.issue-account')" wire:navigate variant="subtle" class="mt-2 block text-sm">
                                {{ __('Issue a login') }}
                            </flux:link>
                        @endcan
                    </div>
                </div>

                <div class="grid gap-6 md:grid-cols-2">
                    <flux:card>
                        <flux:heading size="lg">{{ __('Where people work') }}</flux:heading>
                        <flux:subheading>{{ __('Active employees by division') }}</flux:subheading>

                        <div class="mt-6">
                            <x-charts.bars :rows="$metrics->headcountByDivision()" />
                        </div>
                    </flux:card>

                    <flux:card>
                        <flux:heading size="lg">{{ __('How they are appointed') }}</flux:heading>
                        <flux:subheading>{{ __('The whole roster, by employment status') }}</flux:subheading>

                        <div class="mt-6">
                            <x-charts.composition :rows="$metrics->headcountByStatus()" />
                        </div>
                    </flux:card>
                </div>

                <div class="border-t border-zinc-200 pt-8 dark:border-white/10">
                    <flux:heading size="lg">{{ __('Leave filed each month') }}</flux:heading>
                    <flux:subheading>{{ __('The last six months.') }}</flux:subheading>

                    <div class="mt-6">
                        @if ($metrics->hasLeaveToShow())
                            <x-charts.trend :points="$metrics->leaveTrend()" />
                        @else
                            {{-- An empty screen is an invitation, not a mood. --}}
                            <flux:text class="text-sm">
                                {{ __('Nobody has filed leave yet. The curve starts with the first application.') }}
                            </flux:text>
                        @endif
                    </div>
                </div>
            @endif

            @if ($employee && $recent->isNotEmpty())
                <div class="border-t border-zinc-200 pt-8 dark:border-white/10">
                    <div class="flex items-baseline justify-between gap-4">
                        <flux:heading size="lg">{{ __('What you have asked for') }}</flux:heading>

                        <flux:link :href="route('leave.mine')" wire:navigate variant="subtle" class="text-sm">
                            {{ __('See all') }}
                        </flux:link>
                    </div>

                    <ul class="mt-4">
                        @foreach ($recent as $application)
                            <li wire:key="recent-{{ $application->id }}"
                                class="flex flex-wrap items-baseline gap-x-4 gap-y-1 border-b border-zinc-200 py-3 text-sm dark:border-white/10">
                                <span class="min-w-0 flex-1 truncate">{{ $application->type->name }}</span>
                                <span class="text-zinc-600 dark:text-zinc-400">
                                    {{ $application->date_from->format('j M Y') }}
                                </span>
                                <span class="w-24 text-right text-zinc-600 dark:text-zinc-400">
                                    {{ $application->status->label() }}
                                </span>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif
        </div>

        {{-- One panel, not three stacked cards. The rail is a single thing you
             glance at, and hairlines are enough to separate its parts. --}}
        <aside>
            <flux:card class="lg:sticky lg:top-6">
                @if ($employee)
                    <flux:heading size="lg">{{ __('Your leave') }}</flux:heading>
                    <flux:subheading>{{ __('Anything pending is already taken out.') }}</flux:subheading>

                    <ul class="mt-4 space-y-3">
                        @forelse ($balances as $balance)
                            <li class="flex items-baseline justify-between gap-4" wire:key="balance-{{ $balance['ledger'] }}">
                                <span class="text-sm text-zinc-600 dark:text-zinc-400">{{ $balance['label'] }}</span>
                                <span class="text-lg font-medium tabular-nums text-zinc-900 dark:text-white">
                                    {{ number_format($balance['days'], 2) }}
                                </span>
                            </li>
                        @empty
                            <li>
                                <flux:text class="text-sm">
                                    {{ __('No credits recorded yet. Ask HR to open your balance.') }}
                                </flux:text>
                            </li>
                        @endforelse
                    </ul>

                    <flux:separator class="my-6" />
                @endif

                <flux:heading size="lg">{{ __('Go to') }}</flux:heading>

                <div class="mt-4 flex flex-col items-start gap-3">
                    @if ($employee)
                        <flux:link :href="route('leave.mine')" wire:navigate class="text-sm">
                            {{ __('File leave') }}
                        </flux:link>

                        <flux:link :href="route('pds.personal-information')" wire:navigate class="text-sm">
                            {{ __('My PDS') }}
                        </flux:link>
                    @endif

                    @can('viewAny', App\Models\Employee::class)
                        <flux:link :href="route('employees.index')" wire:navigate class="text-sm">
                            {{ __('Employees') }}
                        </flux:link>
                    @endcan

                    @can('leave.manage')
                        <flux:link :href="route('leave.accrual')" wire:navigate class="text-sm">
                            {{ __('Post leave credits') }}
                        </flux:link>
                    @endcan

                    @can('org.manage')
                        <flux:link :href="route('organization.divisions')" wire:navigate class="text-sm">
                            {{ __('Organization') }}
                        </flux:link>
                    @endcan
                </div>
            </flux:card>
        </aside>
    </div>

    @if ($employee)
        <div class="mt-10">
            <x-pds.completeness :employee="$employee" />
        </div>
    @endif
</section>
