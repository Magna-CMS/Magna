<?php

declare(strict_types=1);

namespace Magna\Blocks\Livewire\Concerns;

use Magna\Blocks\BlockDefinition;
use Magna\Blocks\BlockRegistry;
use Magna\Blocks\Contracts\ProvidesDocumentPreview;
use Magna\Blocks\Icons\IconRegistry;

/**
 * The registry lookups the block editor's Blade view needs while it renders
 * — kept on the component (as this trait) so the view calls $this-> instead
 * of service-locating with app() inline, which the architecture rule on
 * views forbids. Same shape as Admin\Concerns\ReloadsBrowser: a Livewire
 * view concern, not business logic.
 */
trait RendersEditorChrome
{
    /**
     * Live preview lights up only when a renderer is bound (Magna Pages) —
     * core never references a plugin route (§E1 contract seam).
     */
    public function documentPreviewUrl(): ?string
    {
        return app()->bound(ProvidesDocumentPreview::class)
            ? app(ProvidesDocumentPreview::class)->previewUrl()
            : null;
    }

    /** The definition behind a placed block, or null when its plugin is disabled. */
    public function blockDefinition(string $handle): ?BlockDefinition
    {
        return app(BlockRegistry::class)->get($handle);
    }

    /**
     * One icon vocabulary. A definition names an icon; the registry is what
     * turns that name into geometry, here as on the rendered page. A name it
     * does not know draws nothing — a missing icon rather than a crash.
     */
    public function blockIconSvg(string $icon, string $classes): ?string
    {
        return app(IconRegistry::class)->svg($icon, null, $classes);
    }
}
