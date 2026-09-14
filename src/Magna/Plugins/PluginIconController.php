<?php

declare(strict_types=1);

namespace Magna\Plugins;

use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Serves an installed plugin's icon (declared via magna.json's optional
 * "icon" field — same convention as a VS Code extension's package.json
 * "icon"). ManifestValidator already rejects traversal/absolute-path/URL
 * values at manifest-load time; this re-validates via realpath() at serve
 * time too, since resolving an arbitrary path off disk is worth checking
 * twice rather than trusting a value that was merely valid when the plugin
 * was enabled.
 */
class PluginIconController extends Controller
{
    public function show(string $vendor, string $package): BinaryFileResponse|Response
    {
        $record = PluginRecord::query()->where('name', $vendor.'/'.$package)->first();
        if ($record === null) {
            abort(404);
        }

        $iconRelative = is_string($record->manifest['icon'] ?? null) ? $record->manifest['icon'] : null;
        if ($iconRelative === null) {
            abort(404);
        }

        $basePath = realpath(rtrim((string) $record->base_path, '/\\'));
        $resolved = $basePath !== false ? realpath($basePath.DIRECTORY_SEPARATOR.$iconRelative) : false;

        // Containment needs the separator: a bare prefix check lets
        // /plugins/acme sanction /plugins/acme-evil/anything, because the
        // latter also starts with the former as a string.
        if ($basePath === false || $resolved === false || ! str_starts_with($resolved, $basePath.DIRECTORY_SEPARATOR)) {
            abort(404);
        }

        $mime = match (strtolower((string) pathinfo($resolved, PATHINFO_EXTENSION))) {
            'svg' => 'image/svg+xml',
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'webp' => 'image/webp',
            default => null,
        };

        if ($mime === null) {
            abort(404);
        }

        $headers = [
            'Content-Type' => $mime,
            'Cache-Control' => 'public, max-age=86400',
            'X-Content-Type-Options' => 'nosniff',
        ];

        // An SVG is a document, not just an image: opened by navigation it
        // executes scripts on this origin. The icon still has to render in
        // <img> tags (so no attachment disposition, unlike user-uploaded
        // media), but a CSP that allows nothing active makes the navigated
        // document inert.
        if ($mime === 'image/svg+xml') {
            $headers['Content-Security-Policy'] = "default-src 'none'; style-src 'unsafe-inline'";
        }

        return response()->file($resolved, $headers);
    }
}
