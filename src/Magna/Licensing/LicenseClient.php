<?php

declare(strict_types=1);

namespace Magna\Licensing;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Magna\AccountCentre\AccountCentreSettings;
use Magna\MagnaServiceProvider;
use Magna\Marketplace\Marketplace;
use Magna\Support\InstallFingerprint;
use Throwable;

/**
 * This site's half of the licence protocol — every call to the licence
 * authority on managemagna.jrstudios.dev goes through here.
 *
 * Two families:
 *   • Account-scoped (wallet, redeem, install, deactivate) — authenticated
 *     with the Magna Account bearer token this site holds, so the server
 *     resolves the account from the connection rather than trusting anything
 *     the browser sent.
 *   • Licence-scoped (activate, verify, download-url) — authenticated by the
 *     key or the activation token itself.
 *
 * Signed responses are opened through SignedPayload; an envelope that fails
 * verification is returned as null, exactly like a network failure, so a
 * forged response can never be more useful to an attacker than an outage.
 * Nothing here throws: callers treat null as "could not confirm".
 */
class LicenseClient
{
    /** How long the account page's read-only data is reused. */
    private const PAGE_CACHE_SECONDS = 60;

    /**
     * The wallet for the account this site is connected to.
     *
     * @return list<array<array-key, mixed>>|null null when unreachable or not connected
     */
    public function wallet(): ?array
    {
        $licenses = $this->cached('wallet', function (): ?array {
            $json = $this->json($this->accountRequest()?->get(Marketplace::API_BASE.'/account/licenses'));

            return is_array($json['licenses'] ?? null) ? $json['licenses'] : null;
        });

        // The marketplace is trusted to send a list of licence objects, but a
        // caller iterating the wallet must not trip over a scalar that slipped
        // into the array.
        return $licenses === null ? null : self::rows($licenses);
    }

    /**
     * The operator-controlled blocks for this site's Magna Account page —
     * featured plugins and the custom-software banner. Content is authored
     * on the marketplace so one edit reaches every connected site; a null
     * here simply hides the blocks rather than breaking the page.
     *
     * @return array<string, mixed>|null
     */
    public function panel(): ?array
    {
        return $this->cached('panel', function (): ?array {
            $json = $this->json($this->accountRequest()?->get(Marketplace::API_BASE.'/account/panel'));

            return $json === [] ? null : $json;
        });
    }

    /**
     * The account's tax invoices.
     *
     * @return list<array<array-key, mixed>>
     */
    public function invoices(): array
    {
        $invoices = $this->cached('invoices', function (): ?array {
            $json = $this->json($this->accountRequest()?->get(Marketplace::API_BASE.'/account/invoices'));

            return is_array($json['invoices'] ?? null) ? $json['invoices'] : null;
        }) ?? [];

        return self::rows($invoices);
    }

    /**
     * Keep only the array entries of a marketplace collection, reindexed.
     *
     * @param  array<array-key, mixed>  $items
     * @return list<array<array-key, mixed>>
     */
    private static function rows(array $items): array
    {
        $rows = [];

        foreach ($items as $item) {
            if (is_array($item)) {
                $rows[] = $item;
            }
        }

        return $rows;
    }

    /**
     * One invoice as a downloadable document.
     *
     * Fetched server-to-server rather than linked, because the marketplace
     * authenticates this with the site's bearer token and that token must
     * never reach a browser. The admin downloads from their own site; the
     * site is what talks to the marketplace.
     *
     * @return array{body: string, mime: string, filename: string}|null
     */
    public function invoiceDocument(int $invoiceId): ?array
    {
        try {
            $response = $this->accountRequest()?->get(Marketplace::API_BASE.'/account/invoices/'.$invoiceId.'/document');
        } catch (Throwable) {
            return null;
        }

        if ($response === null || ! $response->successful()) {
            return null;
        }

        $disposition = (string) $response->header('Content-Disposition');
        preg_match('/filename="?([^"\r\n;]+)"?/i', $disposition, $matches);

        // Never trust a filename from a remote header verbatim — it becomes
        // a Content-Disposition on our own response.
        $filename = isset($matches[1]) ? basename(trim($matches[1])) : 'invoice-'.$invoiceId;
        $filename = preg_replace('/[^A-Za-z0-9._-]/', '-', $filename) ?? 'invoice';

        return [
            'body' => $response->body(),
            'mime' => (string) ($response->header('Content-Type') ?: 'application/octet-stream'),
            'filename' => $filename,
        ];
    }

    /**
     * Turn a CodeCanyon purchase code into a Magna licence in this account.
     *
     * @return array{ok: bool, error?: ?string, message?: ?string}
     */
    public function redeemEnvato(string $purchaseCode): array
    {
        return $this->postAccountJson('/account/licenses/redeem-envato', ['purchase_code' => $purchaseCode]);
    }

    /**
     * Paste an existing key into this account's wallet.
     *
     * @return array{ok: bool, error?: ?string, message?: ?string}
     */
    public function redeem(string $key): array
    {
        return $this->postAccountJson('/account/licenses/redeem', ['key' => $key]);
    }

