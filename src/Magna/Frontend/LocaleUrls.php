<?php

declare(strict_types=1);

namespace Magna\Frontend;

use Magna\Settings\LocalizationSettings;

/**
 * The URL locale-prefix strategy (docs/magna-pages §A2): the fallback
 * locale lives at bare paths, every other AVAILABLE locale under its
 * prefix — /about and /fr/about. A first segment that is not an available
 * non-fallback locale is just a slug; nothing else gets special-cased.
 *
 * Core owns the rule so every plugin that emits a public URL — a post
 * permalink, an hreflang alternate, a redirect's Location — prefixes it
 * exactly the way the router strips it.
 */
final class LocaleUrls
{
    /**
     * @return array{locale: string|null, path: string} locale null = the
     *                                                  fallback locale, path already stripped
     */
    public function split(string $path): array
    {
        $path = trim($path, '/');
        $settings = LocalizationSettings::get();

        $first = explode('/', $path, 2)[0];
        if ($first !== ''
            && $first !== $settings->fallback_locale
            && in_array($first, $settings->available_locales, true)
        ) {
            return ['locale' => $first, 'path' => substr($path, strlen($first) + 1) ?: ''];
        }

        return ['locale' => null, 'path' => $path];
    }

    /** The locale actually being served (fallback when unprefixed). */
    public function effective(?string $locale): string
    {
        return $locale ?? $this->fallback();
    }

    /** The site-relative URL for a path in the given locale. */
    public function urlFor(string $locale, string $path): string
    {
        $bare = '/'.ltrim($path, '/');

        return $locale === $this->fallback()
            ? $bare
            : '/'.$locale.($bare === '/' ? '' : $bare);
    }

    public function fallback(): string
    {
        return LocalizationSettings::get()->fallback_locale;
    }

    /** @return list<string> */
    public function available(): array
    {
        return LocalizationSettings::get()->available_locales;
    }
}
