{{--
    A horizontal bar chart, one series.

    Built from divs rather than SVG: a percentage width tracks the container on
    its own, and the labels are real text the browser wraps for us. The same
    chart in SVG means measuring every string by hand to keep it from being
    clipped.

    The name sits above its bar rather than beside it. DTRC's longest division
    is "Residential/Inpatient Treatment Division" — forty-one characters — and a
    label column wide enough for that leaves no room for the bars, while a
    column narrow enough for the bars cuts every name off at "Residential/Inp".
    A full-width line costs one row of height and cuts nothing.

    One series, so there is no legend — the heading already says what is being
    counted — and every bar carries its own value, so there are no gridlines
    either. Direct labels before gridlines.
--}}
@props([
    'rows' => [],
    'empty' => null,
])

@php
    $max = collect($rows)->max('count') ?: 1;
@endphp

<div class="space-y-4">
    @forelse ($rows as $row)
        <div>
            <div class="flex items-baseline justify-between gap-3">
                <span class="text-sm text-zinc-600 dark:text-zinc-400">{{ $row['label'] }}</span>
                <span class="shrink-0 text-sm font-medium tabular-nums text-zinc-900 dark:text-zinc-100">
                    {{ number_format($row['count']) }}
                </span>
            </div>

            {{-- The track is the card's full width so every bar shares one
                 scale. A bar measured against its own row would draw five
                 divisions of identical size. --}}
            <div class="mt-1.5 h-2 w-full">
                <div
                    class="h-2 rounded-r-sm bg-[#2a78d6] dark:bg-[#3987e5]"
                    style="width: {{ max(1.5, round($row['count'] / $max * 100, 2)) }}%"
                ></div>
            </div>
        </div>
    @empty
        <flux:text class="text-sm">{{ $empty ?? __('Nothing to show yet.') }}</flux:text>
    @endforelse
</div>
