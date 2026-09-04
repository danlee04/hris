{{--
    The top of every PDS page: the section title, the CS Form 212 reference,
    the Download button, and the tab bar.

    The download lives here rather than at the end of the tab bar because an
    employee filling in their PDS wants the file, not the last tab. It is a
    link to the download page and not the file itself: that page carries the
    warning about empty sections, and it is the single place a PDS leaves the
    system, which is what makes the read auditable.
--}}
@props(['title', 'employee' => null, 'download' => true])

@php
    $subject = $employee
        ? App\Models\Employee::find($employee)
        : auth()->user()?->employee;
@endphp

<div>
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <flux:heading size="xl">{{ $title }}</flux:heading>
            <flux:subheading>{{ $slot }}</flux:subheading>
        </div>

        @if ($download && $subject && auth()->user()?->can('exportPds', $subject))
            <flux:button
                :href="route('pds.export', $employee ? ['employee' => $employee] : [])"
                icon="arrow-down-tray"
                variant="primary"
                size="sm"
                wire:navigate
            >
                {{ __('Download PDS') }}
            </flux:button>
        @endif
    </div>

    <x-pds.section-nav :employee="$employee" class="mt-6" />
</div>
