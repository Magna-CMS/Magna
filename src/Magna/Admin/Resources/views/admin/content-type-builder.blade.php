<x-filament-panels::page>
    {{-- Existing content types list --}}
    @php
        $types = $this->contentTypeRecords();
    @endphp

    @if ($types->isNotEmpty())
        <x-filament::section>
            <x-slot name="heading">Content Types</x-slot>
            <div class="divide-y divide-gray-100 dark:divide-white/5">
                @foreach ($types as $type)
                    <div class="flex items-center justify-between gap-4 py-3 first:pt-0 last:pb-0">
                        <div>
                            <span class="text-sm font-medium text-gray-900 dark:text-white">{{ $type->display_name }}</span>
                            <span class="ml-2 font-mono text-xs text-gray-400">{{ $type->handle }}</span>
                        </div>
                        <x-filament::button
                            size="sm"
                            color="gray"
                            :href="route('filament.magna.pages.content-type-builder') . '?edit=' . $type->handle"
                            tag="a"
                        >
                            Edit
                        </x-filament::button>
                    </div>
                @endforeach
            </div>
        </x-filament::section>
    @endif

    {{-- Type builder form --}}
    <x-filament::section>
        <x-slot name="heading">{{ $editHandle ? 'Edit: ' . $editHandle : 'New Content Type' }}</x-slot>
        {{ $this->form }}
    </x-filament::section>

    {{-- Diff confirmation panel --}}
    @if ($showDiffConfirm && $pendingDiff !== null)
        <x-filament::section>
            <x-slot name="heading">Pending Schema Changes</x-slot>

            @if ($pendingDiff->isEmpty())
                <p class="text-sm text-gray-500 dark:text-gray-400">No changes detected.</p>
            @else
                <ul class="space-y-1.5">
                    @foreach ($pendingDiff->changes as $change)
                        <li class="flex items-start gap-2 text-sm">
                            @if ($change->destructive)
                                <x-filament::badge color="danger" size="sm">DESTRUCTIVE</x-filament::badge>
                            @else
                                <x-filament::badge color="success" size="sm">safe</x-filament::badge>
                            @endif
                            <span class="text-gray-700 dark:text-gray-300">{{ $change->description }}</span>
                        </li>
                    @endforeach
                </ul>

                @if ($pendingDiff->hasDestructive())
                    <div class="mt-4 rounded-lg border border-danger-200 bg-danger-50 p-4 dark:border-danger-800 dark:bg-danger-950/30">
                        <p class="text-sm font-medium text-danger-700 dark:text-danger-400">
                            Destructive changes detected. These will permanently delete column data.
                        </p>
                        <label class="mt-3 flex cursor-pointer items-center gap-2 text-sm text-danger-700 dark:text-danger-400">
                            <input
                                type="checkbox"
                                wire:model="allowDestructive"
                                class="rounded border-danger-300 text-danger-600"
                            >
                            I understand — allow destructive changes
                        </label>
                    </div>
                @endif

                <div class="mt-4 flex items-center gap-3">
                    <x-filament::button
                        wire:click="applyDiff"
                        color="primary"
                        :disabled="$pendingDiff->hasDestructive() && ! $allowDestructive"
                    >
                        Apply changes
                    </x-filament::button>

                    <x-filament::button
                        wire:click="cancelDiff"
                        color="gray"
                    >
                        Cancel
                    </x-filament::button>
                </div>
            @endif
        </x-filament::section>
    @endif
</x-filament-panels::page>
