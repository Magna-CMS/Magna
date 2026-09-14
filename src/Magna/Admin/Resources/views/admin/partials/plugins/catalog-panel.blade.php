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
                @php
                    // An "official" publisher is one the marketplace itself
                    // vouches for, so its listings are not third-party even
                    // though they install through Composer like any other.
                    $isOfficial = ($p['official'] ?? false) === true;
                    $isVerified = ($p['verified'] ?? false) === true;
                    $isThirdParty = ! $isOfficial && $p['source'] !== 'plugins-dev/';
                @endphp
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
                                @if ($isOfficial)
                                    <span class="inline-flex items-center gap-1 text-[10px] font-medium px-1.5 py-0.5 rounded bg-primary-100 dark:bg-primary-900/40 text-primary-700 dark:text-primary-400 border border-primary-200 dark:border-primary-700/50 shrink-0">
                                        <svg class="w-3 h-3" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M8.603 3.799A4.49 4.49 0 0112 2.25c1.357 0 2.573.6 3.397 1.549a4.49 4.49 0 013.498 1.307 4.491 4.491 0 011.307 3.497A4.49 4.49 0 0121.75 12a4.49 4.49 0 01-1.549 3.397 4.491 4.491 0 01-1.307 3.497 4.491 4.491 0 01-3.497 1.307A4.49 4.49 0 0112 21.75a4.49 4.49 0 01-3.397-1.549 4.49 4.49 0 01-3.498-1.306 4.491 4.491 0 01-1.307-3.498A4.49 4.49 0 012.25 12c0-1.357.6-2.573 1.549-3.397a4.49 4.49 0 011.307-3.497 4.49 4.49 0 013.497-1.307zm7.007 6.387a.75.75 0 10-1.22-.872l-3.236 4.53L9.53 12.22a.75.75 0 00-1.06 1.06l2.25 2.25a.75.75 0 001.14-.094l3.75-5.25z" clip-rule="evenodd"/></svg>
                                        Official
                                    </span>
                                @elseif ($isVerified)
                                    <span class="inline-flex items-center gap-1 text-[10px] font-medium px-1.5 py-0.5 rounded bg-success-100 dark:bg-success-900/40 text-success-700 dark:text-success-400 border border-success-200 dark:border-success-700/50 shrink-0">
                                        <svg class="w-3 h-3" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M16.704 4.153a.75.75 0 01.143 1.052l-8 10.5a.75.75 0 01-1.127.075l-4.5-4.5a.75.75 0 011.06-1.06l3.894 3.893 7.48-9.817a.75.75 0 011.05-.143z" clip-rule="evenodd"/></svg>
                                        Verified
                                    </span>
                                @elseif ($isThirdParty)
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
                            <div class="flex flex-col items-end gap-1.5" x-data="{ autoRenew: false }">
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
                                            @if ($term === 'annual')
                                                x-on:click="$wire.buy('{{ $p['name'] }}', 'annual', autoRenew)"
                                            @else
                                                wire:click="buy('{{ $p['name'] }}', '{{ $term }}')"
                                            @endif
                                            wire:loading.attr="disabled"
                                            title="{{ $term === 'annual' ? 'Yearly licence — renews once a year' : 'One-time payment, updates for life' }}"
                                            class="text-xs font-semibold px-3 py-1.5 rounded-lg bg-primary-600 text-white hover:bg-primary-700 transition-colors disabled:opacity-60"
                                        >
                                            {{ $symbol }}{{ number_format(((int) $price) / 100, ((int) $price) % 100 === 0 ? 0 : 2) }}{{ $term === 'annual' ? '/yr' : '' }}
                                        </button>
                                    @endforeach
                                </div>
                                @if (isset(($p['prices'] ?? [])['annual']))
                                    {{-- Buyer-choice auto-debit: off = one-off payment with a
                                         manual Renew button, on = Razorpay mandate that charges
                                         each year until cancelled from the Magna Account page. --}}
                                    <label class="flex items-center gap-1.5 text-[11px] text-gray-500 dark:text-gray-400 cursor-pointer select-none">
                                        <input type="checkbox" x-model="autoRenew" class="h-3 w-3 rounded border-gray-300 dark:border-white/20">
                                        Auto-renew yearly
                                    </label>
                                @endif
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
