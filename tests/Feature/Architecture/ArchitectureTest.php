<?php

declare(strict_types=1);
use Illuminate\Database\Eloquent\Model;
use Magna\Management\Controllers\ManagementController;

// Architecture guardrails. These exist so the structural discipline won earned
// during the 2026 architecture passes can't silently regress: a future change
// that reintroduces a god-class, drops strict types, leaves a debug call in, or
// bypasses the Management controller base fails CI here instead of shipping.
// See the project coding standards and docs/architecture.md.

arch('all core code declares strict types')
    ->expect('Magna')
    ->toUseStrictTypes();

arch('no debugging statements are committed')
    ->expect(['dd', 'ray', 'var_dump', 'var_export', 'print_r'])
    ->not->toBeUsed();

arch('management controllers extend the shared base')
    ->expect('Magna\Management\Controllers')
    ->toExtend('Magna\Management\Controllers\ManagementController')
    ->ignoring('Magna\Management\Controllers\ManagementController');

// Thin controllers: the management API controllers authorize, validate, call a
// service/model, and shape the response — they never build queries directly.
// The DB facade in a controller signals leaked persistence logic. (Content-type
// controllers work through SchemaRegistry, not the DB facade, so this holds for
// the whole namespace.)
arch('management controllers do not use the DB facade')
    ->expect('Magna\Management\Controllers')
    ->not->toUse('Illuminate\Support\Facades\DB');

// ── Intentional divergences from vanilla Laravel (locked so they can't be
// "corrected" back by a well-meaning contributor) ──────────────────────────
//
// 1. Dynamic content Entry model: Entry resolves a per-content-type table via
//    Entry::type($handle), so there is no single Eloquent class to route-model-
//    bind. Management entry endpoints therefore validate inline and resolve with
//    ManagementController::findOrFail() rather than Form Requests + implicit
//    binding. This is deliberate — see docs/architecture.md.
// 2. ContentType is a value object from SchemaRegistry, not an Eloquent model,
//    so ContentTypeController returns hand-rolled 404s for unknown handles
//    instead of findOrFail() (which needs an Eloquent Builder). Also deliberate.
// 3. Delivery (public read) API uses EntryTransformer + response cache/ETag, not
//    JsonResource — the transformer is the cache/decoration seam. Management
//    (write) API uses JsonResource. Both are intentional per their layer.
// 4. Settings are typed DB-backed classes (admin-editable, encrypted, audited),
//    not config — see the project coding standards.

it('keeps the throwing findOrFail base helper (no Model|JsonResponse union)', function (): void {
    $method = new ReflectionMethod(ManagementController::class, 'findOrFail');
    $return = $method->getReturnType();

    // Must return a single Model type, never a union that reintroduces the
    // instanceof-JsonResponse guard the community review flagged.
    expect($return)->toBeInstanceOf(ReflectionNamedType::class)
        ->and($return->getName())->toBe(Model::class);
});

it('serialises the management API through JsonResource classes', function (): void {
    $srcDir = dirname(__DIR__, 3).'/src/Magna';
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($srcDir, FilesystemIterator::SKIP_DOTS)
    );

    $offenders = [];
    foreach ($iterator as $file) {
        if (! $file instanceof SplFileInfo || $file->getExtension() !== 'php') {
            continue;
        }
        // Only API resource classes (…/Http/Resources/…).
        if (! str_contains(str_replace('\\', '/', $file->getPathname()), '/Http/Resources/')) {
            continue;
        }
        $source = (string) file_get_contents($file->getPathname());
        if (! str_contains($source, 'extends JsonResource')) {
            $offenders[] = $file->getFilename();
        }
    }

    expect($offenders)->toBe([]);
});

it('has no god classes beyond the documented ceiling', function (): void {
    // Hard ceiling on a single PHP class. Anything above this is a god-object
    // smell and must be decomposed into collaborators (see how PluginManager
    // was split into PluginSecurityGuard / PluginContentTypeSyncer /
    // PluginRouteRegistrar). The allowlist is only for Filament admin *pages*,
    // which are inherently large declarative UI-orchestration surfaces — not a
    // place to park business logic.
    $ceiling = 600;
    $allowed = [
        // Filament admin page: almost entirely declarative form/table/action
        // schema (Filament's own shape), not business logic — which lives in
        // services it calls. Splitting further would fragment one UI screen
        // across files for no maintainability gain.
        'PluginsPage.php',
    ];

    $srcDir = dirname(__DIR__, 3).'/src/Magna';

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($srcDir, FilesystemIterator::SKIP_DOTS)
    );

    $offenders = [];
    foreach ($iterator as $file) {
        if (! $file instanceof SplFileInfo || $file->getExtension() !== 'php') {
            continue;
        }

        // Blade templates are views, not classes — the ceiling is about class
        // cohesion, so they're out of scope here.
        if (str_ends_with($file->getFilename(), '.blade.php')) {
            continue;
        }

        $lines = count(file($file->getPathname()) ?: []);
        if ($lines > $ceiling && ! in_array($file->getFilename(), $allowed, true)) {
            $offenders[] = $file->getFilename().' ('.$lines.' lines)';
        }
    }

    expect($offenders)->toBe([]);
});

/**
 * Every admin page and resource must decide who may open it.
 *
 * Filament defaults `canAccess()` to true, and a page with no gate is
 * reachable by URL whatever the navigation shows — hiding a nav item proves
 * nothing. That default is how a plugin's settings screen, holding the OAuth
 * client secrets and the licence signing key, ended up open to anyone who
 * could reach the panel at all.
 *
 * The rule is: state the audience, in the class itself. Two pages are exempt
 * and named individually, because "anyone admitted to the panel" is genuinely
 * their audience — a dashboard nobody may open is not a dashboard, and a
 * profile page is about the person opening it.
 */
it('gives every admin page and resource an access gate', function (): void {
    $exempt = [
        // The panel's landing page. Admission is `panel.access`; each widget
        // on it states its own audience.
        'Magna\Admin\Pages\Dashboard',
        // Your own account. Everyone admitted has one.
        'Magna\Admin\Pages\ProfilePage',
    ];

    $roots = [
        dirname(__DIR__, 3).'/src/Magna',
        dirname(__DIR__, 3).'/plugins-dev',
    ];

    $offenders = [];

    foreach ($roots as $root) {
        if (! is_dir($root)) {
            continue;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (! $file instanceof SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }

            $path = str_replace('\\', '/', $file->getPathname());

            // Filament pages and resources only. Their own sub-pages (ListX,
            // EditX) inherit the resource's gate, so they are not counted.
            $isPage = str_contains($path, '/Filament/Pages/') || str_contains($path, '/Admin/Pages/');
            $isResource = preg_match('#/(Filament|Admin)/Resources/[^/]+Resource\.php$#', $path) === 1;

            if (! $isPage && ! $isResource) {
                continue;
            }

            $source = (string) file_get_contents($file->getPathname());

            if (preg_match('/namespace\s+([^;]+);/', $source, $matches) !== 1) {
                continue;
            }

            $class = trim($matches[1]).'\\'.$file->getBasename('.php');

            if (in_array($class, $exempt, true)) {
                continue;
            }

            // Abstract bases are gated by whatever extends them.
            if (preg_match('/\babstract\s+class\b/', $source) === 1) {
                continue;
            }

            $gated = str_contains($source, 'function canAccess(')
                || str_contains($source, 'function canViewAny(');

            if (! $gated) {
                $offenders[] = $class;
            }
        }
    }

    expect($offenders)->toBe([]);
});
