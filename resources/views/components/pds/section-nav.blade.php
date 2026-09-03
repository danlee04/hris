{{--
    The nine sections of CS Form 212. Entries appear here as each section is
    built; Task 8 of the Phase 1b plan adds the completeness state to them.

    `employee` is carried through so HR keeps looking at the same person when
    they move between sections.
--}}
@props(['employee' => null])

@php
    $sections = [
        'pds.personal-information' => __('Personal information'),
        'pds.family-background' => __('Family background'),
    ];

    $query = $employee ? ['employee' => $employee] : [];
@endphp

<flux:navbar class="mb-6 -mx-2 overflow-x-auto">
    @foreach ($sections as $route => $label)
        <flux:navbar.item
            :href="route($route, $query)"
            :current="request()->routeIs($route)"
            wire:navigate
        >
            {{ $label }}
        </flux:navbar.item>
    @endforeach
</flux:navbar>
