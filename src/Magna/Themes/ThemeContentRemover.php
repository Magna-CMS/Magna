<?php

declare(strict_types=1);

namespace Magna\Themes;

/**
 * Takes a theme's demo content back out of the site — when the theme is
 * removed, when another theme replaces it, or on request.
 *
 * Core reads themes but does not own pages, menus or posts: the plugin that
 * does (the page builder) implements this and binds it in the container.
 * With no implementation bound, themes offer no removal, the same way they
 * offer no import.
 *
 * "Untouched only" is the contract, not a mode. A row goes only while the
 * site's copy is exactly what the bundle ships, so anything an admin edited,
 * or made under the same slug, handle or name, stays; and whatever goes can
 * be brought back by importing the theme's demo content again. A row that is
 * untouched but still needed (a page a menu that stays links to, a form with
 * submissions) stays too, with the reason.
 *
 * Nothing records where content came from: the bundle itself is the record,
 * which is why removal has to happen while the theme's files are still here.
 */
interface ThemeContentRemover
{
    /**
     * What removing this bundle would take out, and what it would leave and
     * why. Rows the site does not have at all appear in neither list.
     *
     * @param  array<string, mixed>  $kit  a decoded magna-site-kit bundle
     * @return array{remove: list<array{key: string, label: string}>, keep: list<array{key: string, label: string, reason: string}>}
     *
     * @throws \InvalidArgumentException when the bundle is not a site kit
     */
    public function preview(array $kit): array;

    /**
     * Remove every untouched row of this bundle that nothing still needs, in
     * one transaction.
     *
     * @param  array<string, mixed>  $kit
     * @return array<string, int> removed per kind (pages, templates, menus, patterns, section keys) plus "kept"
     *
     * @throws \InvalidArgumentException when the bundle is not a site kit
     */
    public function removeUntouched(array $kit, ?string $actorId = null): array;
}
