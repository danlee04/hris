{{--
    The nine sections of CS Form 212, with a tick on the ones that hold
    something. The list comes from PdsCompleteness so the tab bar and the
    dashboard cannot disagree about what the sections are.

    `employee` is carried through so HR keeps looking at the same person when
    they move between sections.
--}}
@props(['employee' => null])

@php
    $subject = $employee
        ? App\Models\Employee::find($employee)
        : auth()->user()?->employee;

    $sections = $subject ? app(App\Services\Pds\PdsCompleteness::class)->for($subject) : [];
    $query = $employee ? ['employee' => $employee] : [];
@endphp

<flux:navbar class="-mx-2 mb-6 overflow-x-auto">
    @foreach ($sections as $section)
        <flux:navbar.item
            :href="route($section['route'], $query)"
            :current="request()->routeIs($section['route'])"
            :icon="$section['complete'] ? 'check-circle' : null"
            wire:navigate
        >
            {{ $section['label'] }}
        </flux:navbar.item>
    @endforeach
</flux:navbar>
