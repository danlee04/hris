{{--
    The three tables the employee master points at. They sit together because
    they are edited together: a new section usually arrives with the position
    that goes in it.
--}}
<flux:navbar class="-mx-2 mb-6 overflow-x-auto">
    <flux:navbar.item
        :href="route('organization.divisions')"
        :current="request()->routeIs('organization.divisions')"
        wire:navigate
    >
        {{ __('Divisions') }}
    </flux:navbar.item>

    <flux:navbar.item
        :href="route('organization.sections')"
        :current="request()->routeIs('organization.sections')"
        wire:navigate
    >
        {{ __('Sections') }}
    </flux:navbar.item>

    <flux:navbar.item
        :href="route('organization.positions')"
        :current="request()->routeIs('organization.positions')"
        wire:navigate
    >
        {{ __('Positions') }}
    </flux:navbar.item>
</flux:navbar>
