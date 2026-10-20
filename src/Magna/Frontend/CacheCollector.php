<?php

declare(strict_types=1);

namespace Magna\Frontend;

use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * What the content on one public response says about caching it, gathered
 * while it renders (a scoped binding, one per request).
 *
 * A block listing posts tags the response `blog:posts` so publishing a post
 * can purge it; a post scheduled for tomorrow sets an expiry so it appears
 * on time without anyone saving anything; a block that shows something
 * visitor-specific vetoes the cache outright. The page cache reads all
 * three when it stores the response — a response nobody tagged is still
 * cached, under the page's own tags and TTL.
 */
final class CacheCollector
{
    /** @var array<string, true> */
    private array $tags = [];

    private ?CarbonImmutable $expiresAt = null;

    private ?string $veto = null;

    public function tag(string ...$tags): void
    {
        foreach ($tags as $tag) {
            $tag = trim($tag);
            if ($tag !== '') {
                $this->tags[$tag] = true;
            }
        }
    }

    /** @return list<string> */
    public function tags(): array
    {
        return array_keys($this->tags);
    }

    /** The response goes stale at $moment; the earliest one reported wins. */
    public function expireAt(?DateTimeInterface $moment): void
    {
        if ($moment === null) {
            return;
        }

        $moment = CarbonImmutable::instance($moment);
        if ($this->expiresAt === null || $moment->lessThan($this->expiresAt)) {
            $this->expiresAt = $moment;
        }
    }

    public function expiresAt(): ?CarbonImmutable
    {
        return $this->expiresAt;
    }

    /** This response must not be cached at all; the first reason is kept. */
    public function veto(string $reason): void
    {
        $this->veto ??= $reason;
    }

    public function vetoed(): bool
    {
        return $this->veto !== null;
    }

    public function vetoReason(): ?string
    {
        return $this->veto;
    }
}
