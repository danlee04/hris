{{--
    One stacked bar: the parts of a whole.

    The segments are separated by a 2px gap in the surface colour rather than by
    a border. A border is ink that is not data, and beside a two-person segment
    it would be most of what you see.

    Nothing is labelled inside a segment. Contract of Service is two people out
    of 134 — a little over one percent of the bar — and a label written into
    that space would be clipped or would spill over its neighbour. The legend
    carries every name and every number instead, which is also what is left for
    a reader who cannot tell the colours apart.
--}}
@props([
    'rows' => [],
])

@php
    $total = collect($rows)->sum('count') ?: 1;

    // Slots 1-3 of the validated categorical order — a fixed order, never a
    // cycle. A fourth slot would put yellow beside orange, and that pair fails
    // the separation check for readers with colour blindness. Four employment
    // statuses exist in the enum, but only three are ever occupied at DTRC; if
    // a fourth fills up, this needs a different form, not a fourth colour.
    $slots = [
        ['#2a78d6', '#3987e5'],
        ['#eb6834', '#d95926'],
        ['#1baf7a', '#199e70'],
    ];
@endphp

<div>
    <div class="flex h-4 w-full gap-[2px]">
        @foreach ($rows as $index => $row)
            <div
                data-viz-fill
                class="h-4 first:rounded-l-sm last:rounded-r-sm"
                style="width: {{ round($row['count'] / $total * 100, 2) }}%; --viz-light: {{ $slots[$index % 3][0] }}; --viz-dark: {{ $slots[$index % 3][1] }}"
                title="{{ $row['label'] }}: {{ number_format($row['count']) }}"
            ></div>
        @endforeach
    </div>

    {{-- The legend is not decoration and must not be removed.

         A legend is always present for two series or more, because it is the
         one channel that does not depend on telling two colours apart. This one
         carries a second duty: on the card's own background the orange segment
         measures 2.88:1, just under the 3:1 a fill needs to stand on its own,
         and a visible label beside every value is what makes that legal. Take
         the legend away and the chart stops being readable for anyone who has
         to squint at it. --}}
    <ul class="mt-4 grid gap-2 sm:grid-cols-2">
        @foreach ($rows as $index => $row)
            <li class="flex items-center gap-2 text-sm">
                <span
                    data-viz-fill
                    class="size-2.5 shrink-0 rounded-full"
                    style="--viz-light: {{ $slots[$index % 3][0] }}; --viz-dark: {{ $slots[$index % 3][1] }}"
                    aria-hidden="true"
                ></span>
                <span class="text-zinc-600 dark:text-zinc-400">{{ $row['label'] }}</span>
                <span class="ml-auto font-medium tabular-nums text-zinc-900 dark:text-zinc-100">
                    {{ number_format($row['count']) }}
                </span>
            </li>
        @endforeach
    </ul>
</div>
