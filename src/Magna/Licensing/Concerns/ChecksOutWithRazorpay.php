<?php

declare(strict_types=1);

namespace Magna\Licensing\Concerns;

use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Magna\Admin\Pages\AccountCentrePage;
use Magna\Licensing\LicenseClient;

/**
 * The buying half of a Filament page: open a gateway window, then wait for
 * the marketplace to say the money actually arrived.
 *
 * Shared by the plugin catalog (first purchase) and the Magna Account page
 * (renewal) because the risky part is identical and must not drift. The
 * shape of it:
 *
 *   1. The marketplace opens an order and prices it. Nothing about the
 *      amount comes from this browser.
 *   2. Razorpay's own window collects the payment. No card detail ever
 *      touches this page or this server.
 *   3. What the window hands back is reported, but not believed — the
 *      licence is issued from Razorpay's signed webhook, so this page polls
 *      the order until the marketplace confirms it.
 *
 * Step 3 is why a customer who pays and immediately closes the tab still
 * gets what they bought, and why a browser that claims success without a
 * matching webhook gets nothing.
 *
 * The consuming page supplies onOrderSettled() — what to do once the sale is
 * real (install the product, refresh the licence list).
 */
trait ChecksOutWithRazorpay
{
    /** The order awaiting confirmation, or null when nothing is in flight. */
    public ?int $pendingOrderId = null;

    /** Product slug being bought — shown while waiting, used on settle. */
    public string $pendingOrderProduct = '';

    /** Poll ticks spent waiting for the webhook. Bounded, see pollOrder(). */
    public int $orderWait = 0;

    /** What to do once the marketplace confirms the sale. */
    abstract protected function onOrderSettled(int $licenseId, string $productSlug): void;

    /**
     * Hand a marketplace checkout reply to the browser.
     *
     * @param  array<string, mixed>  $result  reply from LicenseClient::checkout()/renew()
     */
    protected function beginCheckout(array $result, string $productSlug): void
    {
        if (($result['ok'] ?? false) !== true) {
            Notification::make()
                ->title('Checkout could not be started')
                ->body(is_string($result['message'] ?? null) ? $result['message'] : 'The marketplace refused this payment.')
                ->danger()
                ->send();

            return;
        }

        $order = is_array($result['order'] ?? null) ? $result['order'] : [];
        $buyer = is_array($result['buyer'] ?? null) ? $result['buyer'] : [];

        if (! is_numeric($order['id'] ?? null) || ! is_string($order['gateway_order_id'] ?? null)) {
            Notification::make()->title('The marketplace returned an incomplete checkout.')->danger()->send();

            return;
        }

        $this->pendingOrderId = (int) $order['id'];
        $this->pendingOrderProduct = $productSlug;
        $this->orderWait = 0;

        $this->dispatch('magna-checkout', payload: [
            'key_id' => is_string($result['key_id'] ?? null) ? $result['key_id'] : '',
            'order_id' => (int) $order['id'],
            'gateway_order_id' => $order['gateway_order_id'],
            'amount' => (int) ($order['amount'] ?? 0),
            'currency' => is_string($order['currency'] ?? null) ? $order['currency'] : 'INR',
            'product' => $productSlug,
            'buyer_name' => is_string($buyer['name'] ?? null) ? $buyer['name'] : '',
            'buyer_email' => is_string($buyer['email'] ?? null) ? $buyer['email'] : '',
        ]);
    }

    /**
     * What the gateway window reported.
     *
     * A courtesy: it lets the admin be told "received" immediately. It
     * grants nothing — a mis-signed report leaves the poll running, because
     * the payment may still have been captured.
     */
    public function confirmPayment(int $orderId, string $paymentId, string $signature): void
    {
        if ($this->pendingOrderId !== $orderId) {
            return;
        }

        $result = app(LicenseClient::class)->confirmCheckout($orderId, $paymentId, $signature);

        Notification::make()
            ->title(($result['ok'] ?? false) === true ? 'Payment received' : 'Payment reported')
            ->body(is_string($result['message'] ?? null) ? $result['message'] : 'Waiting for the marketplace to confirm…')
            ->success()
            ->send();
    }

    /** The buyer closed the gateway window. The unpaid order simply expires. */
    public function cancelCheckout(): void
    {
        $this->pendingOrderId = null;
        $this->pendingOrderProduct = '';
        $this->orderWait = 0;
    }

    /** The gateway's script could not be loaded — say so rather than hang. */
    public function checkoutUnavailable(): void
    {
        $this->cancelCheckout();

        Notification::make()
            ->title('Could not load the payment window')
            ->body('Check this browser\'s access to checkout.razorpay.com and try again.')
            ->danger()
            ->send();
    }

    /** Poll the order while the webhook lands, then hand off to the page. */
    public function pollOrder(): void
    {
        if ($this->pendingOrderId === null) {
            return;
        }

        $this->orderWait++;

        $status = app(LicenseClient::class)->orderStatus($this->pendingOrderId);

        // Unreachable is not failed — keep waiting until the bound.
        if ($status === null) {
            $this->stopWaitingIfExhausted();

            return;
        }

        if ($status['status'] === 'paid' && $status['license_id'] !== null) {
            $product = $this->pendingOrderProduct;
            $licenseId = $status['license_id'];

            $this->cancelCheckout();
            app(LicenseClient::class)->forgetCache();

            $this->onOrderSettled($licenseId, $product);

            return;
        }

        if (in_array($status['status'], ['failed', 'refunded'], true)) {
            $this->cancelCheckout();

            Notification::make()
                ->title('The payment did not go through')
                ->body('Nothing was charged. You can try again.')
                ->danger()
                ->send();

            return;
        }

        $this->stopWaitingIfExhausted();
    }

    /**
     * Roughly two minutes at the view's poll interval.
     *
     * A webhook that has not landed by then will not be waited out by a
     * browser tab, and the licence is safe in the account regardless — so
     * the wait ends with somewhere to go rather than an endless spinner.
     */
    private function stopWaitingIfExhausted(): void
    {
        if ($this->orderWait < 60) {
            return;
        }

        $this->cancelCheckout();

        Notification::make()
            ->title('Still confirming your payment')
            ->body('This is taking longer than usual. It will appear on the Magna Account page as soon as the payment settles.')
            ->warning()
            ->actions([
                Action::make('account')->label('Open Magna Account')->url(AccountCentrePage::getUrl()),
            ])
            ->send();
    }
}
