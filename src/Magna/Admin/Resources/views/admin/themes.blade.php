<x-filament-panels::page>
{{--
    Injected by ThemesPage::getViewData():
      $installed   — themes on disk, with the active one flagged and any
                     newer check-in version (update_version /
                     license_blocked_version) attached
      $addons      — theme addons on disk, same update fields
      $available   — marketplace themes not yet installed
      $activeName  — vendor/name of the active theme, or null
--}}

{{-- ── Page header ──────────────────────────────────────────────────────────── --}}
<div class="flex items-start justify-between gap-4 mb-1">
    <div>
        <h1 class="text-2xl font-bold text-gray-900 dark:text-white">Themes</h1>
        <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">
            Magna is API-first: the active theme is published through the Delivery API for whatever renders your site.
        </p>
    </div>
    <div class="flex items-center gap-2 shrink-0">
        {{-- Re-fetches the hour-cached catalog AND runs an update check-in:
             the "Update now" banner below reads update_checks rows the
             scheduled check-in may not have written yet. --}}
        <button
            wire:click="refreshCatalog(true)"
            wire:loading.attr="disabled"
            wire:target="refreshCatalog"
            class="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg border border-gray-300 dark:border-white/10 bg-white dark:bg-gray-800 text-sm font-medium text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-white/5 transition-colors disabled:opacity-60"
        >
            <svg class="w-4 h-4" wire:loading.class="animate-spin" wire:target="refreshCatalog" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0l3.181 3.183a8.25 8.25 0 0013.803-3.7M4.031 9.865a8.25 8.25 0 0113.803-3.7l3.181 3.182m0-4.991v4.99"/></svg>
            <span wire:loading.remove wire:target="refreshCatalog">Check for updates</span>
            <span wire:loading wire:target="refreshCatalog">Checking…</span>
        </button>
    </div>
</div>

{{-- ── Tabs ─────────────────────────────────────────────────────────────────── --}}
<div class="flex gap-6 border-b border-gray-200 dark:border-white/10 mt-6 mb-6 text-sm">
    <button
        wire:click="setTab('installed')"
        class="pb-3 border-b-2 font-semibold transition-colors
               {{ $this->activeTab === 'installed'
                    ? 'border-primary-600 text-primary-700 dark:text-primary-400'
                    : 'border-transparent text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-200' }}"
    >Installed ({{ count($installed) }})</button>
    <button
        wire:click="setTab('browse')"
        class="pb-3 border-b-2 font-semibold transition-colors
               {{ $this->activeTab === 'browse'
                    ? 'border-primary-600 text-primary-700 dark:text-primary-400'
                    : 'border-transparent text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-200' }}"
    >Browse marketplace</button>
</div>

