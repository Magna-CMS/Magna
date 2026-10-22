{{-- Details modal: the rating summary and review list. Included by details-modal, whose variables it reads. --}}
{{-- Ratings and reviews --}}
<section>
    <h3 class="text-base font-semibold text-gray-950 dark:text-white">Ratings and reviews</h3>
    @if ($totalRatings > 0)
        <div class="mt-4 flex items-center gap-6">
            <div class="text-center">
                <div class="text-5xl font-bold leading-none text-gray-950 dark:text-white">{{ $rating !== null ? number_format($rating, 1) : '—' }}</div>
                <div class="mt-1 text-sm tracking-widest text-amber-500" aria-hidden="true">{{ $stars($rating ?? 0) }}</div>
                <div class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">{{ $totalRatings }} {{ \Illuminate\Support\Str::plural('review', $totalRatings) }}</div>
            </div>
            <div class="flex-1 space-y-1.5">
                @foreach ([5, 4, 3, 2, 1] as $n)
                    @php
                        $count = (int) ($breakdown[$n] ?? $breakdown[(string) $n] ?? 0);
                    @endphp
                    <div class="flex items-center gap-2 text-xs text-gray-500 dark:text-gray-400">
                        <span class="w-2 text-right">{{ $n }}</span>
                        <div class="h-2 flex-1 overflow-hidden rounded-full bg-gray-100 dark:bg-white/10">
                            <div class="h-full rounded-full bg-amber-400" style="width: {{ round($count / $maxBar * 100) }}%"></div>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    @else
        <p class="mt-3 text-sm text-gray-500 dark:text-gray-400">No ratings yet.</p>
    @endif

    @if ($d['reviews'] !== [])
        <ul class="mt-6 space-y-5">
            @foreach ($d['reviews'] as $review)
                @php
                    $when = null;
                    try {
                        $when = $review['date'] ? \Illuminate\Support\Carbon::parse($review['date']) : null;
                    } catch (\Throwable) {
                        $when = null;
                    }
                @endphp
                <li>
                    <div class="flex items-center gap-3">
                        <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-gray-100 text-xs font-semibold text-gray-600 dark:bg-white/10 dark:text-gray-300">{{ mb_strtoupper(mb_substr($review['author'], 0, 1)) }}</div>
                        <div class="min-w-0">
                            <div class="text-sm font-medium text-gray-900 dark:text-white">{{ $review['author'] }}</div>
                            <div class="flex items-center gap-2 text-xs">
                                <span class="tracking-wider text-amber-500" aria-label="{{ $review['stars'] }} out of 5">{{ $stars((float) $review['stars']) }}</span>
                                @if ($when)
                                    <span class="text-gray-400 dark:text-gray-500">{{ $when->toFormattedDateString() }}</span>
                                @endif
                            </div>
                        </div>
                    </div>
                    @if ($review['review'] !== '')
                        <p class="mt-2 whitespace-pre-line text-sm leading-relaxed text-gray-700 dark:text-gray-300">{{ $review['review'] }}</p>
                    @endif
                </li>
            @endforeach
        </ul>
    @endif
</section>
