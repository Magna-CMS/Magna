<?php

declare(strict_types=1);

namespace Magna\Licensing;

/**
 * Turns the wallet's raw activation rows into something a licence row can
 * render: how many seats are in use, and which sites are holding them.
 *
 * The wallet has carried `activation_limit` and `activations[]` since the
 * seat model existed, and the account page rendered neither — so a licence
 * that was full looked exactly like one with room, and the first anyone knew
 * of a limit was an install that refused. The arithmetic lives here rather
 * than in the Blade because views are held to fetching nothing and deciding
 * little, and because "how many seats are used" is a rule worth testing
 * directly.
 */
final class SeatSummary
{
    /**
     * Decorate each wallet row with `seats` and `seats_used`.
     *
     * @param  list<array<string, mixed>>  $licenses
     * @return list<array<string, mixed>>
     */
    public static function decorate(array $licenses): array
    {
        $thisSite = self::thisSiteDomain();

        return array_map(
            static function (array $license) use ($thisSite): array {
                $license['seats'] = self::seats($license, $thisSite);
                $license['seats_used'] = count($license['seats']);

                return $license;
            },
            $licenses,
        );
    }

    /**
     * This site's domain as the licence server records it — the host of
     * APP_URL.
     *
     * The single place that answer is derived. LicenseDeactivator matches an
     * activation by the same value when it has no token to present, and the
     * two disagreeing would mean the page marking one row "this site" while
     * the release released another.
     */
    public static function thisSiteDomain(): ?string
    {
        $appUrl = config('app.url');
        $host = is_string($appUrl) ? parse_url($appUrl, PHP_URL_HOST) : null;

        return is_string($host) && $host !== '' ? $host : null;
    }

    /**
     * One entry per seat, newest activation of a domain winning.
     *
     * Keyed by domain because that is what a seat is: a site rebuilt under a
     * new APP_KEY reports a fresh fingerprint, and listing both rows would
     * tell the owner they are using two seats for one website.
     *
     * @param  array<string, mixed>  $license
     * @return list<array{id: string, domain: string, is_dev: bool, is_this_site: bool}>
     */
    private static function seats(array $license, ?string $thisSite): array
    {
        $rows = is_array($license['activations'] ?? null) ? $license['activations'] : [];
        $seats = [];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $domain = $row['site_domain'] ?? null;
            $id = $row['id'] ?? null;

            if (! is_string($domain) || $domain === '' || ! is_string($id) && ! is_int($id)) {
                continue;
            }

            $seats[$domain] = [
                'id' => (string) $id,
                'domain' => $domain,
                'is_dev' => (bool) ($row['is_dev'] ?? false),
                'is_this_site' => $thisSite !== null && $domain === $thisSite,
            ];
        }

        ksort($seats);

        return array_values($seats);
    }
}
