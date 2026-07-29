<?php

declare(strict_types=1);

namespace Magna\Auth;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;

/**
 * Exponential-backoff brute-force protection for the login page.
 *
 * Each failure increases the lockout window: base * 2^(excess failures).
 * Failures are counted twice over — once per identity and once per IP (see
 * scopes()) — under hashed cache keys, so neither the email nor the address
 * leaks to the cache backend. The identifier is passed in explicitly rather than read from the
 * request, so it works the same whether the caller is a plain controller or a
 * Livewire component (the Filament login page) where the email is component
 * state, not a request input.
 */
class LoginThrottle
{
    private int $maxAttempts;

    private int $baseLockoutSeconds;

    private int $maxLockoutSeconds;

    public function __construct()
    {
        $this->maxAttempts = Config::integer('magna.login.max_attempts', 5);
        $this->baseLockoutSeconds = Config::integer('magna.login.base_lockout_seconds', 30);
        $this->maxLockoutSeconds = Config::integer('magna.login.max_lockout_seconds', 900);
    }

    public function isLocked(string $identifier): bool
    {
        foreach (array_keys($this->scopes($identifier)) as $scope) {
            if (Cache::has($this->lockKey($scope))) {
                return true;
            }
        }

        return false;
    }

    /** Seconds until the current lockout expires, or 0 if not locked. */
    public function availableIn(string $identifier): int
    {
        $longest = 0;

        foreach (array_keys($this->scopes($identifier)) as $scope) {
            $expiresAt = Cache::get($this->lockKey($scope));

            if (is_int($expiresAt)) {
                $longest = max($longest, $expiresAt - now()->getTimestamp());
            }
        }

        return max(0, $longest);
    }

    public function hit(string $identifier): void
    {
        foreach ($this->scopes($identifier) as $scope => $maxAttempts) {
            $attemptsKey = $this->attemptsKey($scope);
            $cached = Cache::get($attemptsKey, 0);
            $attempts = (is_int($cached) ? $cached : 0) + 1;

            Cache::put($attemptsKey, $attempts, now()->addDay());

            if ($attempts >= $maxAttempts) {
                $excess = $attempts - $maxAttempts;
                $lockoutSeconds = (int) min(
                    $this->baseLockoutSeconds * (2 ** $excess),
                    $this->maxLockoutSeconds,
                );

                // Store the expiry timestamp so availableIn() can compute TTL.
                Cache::put($this->lockKey($scope), now()->addSeconds($lockoutSeconds)->getTimestamp(), $lockoutSeconds);
            }
        }
    }

    public function clear(string $identifier): void
    {
        foreach (array_keys($this->scopes($identifier)) as $scope) {
            Cache::forget($this->attemptsKey($scope));
            Cache::forget($this->lockKey($scope));
        }
    }

    /**
     * Two independent counters, and whichever trips first locks the attempt.
     *
     * Keying solely on identity+IP (the original behaviour) has one hole and
     * one nuisance. The hole: a distributed spray that tries one password per
     * IP never accumulates against anything, so the lockout never engages. The
     * nuisance: everyone behind one office NAT shares a counter, so one user's
     * typo can lock out a colleague.
     *
     * Splitting them fixes both — the identity counter sees the spray no
     * matter how many IPs it comes from, and the IP counter sees one host
     * working through a list of accounts. Its ceiling is deliberately looser,
     * since a shared egress IP legitimately produces more failures than one
     * person does.
     *
     * @return array<string, int> cache-key scope => attempts allowed
     */
    private function scopes(string $identifier): array
    {
        $ip = (string) request()->ip();

        return [
            'id:'.hash('sha256', mb_strtolower($identifier)) => $this->maxAttempts,
            'ip:'.hash('sha256', $ip) => $this->maxAttempts * 4,
        ];
    }

    private function attemptsKey(string $scope): string
    {
        return 'login.attempts:'.$scope;
    }

    private function lockKey(string $scope): string
    {
        return 'login.lock:'.$scope;
    }
}
