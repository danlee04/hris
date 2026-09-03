@props(['heading' => null, 'addLabel' => __('Add row')])

<div class="space-y-4">
    @if ($heading)
        <flux:heading size="lg">{{ $heading }}</flux:heading>
    @endif

    <div class="space-y-3">
        {{ $slot }}
    </div>

    <flux:button type="button" wire:click="addRow" variant="subtle" icon="plus" size="sm">
        {{ $addLabel }}
    </flux:button>
</div>
