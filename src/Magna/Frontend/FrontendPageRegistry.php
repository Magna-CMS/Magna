<?php

declare(strict_types=1);

namespace Magna\Frontend;

/**
 * Every frontend page enabled plugins expose, keyed by name. Same shape as
 * BlockRegistry: plugins contribute at enable-time via the
 * ProvidesFrontendPages contract; consumers (the Pages router, the menu
 * picker) only ever read.
 */
final class FrontendPageRegistry
{
    /** @var array<string, FrontendPage> */
    private array $pages = [];

    public function register(FrontendPage $page): void
    {
        $this->pages[$page->name] = $page;
    }

    public function get(string $name): ?FrontendPage
    {
        return $this->pages[$name] ?? null;
    }

    /** The page mounted at the given site-relative path, if any. */
    public function match(string $path): ?FrontendPage
    {
        $path = trim($path, '/');
        foreach ($this->pages as $page) {
            if ($page->normalizedPath() === $path) {
                return $page;
            }
        }

        return null;
    }

    /** @return array<string, FrontendPage> */
    public function all(): array
    {
        return $this->pages;
    }
}
