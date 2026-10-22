<?php

declare(strict_types=1);

namespace Magna\Themes;

/**
 * Applies a theme's demo content — a site-kit bundle from its starter/
 * folder — to this site, adding only what is missing.
 *
 * Core reads themes but does not own pages, menus or posts: the plugin that
 * does (the page builder) implements this and binds it in the container.
 * With no implementation bound, themes simply offer no demo content.
 *
 * "Missing only" is the contract, not a mode: demo content is offered on
 * install, often to a site that already has content, and must never replace
 * a page, menu, setting or post someone has made. An entry whose identity
 * (slug, handle, name) already exists is left alone and counted as skipped.
 */
interface ThemeContentImporter
{
    /**
     * @param  array<string, mixed>  $kit  a decoded magna-site-kit bundle
     * @return array<string, int> what was created, per kind, plus "skipped"
     *
     * @throws \InvalidArgumentException when the bundle is not a site kit
     */
    public function importMissing(array $kit, ?string $actorId = null): array;
}
