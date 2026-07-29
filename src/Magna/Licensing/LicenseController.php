<?php

declare(strict_types=1);

namespace Magna\Licensing;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Magna\Admin\Pages\AccountCentrePage;
use RuntimeException;

/**
 * The admin-facing actions behind the Licences section of the Magna Account
 * page. Thin: authorize, one service call, redirect with a flash message.
 *
 * Every action is gated on `licensing.manage` and CSRF protected by the `web`
 * group; none of them accept a licence key from anywhere but the signed-in
 * admin's own form.
 */
class LicenseController
{
    public function __construct(
        private readonly LicenseClient $client,
        private readonly LicenseStore $store,
        private readonly LicenseInstaller $installer,
        private readonly LicenseGuard $guard,
    ) {}

    /**
     * Paste any licence key into the connected account's wallet.
     *
     * One field, two credential types. A Magna key has a fixed shape, so it
     * can be recognised on sight; anything else is tried as an external
     * purchase code. The customer should not have to know which queue their
     * key belongs in — they have a key, they want their product.
     *
     * A failure on the external path is reported generically. Saying "Envato
     * could not be reached" to someone who mistyped a Magna key is worse
     * than useless: it names a system they have never heard of and hides the
     * actual mistake.
     */
    public function redeem(Request $request): RedirectResponse
    {
        $this->authorize();

        $data = $request->validate([
            'key' => ['required', 'string', 'max:64'],
        ]);

        $key = trim($data['key']);

        if ($this->looksLikeMagnaKey($key)) {
            $result = $this->client->redeem($key);

            return $result['ok'] === true
                ? $this->ok('Licence added to your Magna Account.')
                : $this->fail($result['message'] ?? 'That licence key could not be added.');
        }

        $result = $this->client->redeemEnvato($key);

        if ($result['ok'] === true) {
            return $this->ok('Purchase verified and added to your Magna Account.');
        }

        // Channel-specific failures leak an implementation detail the
        // customer cannot act on — answer as the key simply not being valid.
        $opaque = in_array($result['error'] ?? '', ['envato_unavailable', 'envato_item_unmapped', 'license_not_found'], true);

        return $this->fail($opaque
            ? 'That licence key was not recognised. Check it and try again.'
            : ($result['message'] ?? 'That licence key could not be added.'));
    }

    /** `MAGNA-` followed by six groups of four — see Support\LicenseKey. */
    private function looksLikeMagnaKey(string $key): bool
    {
        return preg_match('/^MAGNA(?:-[0-9A-Za-z]{4}){6}$/i', $key) === 1;
    }

    /**
     * Activate this site on a wallet licence and install the product in one
     * step (licensing-plan v0.2 W1). The activation token is cached locally
     * only after the response's signature has been verified.
     */
    public function install(Request $request): RedirectResponse
    {
        $this->authorize();

        $data = $request->validate([
            'license_id' => ['required', 'integer'],
            'product_slug' => ['required', 'string', 'max:255'],
        ]);

        try {
            $message = $this->installer->installLicense((int) $data['license_id'], $data['product_slug']);
        } catch (RuntimeException $e) {
            return $this->fail($e->getMessage());
        }

        return $this->ok($message);
    }

    /** Start a product's free trial, into the connected account's wallet (§7). */
    public function startTrial(Request $request): RedirectResponse
    {
        $this->authorize();

        $data = $request->validate([
            'product_slug' => ['required', 'string', 'max:255'],
        ]);

        $result = $this->client->startTrial($data['product_slug']);

        return $result['ok'] === true
            ? $this->ok('Trial started for '.$data['product_slug'].'. Install it from your licences below.')
            : $this->fail($result['message'] ?? 'That trial could not be started.');
    }

    /** Pull and install the newest entitled version (W5). */
    public function update(Request $request): RedirectResponse
    {
        $this->authorize();

        $data = $request->validate([
            'product_slug' => ['required', 'string', 'max:255'],
        ]);

        try {
            $message = $this->installer->update($data['product_slug']);
        } catch (RuntimeException $e) {
            return $this->fail($e->getMessage());
        }

        return $this->ok($message);
    }

    /**
     * Release this site's seat so the licence can be moved to another domain
     * (§6.3). The local cache is cleared regardless of whether the server
     * call succeeded — a site must always be able to forget its own licence,
     * the same rule Account Centre's disconnect follows.
     */
    public function deactivate(Request $request): RedirectResponse
    {
        $this->authorize();

        $data = $request->validate([
            'product_slug' => ['required', 'string', 'max:255'],
        ]);

        $entry = $this->store->get($data['product_slug']);

        if ($entry === null) {
            return $this->fail('No licence for that product is active on this site.');
        }

        $released = $this->client->deactivate($entry->token);
        $this->store->forget($data['product_slug']);

        // Forgetting the entry also clears any licensing-imposed lock; the
        // plugin is now simply an unlicensed local install, which the gate
        // does not block (free plugins have no entry either).

        return $this->ok($released
            ? 'Licence released — the seat is free to use on another domain.'
            : 'Licence removed from this site. The seat could not be released remotely; release it from your account page.');
    }

    /**
     * Force the ~daily phone-home now, from the admin — and enforce what it
     * finds. This is the "stop waiting for the schedule" path: an operator
     * who has just cancelled a corporate licence can have the client's site
     * act on it within seconds rather than at the next cycle.
     */
    public function verifyNow(LicenseEnforcer $enforcer): RedirectResponse
    {
        $this->authorize();

        $count = $this->guard->refreshAll();
        $result = $enforcer->sync();

        $message = $count === 0
            ? 'No licence could be re-verified — the licence server may be unreachable.'
            : $count.' licence(s) re-verified.';

        if ($result['locked'] !== []) {
            $message .= ' Disabled: '.implode(', ', $result['locked']).'.';
        }

        if ($result['restored'] !== []) {
            $message .= ' Re-enabled: '.implode(', ', $result['restored']).'.';
        }

        return $this->ok($message);
    }

    /**
     * Download a tax invoice.
     *
     * Streamed through this site rather than linked to the marketplace: the
     * bearer token that authorises the fetch belongs to the site, not to the
     * admin's browser, and handing it out to make a link work would trade a
     * server credential for a convenience.
     */
    public function invoice(int $invoice): Response|RedirectResponse
    {
        $this->authorize();

        $document = $this->client->invoiceDocument($invoice);

        if ($document === null) {
            return $this->fail('That invoice could not be downloaded. Try again in a moment.');
        }

        return response($document['body'], 200, [
            'Content-Type' => $document['mime'],
            'Content-Disposition' => 'attachment; filename="'.$document['filename'].'"',
        ]);
    }

    /**
     * `licensing.manage`, not `settings.view` — these actions download and
     * enable third-party code on this server, so a read-only settings role
     * (support, auditor) must not reach them, and neither should a settings
     * administrator who was never granted licensing explicitly. Invoice
     * download rides the same permission: it streams the account's billing
     * records, which are no less sensitive than the wallet itself.
     */
    private function authorize(): void
    {
        abort_unless(auth()->user()?->can('licensing.manage') ?? false, 403);
    }

    private function ok(string $message): RedirectResponse
    {
        // Anything that reached here changed the wallet, so the cached page
        // data must go — otherwise the admin performs an action and sees no
        // change for up to a minute.
        $this->client->forgetCache();

        return redirect(AccountCentrePage::getUrl())
            ->with('account_centre_status', $message);
    }

    private function fail(string $message): RedirectResponse
    {
        return redirect(AccountCentrePage::getUrl())
            ->with('account_centre_error', $message);
    }
}
