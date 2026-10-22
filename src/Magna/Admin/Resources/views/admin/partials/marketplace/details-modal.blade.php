{{--
    The store listing for one marketplace plugin or theme, opened by "See more"
    on a catalog card (ShowsMarketplaceDetails). Laid out like an app-store
    page: identity and the install button first, then the facts a decision
    rests on (rating, installs, version, compatibility), screenshots, the
    description, ratings and reviews, and the publisher's links.

    Receives:
      $details      ShowsMarketplaceDetails::$details (array) — via $this
      $detailsRow   the page's card row for this listing, or null when it is
                    not offered here (already installed, or not compatible)
      $actionsView  the card's own buttons partial, reused so the modal can
                    never offer something the card would not
      $actionsVar   the variable name that partial expects ('p' or 't')

    Everything shown is publisher-supplied: text is escaped by {{ }}, URLs were
    reduced to http(s) by ListingDetails, and the description is rendered by
    ListingDetails::descriptionHtml(), which escapes raw HTML.
--}}
@php
    $d = $this->details;
@endphp

@if ($d !== null)
    @php
        $isTheme = ($d['type'] ?? 'plugin') === 'theme';
        $kind = $isTheme ? 'theme' : 'plugin';
        $words = preg_split('/\s+/', trim((string) $d['name'])) ?: [];
        $initials = mb_strtoupper(mb_substr($words[0] ?? '', 0, 1).mb_substr($words[1] ?? '', 0, 1));
        $breakdown = $d['rating_breakdown'] ?? [];
        $totalRatings = (int) ($d['ratings_count'] ?? 0);
        $maxBar = max(1, ...array_values($breakdown ?: [0]));
        $rating = $d['rating'] !== null ? (float) $d['rating'] : null;
        $released = null;
        try {
            $released = ! empty($d['released_at']) ? \Illuminate\Support\Carbon::parse($d['released_at']) : null;
        } catch (\Throwable) {
            $released = null;
        }
        $installs = $d['installs'];
        $installsLabel = $installs === null ? null : ($installs >= 1000 ? number_format($installs / 1000, $installs >= 10000 ? 0 : 1).'K+' : (string) $installs);
        $description = \Magna\Marketplace\ListingDetails::descriptionHtml($d['description'] ?? '');
        $stars = function (float $value): string {
            $full = (int) round($value);

            return str_repeat('★', max(0, min(5, $full))).str_repeat('☆', 5 - max(0, min(5, $full)));
        };
    @endphp

    <div
        wire:key="marketplace-details-{{ $d['package'] }}"
        x-data="{ shot: null, shots: @js($d['screenshots']), expanded: false }"
        x-on:keydown.escape.window="shot !== null ? shot = null : $wire.closeDetails()"
        class="fixed inset-0 z-40 flex items-start justify-center overflow-y-auto bg-gray-950/50 px-4 py-6 backdrop-blur-sm sm:py-10"
        wire:click.self="closeDetails"
        role="dialog"
        aria-modal="true"
        aria-labelledby="marketplace-details-title"
    >
        <div class="relative w-full max-w-4xl overflow-hidden rounded-2xl bg-white shadow-2xl ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">

            {{-- Close --}}
            <button type="button" wire:click="closeDetails" class="absolute right-4 top-4 z-10 rounded-full p-2 text-gray-400 hover:bg-gray-100 hover:text-gray-600 dark:hover:bg-white/5 dark:hover:text-gray-200" aria-label="Close">
                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>

            {{-- Header: identity --}}
            <div class="flex flex-col gap-5 p-6 pb-0 sm:flex-row sm:items-start sm:p-8 sm:pb-0">
                @if ($d['icon'])
                    <img src="{{ $d['icon'] }}" alt="" class="h-20 w-20 shrink-0 rounded-2xl border border-gray-100 bg-white object-cover shadow-sm dark:border-white/10 sm:h-24 sm:w-24">
                @else
                    <div class="flex h-20 w-20 shrink-0 select-none items-center justify-center rounded-2xl bg-primary-50 text-2xl font-bold text-primary-700 dark:bg-primary-900/30 dark:text-primary-400 sm:h-24 sm:w-24">{{ $initials }}</div>
                @endif

                <div class="min-w-0 flex-1 pr-8">
                    <h2 id="marketplace-details-title" class="text-2xl font-bold tracking-tight text-gray-950 dark:text-white sm:text-3xl">{{ $d['name'] }}</h2>
                    <div class="mt-1 flex flex-wrap items-center gap-x-2 gap-y-1 text-sm">
                        @if ($d['publisher'])
                            @if ($d['website'] ?? null)
                                <a href="{{ $d['website'] }}" target="_blank" rel="noopener noreferrer" class="font-semibold text-primary-600 hover:underline dark:text-primary-400">{{ $d['publisher'] }}</a>
                            @else
                                <span class="font-semibold text-primary-600 dark:text-primary-400">{{ $d['publisher'] }}</span>
                            @endif
                        @endif
                        @if ($d['official'])
                            <span class="inline-flex items-center gap-1 rounded bg-primary-100 px-1.5 py-0.5 text-[11px] font-medium text-primary-700 dark:bg-primary-900/40 dark:text-primary-400">Official</span>
                        @elseif ($d['verified'])
                            <span class="inline-flex items-center gap-1 rounded bg-success-100 px-1.5 py-0.5 text-[11px] font-medium text-success-700 dark:bg-success-900/40 dark:text-success-400">Verified</span>
                        @endif
                        <span class="rounded bg-gray-100 px-1.5 py-0.5 text-[11px] font-medium capitalize text-gray-600 dark:bg-white/5 dark:text-gray-400">{{ $kind }}</span>
                    </div>
                    @if ($d['short_description'] !== '')
                        <p class="mt-2 text-sm leading-relaxed text-gray-600 dark:text-gray-300">{{ $d['short_description'] }}</p>
                    @endif
                </div>
            </div>

            {{-- Stats strip --}}
            <div class="mx-6 mt-6 grid grid-cols-3 gap-px overflow-hidden rounded-xl border border-gray-200 bg-gray-200 text-center dark:border-white/10 dark:bg-white/10 sm:mx-8 sm:grid-cols-5">
                <div class="bg-white px-2 py-3 dark:bg-gray-900">
                    <div class="text-base font-bold text-gray-950 dark:text-white">
                        @if ($rating !== null)
                            {{ number_format($rating, 1) }} <span class="text-amber-500">★</span>
                        @else
                            —
                        @endif
                    </div>
                    <div class="text-[11px] text-gray-500 dark:text-gray-400">{{ $totalRatings }} {{ \Illuminate\Support\Str::plural('review', $totalRatings) }}</div>
                </div>
                <div class="bg-white px-2 py-3 dark:bg-gray-900">
                    <div class="text-base font-bold text-gray-950 dark:text-white">{{ $installsLabel ?? '—' }}</div>
                    <div class="text-[11px] text-gray-500 dark:text-gray-400">Installs</div>
                </div>
                <div class="bg-white px-2 py-3 dark:bg-gray-900">
                    <div class="text-base font-bold text-gray-950 dark:text-white">v{{ ltrim((string) $d['version'], 'vV') }}</div>
                    <div class="text-[11px] text-gray-500 dark:text-gray-400">{{ $released ? 'Updated '.$released->diffForHumans() : 'Version' }}</div>
                </div>
                <div class="bg-white px-2 py-3 dark:bg-gray-900">
                    <div class="font-mono text-sm font-bold text-gray-950 dark:text-white">{{ $d['compat'] }}</div>
                    <div class="text-[11px] text-gray-500 dark:text-gray-400">Requires Magna</div>
                </div>
                <div class="col-span-2 bg-white px-2 py-3 dark:bg-gray-900 sm:col-span-1">
                    <div class="text-base font-bold text-gray-950 dark:text-white">{{ $d['is_paid'] ? 'Paid' : 'Free' }}</div>
                    <div class="text-[11px] text-gray-500 dark:text-gray-400">{{ $d['is_paid'] ? 'Licensed' : 'No cost' }}</div>
                </div>
            </div>

            {{-- Actions: the card's own buttons. A click closes this view first,
                 so an install confirmation or checkout opens on a clear page. --}}
            <div class="flex flex-wrap items-center gap-3 px-6 pt-5 sm:px-8"
                 x-on:click.capture="if ($event.target.closest('button')) { $wire.closeDetails() }">
                @if ($detailsRow !== null)
                    {{-- The card's buttons, sized up: this is the primary action of the page. --}}
                    <div class="flex flex-wrap items-center gap-2 [&_button]:rounded-xl [&_button]:px-6 [&_button]:py-2.5 [&_button]:text-sm">
                        @include($actionsView, [$actionsVar => $detailsRow])
                    </div>
                @else
                    <span class="inline-flex items-center gap-1.5 rounded-lg bg-success-50 px-3 py-1.5 text-xs font-semibold text-success-700 dark:bg-success-500/10 dark:text-success-400">
                        <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M16.704 4.153a.75.75 0 01.143 1.052l-8 10.5a.75.75 0 01-1.127.075l-4.5-4.5a.75.75 0 011.06-1.06l3.894 3.893 7.48-9.817a.75.75 0 011.05-.143z" clip-rule="evenodd"/></svg>
                        Installed on this site
                    </span>
                @endif
                @if (! $isTheme && method_exists($this, 'requestReview'))
                    <button type="button" wire:click="requestReview('{{ $d['package'] }}')" class="text-xs font-semibold text-gray-600 hover:text-amber-600 dark:text-gray-300 dark:hover:text-amber-400">Write a review</button>
                @endif
            </div>

            {{-- Screenshots --}}
            @if ($d['screenshots'] !== [])
                <div class="mt-6">
                    <div class="flex snap-x snap-mandatory gap-3 overflow-x-auto px-6 pb-2 sm:px-8">
                        @foreach ($d['screenshots'] as $i => $url)
                            <button type="button" x-on:click="shot = {{ $i }}" class="shrink-0 snap-start overflow-hidden rounded-xl border border-gray-200 bg-gray-50 transition hover:opacity-90 focus:outline-none focus:ring-2 focus:ring-primary-500 dark:border-white/10 dark:bg-white/5" aria-label="Open screenshot {{ $i + 1 }}">
                                <img src="{{ $url }}" alt="Screenshot {{ $i + 1 }} of {{ $d['name'] }}" loading="lazy" class="{{ $isTheme ? 'h-56 sm:h-64' : 'h-48 sm:h-56' }} w-auto max-w-none object-cover">
                            </button>
                        @endforeach
                    </div>
                </div>
            @endif

            <div class="grid gap-8 p-6 sm:p-8 lg:grid-cols-[minmax(0,1fr)_16rem]">
                <div class="min-w-0 space-y-8">
                    {{-- About --}}
                    <section>
                        <h3 class="text-base font-semibold text-gray-950 dark:text-white">About this {{ $kind }}</h3>
                        @if ((string) $description !== '')
                            <div class="relative mt-3">
                                <div :class="expanded ? '' : 'max-h-56 overflow-hidden'"
                                     class="text-sm leading-relaxed text-gray-700 dark:text-gray-300 [&_a]:font-medium [&_a]:text-primary-600 [&_a]:underline dark:[&_a]:text-primary-400 [&_code]:rounded [&_code]:bg-gray-100 [&_code]:px-1 [&_code]:font-mono [&_code]:text-[0.85em] dark:[&_code]:bg-white/10 [&_h1]:mb-2 [&_h1]:mt-4 [&_h1]:text-base [&_h1]:font-semibold [&_h2]:mb-2 [&_h2]:mt-4 [&_h2]:text-base [&_h2]:font-semibold [&_h3]:mb-1 [&_h3]:mt-3 [&_h3]:font-semibold [&_li]:my-0.5 [&_ol]:mb-3 [&_ol]:list-decimal [&_ol]:pl-5 [&_p]:mb-3 [&_pre]:mb-3 [&_pre]:overflow-x-auto [&_pre]:rounded-lg [&_pre]:bg-gray-100 [&_pre]:p-3 dark:[&_pre]:bg-white/5 [&_ul]:mb-3 [&_ul]:list-disc [&_ul]:pl-5">
                                    {{ $description }}
                                </div>
                                <div x-show="! expanded" class="pointer-events-none absolute inset-x-0 bottom-0 h-16 bg-gradient-to-t from-white to-transparent dark:from-gray-900"></div>
                            </div>
                            <button type="button" x-on:click="expanded = ! expanded" class="mt-2 text-xs font-semibold text-primary-600 hover:underline dark:text-primary-400" x-text="expanded ? 'Show less' : 'Read more'">Read more</button>
                        @else
                            <p class="mt-3 text-sm text-gray-500 dark:text-gray-400">The publisher has not added a description yet.</p>
                        @endif

                        @if ($d['categories'] !== [])
                            <div class="mt-4 flex flex-wrap gap-2">
                                @foreach ($d['categories'] as $category)
                                    <span class="rounded-full border border-gray-200 px-3 py-1 text-xs font-medium capitalize text-gray-600 dark:border-white/10 dark:text-gray-300">{{ str_replace('-', ' ', $category) }}</span>
                                @endforeach
                            </div>
                        @endif
                    </section>

                    @include('magna::admin.partials.marketplace.details-reviews')
                </div>

                @include('magna::admin.partials.marketplace.details-sidebar')
            </div>
        </div>

        @include('magna::admin.partials.marketplace.details-viewer')
    </div>
@endif
