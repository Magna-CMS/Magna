{{--
    The buy / try / get buttons for one marketplace theme. Shared by the Browse
    card and the details modal, so both always offer exactly the same thing.

    Receives: $t (a ThemesPage::$available row).
--}}
@php
    $symbol = ['INR' => '₹', 'USD' => '$', 'EUR' => '€', 'GBP' => '£'][$t['currency'] ?? 'INR'] ?? (($t['currency'] ?? '').' ');
@endphp
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
        <span class="text-xs text-gray-400 dark:text-gray-500">Free</span>
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
