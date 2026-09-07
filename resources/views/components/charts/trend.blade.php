{{--
    A curve over months.

    SVG here, where the bars are divs, because this is real geometry. The
    viewBox scales with the container and keeps its aspect ratio, so nothing is
    stretched: the 2px line stays 2px and the end dot stays round.

    The month names sit in HTML underneath rather than as SVG text. Text inside
    a scaling viewBox resizes with the drawing, and a label that shrinks below
    legibility on a narrow screen is a label that is not there. Each month owns
    an equal slice of the width and its point sits in the middle of that slice,
    so a name is always directly under its own point.
--}}
@props([
    'points' => [],
])

@php
    $width = 600;
    $top = 18;
    $bottom = 132;

    $max = collect($points)->max('count') ?: 1;
    $count = max(count($points), 1);

    $coords = [];

    foreach (array_values($points) as $index => $point) {
        $coords[] = [
            'x' => $width * ($index + 0.5) / $count,
            'y' => $bottom - ($point['count'] / $max) * ($bottom - $top),
            'point' => $point,
        ];
    }

    // Monotone, so a busy month between two quiet ones cannot bow the curve
    // below the baseline on its way there. See SmoothPath.
    $line = app(App\Services\Dashboard\SmoothPath::class)->curve($coords);

    $first = $coords[0] ?? null;
    $last = end($coords) ?: null;

    $area = $line === '' ? '' : sprintf(
        '%s L %s,%s L %s,%s Z',
        $line, round($last['x'], 2), $bottom, round($first['x'], 2), $bottom
    );

    $busiest = collect($coords)->sortByDesc(fn ($c) => $c['point']['count'])->first();
@endphp

<div>
    <svg viewBox="0 0 {{ $width }} 150" class="w-full" role="img"
         aria-label="{{ __('Leave applications filed each month') }}">
        {{-- Where zero is. Hairline and one step off the surface, so it holds
             the floor without competing with the data. --}}
        <line x1="0" y1="{{ $bottom }}" x2="{{ $width }}" y2="{{ $bottom }}"
              class="stroke-zinc-200 dark:stroke-white/10" stroke-width="1" />

        <path d="{{ $area }}" class="fill-[#2a78d6]/10 dark:fill-[#3987e5]/10" />

        <path d="{{ $line }}" fill="none" stroke-width="2"
              stroke-linejoin="round" stroke-linecap="round"
              class="stroke-[#2a78d6] dark:stroke-[#3987e5]" />

        {{-- The busiest month, and only that one. A dot on every point is
             decoration that reads as data. --}}
        @if ($busiest && $busiest['point']['count'] > 0)
            <circle cx="{{ round($busiest['x'], 2) }}" cy="{{ round($busiest['y'], 2) }}" r="4"
                    class="fill-[#2a78d6] dark:fill-[#3987e5]" />
        @endif
    </svg>

    <div class="mt-2 flex text-xs text-zinc-500 dark:text-zinc-400">
        @foreach ($points as $point)
            <span class="flex-1 text-center">{{ $point['label'] }}</span>
        @endforeach
    </div>
</div>
