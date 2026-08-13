<?php

declare(strict_types=1);

namespace Magna\Frontend;

/**
 * A public page a plugin contributes to the site — the definition VO behind
 * the ProvidesFrontendPages contract (docs/magna-pages/07-EXTENSIBILITY.md
 * §3). The Pages plugin mounts it under the site router, wraps the view in
 * the active layout shell (header/footer parts, theme tokens), and offers
 * it to the menu builder; without Pages the definition is inert data.
 *
 * The view renders in the layout's MAIN slot only — never the whole
 * document. `mode: app` marks an interactive page (its own SPA mount in
 * the main slot); the shell still belongs to the site.
 */
final class FrontendPage
{
    public const MODE_CONTENT = 'content';

    public const MODE_APP = 'app';

    public function __construct(
        /** Site-relative path, no leading slash required (`chat`, `chat/settings`). */
        public readonly string $path,
        /** Unique name, `vendor.name` style (`chat.home`) — what menus reference. */
        public readonly string $name,
        /** Human title: the <title> and the menu picker label. */
        public readonly string $title,
        /** Blade view rendered into the layout's main slot. */
        public readonly string $view,
        /** Offer this page in the menu builder's picker. */
        public readonly bool $menuVisible = true,
        /** MODE_CONTENT or MODE_APP. */
        public readonly string $mode = self::MODE_CONTENT,
        /** Require an authenticated user (guests are sent to login). */
        public readonly bool $requiresAuth = false,
        /** Permission the user must hold, or null for none. Implies auth. */
        public readonly ?string $permission = null,
    ) {}

    /** The site-relative path, normalized without a leading slash. */
    public function normalizedPath(): string
    {
        return trim($this->path, '/');
    }
}
