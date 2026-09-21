{{--
    The Sites cell of a licence row: how many seats are in use, and which
    domains hold them.

    The wallet has always carried this and the page never showed it, so a
    licence with no room left looked exactly like one with room and the first
    anyone heard of a limit was an install that refused.

    Its own file because it is a self-contained cell, and because the row it
    sits in was already the longest thing on the page.
--}}
<td class="py-4 align-top">
    @if ($seatLimit > 0)
        <span @class([
            'rounded-full px-2 py-0.5 text-xs font-medium',
            'bg-amber-50 text-amber-700 dark:bg-amber-500/10 dark:text-amber-300' => $seatsFull,
            'bg-gray-100 text-gray-600 dark:bg-white/10 dark:text-gray-300' => ! $seatsFull,
        ])>{{ $seatsUsed }} of {{ $seatLimit }}</span>
        @if ($seatsFull)
            <p class="mt-1 text-[11px] text-amber-700 dark:text-amber-300">Release a site to install elsewhere.</p>
        @endif
    @endif

    @if ($seats !== [])
        <ul class="mt-2 space-y-1">
            @foreach ($seats as $seat)
                <li class="flex items-center gap-1.5 text-xs text-gray-600 dark:text-gray-400">
                    <span class="truncate font-medium text-gray-700 dark:text-gray-300">{{ $seat['domain'] }}</span>
                    @if ($seat['is_this_site'])
                        <span class="shrink-0 rounded bg-primary-50 px-1.5 py-0.5 text-[10px] font-semibold text-primary-700 dark:bg-primary-500/10 dark:text-primary-400">this site</span>
                    @endif
                    @if ($seat['is_dev'])
                        {{-- A development host holds a seat like any other. It
                             did not always, which is how one licence came to
                             run on every .test address its owner had. --}}
                        <span class="shrink-0 rounded bg-gray-100 px-1.5 py-0.5 text-[10px] font-medium text-gray-500 dark:bg-white/10 dark:text-gray-400" title="Development host. It holds a seat like any other domain.">dev</span>
                    @endif
                    @can('licensing.manage')
                        <form method="POST" action="{{ route('licensing.release-site') }}" class="shrink-0"
                              onsubmit="return confirm('Release the seat held by {{ $seat['domain'] }}? That site stops being licensed and the seat becomes free for another domain.');">
                            @csrf
                            <input type="hidden" name="license_id" value="{{ $license['id'] }}">
                            <input type="hidden" name="activation_id" value="{{ $seat['id'] }}">
                            <input type="hidden" name="product_slug" value="{{ $license['product_slug'] }}">
                            <input type="hidden" name="domain" value="{{ $seat['domain'] }}">
                            <button type="submit" class="text-[11px] font-semibold text-gray-400 transition-colors hover:text-rose-600 dark:hover:text-rose-400">Release</button>
                        </form>
                    @endcan
                </li>
            @endforeach
        </ul>
    @endif
</td>
