<?php

declare(strict_types=1);

namespace Magna\Frontend;

use Illuminate\Http\Request;

/**
 * What the public request being rendered is asking for — its locale and
 * its page number — held for one request (a scoped binding) so a data
 * source or a frontend page provider deep inside rendering can read them
 * without the router threading them through every block.
 *
 * It also records what rendering DID with them: whether anything paginated
 * (a `?page=2` URL nothing paginated is a duplicate of page 1 and must not
 * be cached as a page of its own) and whether the requested page lay past
 * the end (a 404, not an empty listing).
 *
 * Outside a public request — a console command, a queued job, the editor
 * preview — it answers the defaults: the fallback locale, page 1.
 */
final class RenderContext
{
    /** The deepest page a listing serves; anything past it is out of range. */
    public const MAX_PAGE = 100;

    private ?string $locale = null;

    private string $path = '/';

    private int $page = 1;

    private bool $paginated = false;

    private bool $outOfRange = false;

    public function __construct(private readonly LocaleUrls $locales) {}

    /**
     * Called by the router once it knows which locale the path is in.
     *
     * @param  string|null  $locale  null = the fallback locale (an unprefixed path)
     */
    public function setRequest(Request $request, ?string $locale = null): void
    {
        $this->locale = $locale;
        $this->path = '/'.trim($request->path(), '/');
        $this->page = self::parsePage($request->query('page'));
        // Out of range only once something paginates: `?page=101` on a page
        // with no listing is that page, not a missing one.
        $this->paginated = false;
        $this->outOfRange = false;
    }

    /** The locale being served — the fallback locale when unprefixed. */
    public function locale(): string
    {
        return $this->locales->effective($this->locale);
    }

    /** Whether the request is in the site's fallback locale (bare paths). */
    public function isFallbackLocale(): bool
    {
        return $this->locale === null || $this->locale === $this->locales->fallback();
    }

    /** The requested path, locale prefix included, without the query. */
    public function path(): string
    {
        return $this->path;
    }

    /** The requested page number, 1 when absent or malformed. */
    public function page(): int
    {
        return min($this->page, self::MAX_PAGE);
    }

    /**
     * Turn a listing's total into its page for this request, and record
     * that pagination happened (and whether the page exists).
     */
    public function paginate(int $total, int $perPage): Pagination
    {
        $pagination = new Pagination($this->page(), max(1, $perPage), max(0, $total), $this->path);

        $this->paginated = true;
        if ($this->page > self::MAX_PAGE || ($this->page > 1 && $pagination->isOutOfRange())) {
            $this->outOfRange = true;
        }

        return $pagination;
    }

    /** Whether anything on this response paginated. */
    public function paginated(): bool
    {
        return $this->paginated;
    }

    /** Whether the requested page lies past the end — answer 404. */
    public function outOfRange(): bool
    {
        return $this->outOfRange;
    }

    /**
     * `?page=` as a page number. Anything but plain digits is page 1, a
     * value past MAX_PAGE is held at MAX_PAGE + 1 so it reads as out of
     * range without a huge offset reaching a query.
     */
    private static function parsePage(mixed $raw): int
    {
        if (! is_string($raw) || $raw === '' || ! ctype_digit($raw)) {
            return 1;
        }

        $digits = ltrim($raw, '0');
        if ($digits === '') {
            return 1;
        }

        return strlen($digits) > 4 ? self::MAX_PAGE + 1 : min((int) $digits, self::MAX_PAGE + 1);
    }
}
