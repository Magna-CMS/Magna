{{--
    The buy / try / install buttons for one marketplace plugin. Shared by the
    catalog card and the details modal, so both always offer exactly the
    same thing.

    Receives: $p (a PluginsPage::$available row).
--}}
@php
    $isThirdParty = ! (($p['official'] ?? false) === true) && ($p['source'] ?? '') !== 'plugins-dev/';
@endphp
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
