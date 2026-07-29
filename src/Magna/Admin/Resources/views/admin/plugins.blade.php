<x-filament-panels::page>
{{--
    Computed variables injected by PluginsPage::getViewData() (called on every render):
      $filteredInstalled  — installed plugins after status filter + search
      $filteredAvailable  — available plugins after search
      $counts             — ['all', 'active', 'inactive', 'update'] plugin counts
      $filteredNames      — names of currently visible installed plugins
      $allSelected        — bool: every visible row is checked
--}}

{{-- ── Page header ──────────────────────────────────────────────────────────── --}}
<div class="flex items-start justify-between gap-4 mb-1">
    <div>
        <h1 class="text-2xl font-bold text-gray-900 dark:text-white">
            {{ $this->activeTab === 'installed' ? 'Plugins' : 'Add New Plugin' }}
        </h1>
        <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">
            @if ($this->activeTab === 'installed')
                Manage the plugins installed in this panel.
            @else
                Discover plugins from Composer and your local <code class="font-mono text-xs">plugins-dev/</code> directory.
            @endif
        </p>
    </div>
    <div class="flex items-center gap-2 shrink-0">
        @if ($this->activeTab === 'installed')
            <button
                wire:click="setTab('addnew')"
                class="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-lg bg-primary-600 text-white text-sm font-medium hover:bg-primary-700 shadow-sm transition-colors"
            >
                <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 5v14M5 12h14"/></svg>
                Add New Plugin
            </button>
        @else
            <button
                wire:click="setTab('installed')"
                class="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-sm font-medium text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors"
            >
                <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M15 19l-7-7 7-7"/></svg>
                Back to Installed
            </button>
        @endif
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
    >Installed Plugins</button>
    <button
        wire:click="setTab('addnew')"
        class="pb-3 border-b-2 font-semibold transition-colors
               {{ $this->activeTab === 'addnew'
                    ? 'border-primary-600 text-primary-700 dark:text-primary-400'
                    : 'border-transparent text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-200' }}"
    >Add New Plugin</button>
</div>

{{-- ═══════════════════════════════════════════════════════════════════════════
     INSTALLED PANEL
     ═══════════════════════════════════════════════════════════════════════════ --}}
