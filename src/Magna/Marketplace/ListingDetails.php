<?php

declare(strict_types=1);

namespace Magna\Marketplace;

use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

/**
 * Everything the marketplace says about one plugin or theme — the summary a
 * catalog card shows plus what only the details view needs: the long
 * description, screenshots, reviews, the star spread and the publisher's
 * links.
 *
 * All of it is third-party content, so it is normalised once here rather
 * than defended against in the view: only http(s) URLs survive, text is
 * trimmed to sane lengths, the description is rendered from Markdown with
 * raw HTML escaped and unsafe links dropped, and fields a marketplace does
 * not send default to "absent" rather than to a guess.
 */
final class ListingDetails
{
    private const MAX_SCREENSHOTS = 8;

    private const MAX_REVIEWS = 10;

    /**
     * @param  list<string>  $screenshots
     * @param  list<array{author: string, stars: int, review: string, date: ?string}>  $reviews
     * @param  array<int, int>  $ratingBreakdown  stars (5 → 1) => count
     */
    public function __construct(
        public readonly PluginListing $listing,
        public readonly string $description = '',
        public readonly array $screenshots = [],
        public readonly array $reviews = [],
        public readonly array $ratingBreakdown = [],
        public readonly ?string $publisher = null,
        public readonly ?string $homepage = null,
        public readonly ?string $docsUrl = null,
        public readonly ?string $supportEmail = null,
        public readonly ?string $videoUrl = null,
        public readonly ?string $releasedAt = null,
    ) {}

    /** @param  array<array-key, mixed>  $data  a GET /plugins/{vendor}/{package} reply */
    public static function fromArray(array $data): ?self
    {
        $listing = PluginListing::fromArray($data);

        if ($listing === null) {
            return null;
        }

        $screenshots = [];
        foreach (is_array($data['screenshots'] ?? null) ? $data['screenshots'] : [] as $url) {
            if (($url = self::url($url)) !== null) {
                $screenshots[] = $url;
            }
        }

        $reviews = [];
        foreach (is_array($data['reviews'] ?? null) ? $data['reviews'] : [] as $row) {
            if (! is_array($row) || ! is_numeric($row['stars'] ?? null)) {
                continue;
            }

            $reviews[] = [
                'author' => Str::limit(trim(is_string($row['author'] ?? null) ? $row['author'] : '') ?: 'Anonymous', 60),
                'stars' => max(1, min(5, (int) $row['stars'])),
                'review' => Str::limit(trim(is_string($row['review'] ?? null) ? $row['review'] : ''), 2000),
                'date' => is_string($row['date'] ?? null) ? $row['date'] : null,
            ];
        }

        $breakdown = [];
        $rawBreakdown = is_array($data['ratingBreakdown'] ?? null) ? $data['ratingBreakdown'] : [];
        foreach ([5, 4, 3, 2, 1] as $stars) {
            $count = $rawBreakdown[$stars] ?? $rawBreakdown[(string) $stars] ?? 0;
            $breakdown[$stars] = is_numeric($count) ? max(0, (int) $count) : 0;
        }

        $description = is_string($data['description'] ?? null) ? trim($data['description']) : '';

        $email = is_string($data['supportEmail'] ?? null) ? trim($data['supportEmail']) : '';

        return new self(
            listing: $listing,
            description: Str::limit($description, 10000),
            screenshots: array_slice($screenshots, 0, self::MAX_SCREENSHOTS),
            reviews: array_slice($reviews, 0, self::MAX_REVIEWS),
            ratingBreakdown: $breakdown,
            publisher: is_string($data['publisher'] ?? null) && trim($data['publisher']) !== '' ? Str::limit(trim($data['publisher']), 80) : null,
            homepage: self::url($data['homepage'] ?? null),
            docsUrl: self::url($data['docsUrl'] ?? null),
            supportEmail: filter_var($email, FILTER_VALIDATE_EMAIL) !== false ? $email : null,
            videoUrl: self::url($data['videoUrl'] ?? null),
            releasedAt: is_string($data['releasedAt'] ?? null) ? $data['releasedAt'] : null,
        );
    }

    /**
     * The shape a Livewire page keeps in a public property and a view reads.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $l = $this->listing;
        $totalRatings = array_sum($this->ratingBreakdown);

        return [
            'package' => $l->package,
            'name' => $l->name,
            'short_description' => $l->shortDescription,
            'type' => $l->productType,
            'version' => $l->version,
            'compat' => $l->compat,
            'icon' => self::url($l->icon),
            'categories' => $l->categories,
            'author' => $l->author,
            'publisher' => $this->publisher ?? $l->author,
            'installs' => $l->installs,
            'permissions' => $l->permissions,
            'rating' => $l->rating,
            'ratings_count' => max($l->ratingsCount, $totalRatings),
            'rating_breakdown' => $this->ratingBreakdown,
            'website' => self::url($l->website),
            'homepage' => $this->homepage,
            'docs_url' => $this->docsUrl,
            'support_email' => $this->supportEmail,
            'video_url' => $this->videoUrl,
            'released_at' => $this->releasedAt,
            'is_paid' => $l->isPaid(),
            'official' => $l->official,
            'verified' => $l->verified,
            // Raw Markdown: rendered at display time by descriptionHtml(), so
            // what reaches the page is always the escaped, link-checked form —
            // even if the stored value were tampered with.
            'description' => $this->description,
            'screenshots' => $this->screenshots,
            'reviews' => $this->reviews,
        ];
    }

    /**
     * The publisher's Markdown description as safe HTML: raw HTML in it is
     * escaped, not passed through, and javascript:/data: links are dropped.
     * Returned as HtmlString so a view prints it with {{ }} — the escaping
     * already happened here, in one audited place.
     */
    public static function descriptionHtml(mixed $markdown): HtmlString
    {
        if (! is_string($markdown) || trim($markdown) === '') {
            return new HtmlString('');
        }

        return new HtmlString(Str::markdown(Str::limit($markdown, 10000), [
            'html_input' => 'escape',
            'allow_unsafe_links' => false,
            'max_nesting_level' => 10,
        ]));
    }

    /** An http(s) URL, or null — never javascript:, data: or a relative path. */
    private static function url(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '' || strlen($value) > 2048) {
            return null;
        }

        $value = trim($value);
        $scheme = strtolower((string) parse_url($value, PHP_URL_SCHEME));

        return in_array($scheme, ['http', 'https'], true) && filter_var($value, FILTER_VALIDATE_URL) !== false ? $value : null;
    }
}
