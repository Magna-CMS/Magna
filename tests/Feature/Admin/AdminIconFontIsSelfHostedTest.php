<?php

declare(strict_types=1);

/**
 * The admin panel's icon font must be served from this installation.
 *
 * System Insights and the Media Library draw their icons as Material Symbols
 * ligatures, and both loaded the font with a <link> to fonts.googleapis.com.
 * The panel runs under AdminCspMiddleware, whose policy is
 * "style-src 'self' 'unsafe-inline'" and "font-src 'self' data:", so the
 * stylesheet was refused and the woff2 behind it never fetched. Every icon
 * then rendered as the ligature name itself: "shield_with_house" sat next to
 * "Environment Target" as plain text, "warning" opened the performance
 * warning, "deployed_code" headed the version panel.
 *
 * Reported on a live install (managemagna) once the CSP middleware was
 * actually attached to the panel — the policy is correct, the remote font
 * was the thing that did not belong. Self-hosting also keeps the icons on an
 * install with no route to the public internet, which a CMS on a client's own
 * hardware routinely is.
 */

/** @return list<array{string}> */
function adminBladeViews(): array
{
    $root = __DIR__.'/../../../src/Magna/Admin/Resources/views';

    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));

    $views = [];

    foreach ($files as $file) {
        if ($file->isFile() && str_ends_with($file->getFilename(), '.blade.php')) {
            $views[] = [$file->getPathname()];
        }
    }

    sort($views);

    return $views;
}

/**
 * Only <link> and <script> are checked, not every href on the page: an anchor
 * to an external documentation page is a link the operator clicks, and the CSP
 * has nothing to say about it. A subresource the browser must fetch to render
 * the panel is the case that breaks.
 */
it('pulls no stylesheet, font or script from a remote origin', function (string $view): void {
    $markup = (string) file_get_contents($view);

    expect($markup)->not->toMatch('#<(?:link|script)\b[^>]*\b(?:href|src)\s*=\s*["\']https?://#i');
})->with(adminBladeViews());

it('declares the icon font against a file that ships with the installation', function (): void {
    $partial = __DIR__.'/../../../src/Magna/Admin/Resources/views/admin/partials/material-symbols-font.blade.php';
    $font = __DIR__.'/../../../public/fonts/material-symbols/material-symbols-rounded-subset.woff2';

    expect($partial)->toBeReadableFile()
        ->and(file_get_contents($partial))->toContain('@font-face')
        ->and($font)->toBeReadableFile();

    // wOF2 signature. A truncated download, or an HTML error page saved under
    // the font's name, would leave a file present and the icons still broken —
    // which is the failure this check exists to catch.
    expect(file_get_contents($font, false, null, 0, 4))->toBe('wOF2');
});

it('renders the icons in the views that use them through that partial', function (string $view): void {
    expect(file_get_contents($view))->toContain("@include('magna::admin.partials.material-symbols-font')");
})->with([
    [__DIR__.'/../../../src/Magna/Admin/Resources/views/admin/system-info.blade.php'],
    [__DIR__.'/../../../src/Magna/Admin/Resources/views/admin/media-list.blade.php'],
]);