@if ($this->activeTab === 'installed')

    {{-- Status filter bar + search ──────────────────────────────────────────── --}}
    <div class="flex flex-wrap items-center justify-between gap-4 mb-4">
        <div class="flex flex-wrap items-center gap-0.5 text-sm text-gray-500 dark:text-gray-400">
            @foreach (['all' => 'All', 'active' => 'Active', 'inactive' => 'Inactive', 'update' => 'Update Available'] as $key => $label)
                @if (! $loop->first)
                    <span class="px-1.5 text-gray-300 dark:text-gray-600 select-none">|</span>
                @endif
                <button
                    wire:click="setStatusFilter('{{ $key }}')"
                    class="px-1 py-0.5 rounded transition-colors hover:text-gray-900 dark:hover:text-white
                           {{ $this->statusFilter === $key ? 'font-semibold text-gray-900 dark:text-white' : '' }}"
                >
                    {{ $label }}
                    <span class="text-gray-400 dark:text-gray-500 font-normal">({{ $counts[$key] }})</span>
                </button>
            @endforeach
        </div>

        <div class="relative w-full sm:w-72">
            <svg class="w-4 h-4 text-gray-400 absolute left-3 top-1/2 -translate-y-1/2 pointer-events-none" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="7"/><line x1="20" y1="20" x2="16.2" y2="16.2"/></svg>
            <input
                wire:model.live="searchInstalled"
                type="text"
                placeholder="Search installed plugins…"
                class="w-full pl-9 pr-3 py-2 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-900 dark:text-white text-sm placeholder-gray-400 dark:placeholder-gray-500 focus:outline-none focus:ring-2 focus:ring-primary-500 focus:border-primary-500"
            >
        </div>
    </div>

    {{-- Bulk actions ──────────────────────────────────────────────────────── --}}
    <div class="flex items-center gap-2 mb-3">
        <select
            wire:model="bulkAction"
            class="text-sm border border-gray-300 dark:border-gray-600 rounded-lg px-2.5 py-1.5 bg-white dark:bg-gray-800 text-gray-700 dark:text-gray-300 focus:outline-none focus:ring-2 focus:ring-primary-500"
        >
            <option value="">Bulk actions</option>
            <option value="activate">Enable</option>
            <option value="deactivate">Disable</option>
            <option value="delete">Uninstall</option>
        </select>
        <button
            x-on:click="
                if ($wire.bulkAction === 'delete' && $wire.selectedPlugins.length > 0) {
                    if (! confirm('Uninstall ' + $wire.selectedPlugins.length + ' plugin(s)? This cannot be undone.')) return;
                }
                $wire.applyBulkAction()
            "
            class="text-sm px-3 py-1.5 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 font-medium text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors"
        >Apply</button>
        @if (count($this->selectedPlugins) > 0)
            <span class="text-xs text-gray-400 dark:text-gray-500">{{ count($this->selectedPlugins) }} selected</span>
        @endif
    </div>

    {{-- Plugins table ─────────────────────────────────────────────────────── --}}
    <div class="bg-white dark:bg-gray-900/60 rounded-xl border border-gray-200 dark:border-white/10 overflow-hidden">
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b border-gray-200 dark:border-white/10 bg-gray-50/70 dark:bg-white/[0.03] text-left text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">
                    <th class="w-10 py-3 pl-4">
                        <input
                            type="checkbox"
                            wire:click="toggleSelectAll"
                            @checked($allSelected)
                            class="rounded border-gray-300 dark:border-gray-600 text-primary-600 focus:ring-primary-500 dark:bg-gray-800"
                        >
                    </th>
                    <th class="py-3 px-2">Plugin</th>
                    <th class="py-3 px-2 w-28">Version</th>
                    <th class="py-3 px-2 w-24">Status</th>
                    <th class="py-3 px-2 pr-4 w-56 text-right">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                @forelse ($filteredInstalled as $p)
                    @php
                        $words = explode(' ', $p['display_name']);
                        $initials = strtoupper(mb_substr($words[0] ?? '', 0, 1))
                            . (isset($words[1]) ? strtoupper(mb_substr($words[1], 0, 1)) : '');
                    @endphp
                    <tr class="align-top hover:bg-gray-50/60 dark:hover:bg-white/[0.02] transition-colors">

                        {{-- Checkbox --}}
                        <td class="pl-4 py-4">
                            <input
                                type="checkbox"
                                wire:model.live="selectedPlugins"
                                value="{{ $p['name'] }}"
                                class="rounded border-gray-300 dark:border-gray-600 text-primary-600 focus:ring-primary-500 dark:bg-gray-800"
                            >
                        </td>

                        {{-- Plugin info --}}
                        <td class="py-4 px-2">
                            <div class="flex gap-3">
                                @if ($p['icon_url'] ?? null)
                                    <img src="{{ $p['icon_url'] }}" alt="" class="w-11 h-11 rounded-lg object-cover shrink-0 bg-white dark:bg-gray-800 border border-gray-100 dark:border-white/10">
                                @else
                                    <div class="w-11 h-11 rounded-lg bg-primary-50 dark:bg-primary-900/40 text-primary-700 dark:text-primary-400 flex items-center justify-center font-bold text-sm shrink-0 select-none">
                                        {{ $initials }}
                                    </div>
                                @endif
                                <div class="min-w-0">
                                    <div class="font-semibold text-gray-900 dark:text-white">{{ $p['display_name'] }}</div>
                                    @if ($p['description'])
                                        <p class="text-gray-500 dark:text-gray-400 text-[13px] mt-0.5 max-w-md leading-relaxed">{{ $p['description'] }}</p>
                                    @endif
                                    <div class="text-xs text-gray-400 dark:text-gray-500 mt-1.5">
                                        By {{ $p['author'] }}
                                        @if ($p['source'])
                                            · <span class="font-mono">{{ $p['source'] }}</span>
                                        @endif
                                    </div>

                                    {{-- Inline WP-style row actions --}}
                                    <div class="flex items-center text-xs text-gray-500 dark:text-gray-400 mt-2">
                                        @if ($p['settings_url'])
                                            <a href="{{ $p['settings_url'] }}" wire:navigate class="hover:text-primary-600 dark:hover:text-primary-400 transition-colors font-medium">Settings</a>
                                            <span class="px-1.5 text-gray-300 dark:text-gray-600 select-none">|</span>
                                        @endif
                                        @if ($p['enabled'])
                                            <button wire:click="disable('{{ $p['name'] }}')" class="hover:text-gray-900 dark:hover:text-white transition-colors">Disable</button>
                                            <span class="px-1.5 text-gray-300 dark:text-gray-600 select-none">|</span>
                                            <button wire:click="requestUninstall('{{ $p['name'] }}')" class="hover:text-red-600 transition-colors">Uninstall</button>
                                            <span class="px-1.5 text-gray-300 dark:text-gray-600 select-none">|</span>
                                            <button wire:click="requestPurge('{{ $p['name'] }}')" class="hover:text-red-600 transition-colors">Purge</button>
                                        @else
                                            <button wire:click="enable('{{ $p['name'] }}')" class="font-medium text-green-700 dark:text-green-500 hover:text-green-900 dark:hover:text-green-400 transition-colors">Enable</button>
                                            <span class="px-1.5 text-gray-300 dark:text-gray-600 select-none">|</span>
                                            <button wire:click="requestUninstall('{{ $p['name'] }}')" class="hover:text-red-600 transition-colors">Uninstall</button>
                                            <span class="px-1.5 text-gray-300 dark:text-gray-600 select-none">|</span>
                                            <button wire:click="requestPurge('{{ $p['name'] }}')" class="hover:text-red-600 transition-colors">Purge</button>
                                        @endif
                                    </div>

                                    {{-- Update available banner --}}
                                    @if ($p['update_version'])
                                        <div class="mt-2.5 inline-flex items-center gap-2 bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-700/50 text-amber-800 dark:text-amber-400 text-[12.5px] rounded-lg px-3 py-2">
                                            <svg class="w-3.5 h-3.5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 19V5M5 12l7-7 7 7"/></svg>
                                            Version {{ $p['update_version'] }} is available.
                                            <button wire:click="update('{{ $p['name'] }}')" class="font-semibold underline hover:no-underline transition-all">Update now</button>
                                        </div>
                                    @elseif ($p['license_blocked_version'] ?? null)
                                        {{-- A newer version exists that this site's licence does not
                                             cover. Deliberately not an Update button: the download
                                             would be refused. Say the true thing instead. --}}
                                        <div class="mt-2.5 inline-flex items-center gap-2 rounded-lg border border-primary-200 bg-primary-50 px-3 py-2 text-[12.5px] text-primary-800 dark:border-primary-700/50 dark:bg-primary-500/10 dark:text-primary-300">
                                            <svg class="w-3.5 h-3.5 shrink-0" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M10 1a4.5 4.5 0 00-4.5 4.5V9H5a2 2 0 00-2 2v6a2 2 0 002 2h10a2 2 0 002-2v-6a2 2 0 00-2-2h-.5V5.5A4.5 4.5 0 0010 1zm3 8V5.5a3 3 0 10-6 0V9h6z" clip-rule="evenodd"/></svg>
                                            Version {{ $p['license_blocked_version'] }} is available — your licence no longer covers updates.
                                            <a href="{{ \Magna\Admin\Pages\AccountCentrePage::getUrl() }}" class="font-semibold underline transition-all hover:no-underline">Renew</a>
                                        </div>
                                    @endif
                                </div>
                            </div>
                        </td>

                        {{-- Version --}}
                        <td class="py-4 px-2 text-gray-600 dark:text-gray-400 whitespace-nowrap align-top pt-5">
                            v{{ $p['version'] }}
                        </td>

                        {{-- Status badge --}}
                        <td class="py-4 px-2 align-top pt-5">
                            @if ($p['enabled'])
                                <span class="inline-flex items-center gap-1.5 text-xs font-medium px-2 py-1 rounded-full bg-green-50 dark:bg-green-900/20 text-green-700 dark:text-green-400">
                                    <span class="w-1.5 h-1.5 rounded-full bg-green-500"></span>Active
                                </span>
                            @else
                                <span class="inline-flex items-center gap-1.5 text-xs font-medium px-2 py-1 rounded-full bg-gray-100 dark:bg-white/5 text-gray-500 dark:text-gray-400">
                                    <span class="w-1.5 h-1.5 rounded-full bg-gray-400 dark:bg-gray-500"></span>Inactive
                                </span>
                            @endif
                        </td>

                        {{-- Action buttons --}}
                        <td class="py-4 px-2 pr-4 text-right whitespace-nowrap align-top pt-5">
                            <span class="inline-flex gap-1.5 flex-wrap justify-end">
                                @if ($p['settings_url'])
                                    <a
                                        href="{{ $p['settings_url'] }}"
                                        wire:navigate
                                        class="text-xs font-medium px-3 py-1.5 rounded-lg border border-primary-300 dark:border-primary-700 text-primary-700 dark:text-primary-400 hover:bg-primary-50 dark:hover:bg-primary-900/20 transition-colors"
                                    >Settings</a>
                                @endif
                                @if ($p['enabled'])
                                    <button
                                        wire:click="disable('{{ $p['name'] }}')"
                                        class="text-xs font-medium px-3 py-1.5 rounded-lg border border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-white/5 transition-colors"
                                    >Disable</button>
                                @else
                                    <button
                                        wire:click="enable('{{ $p['name'] }}')"
                                        class="text-xs font-medium px-3 py-1.5 rounded-lg bg-green-600 text-white hover:bg-green-700 transition-colors"
                                    >Enable</button>
                                    <button
                                        wire:click="requestUninstall('{{ $p['name'] }}')"
                                        class="text-xs font-medium px-3 py-1.5 rounded-lg border border-red-200 dark:border-red-800/50 text-red-600 dark:text-red-400 hover:bg-red-50 dark:hover:bg-red-900/20 transition-colors"
                                    >Uninstall</button>
                                @endif
                            </span>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="text-center py-16">
                            <p class="text-sm text-gray-500 dark:text-gray-400">
                                {{ $this->searchInstalled !== '' || $this->statusFilter !== 'all'
                                    ? 'No plugins match your filters.'
                                    : 'No plugins installed yet.' }}
                            </p>
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

