{{--
    The demo-content dialog (ManagesThemeDemoContent): what removing a theme's
    demo content would take out, and what it would leave and why — opened by
    the card's "Remove demo content", by Remove on a theme whose demo is on
    the site, and by activating another theme while the active one's is.

      $installed — the page's installed-theme rows, for display names

    Labels come from the theme's bundle, so they are escaped by {{ }}.
--}}
@if ($this->demoMode !== null)
    @php
        $names = collect($installed)->pluck('display_name', 'name');
        $themeLabel = $names[$this->demoTheme] ?? $this->demoTheme;
        $targetLabel = $this->demoTarget !== null ? ($names[$this->demoTarget] ?? $this->demoTarget) : null;
        $targetHasDemo = $this->demoTarget !== null && (bool) (collect($installed)->firstWhere('name', $this->demoTarget)['has_demo'] ?? false);
        $remove = $this->demoPreview['remove'];
        $keep = $this->demoPreview['keep'];
        [$title, $confirm, $danger] = match ($this->demoMode) {
            'remove-theme' => ['Remove '.$themeLabel, 'Remove theme', true],
            'switch' => ['Switch to '.$targetLabel, 'Activate '.$targetLabel, false],
            default => ['Remove '.$themeLabel.' demo content', 'Remove demo content', true],
        };
    @endphp

    <div
        wire:key="theme-demo-dialog"
        x-on:keydown.escape.window="$wire.closeDemoDialog()"
        class="fixed inset-0 z-40 flex items-start justify-center overflow-y-auto bg-gray-950/50 px-4 py-10 backdrop-blur-sm"
        wire:click.self="closeDemoDialog"
        role="dialog"
        aria-modal="true"
        aria-labelledby="theme-demo-dialog-title"
    >
        <div class="w-full max-w-lg rounded-2xl bg-white p-5 shadow-2xl ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <h2 id="theme-demo-dialog-title" class="text-base font-semibold text-gray-900 dark:text-white">{{ $title }}</h2>

            @if ($this->demoMode === 'remove-theme')
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">This deletes the theme's files from the site.</p>
            @elseif ($this->demoMode === 'switch')
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ $themeLabel }}'s demo content is still on your site.</p>
            @endif

            @if ($this->demoMode !== 'remove-demo')
                <label class="mt-4 flex items-start gap-2 text-sm text-gray-700 dark:text-gray-200">
                    <input type="checkbox" wire:model.live="demoRemove" class="mt-0.5 rounded border-gray-300 text-primary-600 dark:border-white/20 dark:bg-white/5">
                    <span>Also remove {{ $themeLabel }}'s demo content</span>
                </label>
            @endif

            @if ($this->demoMode === 'switch' && $targetHasDemo)
                <label class="mt-2 flex items-start gap-2 text-sm text-gray-700 dark:text-gray-200">
                    <input type="checkbox" wire:model.live="demoImport" class="mt-0.5 rounded border-gray-300 text-primary-600 dark:border-white/20 dark:bg-white/5">
                    <span>Import {{ $targetLabel }}'s demo content</span>
                </label>
            @endif

            @if ($this->demoMode === 'remove-demo' || $this->demoRemove)
                <div class="mt-4 max-h-72 space-y-3 overflow-y-auto rounded-lg border border-gray-200 bg-gray-50 px-3 py-2.5 text-[12.5px] dark:border-white/10 dark:bg-white/5">
                    <div>
                        <div class="font-semibold text-gray-800 dark:text-gray-200">Removed ({{ count($remove) }})</div>
                        <p class="text-gray-500 dark:text-gray-400">Exactly as the theme shipped them. Import the demo again to bring them back.</p>
                        <ul class="mt-1 space-y-0.5">
                            @forelse ($remove as $row)
                                <li class="truncate font-mono text-[11.5px] text-gray-700 dark:text-gray-300">{{ $row['label'] }}</li>
                            @empty
                                <li class="text-gray-500 dark:text-gray-400">Nothing — none of it is on the site unchanged.</li>
                            @endforelse
                        </ul>
                    </div>
                    @if ($keep !== [])
                        <div>
                            <div class="font-semibold text-gray-800 dark:text-gray-200">Kept ({{ count($keep) }})</div>
                            <ul class="mt-1 space-y-0.5">
                                @foreach ($keep as $row)
                                    <li class="flex justify-between gap-2">
                                        <span class="truncate font-mono text-[11.5px] text-gray-700 dark:text-gray-300">{{ $row['label'] }}</span>
                                        <span class="shrink-0 text-gray-500 dark:text-gray-400">{{ $row['reason'] }}</span>
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    @endif
                </div>
            @endif

            <div class="mt-5 flex justify-end gap-2">
                <button
                    wire:click="closeDemoDialog"
                    class="rounded-lg border border-gray-300 px-3 py-1.5 text-xs font-semibold text-gray-700 transition-colors hover:bg-gray-50 dark:border-white/10 dark:text-gray-200 dark:hover:bg-white/5"
                >Cancel</button>
                <button
                    wire:click="confirmDemoDialog"
                    wire:loading.attr="disabled"
                    @disabled($this->demoMode === 'remove-demo' && $remove === [])
                    class="rounded-lg px-3 py-1.5 text-xs font-semibold text-white transition-colors disabled:opacity-50 {{ $danger ? 'bg-danger-600 hover:bg-danger-700' : 'bg-primary-600 hover:bg-primary-700' }}"
                >{{ $confirm }}</button>
            </div>
        </div>
    </div>
@endif
