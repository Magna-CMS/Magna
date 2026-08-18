<?php

declare(strict_types=1);

namespace Magna\Content;

use Magna\Content\Exceptions\SchemaException;

/**
 * Maintains the structural columns of hierarchical content types
 * (EntryManager collaborator — orchestration there, tree math here).
 *
 * The materialized `path` is the joined ancestor slugs ("docs/guides/intro")
 * and is what nested URL resolution reads — one indexed lookup per request
 * instead of a parent walk. Slugs are deduplicated per sibling set
 * (SlugGenerator's scoped mode), so "intro" can live under both /docs and
 * /guides; assertPathAvailable() is the backstop that rejects an explicit
 * slug or a move that would collide two entries on one URL.
 */
class HierarchyMaintainer
{
    /** Sanity bound for parent-chain walks (cycle guard + runaway depth). */
    private const MAX_DEPTH = 25;

    /**
     * Apply parent/position/path before an entry is saved. `parent_id` and
     * `position` arrive through $data (structural, not schema fields — the
     * same special-cased handling as `locale`).
     *
     * @param  array<string, mixed>  $data
     *
     * @throws SchemaException
     */
    public function applyOnSave(Entry $entry, ContentType $type, array $data): void
    {
        if (! $type->hierarchical) {
            return;
        }

        if (array_key_exists('parent_id', $data)) {
            $parentId = $data['parent_id'];
            $entry->parent_id = is_string($parentId) && $parentId !== '' ? $parentId : null;
        }

        if (array_key_exists('position', $data) && is_numeric($data['position'])) {
            $entry->position = max(0, (int) $data['position']);
        }

        $entry->path = $this->pathFor($entry, $type);
        $this->assertPathAvailable($entry, $type);
    }

    /**
     * After a saved entry's path changed, rewrite every descendant's path.
     * Each descendant is saved through Eloquent so downstream listeners
     * (auto-301 redirects, cache purges) fire for the moved URLs too.
     */
    public function cascadeDescendants(Entry $entry, ContentType $type): void
    {
        if (! $type->hierarchical || ! $entry->wasChanged('path')) {
            return;
        }

        $this->rewriteChildren($entry, $type, depth: 0);
    }

    /**
     * Re-parent a deleted entry's direct children onto its own parent (or
     * the root when it had none) and rewrite their subtree paths. Called
     * after the parent row is gone — inside the caller's transaction — so a
     * child whose slug matches the deleted parent's can take over the freed
     * URL without a false collision, and a real collision rolls the whole
     * delete back.
     *
     * @throws SchemaException
     */
    public function promoteChildrenOf(Entry $deleted, ContentType $type): void
    {
        if (! $type->hierarchical) {
            return;
        }

        $parentId = $deleted->parent_id;

        foreach (Entry::type($type->handle)->where('parent_id', $deleted->getKey())->get() as $child) {
            $this->applyOnSave($child, $type, [
                'parent_id' => is_string($parentId) ? $parentId : null,
            ]);
            $child->save();
            $this->cascadeDescendants($child, $type);
        }
    }

    /**
     * @throws SchemaException
     */
    private function pathFor(Entry $entry, ContentType $type): ?string
    {
        $slug = $entry->getAttribute('slug');
        if (! is_string($slug) || $slug === '') {
            return null;
        }

        $parentId = $entry->parent_id;
        if ($parentId === null || $parentId === '') {
            return $slug;
        }

        if ($parentId === $entry->getKey()) {
            throw new SchemaException('An entry cannot be its own parent.');
        }

        // Walk the ancestor chain: builds the prefix and rejects cycles
        // (reaching this entry again) or runaway depth in one pass.
        $segments = [];
        $cursor = $parentId;
        for ($depth = 0; $depth < self::MAX_DEPTH; $depth++) {
            /** @var Entry|null $ancestor */
            $ancestor = Entry::type($type->handle)->find($cursor);
            if ($ancestor === null) {
                throw new SchemaException('The selected parent does not exist.');
            }

            if ($ancestor->getKey() === $entry->getKey()) {
                throw new SchemaException('An entry cannot be moved under its own descendant.');
            }

            $ancestorSlug = $ancestor->getAttribute('slug');
            array_unshift($segments, is_string($ancestorSlug) ? $ancestorSlug : '');

            $cursor = $ancestor->parent_id;
            if ($cursor === null || $cursor === '') {
                return implode('/', [...$segments, $slug]);
            }
        }

        throw new SchemaException('The page tree is nested too deeply.');
    }

    /**
     * Reject a save whose computed path is already taken by a different
     * entry (explicit duplicate sibling slug, or a move under a parent that
     * already has a child with this slug). Only canonical rows count — an
     * entry and its working draft copy (draft_of) legitimately share a path.
     *
     * @throws SchemaException
     */
    private function assertPathAvailable(Entry $entry, ContentType $type): void
    {
        $path = $entry->path;
        if ($path === null || $path === '') {
            return;
        }

        $query = Entry::type($type->handle)
            ->where('path', $path)
            ->where('locale', $entry->locale ?? '')
            ->whereNull('draft_of');

        if ($entry->getKey() !== null) {
            $query->where('id', '!=', $entry->getKey());
        }
        if ($entry->draft_of !== null) {
            $query->where('id', '!=', $entry->draft_of);
        }

        if ($query->exists()) {
            throw new SchemaException("Another entry already uses the URL path \"{$path}\".");
        }
    }

    private function rewriteChildren(Entry $parent, ContentType $type, int $depth): void
    {
        if ($depth >= self::MAX_DEPTH) {
            return;
        }

        $parentPath = $parent->path;

        foreach (Entry::type($type->handle)->where('parent_id', $parent->getKey())->get() as $child) {
            $childSlug = $child->getAttribute('slug');
            $child->path = is_string($childSlug) && $childSlug !== ''
                ? ($parentPath !== null && $parentPath !== '' ? $parentPath.'/'.$childSlug : $childSlug)
                : null;
            $child->save();

            $this->rewriteChildren($child, $type, $depth + 1);
        }
    }
}