@if ($this->activeTab === 'installed')

    @if (count($installed) > 0)
        <div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-4">
            @foreach ($installed as $theme)
                <div class="bg-white dark:bg-gray-900/60 rounded-xl border {{ $theme['active'] ? 'border-primary-300 dark:border-primary-500/40' : 'border-gray-200 dark:border-white/10' }} p-4 flex flex-col">
                    <div class="flex items-start justify-between gap-2">
                        <div class="min-w-0">
                            <div class="font-semibold text-gray-900 dark:text-white">{{ $theme['display_name'] }}</div>
                            <div class="text-xs text-gray-400 dark:text-gray-500 mt-0.5">
                                {{ $theme['version_label'] }}@if ($theme['author'] !== '') · {{ $theme['author'] }}@endif
                            </div>
                        </div>
                        @if ($theme['active'])
                            <span class="shrink-0 rounded-full bg-primary-50 dark:bg-primary-500/10 px-2 py-0.5 text-[11px] font-medium text-primary-700 dark:text-primary-400">Active</span>
                        @endif
                    </div>

                    @if ($theme['description'] !== '')
                        <p class="text-[13px] text-gray-500 dark:text-gray-400 flex-1 leading-relaxed mt-3">{{ $theme['description'] }}</p>
                    @endif

                    @if ($theme['tags'] !== [])
                        <div class="flex flex-wrap gap-1 mt-3">
                            @foreach ($theme['tags'] as $tag)
                                <span class="rounded bg-gray-100 dark:bg-white/5 px-1.5 py-0.5 text-[11px] text-gray-600 dark:text-gray-400">{{ $tag }}</span>
                            @endforeach
                        </div>
                    @endif

                    {{-- Update available banner — same split as the Plugins page --}}
                    @if ($theme['update_version'])
                        <div class="mt-3 inline-flex items-center gap-2 bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-700/50 text-amber-800 dark:text-amber-400 text-[12.5px] rounded-lg px-3 py-2">
                            <svg class="w-3.5 h-3.5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 19V5M5 12l7-7 7 7"/></svg>
                            Version {{ $theme['update_version'] }} is available.
                            <button wire:click="update('{{ $theme['name'] }}')" wire:loading.attr="disabled" class="font-semibold underline hover:no-underline transition-all">Update now</button>
                        </div>
                    @elseif ($theme['license_blocked_version'])
                        {{-- A newer version exists that this site's licence does not
                             cover. Deliberately not an Update button: the download
                             would be refused. Say the true thing instead. --}}
                        <div class="mt-3 inline-flex items-center gap-2 rounded-lg border border-primary-200 bg-primary-50 px-3 py-2 text-[12.5px] text-primary-800 dark:border-primary-700/50 dark:bg-primary-500/10 dark:text-primary-300">
                            <svg class="w-3.5 h-3.5 shrink-0" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M10 1a4.5 4.5 0 00-4.5 4.5V9H5a2 2 0 00-2 2v6a2 2 0 002 2h10a2 2 0 002-2v-6a2 2 0 00-2-2h-.5V5.5A4.5 4.5 0 0010 1zm3 8V5.5a3 3 0 10-6 0V9h6z" clip-rule="evenodd"/></svg>
                            Version {{ $theme['license_blocked_version'] }} is available — your licence no longer covers updates.
                            <a href="{{ \Magna\Admin\Pages\AccountCentrePage::getUrl() }}" class="font-semibold underline transition-all hover:no-underline">Renew</a>
                        </div>
                    @endif

                    <div class="flex items-center justify-between mt-4 pt-3 border-t border-gray-100 dark:border-white/5">
                        <span class="font-mono text-[11px] text-gray-400 dark:text-gray-500">{{ $theme['name'] }}</span>
                        <div class="flex items-center gap-3">
                            <button
                                wire:click="remove('{{ $theme['name'] }}')"
                                wire:confirm="Delete this theme's files from the site?"
                                class="text-xs font-semibold text-gray-500 hover:text-danger-600 dark:text-gray-400 dark:hover:text-danger-400 transition-colors"
                            >Remove</button>
                            @if ($theme['active'])
                                <button
                                    wire:click="deactivate"
                                    class="text-xs font-semibold px-3 py-1.5 rounded-lg border border-gray-300 dark:border-white/10 text-gray-700 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-white/5 transition-colors"
                                >Deactivate</button>
                            @else
                                <button
                                    wire:click="activate('{{ $theme['name'] }}')"
                                    class="text-xs font-semibold px-3 py-1.5 rounded-lg bg-primary-600 text-white hover:bg-primary-700 transition-colors"
                                >Activate</button>
                            @endif
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
    @else
        <div class="text-center py-16 bg-white dark:bg-gray-900/40 rounded-xl border border-dashed border-gray-300 dark:border-white/10">
            <div class="w-12 h-12 rounded-xl bg-gray-100 dark:bg-white/5 flex items-center justify-center mx-auto mb-3">
                <svg class="w-6 h-6 text-gray-400 dark:text-gray-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M4.098 19.902a3.75 3.75 0 005.304 0l6.401-6.402M6.75 21A3.75 3.75 0 013 17.25V4.125C3 3.504 3.504 3 4.125 3h5.25c.621 0 1.125.504 1.125 1.125v4.072M6.75 21a3.75 3.75 0 003.75-3.75V8.197"/></svg>
            </div>
            <p class="text-sm font-medium text-gray-700 dark:text-gray-300">No themes installed</p>
            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">Browse the marketplace to add one, or drop a theme into <code class="font-mono">themes/vendor/name</code>.</p>
        </div>
    @endif

    @if (count($addons) > 0)
        <div class="mt-8">
            <h2 class="text-sm font-semibold text-gray-900 dark:text-white">Theme addons</h2>
            <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">Addons style a paired plugin's blocks inside their host theme. They apply automatically — no activation.</p>

            <div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-4 mt-3">
                @foreach ($addons as $addon)
                    <div class="bg-white dark:bg-gray-900/60 rounded-xl border {{ $addon['applies'] ? 'border-primary-300 dark:border-primary-500/40' : 'border-gray-200 dark:border-white/10' }} p-4 flex flex-col">
                        <div class="flex items-start justify-between gap-2">
                            <div class="min-w-0">
                                <div class="font-semibold text-gray-900 dark:text-white">{{ $addon['display_name'] }}</div>
                                <div class="text-xs text-gray-400 dark:text-gray-500 mt-0.5">{{ $addon['version_label'] }}</div>
                            </div>
                            @if ($addon['applies'])
                                <span class="shrink-0 rounded-full bg-primary-50 dark:bg-primary-500/10 px-2 py-0.5 text-[11px] font-medium text-primary-700 dark:text-primary-400">Applies</span>
                            @else
                                <span class="shrink-0 rounded-full bg-gray-100 dark:bg-white/5 px-2 py-0.5 text-[11px] font-medium text-gray-500 dark:text-gray-400" title="Its host theme is not active">Waiting for {{ $addon['extends'] }}</span>
                            @endif
                        </div>

                        @if ($addon['description'] !== '')
                            <p class="text-[13px] text-gray-500 dark:text-gray-400 flex-1 leading-relaxed mt-3">{{ $addon['description'] }}</p>
                        @endif

                        <div class="flex flex-wrap gap-1 mt-3">
                            <span class="rounded bg-gray-100 dark:bg-white/5 px-1.5 py-0.5 text-[11px] text-gray-600 dark:text-gray-400">{{ $addon['extends'] === '*' ? 'Any theme' : 'For '.$addon['extends'] }}</span>
                            @foreach ($addon['pairs_with'] as $plugin)
                                <span class="rounded bg-gray-100 dark:bg-white/5 px-1.5 py-0.5 text-[11px] text-gray-600 dark:text-gray-400">Styles {{ $plugin }}</span>
                            @endforeach
                        </div>

                        @if ($addon['update_version'])
                            <div class="mt-3 inline-flex items-center gap-2 bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-700/50 text-amber-800 dark:text-amber-400 text-[12.5px] rounded-lg px-3 py-2">
                                <svg class="w-3.5 h-3.5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 19V5M5 12l7-7 7 7"/></svg>
                                Version {{ $addon['update_version'] }} is available.
                                <button wire:click="update('{{ $addon['name'] }}')" wire:loading.attr="disabled" class="font-semibold underline hover:no-underline transition-all">Update now</button>
                            </div>
                        @elseif ($addon['license_blocked_version'])
                            <div class="mt-3 inline-flex items-center gap-2 rounded-lg border border-primary-200 bg-primary-50 px-3 py-2 text-[12.5px] text-primary-800 dark:border-primary-700/50 dark:bg-primary-500/10 dark:text-primary-300">
                                <svg class="w-3.5 h-3.5 shrink-0" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M10 1a4.5 4.5 0 00-4.5 4.5V9H5a2 2 0 00-2 2v6a2 2 0 002 2h10a2 2 0 002-2v-6a2 2 0 00-2-2h-.5V5.5A4.5 4.5 0 0010 1zm3 8V5.5a3 3 0 10-6 0V9h6z" clip-rule="evenodd"/></svg>
                                Version {{ $addon['license_blocked_version'] }} is available — your licence no longer covers updates.
                                <a href="{{ \Magna\Admin\Pages\AccountCentrePage::getUrl() }}" class="font-semibold underline transition-all hover:no-underline">Renew</a>
                            </div>
                        @endif

                        <div class="flex items-center justify-between mt-4 pt-3 border-t border-gray-100 dark:border-white/5">
                            <span class="font-mono text-[11px] text-gray-400 dark:text-gray-500">{{ $addon['name'] }}</span>
                            <button
                                wire:click="remove('{{ $addon['name'] }}')"
                                wire:confirm="Delete this addon's files from the site?"
                                class="text-xs font-semibold text-gray-500 hover:text-danger-600 dark:text-gray-400 dark:hover:text-danger-400 transition-colors"
                            >Remove</button>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    @endif

