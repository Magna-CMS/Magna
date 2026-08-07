<?php

declare(strict_types=1);

namespace Magna\Content;

use Illuminate\Support\Str;

class SlugGenerator
{
    /**
     * Generate a URL-safe slug from a source string, unique per
     * type+field+locale — or, for hierarchical types ($scopeToParent), unique
     * only among siblings of $parentId, so "intro" can exist under both
     * /docs and /guides without a "-2" suffix. Path uniqueness for scoped
     * slugs is enforced separately (HierarchyMaintainer).
     *
     * Returns an empty string if $source produces no slug-able characters.
     */
    public function generate(
        ContentType $type,
        string $fieldHandle,
        string $source,
        ?string $locale = null,
        bool $scopeToParent = false,
        ?string $parentId = null,
    ): string {
        $base = Str::slug($source);

        if ($base === '') {
            return '';
        }

        $slug = $base;
        $counter = 1;

        while ($this->exists($type, $fieldHandle, $slug, $locale, $scopeToParent, $parentId)) {
            $counter++;
            $slug = $base.'-'.$counter;
        }

        return $slug;
    }

    private function exists(
        ContentType $type,
        string $fieldHandle,
        string $slug,
        ?string $locale,
        bool $scopeToParent,
        ?string $parentId,
    ): bool {
        $query = Entry::type($type->handle)->where($fieldHandle, $slug);

        if ($locale !== null && $locale !== '') {
            $query->where('locale', $locale);
        }

        if ($scopeToParent) {
            $parentId === null || $parentId === ''
                ? $query->whereNull('parent_id')
                : $query->where('parent_id', $parentId);
        }

        return $query->exists();
    }
}
