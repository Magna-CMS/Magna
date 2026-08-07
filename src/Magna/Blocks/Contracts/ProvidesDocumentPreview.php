<?php

declare(strict_types=1);

namespace Magna\Blocks\Contracts;

/**
 * Binding contract for a live block-document preview renderer.
 *
 * Core's block editor shows its preview pane only when an implementation is
 * bound (Magna Pages binds one that renders through the active theme) — core
 * never references a plugin route directly
 * (docs/magna-pages/10-REVIEW-RESOLUTIONS.md §E1). A pure headless install
 * has no binding and no pane.
 */
interface ProvidesDocumentPreview
{
    /**
     * Endpoint accepting a POSTed document (blocks_data JSON + title) and
     * returning fully rendered HTML for an iframe.
     */
    public function previewUrl(): string;
}
