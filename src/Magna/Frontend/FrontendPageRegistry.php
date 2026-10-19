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

    /**
     * The page claiming the given path, with any `{param}` segments captured.
     *
     * Exact paths are consulted first, whatever the registration order: a
     * page mounted literally at `blog/archive` must not be swallowed by a
     * sibling's `blog/{slug}`. Parameterized pages then match in
     * registration order — segment counts must agree, literal segments
     * compare exactly, and a `{name}` segment captures exactly one
     * non-empty segment, never spanning a slash.
     */
    public function resolve(string $path): ?FrontendPageMatch
    {
        $exact = $this->match($path);
        if ($exact !== null) {
            return new FrontendPageMatch($exact);
        }

        $segments = explode('/', trim($path, '/'));

        foreach ($this->pages as $page) {
            if (! $page->hasParameters()) {
                continue;
            }

            $params = self::capture(explode('/', $page->normalizedPath()), $segments);
            if ($params !== null) {
                return new FrontendPageMatch($page, $params);
            }
        }

        return null;
    }

    /**
     * The captured `{name}` segments, or null when the path does not fit.
     *
     * @param  list<string>  $pattern
     * @param  list<string>  $actual
     * @return array<string, string>|null
     */
    private static function capture(array $pattern, array $actual): ?array
    {
        if (count($pattern) !== count($actual)) {
            return null;
        }

        $params = [];
        foreach ($pattern as $i => $expected) {
            $segment = $actual[$i];

            if (preg_match('/^\{([a-zA-Z_][a-zA-Z0-9_]*)\}$/', $expected, $matches) === 1) {
                if ($segment === '') {
                    return null;
                }
                $params[$matches[1]] = $segment;

                continue;
            }

            if ($expected !== $segment) {
                return null;
            }
        }

        return $params;
    }

    /** @return array<string, FrontendPage> */
    public function all(): array
    {
        return $this->pages;
    }
}
