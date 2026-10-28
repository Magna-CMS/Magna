<?php

declare(strict_types=1);

namespace Magna\Themes;

/**
 * Carries a theme update's content changes onto the site — the half of an
 * update that is not files.
 *
 * A theme ships pages, template parts and menus in its starter/ bundles, and
 * a release often changes them: a new band on the pricing page, a button in
 * the header. Swapping the files alone left all of that on the server,
 * unseen, until somebody ran a sync from a shell — and a sync overwrites
 * whatever an editor changed. This contract is the middle path.
 *
 * Three versions of every entry are compared: what the OUTGOING theme
 * shipped (the base), what the site has now, and what the INCOMING theme
 * ships. An entry the site never touched (site = base) takes the new
 * version; an entry the site already matches needs nothing; an entry the
 * site has edited is kept and reported, so an admin can choose. Nothing is
 * ever deleted.
 *
 * Core reads themes and does not own pages: the plugin that does (the page
 * builder) implements this and binds it in the container. Without one, a
 * theme update changes files only and says so.
 */
interface ThemeContentUpdater
{
    /**
     * @param  array<string, mixed>  $base  the bundle as the outgoing theme shipped it; [] when there was none
     * @param  array<string, mixed>  $kit  the bundle as the incoming theme ships it
     * @return array{updated: list<string>, added: list<string>, kept: list<string>}
     *                                                                               entry keys such as "page:pricing@" or "menu:primary"
     *
     * @throws \InvalidArgumentException when $kit is not a site kit
     */
    public function update(array $base, array $kit, ?string $actorId = null): array;

    /**
     * Put the kit's version of the given entries on the site, replacing the
     * site's own — "Use theme version" for an entry update() kept. The
     * implementation must leave the replaced version restorable.
     *
     * @param  array<string, mixed>  $kit
     * @param  list<string>  $keys  keys as update() reported them
     * @return int how many entries were replaced
     */
    public function replace(array $kit, array $keys, ?string $actorId = null): int;
}
