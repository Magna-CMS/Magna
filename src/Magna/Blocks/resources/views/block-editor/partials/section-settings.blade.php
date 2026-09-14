{{-- One section's settings panel. Scope from editor.blade: $si, $section. --}}
<div x-show="settingsOpen" x-collapse class="border-t border-gray-100 bg-gray-50 px-4 py-4 dark:border-white/10 dark:bg-white/5">
    <div class="grid grid-cols-2 gap-4">
        <div>
            <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">Background type</label>
            <select wire:model.live="sections.{{ $si }}.settings.background.type"
                    class="w-full rounded-md border border-gray-200 bg-white px-2.5 py-1.5 text-xs dark:border-white/10 dark:bg-white/10 dark:text-white">
                <option value="none">None</option>
                <option value="color">Solid colour</option>
                <option value="gradient">Gradient</option>
                <option value="image">Image</option>
            </select>
        </div>
        <div>
            <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">Max width</label>
            <select wire:model.live="sections.{{ $si }}.settings.maxWidth"
                    class="w-full rounded-md border border-gray-200 bg-white px-2.5 py-1.5 text-xs dark:border-white/10 dark:bg-white/10 dark:text-white">
                @foreach(['sm','md','lg','xl','2xl','full'] as $mw)
                    <option value="{{ $mw }}">{{ strtoupper($mw) }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">Anchor ID</label>
            <input type="text" wire:model.live="sections.{{ $si }}.settings.anchor"
                   placeholder="e.g. hero"
                   class="w-full rounded-md border border-gray-200 bg-white px-2.5 py-1.5 text-xs dark:border-white/10 dark:bg-white/10 dark:text-white">
        </div>
        <div>
            <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">CSS class</label>
            <input type="text" wire:model.live="sections.{{ $si }}.settings.cssClass"
                   class="w-full rounded-md border border-gray-200 bg-white px-2.5 py-1.5 text-xs dark:border-white/10 dark:bg-white/10 dark:text-white">
        </div>
    </div>

    {{-- Column layout picker --}}
    <div class="mt-4">
        <p class="text-xs font-medium text-gray-600 dark:text-gray-400 mb-2">Column layout</p>
        <div class="flex flex-wrap gap-2">
            @foreach([[12], [6,6], [4,8], [8,4], [4,4,4], [3,3,3,3], [3,6,3]] as $preset)
                <button type="button"
                        wire:click="applyColumnLayout({{ $si }}, {{ json_encode($preset) }})"
                        class="rounded border border-gray-200 bg-white px-2 py-1 text-xs hover:bg-indigo-50 dark:border-white/10 dark:bg-white/5 dark:hover:bg-indigo-950/40"
                        title="{{ implode('+', $preset) }}">
                    [{{ implode('+', $preset) }}]
                </button>
            @endforeach
        </div>
    </div>

    {{-- Token overrides --}}
    <div class="mt-4" x-data="{}">
        <div class="flex items-center justify-between mb-2">
            <p class="text-xs font-medium text-gray-600 dark:text-gray-400">Token overrides</p>
            <button type="button" wire:click="addTokenOverride({{ $si }})"
                    class="text-xs text-indigo-600 hover:underline dark:text-indigo-400">+ Add</button>
        </div>
        <p class="mb-2 text-xs text-gray-400">Override design tokens for this section only (e.g. key: <code>color-on-surface</code>  value: <code>#ffffff</code>). Changes take effect immediately in the preview.</p>
        @foreach($section['settings']['tokenOverrides'] ?? [] as $oi => $override)
            <div class="flex items-center gap-2 mb-1">
                <input type="text"
                       wire:model.live="sections.{{ $si }}.settings.tokenOverrides.{{ $oi }}.key"
                       placeholder="key (e.g. color-on-surface)"
                       class="flex-1 rounded border border-gray-200 bg-white px-2 py-1 text-xs dark:border-white/10 dark:bg-white/10 dark:text-white">
                <input type="text"
                       wire:model.live="sections.{{ $si }}.settings.tokenOverrides.{{ $oi }}.value"
                       placeholder="value (e.g. #ffffff)"
                       class="flex-1 rounded border border-gray-200 bg-white px-2 py-1 text-xs dark:border-white/10 dark:bg-white/10 dark:text-white">
                <button type="button" wire:click="removeTokenOverride({{ $si }}, {{ $oi }})" class="text-red-400 hover:text-red-600">
                    <x-heroicon-o-x-mark class="h-3.5 w-3.5"/>
                </button>
            </div>
        @endforeach
    </div>
</div>
