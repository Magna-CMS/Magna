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
                                    <div class="flex items-center gap-2 leading-tight">
                                        <span class="font-semibold text-gray-900 dark:text-white">{{ $p['display_name'] }}</span>
                                        @if (($p['official'] ?? false) === true)
                                            <span class="inline-flex items-center gap-1 text-[10px] font-medium px-1.5 py-0.5 rounded bg-primary-100 dark:bg-primary-900/40 text-primary-700 dark:text-primary-400 border border-primary-200 dark:border-primary-700/50 shrink-0" title="Published by a developer account the marketplace vouches for">
                                                <svg class="w-3 h-3" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M16.704 4.153a.75.75 0 01.143 1.052l-8 10.5a.75.75 0 01-1.127.075l-4.5-4.5a.75.75 0 011.06-1.06l3.894 3.893 7.48-9.817a.75.75 0 011.05-.143z" clip-rule="evenodd"/></svg>
                                                Official
                                            </span>
                                        @elseif (($p['verified'] ?? false) === true)
                                            <span class="inline-flex items-center gap-1 text-[10px] font-medium px-1.5 py-0.5 rounded bg-success-100 dark:bg-success-900/40 text-success-700 dark:text-success-400 border border-success-200 dark:border-success-700/50 shrink-0" title="Published by a developer account with a verified identity">
                                                <svg class="w-3 h-3" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M16.704 4.153a.75.75 0 01.143 1.052l-8 10.5a.75.75 0 01-1.127.075l-4.5-4.5a.75.75 0 011.06-1.06l3.894 3.893 7.48-9.817a.75.75 0 011.05-.143z" clip-rule="evenodd"/></svg>
                                                Verified
                                            </span>
                                        @endif
                                    </div>
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
