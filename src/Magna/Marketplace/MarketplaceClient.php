<?php

declare(strict_types=1);

namespace Magna\Marketplace;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Magna\AccountCentre\AccountCentreSettings;
use Magna\MagnaServiceProvider;

/**
 * Reads the official marketplace catalog. The catalog is cached and this client
 * never throws into the caller — if the marketplace is unreachable it returns
 * the cached list (or an empty list), so the admin panel degrades gracefully.
 */
class MarketplaceClient
{
    /**
     * Set by plugins() when the catalog had to be fetched fresh (cache miss)
     * and that fetch failed — distinguishes "the marketplace is genuinely
     * empty" from "we couldn't reach it," without exposing anything about
     * the marketplace's URL to whatever's asking. Check after calling
     * plugins() on the SAME instance.
     */
    private bool $lastFetchFailed = false;

    /**
     * All marketplace plugins compatible with the running core version.
     *
     * @return list<PluginListing>
     */
    public function plugins(): array
    {
        $this->lastFetchFailed = false;
        $raw = Cache::get(Marketplace::CACHE_KEY);

        if (! is_array($raw)) {
            $raw = $this->fetch('/plugins');
            if ($raw !== null) {
                Cache::put(Marketplace::CACHE_KEY, $raw, Marketplace::CACHE_TTL);
            } else {
                $this->lastFetchFailed = true;
            }
        }

        return $this->toCompatibleListings(is_array($raw) ? $raw : []);
    }

    /** Cache key for the theme catalog — kept apart from the plugin one. */
    private const THEME_CACHE_KEY = Marketplace::CACHE_KEY.'.themes';

    /**
     * All marketplace THEMES compatible with the running core version.
     *
     * A separate call rather than a filter over plugins(): the catalog
     * endpoint answers with plugins unless asked otherwise, so a site that
     * never looks at themes never fetches them — and a marketplace that
     * predates themes simply returns nothing here.
     *
     * @return list<PluginListing>
     */
    public function themes(): array
    {
        $this->lastFetchFailed = false;
        $raw = Cache::get(self::THEME_CACHE_KEY);

        if (! is_array($raw)) {
            $raw = $this->fetch('/plugins', ['type' => 'theme']);

            if ($raw !== null) {
                Cache::put(self::THEME_CACHE_KEY, $raw, Marketplace::CACHE_TTL);
            } else {
                $this->lastFetchFailed = true;
            }
        }

        return $this->toCompatibleListings(is_array($raw) ? $raw : []);
    }

    /** Whether the most recent plugins()/themes() call had to fetch fresh and that fetch failed. */
    public function wasUnreachable(): bool
    {
        return $this->lastFetchFailed;
    }

    /** A single plugin by package name, or null if unknown/unreachable. */
    public function plugin(string $package): ?PluginListing
    {
        foreach ($this->plugins() as $listing) {
            if ($listing->package === $package) {
                return $listing;
            }
        }

        // Not in the cached catalog — try a direct detail lookup.
        $raw = $this->fetch('/plugins/'.$package);

        return is_array($raw) ? PluginListing::fromArray($raw) : null;
    }

    /**
     * Available published version strings for a package (as ordered by the API).
     *
     * @return list<string>
     */
    public function versions(string $package): array
    {
        $raw = $this->fetch('/plugins/'.$package.'/versions');
        if (! is_array($raw)) {
            return [];
        }

        $versions = [];
        foreach ($raw as $row) {
            $version = is_array($row) ? ($row['version'] ?? null) : $row;
            if (is_string($version)) {
                $versions[] = $version;
            }
        }

        return $versions;
    }

    /** Drop the cached catalog (e.g. after installing a plugin). */
    public function clearCache(): void
    {
        Cache::forget(Marketplace::CACHE_KEY);
        Cache::forget(self::THEME_CACHE_KEY);
    }

    /**
     * Tell the marketplace a successful install happened, so it can show install
     * counts. Best-effort: never throws and never blocks the install on failure.
     */
    public function reportInstall(string $package): void
    {
        try {
            Http::timeout(Marketplace::REQUEST_TIMEOUT)
                ->acceptJson()
                ->post(Marketplace::API_BASE.'/plugins/'.$package.'/installed');
        } catch (\Throwable) {
            // Stats are non-critical — ignore any failure.
        }
    }

    /**
     * Submit (or update) this site's review: a 1–5 star rating with an optional
     * written review and display name. Requires a connected Magna Account —
     * returns false without a network call if this site isn't connected.
     */
    public function submitReview(string $package, int $stars, ?string $review = null, ?string $author = null): bool
    {
        $token = AccountCentreSettings::get()->token;
        if ($token === null) {
            return false;
        }

        return $this->postAuthed('/plugins/'.$package.'/ratings', array_filter([
            'stars' => $stars,
            'review' => $review,
            'author' => $author,
        ], fn (mixed $v): bool => $v !== null && $v !== ''), $token);
    }

    /**
     * Flag a plugin to the marketplace operators. Requires a connected Magna
     * Account — returns false without a network call if this site isn't connected.
     */
    public function reportPlugin(string $package, string $reason, ?string $details = null): bool
    {
        $token = AccountCentreSettings::get()->token;
        if ($token === null) {
            return false;
        }

        return $this->postAuthed('/plugins/'.$package.'/reports', array_filter([
            'reason' => $reason,
            'details' => $details,
        ], fn (mixed $v): bool => $v !== null && $v !== ''), $token);
    }

    /**
     * The written reviews for a package, newest first (empty on any failure).
     *
     * @return list<array<array-key, mixed>>
     */
    public function reviews(string $package): array
    {
        $raw = $this->fetch('/plugins/'.$package.'/reviews');
        if (! is_array($raw)) {
            return [];
        }

        return array_values(array_filter($raw, 'is_array'));
    }

    /**
     * POST a small JSON payload to the marketplace with this site's Magna
     * Account bearer token attached — for the endpoints that require a
     * connected account (ratings, reports).
     *
     * @param  array<string, mixed>  $payload
     */
    private function postAuthed(string $path, array $payload, string $token): bool
    {
        try {
            return Http::timeout(Marketplace::REQUEST_TIMEOUT)
                ->acceptJson()
                ->asJson()
                ->withToken($token)
                ->post(Marketplace::API_BASE.$path, $payload)
                ->successful();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * GET a marketplace endpoint. Returns the decoded JSON array, or null on any
     * failure (network error, non-2xx, or a non-array body).
     *
     * @param  array<string, string>  $query  extra query parameters
     * @return array<array-key, mixed>|null
     */
    private function fetch(string $path, array $query = []): ?array
    {
        try {
            $response = Http::timeout(Marketplace::REQUEST_TIMEOUT)
                ->acceptJson()
                ->get(Marketplace::API_BASE.$path, ['magna' => MagnaServiceProvider::VERSION] + $query);

            if (! $response->successful()) {
                return null;
            }

            $json = $response->json();

            return is_array($json) ? $json : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Map raw catalog rows to listings, dropping malformed entries and any that
     * aren't compatible with the running core version.
     *
     * @param  array<array-key, mixed>  $raw
     * @return list<PluginListing>
     */
    private function toCompatibleListings(array $raw): array
    {
        $core = MagnaServiceProvider::VERSION;
        $listings = [];

        foreach ($raw as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $listing = PluginListing::fromArray($entry);
            if ($listing !== null && $listing->isCompatibleWith($core)) {
                $listings[] = $listing;
            }
        }

        return $listings;
    }
}
