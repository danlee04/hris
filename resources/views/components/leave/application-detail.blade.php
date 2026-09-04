{{--
    One leave application, in full.

    What an approver needs before recommending anything: what was asked for,
    why, and who has already signed. The queue shows dates; a decision needs
    more than dates.
--}}
@props(['application'])

@php
    $labels = [
        'vacation_where' => __('Where'),
        'vacation_detail' => __('Specify'),
        'sick_where' => __('Where'),
        'sick_detail' => __('Illness'),
        'study_purpose' => __('Purpose'),
        'study_detail' => __('Other purpose'),
        'women_detail' => __('Illness'),
    ];

    $values = [
        'within_philippines' => __('Within the Philippines'),
        'abroad' => __('Abroad'),
        'in_hospital' => __('In hospital'),
        'out_patient' => __('Out patient'),
        'masters' => __("Completion of master's degree"),
        'board_review' => __('BAR / board examination review'),
    ];
@endphp

<div class="space-y-6">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <flux:heading size="lg">{{ $application->employee->fullName() }}</flux:heading>
            <flux:subheading>{{ $application->type->name }}</flux:subheading>
        </div>

        <flux:badge :color="$application->status->color()">
            {{ $application->status->label() }}
        </flux:badge>
    </div>

    <flux:separator variant="subtle" />

    <div class="grid gap-4 sm:grid-cols-3">
        <div>
            <flux:subheading>{{ __('Dates') }}</flux:subheading>
            <flux:text>
                {{ $application->date_from->format('d/m/Y') }} –
                {{ $application->date_to->format('d/m/Y') }}
            </flux:text>
        </div>

        <div>
            <flux:subheading>{{ __('Working days') }}</flux:subheading>
            <flux:text>{{ number_format($application->days, 2) }}</flux:text>
        </div>

        <div>
            {{-- The split is decided at filing, against the balance at that
                 moment. It is what item 7.C of the form prints. --}}
            <flux:subheading>{{ __('With pay / without') }}</flux:subheading>
            <flux:text>
                {{ number_format($application->days_with_pay, 2) }} /
                {{ number_format($application->days_without_pay, 2) }}
            </flux:text>
        </div>

        <div>
            <flux:subheading>{{ __('Filed') }}</flux:subheading>
            <flux:text>{{ $application->filed_at?->format('d/m/Y') ?? '—' }}</flux:text>
        </div>

        <div>
            <flux:subheading>{{ __('Commutation') }}</flux:subheading>
            <flux:text>
                {{ $application->commutation === 'requested' ? __('Requested') : __('Not requested') }}
            </flux:text>
        </div>
    </div>

    @if ($application->purpose)
        <div>
            <flux:subheading>{{ __('Purpose') }}</flux:subheading>
            <flux:text>{{ $application->purpose }}</flux:text>
        </div>
    @endif

    @if ($application->details)
        <div>
            <flux:subheading>{{ __('Details') }}</flux:subheading>

            <div class="mt-1 grid gap-2 sm:grid-cols-2">
                @foreach ($application->details as $key => $value)
                    <flux:text wire:key="detail-{{ $key }}">
                        <span class="font-medium">{{ $labels[$key] ?? $key }}:</span>
                        {{ $values[$value] ?? $value }}
                    </flux:text>
                @endforeach
            </div>
        </div>
    @endif

    <flux:separator variant="subtle" />

    <div>
        <flux:subheading>{{ __('Signatures') }}</flux:subheading>

        <div class="mt-2 space-y-2">
            @foreach ($application->approvals as $approval)
                <div wire:key="approval-{{ $approval->id }}" class="flex flex-wrap items-baseline gap-2 text-sm">
                    <span class="font-medium">{{ $approval->step->label() }}</span>

                    {{-- The HR step is held by an office, so it names the
                         person who acted rather than one appointed in advance. --}}
                    <flux:text>
                        {{ $approval->approver?->fullName() ?? $approval->actedBy?->name ?? __('Human Resource') }}
                    </flux:text>

                    @if ($approval->acted_at)
                        <flux:badge size="sm" color="zinc">
                            {{ $approval->action?->label() }} {{ $approval->acted_at->format('d/m/Y') }}
                        </flux:badge>

                        @if ($approval->remarks)
                            <flux:text class="w-full text-xs">{{ $approval->remarks }}</flux:text>
                        @endif
                    @else
                        <flux:badge size="sm" color="amber">{{ __('Waiting') }}</flux:badge>
                    @endif
                </div>
            @endforeach
        </div>
    </div>
</div>
