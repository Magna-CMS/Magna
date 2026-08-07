<?php

declare(strict_types=1);

namespace Magna\Blocks\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Magna\Blocks\BlockRegistry;
use Magna\Blocks\PageTree;
use Magna\Blocks\PageTreeAuthorizer;
use Magna\Blocks\PageTreeValidator;
use Magna\Blocks\Resolution\BlockDataResolver;

/**
 * Debug renderer for the Section/Column/Block page tree.
 *
 * Accepts a blocks_data JSON payload (POST body or query param) and returns
 * semantic unstyled HTML with tokenOverrides emitted as inline CSS custom
 * properties on each <section> element.
 *
 * This is intentionally unstyled — Stage 17 adds the design-token renderer
 * that maps tokens to actual CSS. This endpoint just proves the tree renders
 * correctly in structure.
 *
 * Route: POST /magna-preview/blocks  (admin-auth protected)
 */
final class BlockPreviewController
{
    public function __construct(
        private readonly BlockRegistry $registry,
        private readonly PageTreeValidator $validator,
        private readonly PageTreeAuthorizer $authorizer,
        private readonly BlockDataResolver $resolver,
    ) {}

    public function __invoke(Request $request): Response
    {
        // Stage 7: this route was previously gated only by 'auth' — any
        // logged-in user, not the "admin/editor roles only" the block
        // templates' own comments claim — and rendered whatever
        // PageTree::fromJson() produced with no validation at all, so an
        // unregistered/malformed tree (or a raw html/text block from a
        // caller who shouldn't have that permission) rendered unchecked.
        Gate::authorize('blocks.preview');

        $json = $request->input('blocks_data', '[]');

        if (! is_string($json)) {
            $json = '[]';
        }

        $decoded = json_decode($json, true);
        if (is_array($decoded)) {
            // Structural validation + actor authorization (raw-HTML gate) —
            // the split keeps the validator auth-free for system contexts.
            $errors = [
                ...$this->validator->validate($decoded),
                ...$this->authorizer->authorize($decoded, $request->user()),
            ];
            if ($errors !== []) {
                return response(e(implode("\n", $errors)), 422, [
                    'Content-Type' => 'text/plain; charset=utf-8',
                ]);
            }
        }

        $tree = PageTree::fromJson($json);

        $html = view('magna::block-preview.preview', [
            'tree' => $tree,
            'registry' => $this->registry,
            'resolver' => $this->resolver,
        ])->render();

        return response($html, 200, [
            'Content-Type' => 'text/html; charset=utf-8',
            'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; img-src 'self' data:; font-src 'self' data:",
        ]);
    }
}
