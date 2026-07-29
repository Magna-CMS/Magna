<?php

declare(strict_types=1);

namespace Magna\Licensing;

use Illuminate\Support\Facades\Log;
use Magna\Plugins\PluginManager;
use Throwable;

/**
 * Acts on what the daily verify learned.
 *
 * A licence ending is not just a flag — the product has to actually stop.
 * This runs straight after every verify pass and after any admin-triggered
 * re-check, so the window between the operator cancelling a corporate
 * licence and the client's site ceasing to use the product is one verify
 * cycle, not "whenever someone notices".
 *
 * Symmetry matters as much as the lock: a licence that is renewed, resumed,
 * or extended re-enables the product automatically — but ONLY if licensing
 * was what disabled it (autoDisabled). A plugin an admin switched off by
 * hand stays off.
 */
class LicenseEnforcer
{
    public function __construct(
        private readonly LicenseStore $store,
        private readonly LicenseGate $gate,
        private readonly PluginManager $plugins,
    ) {}

    /**
     * Reconcile every licensed product's on-disk enablement with its licence
     * state.
     *
     * @return array{locked: list<string>, restored: list<string>}
     */
    public function sync(): array
    {
        $locked = [];
        $restored = [];

        foreach ($this->store->all() as $entry) {
            $state = $this->gate->stateOf($entry->productSlug);

            if ($state === LicenseState::Locked && ! $entry->autoDisabled) {
                if ($this->disable($entry->productSlug)) {
                    $this->store->put($entry->withAutoDisabled(true));
                    $locked[] = $entry->productSlug;
                }

                continue;
            }

            if ($state !== LicenseState::Locked && $entry->autoDisabled) {
                if ($this->enable($entry->productSlug)) {
                    $this->store->put($entry->withAutoDisabled(false));
                    $restored[] = $entry->productSlug;
                }
            }
        }

        return ['locked' => $locked, 'restored' => $restored];
    }

    /**
     * Reports what actually happened, not what was attempted.
     *
     * This previously logged "disabled" and returned true even when
     * PluginManager::disable() threw — so an entry could be marked
     * autoDisabled, the admin told the product had been stopped, and the log
     * assert it, while the plugin was still enabled and booting on every
     * request. A licence control that lies about enforcement is worse than one
     * that fails loudly.
     */
    private function disable(string $productSlug): bool
    {
        try {
            $this->plugins->disable($productSlug);
        } catch (Throwable $e) {
            Log::error("Licensing: could not disable [{$productSlug}] — the product may still be running.", [
                'product' => $productSlug,
                'error' => $e->getMessage(),
            ]);

            return false;
        }

        Log::warning("Licensing: [{$productSlug}] disabled — licence no longer valid.");

        return true;
    }

    private function enable(string $productSlug): bool
    {
        try {
            $this->plugins->enable($productSlug);

            Log::info("Licensing: [{$productSlug}] re-enabled — licence valid again.");

            return true;
        } catch (Throwable $e) {
            // Files removed, dependency missing, incompatible version — leave
            // it flagged so the next pass tries again rather than silently
            // clearing the flag.
            Log::warning("Licensing: could not re-enable [{$productSlug}]: {$e->getMessage()}");

            return false;
        }
    }
}
