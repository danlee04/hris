<?php

use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;
use Spatie\Activitylog\Models\Activity;

new #[Title('Audit log')] class extends Component {
    use WithPagination;

    public function mount(): void
    {
        abort_unless(auth()->user()->can('audit.view'), 403);
    }

    /** @return array<string, mixed> */
    public function with(): array
    {
        return [
            'activities' => Activity::query()->with('causer')->latest('id')->paginate(50),
        ];
    }
}; ?>

<section class="w-full">
    <flux:heading size="xl">{{ __('Audit log') }}</flux:heading>
    <flux:subheading>{{ __('Every change and every read, most recent first.') }}</flux:subheading>

    <flux:table class="mt-6" :paginate="$activities">
        <flux:table.columns>
            <flux:table.column>{{ __('When') }}</flux:table.column>
            <flux:table.column>{{ __('Who') }}</flux:table.column>
            <flux:table.column>{{ __('Event') }}</flux:table.column>
            <flux:table.column>{{ __('Subject') }}</flux:table.column>
            <flux:table.column>{{ __('Changed') }}</flux:table.column>
        </flux:table.columns>

        <flux:table.rows>
            @forelse ($activities as $activity)
                <flux:table.row wire:key="activity-{{ $activity->id }}">
                    <flux:table.cell class="whitespace-nowrap">
                        {{ $activity->created_at->format('Y-m-d H:i') }}
                    </flux:table.cell>
                    <flux:table.cell>{{ $activity->causer?->name ?? __('System') }}</flux:table.cell>
                    <flux:table.cell>
                        <flux:badge size="sm"
                            :color="match ($activity->event) {
                                                        'created' => 'green',
                                                        'deleted' => 'red',
                                                        'read' => 'blue',
                                                        default => 'zinc',
                                                    }">
                            {{ $activity->event }}</flux:badge>
                    </flux:table.cell>
                    <flux:table.cell class="whitespace-nowrap">
                        {{ class_basename($activity->subject_type) }} #{{ $activity->subject_id }}
                    </flux:table.cell>
                    <flux:table.cell class="text-sm">
                        @foreach ($activity->attribute_changes['attributes'] ?? [] as $field => $value)
                            <div>
                                <span class="font-medium">{{ $field }}</span>:
                                <span
                                    class="text-zinc-500 line-through">{{ $activity->attribute_changes['old'][$field] ?? '—' }}</span>
                                <span>{{ $value }}</span>
                            </div>
                        @endforeach
                    </flux:table.cell>
                </flux:table.row>
            @empty
                <flux:table.row>
                    <flux:table.cell colspan="5" class="text-center">
                        {{ __('Nothing recorded yet.') }}
                    </flux:table.cell>
                </flux:table.row>
            @endforelse
        </flux:table.rows>
    </flux:table>
</section>
