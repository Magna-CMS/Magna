<?php

declare(strict_types=1);

namespace Magna\Licensing\Concerns;

use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Magna\AccountCentre\AccountCentreSettings;
use Magna\Admin\Concerns\ReloadsBrowser;
use Magna\Admin\Pages\AccountCentrePage;
use Magna\Licensing\LicenseClient;
use Magna\Licensing\LicenseInstaller;
use Magna\Marketplace\MarketplaceClient;
use Throwable;

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
    use ReloadsBrowser;

    /** The order awaiting confirmation, or null when nothing is in flight. */
    public ?int $pendingOrderId = null;

    /**
     * The auto-debit subscription awaiting its first charge, or null. At
     * most one of this and pendingOrderId is set — a checkout is either a
     * one-off order or a mandate, never both.
     */
    public ?int $pendingSubscriptionId = null;

    /** Product slug being bought — shown while waiting, used on settle. */
    public string $pendingOrderProduct = '';

    /** Poll ticks spent waiting for the webhook. Bounded, see pollOrder(). */
    public int $orderWait = 0;

    /** The operator's refund policy, shown beside the payment window. */
    public string $pendingRefundTerms = '';

    /** Whether any checkout — order or subscription — is awaiting settlement. */
    public function checkoutInFlight(): bool
    {
        return $this->pendingOrderId !== null || $this->pendingSubscriptionId !== null;
    }

    /**
     * Buy a product. No price travels from here: the term is a name, the
     * marketplace prices it, and the amount that comes back is only used to
     * render the gateway window. This page never handles card data.
     *
     * This used to be copy-pasted between the plugin catalog and the themes
     * page — one notification string apart — which is exactly how the two
     * would have drifted on the next change to the risky part.
     */
    public function buy(string $package, string $term, bool $autoRenew = false): void
    {
        if (! $this->requireConnectedAccount($this->purchaseNeedsAccountBecause())) {
            return;
        }

        if (! in_array($term, ['lifetime', 'annual'], true)) {
            return;
        }

        // Auto-renew is a property of the annual term only; the flag is
        // simply dropped for lifetime rather than refused, since the UI
        // never offers it there.
        $this->beginCheckout(
            app(LicenseClient::class)->checkout($package, $term, $autoRenew && $term === 'annual'),
            $package,
        );
    }

    /**
     * Start a product's free trial straight from the catalog, then install
     * it — a trial someone has to go and find on another page is a trial
     * most people never start.
     */
    public function startTrial(string $package): void
    {
        if (! $this->requireConnectedAccount($this->trialNeedsAccountBecause())) {
            return;
        }

        $client = app(LicenseClient::class);
        $result = $client->startTrial($package);

        if (($result['ok'] ?? false) !== true) {
            Notification::make()
                ->title('Trial could not be started')
                ->body(is_string($result['message'] ?? null) ? $result['message'] : 'The marketplace refused this trial.')
                ->danger()
                ->send();

            return;
        }

        $client->forgetCache();

        // The trial licence exists now, but its id is not in the reply — the
        // wallet is the one place that knows it, and reading it back also
        // proves the licence really landed.
        $licenseId = $this->walletLicenseIdFor($package);

        if ($licenseId === null) {
            Notification::make()
                ->title('Trial started')
                ->body('Install it from the Magna Account page.')
                ->success()
                ->send();

            return;
        }

        $this->onOrderSettled($licenseId, $package);
    }

    /**
     * The sale is real — install what was bought.
     *
     * A failure here is reported as a failure to INSTALL, never as a failure
     * to buy: the licence is already in the account, and telling someone who
     * has just paid that something "failed" without that distinction is how
     * support tickets are made.
     *
     * The default suits a first purchase (catalog pages); a page whose
     * settle means something else — the Account Centre's renewals — replaces
     * it wholesale.
     */
    protected function onOrderSettled(int $licenseId, string $productSlug): void
    {
        try {
            $message = app(LicenseInstaller::class)->installLicense($licenseId, $productSlug);
        } catch (Throwable $e) {
            Notification::make()
                ->title('Purchased — but the install did not finish')
                ->body($e->getMessage().' Your licence is safe; install it from the Magna Account page.')
                ->warning()
                ->send();

            return;
        }

        app(MarketplaceClient::class)->clearCache();

        Notification::make()->title($message)->success()->send();

        $url = static::getUrl();
        $this->replaceUrl($url, 800);
    }

    /**
     * Anything sold or issued here belongs to a Magna Account. Returns
     * whether the caller may proceed; when not connected, points the admin
     * at the Account Centre instead of opening the checkout.
     */
    protected function requireConnectedAccount(?string $because = null): bool
    {
        if (AccountCentreSettings::get()->connected) {
            return true;
        }

        Notification::make()
            ->title('Connect your Magna Account first')
            ->body($because ?? 'This action is tied to your Magna Account, so connect one first.')
            ->warning()
            ->actions([
                Action::make('connect')->label('Go to Magna Account')->url(AccountCentrePage::getUrl()),
            ])
            ->send();

        return false;
    }

    /** Page-specific copy for why buying needs an account; null = generic. */
    protected function purchaseNeedsAccountBecause(): ?string
    {
        return null;
    }

    /** Page-specific copy for why a trial needs an account; null = generic. */
    protected function trialNeedsAccountBecause(): ?string
    {
        return null;
    }

    /** The wallet licence id for a product, or null when it is not there. */
    protected function walletLicenseIdFor(string $package): ?int
    {
        foreach (app(LicenseClient::class)->wallet() ?? [] as $licence) {
            if (($licence['product_slug'] ?? null) === $package && is_numeric($licence['id'] ?? null)) {
                return (int) $licence['id'];
            }
        }

        return null;
    }

    /**
     * Hand a marketplace checkout reply to the browser.
     *
     * Two shapes come back: an `order` block (one-off payment) or a
     * `subscription` block (buyer opted into auto-renew — the widget
     * registers a mandate and the first charge mints the licence).
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

        $buyer = is_array($result['buyer'] ?? null) ? $result['buyer'] : [];
        $terms = is_string($result['refund_terms'] ?? null) ? $result['refund_terms'] : '';

        $common = [
            'key_id' => is_string($result['key_id'] ?? null) ? $result['key_id'] : '',
            'product' => $productSlug,
            'buyer_name' => is_string($buyer['name'] ?? null) ? $buyer['name'] : '',
            'buyer_email' => is_string($buyer['email'] ?? null) ? $buyer['email'] : '',
        ];

        if (is_array($result['subscription'] ?? null)) {
            $subscription = $result['subscription'];

            if (! is_numeric($subscription['id'] ?? null) || ! is_string($subscription['gateway_subscription_id'] ?? null)) {
                Notification::make()->title('The marketplace returned an incomplete checkout.')->danger()->send();

                return;
            }

            $this->pendingSubscriptionId = (int) $subscription['id'];
            $this->pendingOrderId = null;
            $this->pendingOrderProduct = $productSlug;
            $this->orderWait = 0;
            $this->pendingRefundTerms = $terms;

            $this->dispatch('magna-checkout', payload: $common + [
                'subscription_id' => (int) $subscription['id'],
                'gateway_subscription_id' => $subscription['gateway_subscription_id'],
                'amount' => (int) ($subscription['amount'] ?? 0),
                'currency' => is_string($subscription['currency'] ?? null) ? $subscription['currency'] : 'INR',
            ]);

            return;
        }

        $order = is_array($result['order'] ?? null) ? $result['order'] : [];

        if (! is_numeric($order['id'] ?? null) || ! is_string($order['gateway_order_id'] ?? null)) {
            Notification::make()->title('The marketplace returned an incomplete checkout.')->danger()->send();

            return;
        }

        $this->pendingOrderId = (int) $order['id'];
        $this->pendingSubscriptionId = null;
        $this->pendingOrderProduct = $productSlug;
        $this->orderWait = 0;
        $this->pendingRefundTerms = $terms;

        $this->dispatch('magna-checkout', payload: $common + [
            'order_id' => (int) $order['id'],
            'gateway_order_id' => $order['gateway_order_id'],
            'amount' => (int) ($order['amount'] ?? 0),
            'currency' => is_string($order['currency'] ?? null) ? $order['currency'] : 'INR',
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

    /** The subscription widget's report — same courtesy semantics. */
    public function confirmSubscriptionPayment(int $subscriptionId, string $paymentId, string $signature): void
    {
        if ($this->pendingSubscriptionId !== $subscriptionId) {
            return;
        }

        $result = app(LicenseClient::class)->confirmSubscriptionCheckout($subscriptionId, $paymentId, $signature);

        Notification::make()
            ->title(($result['ok'] ?? false) === true ? 'Payment received' : 'Payment reported')
            ->body(is_string($result['message'] ?? null) ? $result['message'] : 'Waiting for the marketplace to confirm…')
            ->success()
            ->send();
    }

    /** The buyer closed the gateway window. The unpaid checkout simply expires. */
    public function cancelCheckout(): void
    {
        $this->pendingOrderId = null;
        $this->pendingSubscriptionId = null;
        $this->pendingOrderProduct = '';
        $this->orderWait = 0;
        $this->pendingRefundTerms = '';
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

    /** Poll the in-flight checkout while the webhook lands, then hand off. */
    public function pollOrder(): void
    {
        if (! $this->checkoutInFlight()) {
            return;
        }

        $this->orderWait++;

        // One-off orders report {status: paid|failed|refunded}; auto-debit
        // subscriptions report {status: created|active|failed|cancelled}. In
        // both, a non-null license_id is the real signal that settlement
        // finished — the webhook has minted or extended.
        $status = $this->pendingOrderId !== null
            ? app(LicenseClient::class)->orderStatus($this->pendingOrderId)
            : app(LicenseClient::class)->subscriptionStatus((int) $this->pendingSubscriptionId);

        // Unreachable is not failed — keep waiting until the bound.
        if ($status === null) {
            $this->stopWaitingIfExhausted();

            return;
        }

        if ($status['license_id'] !== null && in_array($status['status'], ['paid', 'active'], true)) {
            $product = $this->pendingOrderProduct;
            $licenseId = $status['license_id'];

            $this->cancelCheckout();
            app(LicenseClient::class)->forgetCache();

            $this->onOrderSettled($licenseId, $product);

            return;
        }

        if (in_array($status['status'], ['failed', 'refunded', 'cancelled'], true)) {
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
