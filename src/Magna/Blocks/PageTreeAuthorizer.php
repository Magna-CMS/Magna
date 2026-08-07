<?php

declare(strict_types=1);

namespace Magna\Blocks;

use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Actor-dependent authorization for a block document, separated from the
 * structural {@see PageTreeValidator} so that render paths and system
 * contexts (CLI, theme activation, demo/library imports) can validate
 * structure without an authenticated user
 * (docs/magna-pages/10-REVIEW-RESOLUTIONS.md §C2).
 *
 * v1 scope: the raw-HTML gate. A null actor means a system context and is
 * allowed — every human-driven write surface (admin panel, management API)
 * always has an authenticated actor, so "no actor" cannot be reached from a
 * request. The full diff-based patch authorizer (per-operation, per-node
 * locks) is a later phase and will absorb this class's responsibility.
 */
final class PageTreeAuthorizer
{
    /**
     * Block handles whose Blade partials render field data unescaped
     * ({!! !!}) WITHOUT sanitization — resources/views/blocks/html.blade.php.
     * Keep in sync with any future block type that does the same. Gated
     * behind a dedicated permission rather than being available to anyone
     * who can edit a `blocks` field at all — ordinary content-edit
     * permission (content.{type}.update) says nothing about whether that
     * editor should be trusted with raw HTML/script.
     *
     * The `text` block is deliberately NOT in this list: its body renders
     * through the RichTextSanitizer allowlist (TextBlockResolver), so
     * content-tier editors can write formatted text without script
     * capability (10-REVIEW-RESOLUTIONS §C1).
     *
     * @var list<string>
     */
    public const RAW_HTML_BLOCK_HANDLES = ['html'];

    /**
     * Authorize a decoded blocks_data payload (either document shape) for
     * the given actor.
     *
     * @param  array<mixed, mixed>  $data
     * @return list<string> Error messages (empty = authorized)
     */
    public function authorize(array $data, Authenticatable|Authorizable|null $actor): array
    {
        if ($actor === null) {
            return [];
        }

        // An authenticated actor that cannot express permissions is treated
        // as unauthorized for raw HTML (fail closed).
        if ($actor instanceof Authorizable && $actor->can('blocks.raw_html')) {
            return [];
        }

        $errors = [];
        $this->walkBlocks($data, function (array $blockRaw) use (&$errors): void {
            $handle = isset($blockRaw['block']) && is_string($blockRaw['block']) ? $blockRaw['block'] : '';
            if (in_array($handle, self::RAW_HTML_BLOCK_HANDLES, true)) {
                $id = isset($blockRaw['id']) && is_string($blockRaw['id']) ? $blockRaw['id'] : '';
                $errors[] = "Block '{$handle}' (id: {$id}) renders raw HTML and requires the blocks.raw_html permission.";
            }
        });

        return $errors;
    }

    /**
     * Walk every block node (including nested children) in either document
     * shape.
     *
     * @param  array<mixed, mixed>  $data
     * @param  callable(array<mixed, mixed>): void  $callback
     */
    private function walkBlocks(array $data, callable $callback): void
    {
        $sections = $data;
        if (! array_is_list($data)) {
            $sectionsRaw = $data['sections'] ?? [];
            $sections = is_array($sectionsRaw) ? $sectionsRaw : [];
        }

        foreach ($sections as $sectionRaw) {
            if (! is_array($sectionRaw)) {
                continue;
            }
            $columns = $sectionRaw['columns'] ?? [];
            if (! is_array($columns)) {
                continue;
            }
            foreach ($columns as $colRaw) {
                if (! is_array($colRaw)) {
                    continue;
                }
                $blocks = $colRaw['blocks'] ?? [];
                if (! is_array($blocks)) {
                    continue;
                }
                foreach ($blocks as $blockRaw) {
                    if (is_array($blockRaw)) {
                        $this->walkBlock($blockRaw, $callback);
                    }
                }
            }
        }
    }

    /**
     * @param  array<mixed, mixed>  $blockRaw
     * @param  callable(array<mixed, mixed>): void  $callback
     */
    private function walkBlock(array $blockRaw, callable $callback): void
    {
        $callback($blockRaw);

        $children = $blockRaw['children'] ?? null;
        if (is_array($children)) {
            foreach ($children as $childRaw) {
                if (is_array($childRaw)) {
                    $this->walkBlock($childRaw, $callback);
                }
            }
        }
    }
}
