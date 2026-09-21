<?php

declare(strict_types=1);

namespace Magna\Support;

use Carbon\CarbonImmutable;
use Magna\Install\EnvWriter;
use Throwable;

/**
 * A debug session that closes itself.
 *
 * APP_DEBUG on a live site renders stack traces, SQL and environment dumps to
 * every visitor, so the panel used to offer a one-click toggle and then the
 * toggle was removed outright. Both extremes are wrong: an operator debugging
 * a production incident has a real need, and the danger is not switching it on
 * -- it is forgetting to switch it back off, which leaves a site disclosing
 * indefinitely.
 *
 * So the panel may open a BOUNDED window. Opening stamps an expiry beside the
 * flag; any later request finds the stamp expired and puts the flag back. No
 * cron, no queue worker, nothing to install: the next visitor closes it.
 *
 * A flag an operator set by hand at the shell carries no stamp and is left
 * exactly alone -- that decision stays theirs, which is the part the removal
 * got right.
 */
final class DebugWindow
{
    /** How long the panel may hold debug open, and the longest it may ask for. */
    public const DEFAULT_MINUTES = 30;

    public const MAX_MINUTES = 120;

    public function __construct(
        private readonly EnvWriter $env,
        private readonly string $stampPath,
    ) {}

    /** @return CarbonImmutable when the window closes */
    public function open(int $minutes = self::DEFAULT_MINUTES): CarbonImmutable
    {
        $minutes = max(1, min($minutes, self::MAX_MINUTES));
        $expiresAt = CarbonImmutable::now()->addMinutes($minutes);

        $this->env->set(['APP_DEBUG' => 'true']);
        file_put_contents($this->stampPath, (string) json_encode([
            'expires_at' => $expiresAt->toIso8601String(),
        ], JSON_PRETTY_PRINT));

        $this->forgetCachedConfig();

        return $expiresAt;
    }

    public function close(): void
    {
        $this->env->set(['APP_DEBUG' => 'false']);

        if (is_file($this->stampPath)) {
            @unlink($this->stampPath);
        }

        $this->forgetCachedConfig();
    }

    /** When the panel-opened window closes, or null if no window is open. */
    public function expiresAt(): ?CarbonImmutable
    {
        if (! is_file($this->stampPath)) {
            return null;
        }

        /** @var mixed $data */
        $data = json_decode((string) file_get_contents($this->stampPath), true);

        if (! is_array($data) || ! is_string($data['expires_at'] ?? null)) {
            return null;
        }

        try {
            return CarbonImmutable::parse($data['expires_at']);
        } catch (Throwable) {
            // An unparseable stamp is treated as no window at all, which
            // enforce() then closes -- failing towards the flag being off.
            return null;
        }
    }

    public function isOpen(): bool
    {
        return $this->expiresAt()?->isFuture() ?? false;
    }

    /**
     * Put the flag back if a panel-opened window has run out.
     *
     * Called on every request, so it does nothing at all -- one stat call --
     * unless a stamp exists. The current request finishes with debug still on;
     * `app.debug` is deliberately not mutated mid-request, because Livewire
     * records its render timing only when debug was set at the start of one
     * and flipping it underneath produces "Undefined variable $start".
     */
    public function enforce(): void
    {
        if (! is_file($this->stampPath)) {
            return;
        }

        if ($this->isOpen()) {
            return;
        }

        $this->close();
    }

    /**
     * A cached config file pins APP_DEBUG at whatever it held when it was
     * built, so the .env write would not be read until it is gone.
     */
    private function forgetCachedConfig(): void
    {
        $cached = base_path('bootstrap/cache/config.php');

        if (is_file($cached)) {
            @unlink($cached);
        }
    }
}
