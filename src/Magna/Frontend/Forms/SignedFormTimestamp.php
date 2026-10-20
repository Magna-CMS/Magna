<?php

declare(strict_types=1);

namespace Magna\Frontend\Forms;

/**
 * The signed render time a public form carries in `_ts`, so a submission
 * that arrives faster than a person could type is treated as a bot.
 *
 * Signed, so a bot cannot post an old time to pass; scoped by $purpose,
 * so a stamp issued for one plugin's form cannot be replayed at another's.
 * There is deliberately no maximum age: a form on a cached page keeps the
 * stamp of the render that was cached, and that must still pass.
 */
final class SignedFormTimestamp
{
    public const FIELD = '_ts';

    public static function issue(string $purpose): string
    {
        // The APP clock, not PHP's — one clock for issuing and checking,
        // and one a test (or a frozen-time run) can move coherently.
        $now = now()->getTimestamp();

        return $now.'.'.self::sign($purpose, $now);
    }

    /** Whether $stamp is genuine and at least $minSeconds old. */
    public static function passes(mixed $stamp, string $purpose, int $minSeconds): bool
    {
        if (! is_string($stamp) || ! str_contains($stamp, '.')) {
            return false;
        }

        [$issuedAt, $signature] = explode('.', $stamp, 2);
        if ($issuedAt === '' || ! ctype_digit($issuedAt) || strlen($issuedAt) > 12) {
            return false;
        }

        if (! hash_equals(self::sign($purpose, (int) $issuedAt), $signature)) {
            return false;
        }

        return (now()->getTimestamp() - (int) $issuedAt) >= $minSeconds;
    }

    private static function sign(string $purpose, int $issuedAt): string
    {
        return hash_hmac('sha256', $purpose.':'.$issuedAt, config()->string('app.key'));
    }
}
