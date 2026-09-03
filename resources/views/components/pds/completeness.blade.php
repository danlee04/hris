{{--
    The only thing that tells an employee their PDS is unfinished. There is no
    approval gate in this design, so nobody else will.
--}}
@props(['employee'])

@php
    $sections = app(App\Services\Pds\PdsCompleteness::class)->for($employee);
    $done = collect($sections)->where('complete', true)->count();
    $total = count($sections);
@endphp

<flux:card>
    <div class="flex items-start justify-between gap-4">
        <div>
            <flux:heading size="lg">{{ __('Your Personal Data Sheet') }}</flux:heading>
            <flux:subheading>
                {{ __(':done of :total sections started', ['done' => $done, 'total' => $total]) }}
            </flux:subheading>
        </div>

        @if ($done === $total)
            <flux:badge color="green">{{ __('All sections started') }}</flux:badge>
        @endif
    </div>

    <ul class="mt-4 grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
        @foreach ($sections as $section)
            <li>
                <flux:link :href="route($section['route'])" wire:navigate class="flex items-center gap-2 text-sm">
                    @if ($section['complete'])
                        <flux:icon.check-circle variant="mini" class="text-green-600 dark:text-green-400" />
                    @else
                        <flux:icon.minus-circle variant="mini" class="text-zinc-400" />
                    @endif
                    {{ $section['label'] }}
                </flux:link>
            </li>
        @endforeach
    </ul>
</flux:card>