@else

    @if (count($available) > 0)
        <div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-4">
            @foreach ($available as $t)
                @php
                    $symbol = ['INR' => '₹', 'USD' => '$', 'EUR' => '€', 'GBP' => '£'][$t['currency'] ?? 'INR'] ?? (($t['currency'] ?? '').' ');
                @endphp
                <div class="bg-white dark:bg-gray-900/60 rounded-xl border border-gray-200 dark:border-white/10 p-4 flex flex-col">
                    <div class="flex gap-3">
                        @if ($t['icon'] ?? null)
                            <img src="{{ $t['icon'] }}" alt="" class="w-11 h-11 rounded-lg object-cover shrink-0 border border-gray-100 dark:border-white/10">
                        @endif
                        <div class="min-w-0">
                            <div class="font-semibold text-gray-900 dark:text-white">{{ $t['display_name'] }}</div>
                            <div class="text-xs text-gray-400 dark:text-gray-500 mt-0.5">{{ $t['version_label'] ?? '' }}@if ($t['author'] !== '') · {{ $t['author'] }}@endif</div>
                        </div>
                    </div>

                    @if ($t['description'] !== '')
                        <p class="text-[13px] text-gray-500 dark:text-gray-400 flex-1 leading-relaxed mt-3">{{ $t['description'] }}</p>
                    @endif

                    <div class="flex items-center justify-end gap-2 mt-4 pt-3 border-t border-gray-100 dark:border-white/5">
                        @if ($t['is_paid'])
                            @if ($t['trial_enabled'])
                                <button
                                    wire:click="startTrial('{{ $t['name'] }}')"
                                    wire:loading.attr="disabled"
                                    class="text-xs font-semibold px-3 py-1.5 rounded-lg border border-gray-300 dark:border-white/10 text-gray-700 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-white/5 transition-colors disabled:opacity-60"
                                >Try {{ $t['trial_days'] ?? 14 }} days</button>
                            @endif
                            <span class="contents" x-data="{ autoRenew: false }">
                                @foreach ($t['prices'] ?? [] as $term => $price)
                                    <button
                                        @if ($term === 'annual')
                                            x-on:click="$wire.buy('{{ $t['name'] }}', 'annual', autoRenew)"
                                        @else
                                            wire:click="buy('{{ $t['name'] }}', '{{ $term }}')"
                                        @endif
                                        wire:loading.attr="disabled"
                                        class="text-xs font-semibold px-3 py-1.5 rounded-lg bg-primary-600 text-white hover:bg-primary-700 transition-colors disabled:opacity-60"
                                    >
                                        {{ $symbol }}{{ number_format(((int) $price) / 100, ((int) $price) % 100 === 0 ? 0 : 2) }}{{ $term === 'annual' ? '/yr' : '' }}
                                    </button>
                                @endforeach
                                @if (isset(($t['prices'] ?? [])['annual']))
                                    <label class="flex items-center gap-1.5 text-[11px] text-gray-500 dark:text-gray-400 cursor-pointer select-none">
                                        <input type="checkbox" x-model="autoRenew" class="h-3 w-3 rounded border-gray-300 dark:border-white/20">
                                        Auto-renew
                                    </label>
                                @endif
                            </span>
                        @else
                            @if (($t['claimable'] ?? null) === true)
                                <span class="text-xs text-gray-400 dark:text-gray-500 mr-auto">Free</span>
                                <button
                                    wire:click="getFree('{{ $t['name'] }}')"
                                    wire:loading.attr="disabled"
                                    class="text-xs font-semibold px-3 py-1.5 rounded-lg bg-primary-600 text-white hover:bg-primary-700 transition-colors disabled:opacity-60"
                                >Get &amp; install</button>
                            @elseif (($t['claimable'] ?? null) === false)
                                <span class="text-xs text-gray-400 dark:text-gray-500">Free — not available to install yet</span>
                            @else
                                {{-- A marketplace that predates free-theme claims. --}}
                                <span class="text-xs text-gray-400 dark:text-gray-500">Free — install from your Magna Account</span>
                            @endif
                        @endif
                    </div>
                </div>
            @endforeach
        </div>
    @else
        <div class="text-center py-16 bg-white dark:bg-gray-900/40 rounded-xl border border-dashed border-gray-300 dark:border-white/10">
            @if ($this->marketplaceUnreachable)
                <p class="text-sm font-medium text-gray-700 dark:text-gray-300">Couldn't reach the marketplace</p>
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">The connection may be temporary — try refreshing in a moment.</p>
            @else
                <p class="text-sm text-gray-500 dark:text-gray-400">No themes are available yet. Check back soon.</p>
            @endif
        </div>
    @endif

@endif

@include('magna::admin.partials.checkout')

<x-filament-actions::modals />

</x-filament-panels::page>