    /**
     * Start a free trial for a product, into the connected account's wallet.
     *
     * @return array{ok: bool, error?: ?string, message?: ?string}
     */
    public function startTrial(string $productSlug): array
    {
        return $this->postAccountJson('/account/products/'.$productSlug.'/start-trial', []);
    }

    /**
     * POST to an account endpoint and normalise the reply.
     *
     * Unlike the signed licence calls, these carry a human-facing outcome:
     * the caller renders `message` straight into the admin, so a refusal has
     * to survive the trip intact rather than collapsing into null the way an
     * unverifiable signature does.
     *
     * @param  array<string, mixed>  $payload
     * @return array{ok: bool, error: ?string, message: ?string}
     */
    private function postAccountJson(string $path, array $payload): array
    {
        return self::outcome($this->accountPost($path, $payload));
    }

    /**
     * Same call, but keeping the reply's other keys — checkout needs the order
     * block and the publishable key id alongside the outcome.
     *
     * The normalised `ok` / `error` / `message` are written last and so always
     * win: a marketplace that ever returned its own `ok` string cannot change
     * the meaning of a refusal. Only string keys are carried over; a JSON list
     * holds nothing a caller could address by name.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function postAccountPassthrough(string $path, array $payload): array
    {
        $reply = $this->accountPost($path, $payload);
        $outcome = self::outcome($reply);

        if (! is_array($reply)) {
            return $outcome;
        }

        $merged = [];

        foreach ($reply as $key => $value) {
            if (is_string($key)) {
                $merged[$key] = $value;
            }
        }

        $merged['ok'] = $outcome['ok'];
        $merged['error'] = $outcome['error'];
        $merged['message'] = $outcome['message'];

        return $merged;
    }

    /**
     * The decoded reply, or the reason the call never produced one.
     *
     * @param  array<string, mixed>  $payload
     * @return array<array-key, mixed>|'not_connected'|'unreachable'
     */
    private function accountPost(string $path, array $payload): array|string
    {
        $request = $this->accountRequest();

        if ($request === null) {
            return 'not_connected';
        }

        try {
            $json = $request->post(Marketplace::API_BASE.$path, $payload)->json();
        } catch (Throwable) {
            return 'unreachable';
        }

        return is_array($json) ? $json : 'unreachable';
    }

    /**
     * @param  array<array-key, mixed>|string  $reply
     * @return array{ok: bool, error: ?string, message: ?string}
     */
    private static function outcome(array|string $reply): array
    {
        if ($reply === 'not_connected') {
            return ['ok' => false, 'error' => 'not_connected', 'message' => 'Connect a Magna Account first.'];
        }

        if (is_string($reply)) {
            return ['ok' => false, 'error' => 'unreachable', 'message' => 'Could not reach the Magna licence server.'];
        }

        return [
            'ok' => (bool) ($reply['ok'] ?? false),
            'error' => is_string($reply['error'] ?? null) ? $reply['error'] : null,
            'message' => is_string($reply['message'] ?? null) ? $reply['message'] : null,
        ];
    }

    /**
     * Open a checkout for a paid product and get back what the Razorpay
     * widget needs.
     *
     * No price is sent. The marketplace computes the amount from the
     * publisher's terms, so a tampered browser can at worst buy a different
     * term at that term's correct price.
     *
     * @return array<string, mixed> the marketplace's order block plus `ok`, `error` and `message`
     */
    public function checkout(string $productSlug, string $term): array
    {
        return $this->postAccountPassthrough('/checkout/'.$productSlug, ['term' => $term]);
    }

    /**
     * Renew an annual licence that is close to expiry or already lapsed.
     * Same shape as checkout(); the marketplace prices it from the licence's
     * own product and term rather than from anything sent here.
     *
     * @return array<string, mixed> the marketplace's order block plus `ok`, `error` and `message`
     */
    public function renew(int $licenseId): array
    {
        return $this->postAccountPassthrough('/checkout/renew/'.$licenseId, []);
    }

    /**
     * Report what the widget handed back.
     *
     * A courtesy only: the marketplace settles the sale from Razorpay's
     * webhook, so this call grants nothing, and a failure here does not mean
     * the payment was lost.
     *
     * @return array<string, mixed> the marketplace's reply plus `ok`, `error` and `message`
     */
    public function confirmCheckout(int $orderId, string $paymentId, string $signature): array
    {
        return $this->postAccountPassthrough('/checkout/'.$orderId.'/confirm', [
            'razorpay_payment_id' => $paymentId,
            'razorpay_signature' => $signature,
        ]);
    }

    /**
     * Poll a sale while the webhook lands.
     *
     * @return array{status: string, license_id: ?int}|null null when unreachable
     */
    public function orderStatus(int $orderId): ?array
    {
        $json = $this->json($this->accountRequest()?->get(Marketplace::API_BASE.'/checkout/'.$orderId));

        if (! is_string($json['status'] ?? null)) {
            return null;
        }

        return [
            'status' => $json['status'],
            'license_id' => is_numeric($json['license_id'] ?? null) ? (int) $json['license_id'] : null,
        ];
    }

