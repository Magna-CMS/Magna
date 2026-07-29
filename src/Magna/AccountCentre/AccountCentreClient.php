<?php

declare(strict_types=1);

namespace Magna\AccountCentre;

use Illuminate\Support\Facades\Http;
use Magna\Licensing\SignedPayload;
use Magna\Marketplace\Marketplace;

/**
 * Talks to Update Manager's Magna Account endpoints on managemagna.jrstudios.dev
 * — the exchange step (server-to-server, right after the browser comes back
 * with a code) and the two authenticated calls (sites, disconnect) made with
 * the bearer token that exchange() returns. Best-effort like
 * MarketplaceClient/UpdateCheckClient: never throws, callers handle null.
 */
class AccountCentreClient
{
    /**
     * @return array{token: string, account: array{name: string, email: string}}|null
     */
    public function exchange(string $code, string $siteUrl, string $fingerprint, ?string $siteLabel): ?array
    {
        $raw = $this->post('/account/exchange', array_filter([
            'code' => $code,
            'site_url' => $siteUrl,
            'fingerprint' => $fingerprint,
            'site_label' => $siteLabel,
        ], fn (mixed $v): bool => $v !== null));

        $raw = $this->openEnvelope($raw);

        if (! is_array($raw) || ! is_string($raw['token'] ?? null) || ! is_array($raw['account'] ?? null)) {
            return null;
        }

        $accountName = $raw['account']['name'] ?? null;
        $accountEmail = $raw['account']['email'] ?? null;

        return [
            'token' => $raw['token'],
            'account' => [
                'name' => is_string($accountName) ? $accountName : '',
                'email' => is_string($accountEmail) ? $accountEmail : '',
            ],
        ];
    }

    /**
     * Every Magna CMS install connected to the same account as this one.
     *
     * @return list<array{site_url: string, site_label: ?string, is_this_site: bool, connected_at: string, last_seen_at: ?string}>
     */
    public function sites(string $token): array
    {
        $raw = $this->get('/account/sites', $token);
        if (! is_array($raw) || ! is_array($raw['sites'] ?? null)) {
            return [];
        }

        $sites = [];
        foreach ($raw['sites'] as $site) {
            if (! is_array($site) || ! is_string($site['site_url'] ?? null) || ! is_string($site['connected_at'] ?? null)) {
                continue;
            }

            $sites[] = [
                'site_url' => $site['site_url'],
                'site_label' => is_string($site['site_label'] ?? null) ? $site['site_label'] : null,
                'is_this_site' => (bool) ($site['is_this_site'] ?? false),
                'connected_at' => $site['connected_at'],
                'last_seen_at' => is_string($site['last_seen_at'] ?? null) ? $site['last_seen_at'] : null,
            ];
        }

        return $sites;
    }

    public function disconnect(string $token): void
    {
        // Best-effort: the local disconnect (AccountCentreSettings cleared)
        // must never be blocked by this call failing — the site always gets
        // to forget its own connection even if Update Manager is unreachable.
        $this->post('/account/disconnect', [], $token);
    }

    /**
     * Open a signed envelope when the marketplace sends one.
     *
     * The bearer token this returns authorises the wallet, installs, and
     * deactivations — it is worth exactly as much as a licence response, which
     * has been Ed25519-signed all along. TLS alone protects it otherwise, so a
     * TLS-terminating proxy or a compromised marketplace could hand this site
     * a token of its choosing.
     *
     * Verify-if-present rather than require, because the server has to publish
     * the envelope before it can be demanded; a *failed* signature is always
     * refused, so this can only ever be as weak as the previous behaviour and
     * never weaker. Set `magna.account_centre.require_signed_exchange` once
     * Update Manager signs the response.
     *
     * @param  array<array-key, mixed>|null  $raw
     * @return array<array-key, mixed>|null
     */
    private function openEnvelope(?array $raw): ?array
    {
        $required = (bool) config('magna.account_centre.require_signed_exchange', false);
        $isEnvelope = is_array($raw) && isset($raw['data'], $raw['signature']);

        if (! $isEnvelope) {
            return $required ? null : $raw;
        }

        /** @var array<string, mixed> $raw */
        return SignedPayload::open($raw);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<array-key, mixed>|null
     */
    private function post(string $path, array $payload, ?string $token = null): ?array
    {
        try {
            $request = Http::timeout(Marketplace::REQUEST_TIMEOUT)->acceptJson()->asJson();
            if ($token !== null) {
                $request = $request->withToken($token);
            }

            $response = $request->post(Marketplace::API_BASE.$path, $payload);
            if (! $response->successful()) {
                return null;
            }

            $json = $response->json();

            return is_array($json) ? $json : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /** @return array<array-key, mixed>|null */
    private function get(string $path, string $token): ?array
    {
        try {
            $response = Http::timeout(Marketplace::REQUEST_TIMEOUT)->acceptJson()->withToken($token)->get(Marketplace::API_BASE.$path);
            if (! $response->successful()) {
                return null;
            }

            $json = $response->json();

            return is_array($json) ? $json : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
