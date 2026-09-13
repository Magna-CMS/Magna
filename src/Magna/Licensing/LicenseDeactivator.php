<?php

declare(strict_types=1);

namespace Magna\Licensing;

use Magna\Plugins\Exceptions\DependencyException;
use Magna\Plugins\PluginManager;
use Magna\Plugins\PluginRecord;
use Throwable;

/**
 * Releasing a licence seat from this site, end to end: spend the local
 * token when one exists, fall back to releasing through the account when
 * the site has drifted (plugin uninstalled, key re-issued), and always
 * stop the product here — a seat that is free for another domain must not
 * keep running on this one.
 *
 * Extracted from LicenseController::deactivate() (per the collaborator
 * pattern): a controller carrying a two-path release flow, cache
 * invalidation, a three-branch disable fallback and four message shapes was
 * the thin-controller rule's textbook violation.
 */
class LicenseDeactivator
{
    public function __construct(
        private readonly LicenseClient $client,
        private readonly LicenseStore $store,
        private readonly PluginManager $plugins,
    ) {}

    /**
     * @return array{ok: bool, message: string}
     */
    public function deactivate(string $productSlug): array
    {
        $entry = $this->store->get($productSlug);

        // No local token to spend. That is exactly the state a drifted site is
        // in — the plugin was uninstalled, or the marketplace re-issued the key
        // — and it used to be a dead end: Release answered "no licence is
        // active on this site" while the marketplace showed this site holding
        // the seat, so the seat could never be freed from here. The account
        // owns the licence, so the seat is released through it instead.
        if ($entry === null) {
            if (! $this->releaseSeatThroughAccount($productSlug)) {
                return ['ok' => false, 'message' => 'No licence for that product is active on this site.'];
            }

            $this->client->forgetCache();
            $this->disablePlugin($productSlug);

            return ['ok' => true, 'message' => 'Licence released through your Magna Account — the seat is free to use on another domain.'];
        }

        $released = $this->client->deactivate($entry->token);
        $this->store->forget($productSlug);

        // Releasing the seat has to stop the product here too. The seat is now
        // free for another domain, so leaving the plugin enabled would let one
        // key run on every site it was ever released from — release, activate
        // next door, repeat. LicenseGate refuses to boot it either way (the
        // plugin record remembers it needs a licence), but disabling it says
        // so honestly on the Plugins page instead of showing "Active" for
        // something that no longer loads.
        $disabled = $this->disablePlugin($productSlug);

        if (! $disabled) {
            return ['ok' => true, 'message' => $released
                ? 'Licence released — the seat is free to use on another domain. This site can no longer load the plugin; disable it from the Plugins page.'
                : 'Licence removed from this site. The seat could not be released remotely; release it from your account page.'];
        }

        return ['ok' => true, 'message' => $released
            ? 'Licence released and the plugin disabled here — the seat is free to use on another domain.'
            : 'Licence removed and the plugin disabled here. The seat could not be released remotely; release it from your account page.'];
    }

    /**
     * Frees this site's seat on an account-owned licence when no local token
     * exists to present.
     *
     * The wallet says which licence covers the product and whether one of its
     * activations belongs to this site; the activation is then matched by this
     * site's own domain, because the wallet deliberately never exposes other
     * sites' fingerprints.
     */
    private function releaseSeatThroughAccount(string $productSlug): bool
    {
        $appUrl = config('app.url');
        $host = is_string($appUrl) ? parse_url($appUrl, PHP_URL_HOST) : null;

        if (! is_string($host) || $host === '') {
            return false;
        }

        foreach ($this->client->wallet() ?? [] as $license) {
            if (($license['product_slug'] ?? null) !== $productSlug || ($license['active_on_this_site'] ?? false) !== true) {
                continue;
            }

            $activations = is_array($license['activations'] ?? null) ? $license['activations'] : [];

            foreach ($activations as $activation) {
                if (! is_array($activation) || ($activation['site_domain'] ?? null) !== $host) {
                    continue;
                }

                $licenseId = $license['id'] ?? null;
                $activationId = $activation['id'] ?? null;

                if (is_numeric($licenseId) && (is_string($activationId) || is_int($activationId))) {
                    return $this->client->deactivateSite((int) $licenseId, (string) $activationId);
                }
            }
        }

        return false;
    }

    /**
     * Switch off a plugin whose licence has just left this site.
     *
     * The manager is asked first, because it runs the plugin's own disable
     * hook and refuses when another enabled plugin still requires this one —
     * that refusal has to stand, so DependencyException is reported rather
     * than overridden.
     *
     * Anything else (a missing entry class, files deleted by hand) falls back
     * to flipping the record. The seat is already released at this point, so
     * leaving the row saying "enabled" would be a lie: the gate will not boot
     * it again either way.
     */
    private function disablePlugin(string $productSlug): bool
    {
        $record = PluginRecord::query()->where('name', $productSlug)->first();

        if ($record === null || ! $record->enabled) {
            return false;
        }

        try {
            $this->plugins->disable($productSlug);

            return true;
        } catch (DependencyException) {
            return false;
        } catch (Throwable) {
            $record->forceFill(['enabled' => false, 'disabled_at' => now()])->save();

            return true;
        }
    }
}