    /**
     * Activate this site on a wallet licence and get back the activation
     * token plus a download grant, in one signed response.
     *
     * @return array<string, mixed>|null verified payload
     */
    public function install(int $licenseId): ?array
    {
        $response = $this->accountRequest()?->post(
            Marketplace::API_BASE.'/account/licenses/'.$licenseId.'/install',
            ['core_version' => MagnaServiceProvider::VERSION],
        );

        return $this->signed($response);
    }

    /**
     * Activate by pasting a key directly (no wallet round trip).
     *
     * @return array<string, mixed>|null verified payload
     */
    public function activate(string $key): ?array
    {
        $domain = config('app.url');

        return $this->signed($this->post('/license/activate', [
            'key' => $key,
            'domain' => is_string($domain) ? $domain : '',
            'fingerprint' => InstallFingerprint::derive(),
            'core_version' => MagnaServiceProvider::VERSION,
        ]));
    }

    /**
     * The ~daily phone-home. Null means "could not confirm", not "invalid".
     *
     * @return array<string, mixed>|null verified payload
     */
    public function verify(string $token): ?array
    {
        return $this->signed($this->post('/license/verify', [
            'token' => $token,
            'fingerprint' => InstallFingerprint::derive(),
        ]));
    }

    /**
     * Trade an activation token for a short-lived, single-use download grant.
     *
     * @return array<array-key, mixed>|null
     */
    public function downloadUrl(string $token): ?array
    {
        $payload = $this->signed($this->post('/license/download-url', [
            'token' => $token,
            'fingerprint' => InstallFingerprint::derive(),
        ]));

        return is_array($payload['download'] ?? null) ? $payload['download'] : null;
    }

    /** Release this site's seat on a licence. */
    public function deactivate(string $token): bool
    {
        $response = $this->post('/license/deactivate', ['token' => $token]);

        return $response?->successful() ?? false;
    }

    /**
     * Short-lived cache for the three calls that render the Magna Account
     * page.
     *
     * Without this the page makes four blocking outbound requests before it
     * paints — wallet, panel, invoices, plus Account Centre's own sites
     * call. Against an unreachable marketplace that is four timeouts back to
     * back, and the admin appears to hang. Sixty seconds is short enough
     * that a licence bought moments ago still shows up on the next refresh.
     *
     * Failures are deliberately NOT cached: a blip must not persist for a
     * minute, and null already means "could not confirm" everywhere it is
     * consumed.
     *
     * @param  callable():(array<array-key, mixed>|null)  $fetch
     * @return array<array-key, mixed>|null
     */
    private function cached(string $key, callable $fetch): ?array
    {
        $settings = AccountCentreSettings::get();

        if (! $settings->connected || ! is_string($settings->token) || $settings->token === '') {
            return null;
        }

        // Keyed by the token so switching accounts never serves the previous
        // account's wallet from cache.
        $cacheKey = 'magna.licensing.'.$key.'.'.hash('sha256', $settings->token);

        /** @var array<array-key, mixed>|null $hit */
        $hit = Cache::get($cacheKey);

        if ($hit !== null) {
            return $hit;
        }

        $fresh = $fetch();

        if ($fresh !== null) {
            Cache::put($cacheKey, $fresh, self::PAGE_CACHE_SECONDS);
        }

        return $fresh;
    }

    /**
     * Drop the cached page data — called after any action that changes the
     * wallet, so the admin sees its own change immediately rather than up to
     * a minute later.
     */
    public function forgetCache(): void
    {
        $settings = AccountCentreSettings::get();

        if (! is_string($settings->token) || $settings->token === '') {
            return;
        }

        $suffix = hash('sha256', $settings->token);

        foreach (['wallet', 'panel', 'invoices'] as $key) {
            Cache::forget('magna.licensing.'.$key.'.'.$suffix);
        }
    }

    /**
     * The Magna Account bearer token this site connected with, or null when
     * the site has no account connection yet.
     */
    private function accountRequest(): ?PendingRequest
    {
        $settings = AccountCentreSettings::get();

        if (! $settings->connected || ! is_string($settings->token) || $settings->token === '') {
            return null;
        }

        return Http::timeout(Marketplace::REQUEST_TIMEOUT)->acceptJson()->asJson()->withToken($settings->token);
    }

    /** @param array<string, mixed> $payload */
    private function post(string $path, array $payload): ?Response
    {
        try {
            return Http::timeout(Marketplace::REQUEST_TIMEOUT)->acceptJson()->asJson()
                ->post(Marketplace::API_BASE.$path, $payload);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Open a signed envelope. A failed signature is indistinguishable from
     * an outage on purpose — see the class docblock.
     *
     * @return array<string, mixed>|null
     */
    private function signed(?Response $response): ?array
    {
        $json = $this->json($response);

        return $json === [] ? null : SignedPayload::open($json);
    }

    /** @return array<string, mixed> */
    private function json(?Response $response): array
    {
        if ($response === null || ! $response->successful()) {
            return [];
        }

        $json = $response->json();

        return is_array($json) ? $json : [];
    }
}
