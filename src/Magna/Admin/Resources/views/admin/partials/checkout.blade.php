{{--
    The buying half of a page, in markup.

    Two pieces, both driven by Magna\Licensing\Concerns\ChecksOutWithRazorpay:

      1. A listener that opens Razorpay's own window when the component
         dispatches `magna-checkout`. The gateway script is fetched on demand
         rather than on every admin page load — nobody should pay a request to
         a payment provider for visiting a plugin list.
      2. A small status card that polls the order afterwards. The licence is
         issued from Razorpay's signed webhook, so "the window closed" is not
         the same as "it is paid for", and this is what waits out the gap.

    Include once per page that buys anything.
--}}
<div
    x-data
    x-on:magna-checkout.window="
        (() => {
            const d = $event.detail.payload ?? $event.detail;
            const open = () => {
                if (! window.Razorpay) { $wire.checkoutUnavailable(); return; }
                // A one-off sale carries gateway_order_id; an auto-renew
                // mandate carries gateway_subscription_id. The widget takes
                // exactly one of the two keys.
                const gateway = d.gateway_order_id
                    ? { order_id: d.gateway_order_id }
                    : { subscription_id: d.gateway_subscription_id };
                const handler = d.gateway_order_id
                    ? (r) => $wire.confirmPayment(d.order_id, r.razorpay_payment_id, r.razorpay_signature)
                    : (r) => $wire.confirmSubscriptionPayment(d.subscription_id, r.razorpay_payment_id, r.razorpay_signature);
                new window.Razorpay({
                    key: d.key_id,
                    ...gateway,
                    amount: d.amount,
                    currency: d.currency,
                    name: 'Magna',
                    description: d.product,
                    prefill: { name: d.buyer_name, email: d.buyer_email },
                    handler: handler,
                    modal: { ondismiss: () => $wire.cancelCheckout() },
                }).open();
            };
            if (window.Razorpay) { open(); return; }
            const s = document.createElement('script');
            s.src = 'https://checkout.razorpay.com/v1/checkout.js';
            s.onload = open;
            s.onerror = () => $wire.checkoutUnavailable();
            document.head.appendChild(s);
        })()
    "
></div>

@if ($this->checkoutInFlight())
    <div
        wire:poll.2s="pollOrder"
        class="fixed bottom-6 right-6 z-30 max-w-sm rounded-xl border border-gray-200 dark:border-white/10 bg-white dark:bg-gray-900 px-4 py-3 shadow-lg"
    >
        <div class="flex items-center gap-3">
            <svg class="w-4 h-4 animate-spin text-primary-600 dark:text-primary-400" viewBox="0 0 24 24" fill="none">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3"/>
                <path class="opacity-90" fill="currentColor" d="M4 12a8 8 0 018-8v3a5 5 0 00-5 5H4z"/>
            </svg>
            <div class="text-xs">
                <p class="font-semibold text-gray-900 dark:text-white">Confirming your payment…</p>
                <p class="text-gray-500 dark:text-gray-400">{{ $this->pendingOrderProduct }} — this finishes on its own.</p>
            </div>
        </div>
        @if ($this->pendingRefundTerms !== '')
            <p class="mt-2 border-t border-gray-100 dark:border-white/5 pt-2 text-[11px] leading-snug text-gray-500 dark:text-gray-400">
                <span class="font-medium text-gray-600 dark:text-gray-300">Refund policy:</span>
                {{ $this->pendingRefundTerms }}
            </p>
        @endif
    </div>
@endif