{{-- ═══════════════════════════════════════════════════════════════════════════
     ADD NEW PANEL
     ═══════════════════════════════════════════════════════════════════════════ --}}
@else

    {{-- Install queue — one plugin installs at a time --}}
    @if (count($installQueue) > 0)
        <div wire:poll.2s="pollInstalls" class="rounded-xl border border-primary-200 dark:border-primary-500/30 bg-primary-50 dark:bg-primary-500/10 px-4 py-3 mb-6 space-y-2.5">
            @foreach ($installQueue as $pkg)
                @php $prog = $installProgress[$pkg] ?? ['state' => 'queued', 'message' => '']; @endphp
                <div class="flex items-center gap-3">
                    <svg class="w-4 h-4 shrink-0 animate-spin text-primary-600 dark:text-primary-400" viewBox="0 0 24 24" fill="none">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
                    </svg>
                    <div class="min-w-0">
                        <p class="text-sm font-medium text-primary-900 dark:text-primary-100">{{ $pkg }}</p>
                        <p class="truncate text-xs text-primary-700 dark:text-primary-300">{{ $prog['message'] ?: 'Queued…' }}</p>
                    </div>
                </div>
            @endforeach
        </div>
    @endif

    {{-- Search --}}
    <div class="relative w-full sm:w-80 mb-6">
        <svg class="w-4 h-4 text-gray-400 absolute left-3 top-1/2 -translate-y-1/2 pointer-events-none" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="7"/><line x1="20" y1="20" x2="16.2" y2="16.2"/></svg>
        <input
            wire:model.live="searchAvailable"
            type="text"
            placeholder="Search available plugins…"
            class="w-full pl-9 pr-3 py-2 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-900 dark:text-white text-sm placeholder-gray-400 dark:placeholder-gray-500 focus:outline-none focus:ring-2 focus:ring-primary-500 focus:border-primary-500"
        >
    </div>

    {{-- Plugin cards grid --}}
    @if (count($filteredAvailable) > 0)
        <div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-4">
            @foreach ($filteredAvailable as $p)
                @php
                    $words = explode(' ', $p['display_name']);
                    $initials = strtoupper(mb_substr($words[0] ?? '', 0, 1))
                        . (isset($words[1]) ? strtoupper(mb_substr($words[1], 0, 1)) : '');
                @endphp
                @php $isThirdParty = $p['source'] !== 'plugins-dev/'; @endphp
                <div class="bg-white dark:bg-gray-900/60 rounded-xl border {{ $isThirdParty ? 'border-warning-200 dark:border-warning-800/50' : 'border-gray-200 dark:border-white/10' }} p-4 flex flex-col">
                    <div class="flex gap-3 mb-3">
                        @if ($p['icon'] ?? null)
                            <img src="{{ $p['icon'] }}" alt="" class="w-11 h-11 rounded-lg object-cover shrink-0 bg-white dark:bg-gray-800 border border-gray-100 dark:border-white/10">
                        @else
                            <div class="w-11 h-11 rounded-lg {{ $isThirdParty ? 'bg-warning-50 dark:bg-warning-900/30 text-warning-700 dark:text-warning-400' : 'bg-primary-50 dark:bg-primary-900/30 text-primary-700 dark:text-primary-400' }} flex items-center justify-center font-bold text-sm shrink-0 select-none">
                                {{ $initials }}
                            </div>
                        @endif
                        <div class="min-w-0 flex-1">
                            <div class="flex items-center gap-2 leading-tight">
                                <span class="font-semibold text-gray-900 dark:text-white">{{ $p['display_name'] }}</span>
                                @if ($isThirdParty)
                                    <span class="inline-flex items-center gap-1 text-[10px] font-medium px-1.5 py-0.5 rounded bg-warning-100 dark:bg-warning-900/40 text-warning-700 dark:text-warning-400 border border-warning-200 dark:border-warning-700/50 shrink-0">
                                        <svg class="w-3 h-3" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M8.485 2.495c.673-1.167 2.357-1.167 3.03 0l6.28 10.875c.673 1.167-.17 2.625-1.516 2.625H3.72c-1.347 0-2.189-1.458-1.515-2.625L8.485 2.495zM10 5a.75.75 0 01.75.75v3.5a.75.75 0 01-1.5 0v-3.5A.75.75 0 0110 5zm0 9a1 1 0 100-2 1 1 0 000 2z" clip-rule="evenodd"/></svg>
                                        Third party
                                    </span>
                                @endif
                            </div>
                            <div class="text-xs text-gray-400 dark:text-gray-500 mt-1">By {{ $p['author'] }}</div>
                            @if ($p['website'] ?? null)
                                <a href="{{ $p['website'] }}" target="_blank" rel="noopener" class="inline-flex items-center gap-1 text-xs text-primary-600 dark:text-primary-400 hover:underline mt-0.5">
                                    <svg class="w-3 h-3 shrink-0" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.6"><path stroke-linecap="round" stroke-linejoin="round" d="M10 17a7 7 0 100-14 7 7 0 000 14zM3.5 10h13M10 3c1.8 2 2.8 4.4 2.8 7s-1 5-2.8 7c-1.8-2-2.8-4.4-2.8-7s1-5 2.8-7z"/></svg>
                                    <span class="truncate">{{ preg_replace('#^https?://(www\.)?#', '', $p['website']) }}</span>
                                </a>
                            @endif
                        </div>
                    </div>
                    @if ($p['description'])
                        <p class="text-[13px] text-gray-500 dark:text-gray-400 flex-1 leading-relaxed">{{ $p['description'] }}</p>
                    @endif
                    <div class="flex items-center gap-2 text-xs text-gray-400 dark:text-gray-500 mt-4">
                        <span>v{{ $p['version'] }}</span>
                        <span class="px-2 py-0.5 rounded-full font-mono
                            {{ $isThirdParty
                                ? 'bg-warning-50 dark:bg-warning-900/20 text-warning-700 dark:text-warning-400'
                                : 'bg-gray-100 dark:bg-white/5 text-gray-600 dark:text-gray-400' }}">
                            {{ $p['source'] }}
                        </span>
                        @if ($p['is_paid'] ?? false)
                            <span
                                class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full font-medium bg-primary-50 dark:bg-primary-500/10 text-primary-700 dark:text-primary-400"
                                title="Bought here and delivered by your licence — {{ $p['seat_limit'] ?? 1 }} domain(s) per licence."
                            >
                                <svg class="w-3 h-3" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M10 1a4.5 4.5 0 00-4.5 4.5V9H5a2 2 0 00-2 2v6a2 2 0 002 2h10a2 2 0 002-2v-6a2 2 0 00-2-2h-.5V5.5A4.5 4.5 0 0010 1zm3 8V5.5a3 3 0 10-6 0V9h6z" clip-rule="evenodd"/></svg>
                                Licensed
                            </span>
                        @endif
                        @if (! empty($p['rating']))
                            <span class="inline-flex items-center gap-1 text-amber-500" title="{{ $p['ratings_count'] }} rating(s)">
                                <svg class="w-3.5 h-3.5" viewBox="0 0 20 20" fill="currentColor"><path d="M9.05 2.93c.3-.92 1.6-.92 1.9 0l1.28 3.94a1 1 0 00.95.69h4.15c.97 0 1.37 1.24.59 1.81l-3.36 2.44a1 1 0 00-.36 1.12l1.28 3.94c.3.92-.75 1.69-1.54 1.12l-3.36-2.44a1 1 0 00-1.18 0l-3.36 2.44c-.79.57-1.84-.2-1.54-1.12l1.28-3.94a1 1 0 00-.36-1.12L2.83 9.37c-.78-.57-.38-1.81.59-1.81h4.15a1 1 0 00.95-.69l1.28-3.94z"/></svg>
                                <span class="font-semibold text-gray-500 dark:text-gray-400">{{ number_format((float) $p['rating'], 1) }}</span>
                                <span class="text-gray-400 dark:text-gray-500">({{ $p['ratings_count'] }})</span>
                            </span>
                        @endif
                    </div>
                    <div class="flex items-center justify-between mt-3 pt-3 border-t {{ $isThirdParty ? 'border-warning-100 dark:border-warning-900/30' : 'border-gray-100 dark:border-white/5' }}">
                        <div class="flex items-center gap-3 text-xs">
                            <button
                                wire:click="requestReview('{{ $p['name'] }}')"
                                class="inline-flex items-center gap-1 font-medium text-gray-500 hover:text-amber-600 dark:text-gray-400 dark:hover:text-amber-400 transition-colors"
                            >
                                <svg class="w-3.5 h-3.5" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.6"><path stroke-linecap="round" stroke-linejoin="round" d="M9.05 2.93c.3-.92 1.6-.92 1.9 0l1.28 3.94a1 1 0 00.95.69h4.15c.97 0 1.37 1.24.59 1.81l-3.36 2.44a1 1 0 00-.36 1.12l1.28 3.94c.3.92-.75 1.69-1.54 1.12l-3.36-2.44a1 1 0 00-1.18 0l-3.36 2.44c-.79.57-1.84-.2-1.54-1.12l1.28-3.94a1 1 0 00-.36-1.12L2.83 9.37c-.78-.57-.38-1.81.59-1.81h4.15a1 1 0 00.95-.69l1.28-3.94z"/></svg>
                                Review
                            </button>
                            <button
                                wire:click="requestReport('{{ $p['name'] }}')"
                                class="inline-flex items-center gap-1 font-medium text-gray-400 hover:text-danger-600 dark:text-gray-500 dark:hover:text-danger-400 transition-colors"
                            >
                                <svg class="w-3.5 h-3.5" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.6"><path stroke-linecap="round" stroke-linejoin="round" d="M4 17V4a1 1 0 011-1h9l-1.3 3.5L14 10H5a1 1 0 00-1 1z"/></svg>
                                Report
                            </button>
                        </div>
                        @if ($p['is_paid'] ?? false)
                            {{-- Paid products are bought, not pulled from Composer:
                                 the licence is what fetches the bytes. --}}
                            @php
                                $symbol = ['INR' => '₹', 'USD' => '$', 'EUR' => '€', 'GBP' => '£'][$p['currency'] ?? 'INR'] ?? (($p['currency'] ?? '').' ');
                            @endphp
                            <div class="flex items-center gap-2">
                                @if ($p['trial_enabled'] ?? false)
                                    <button
                                        wire:click="startTrial('{{ $p['name'] }}')"
                                        wire:loading.attr="disabled"
                                        class="text-xs font-semibold px-3 py-1.5 rounded-lg border border-gray-300 dark:border-white/10 text-gray-700 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-white/5 transition-colors disabled:opacity-60"
                                    >Try {{ $p['trial_days'] ?? 14 }} days</button>
                                @endif
                                @foreach ($p['prices'] ?? [] as $term => $price)
                                    <button
                                        wire:click="buy('{{ $p['name'] }}', '{{ $term }}')"
                                        wire:loading.attr="disabled"
                                        title="{{ $term === 'annual' ? 'Yearly licence — renews once a year' : 'One-time payment, updates for life' }}"
                                        class="text-xs font-semibold px-3 py-1.5 rounded-lg bg-primary-600 text-white hover:bg-primary-700 transition-colors disabled:opacity-60"
                                    >
                                        {{ $symbol }}{{ number_format(((int) $price) / 100, ((int) $price) % 100 === 0 ? 0 : 2) }}{{ $term === 'annual' ? '/yr' : '' }}
                                    </button>
                                @endforeach
                            </div>
                        @else
                            <button
                                wire:click="requestInstall('{{ $p['name'] }}')"
                                class="text-xs font-semibold px-3 py-1.5 rounded-lg transition-colors
                                    {{ $isThirdParty
                                        ? 'bg-warning-500 text-white hover:bg-warning-600'
                                        : 'bg-primary-600 text-white hover:bg-primary-700' }}"
                            >Install</button>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>
    @else
        <div class="text-center py-16 bg-white dark:bg-gray-900/40 rounded-xl border border-dashed border-gray-300 dark:border-white/10">
            @if ($this->searchAvailable === '' && $this->marketplaceUnreachable)
                <div class="w-12 h-12 rounded-xl bg-warning-100 dark:bg-warning-500/10 flex items-center justify-center mx-auto mb-3">
                    <svg class="w-6 h-6 text-warning-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 11-18 0 9 9 0 0118 0zm-9 3.75h.008v.008H12v-.008z"/></svg>
                </div>
                <p class="text-sm font-medium text-gray-700 dark:text-gray-300">Couldn't reach the plugin marketplace</p>
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">The connection may be temporary — try again in a moment.</p>
                <button type="button" wire:click="refreshPlugins" class="mt-4 inline-flex items-center gap-1.5 rounded-lg border border-gray-300 dark:border-white/10 px-3 py-1.5 text-xs font-semibold text-gray-700 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-white/5">
                    <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0l3.181 3.183a8.25 8.25 0 0013.803-3.7M4.031 9.865a8.25 8.25 0 0113.803-3.7l3.181 3.182m0-4.991v4.99"/></svg>
                    Refresh
                </button>
            @else
                <div class="w-12 h-12 rounded-xl bg-gray-100 dark:bg-white/5 flex items-center justify-center mx-auto mb-3">
                    <svg class="w-6 h-6 text-gray-400 dark:text-gray-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M9 3v3M15 3v3M6 8h1a2 2 0 0 1 2 2 2 2 0 1 0 4 0 2 2 0 0 1 2-2h1v4h-1a2 2 0 0 0-2 2 2 2 0 1 1-4 0 2 2 0 0 0-2-2H6V8z"/></svg>
                </div>
                <p class="text-sm text-gray-500 dark:text-gray-400">
                    @if ($this->searchAvailable !== '')
                        No plugins match your search.
                    @else
                        No plugins are available in the marketplace yet. Check back soon.
                    @endif
                </p>
            @endif
        </div>
    @endif

@endif

@include('magna::admin.partials.checkout')

<x-filament-actions::modals />

</x-filament-panels::page>
