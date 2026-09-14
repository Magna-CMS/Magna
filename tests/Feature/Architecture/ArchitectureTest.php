<?php

declare(strict_types=1);
use Illuminate\Database\Eloquent\Model;
use Magna\Management\Controllers\ManagementController;
use Symfony\Component\HttpFoundation\Response;

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
//    so unknown handles resolve through ManagementController::resolveTypeOrFail()
//    (throwing, same JSON as findOrFail()) rather than route-model binding.
//    Also deliberate.
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

/**
 * A NOT NULL TIMESTAMP with no default cannot be created on MySQL.
 *
 * With `explicit_defaults_for_timestamp` off — still the case on plenty of
 * hosts — MySQL hands every such column past the first in a table an implicit
 * default of '0000-00-00 00:00:00', which strict mode's NO_ZERO_DATE then
 * rejects, so CREATE TABLE fails outright:
 *
 *     SQLSTATE[42000]: 1067 Invalid default value for 'expires_at'
 *
 * SQLite has no such rule, so the suite runs these migrations happily and only
 * a MySQL install ever finds out — which is exactly what happened to
 * roya_device_sessions, dms_notification_log and update_checks. Whether a given
 * column escapes depends on its position in the table, making it a coin toss
 * rather than a design.
 *
 * Use dateTime() for a required moment in time (it also has no 2038 limit), or
 * make the timestamp nullable, or give it an explicit default.
 */
it('declares no NOT NULL timestamp column without a default', function (): void {
    // Not base_path(): these architecture checks read the tree directly and do
    // not boot the application.
    $root = dirname(__DIR__, 3);

    $migrations = array_merge(
        glob($root.'/database/migrations/*.php') ?: [],
        glob($root.'/plugins-dev/*/*/database/migrations/*.php') ?: [],
    );

    expect($migrations)->not->toBe([]);

    $offenders = [];

    foreach ($migrations as $file) {
        foreach (file($file) ?: [] as $number => $line) {
            // A chained ->nullable(), ->default() or ->useCurrent() on the same
            // line settles it; those are the three ways to be safe.
            if (preg_match("/->timestamp\('([a-z0-9_]+)'\)\s*;/", $line, $matches) !== 1) {
                continue;
            }

            $offenders[] = basename($file).':'.($number + 1)." ({$matches[1]})";
        }
    }

    expect($offenders)->toBe([]);
});

/**
 * Raw Blade output is an allowlist, not a habit.
 *
 * `{!! !!}` is how the one stored-XSS class this codebase has actually had
 * gets in: a view rendering attacker-influencable data raw on the assumption
 * that something upstream sanitized it (see BlockTreeReservedKeysTest for the
 * `_resolved` impersonation this enabled). Every raw echo must therefore be a
 * named, justified exception — SVG from the icon registry, renderer-composed
 * child HTML, resolver-sanitized richtext, the permission-gated raw-HTML
 * block. A new `{!! !!}` anywhere else fails here: either escape it, or argue
 * the case in a review and add the file with a comment saying why it is safe.
 */
it('renders raw Blade output only in the allowlisted views', function (): void {
    $allowed = [
        // QR code SVG generated by BaconQrCode from a server-built otpauth
        // URI — no user-controlled markup.
        'Auth/resources/views/two-factor-setup.blade.php',
        // Child HTML composed by the block renderer itself from already-
        // rendered child views.
        'Blocks/resources/views/blocks/container.blade.php',
        // Richtext sanitized by TextBlockResolver; falls back to e() when no
        // resolver ran. Guarded by BlockTreeReservedKeysTest.
        'Blocks/resources/views/blocks/text.blade.php',
        // Registry SVGs: shipped icon files, sanitized on ingest.
        'Blocks/resources/views/blocks/icon.blade.php',
        'Blocks/resources/views/blocks/scheme-toggle.blade.php',
        'Blocks/resources/views/block-editor/partials/add-block-modal.blade.php',
        // The raw-HTML block: storing it requires blocks.raw_html, enforced
        // by PageTreeAuthorizer on every save path.
        'Blocks/resources/views/blocks/html.blade.php',
    ];

    $srcDir = dirname(__DIR__, 3).'/src/Magna';

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($srcDir, FilesystemIterator::SKIP_DOTS)
    );

    $offenders = [];
    foreach ($iterator as $file) {
        if (! $file instanceof SplFileInfo || ! str_ends_with($file->getFilename(), '.blade.php')) {
            continue;
        }

        if (! str_contains((string) file_get_contents($file->getPathname()), '{!!')) {
            continue;
        }

        $relative = str_replace(DIRECTORY_SEPARATOR, '/', substr($file->getPathname(), strlen($srcDir) + 1));
        if (! in_array($relative, $allowed, true)) {
            $offenders[] = $relative;
        }
    }

    expect($offenders)->toBe([]);
});

/**
 * Every management endpoint authorizes, mechanically.
 *
 * PreviewTokenController shipped without a Gate::authorize while all nine of
 * its management siblings had one — a management-scope token could mint
 * week-long draft-access tokens no matter what its holder was permitted to
 * see. Nothing structural made that omission visible; this rule does. Every
 * public action method in a management controller (plus the
 * management-scoped controllers listed alongside them) must contain a
 * Gate::authorize call in its own body.
 */
it('authorizes in every public management controller action', function (): void {
    $root = dirname(__DIR__, 3);

    $files = glob($root.'/src/Magna/Management/Controllers/*.php') ?: [];
    // Management-scoped controllers living outside that namespace.
    $files[] = $root.'/src/Magna/Delivery/Controllers/PreviewTokenController.php';

    expect($files)->not->toBe([]);

    $offenders = [];

    foreach ($files as $file) {
        $basename = basename($file);

        // The abstract base holds shared protected helpers, not endpoints.
        if ($basename === 'ManagementController.php') {
            continue;
        }

        $source = (string) file_get_contents($file);

        // Slice the class at every method boundary so a private helper's
        // authorize call can never satisfy the check for the public action
        // above it.
        $slices = preg_split('/(?=(?:public|protected|private)\s+(?:static\s+)?function\s)/', $source) ?: [];

        foreach ($slices as $slice) {
            if (preg_match('/^public\s+(?:static\s+)?function\s+(\w+)/', $slice, $matches) !== 1) {
                continue;
            }

            $method = $matches[1];
            if ($method === '__construct') {
                continue;
            }

            if (! str_contains($slice, 'Gate::authorize')) {
                $offenders[] = $basename.'::'.$method;
            }
        }
    }

    expect($offenders)->toBe([]);
});

/**
 * Error paths THROW; only success has a return type.
 *
 * A HELPER returning Response|SomethingElse forces every caller into an
 * instanceof guard — the exact shape the community review flagged on the
 * management side (Model|JsonResponse) and that later grew back in the
 * Delivery layer (Response|DeliveryRequestContext). The framework's own
 * carriers exist for this: HttpResponseException for "this response, now",
 * NotFoundHttpException for 404s. No protected or private controller method
 * may declare a union return type that mixes a Response subtype with
 * anything else. PUBLIC route actions are exempt: View|RedirectResponse on
 * an action is idiomatic Laravel — the framework consumes it, no code ever
 * unpicks it.
 */
it('never declares a Response union on a controller helper', function (): void {
    $srcDir = dirname(__DIR__, 3).'/src/Magna';

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($srcDir, FilesystemIterator::SKIP_DOTS)
    );

    $offenders = [];

    foreach ($iterator as $file) {
        if (! $file instanceof SplFileInfo || $file->getExtension() !== 'php') {
            continue;
        }

        $path = str_replace(DIRECTORY_SEPARATOR, '/', $file->getPathname());
        if (! str_contains($path, '/Controllers/')) {
            continue;
        }

        $source = (string) file_get_contents($file->getPathname());
        if (preg_match('/namespace\s+([^;]+);/', $source, $ns) !== 1
            || preg_match('/(?:class|interface)\s+(\w+)/', $source, $cls) !== 1) {
            continue;
        }

        $fqcn = $ns[1].'\\'.$cls[1];
        if (! class_exists($fqcn)) {
            continue;
        }

        $reflection = new ReflectionClass($fqcn);
        foreach ($reflection->getMethods() as $method) {
            if ($method->getDeclaringClass()->getName() !== $fqcn || $method->isPublic()) {
                continue;
            }

            $return = $method->getReturnType();
            if (! $return instanceof ReflectionUnionType) {
                continue;
            }

            foreach ($return->getTypes() as $type) {
                if ($type instanceof ReflectionNamedType
                    && ! $type->isBuiltin()
                    && is_a($type->getName(), Response::class, true)) {
                    $offenders[] = $cls[1].'::'.$method->getName().'(): '.$return;
                    break;
                }
            }
        }
    }

    expect($offenders)->toBe([]);
});

/**
 * Not-found handling in the management layer is a throw, not a literal.
 *
 * The single biggest complaint from the community review was 20+ copies of
 * find-then-404 across the management controllers. The base class now owns
 * every variant — findOrFail() by key, findByOrFail() by column,
 * resolveTypeOrFail() for schema handles — each throwing the exception the
 * API renderer turns into the canonical {"message": "... not found."} body.
 * A hand-rolled not-found JSON in a management controller means someone is
 * rebuilding the pattern; add a helper to the base instead.
 */
it('hand-rolls no not-found responses in management controllers', function (): void {
    $root = dirname(__DIR__, 3);
    $files = glob($root.'/src/Magna/Management/Controllers/*.php') ?: [];
    $files[] = $root.'/src/Magna/Delivery/Controllers/PreviewTokenController.php';

    expect($files)->not->toBe([]);

    $offenders = [];

    foreach ($files as $file) {
        $basename = basename($file);
        if ($basename === 'ManagementController.php') {
            continue; // the base holds the one legitimate copy of the literal
        }

        foreach (file($file) ?: [] as $number => $line) {
            if (preg_match('/[\x27"]message[\x27"]\s*=>\s*.*not found/i', $line) === 1) {
                $offenders[] = $basename.':'.($number + 1);
            }
        }
    }

    expect($offenders)->toBe([]);
});

/**
 * Raw process environment reads live in one class.
 *
 * getenv() bypasses Laravel's config layer entirely — no caching, no
 * defaults, no test override — so scattered calls are exactly the kind of
 * duplication that drifts (the LARAVEL_OCTANE check was pasted across five
 * files). Everything that merely wants a VALUE goes through config(); the
 * allowlist below is for code whose subject genuinely is this process's
 * environment. Add a method to Runtime for a new process-level fact rather
 * than a new getenv() call site.
 */
it('reads the raw process environment only where the process is the subject', function (): void {
    $allowed = [
        // The one place a process-level fact ("is this an Octane worker?")
        // is answered for everyone else.
        'Runtime.php',
        // Spawns composer as a child process: passes the whole environment
        // through and probes COMPOSER_BINARY/HOME — the environment IS the
        // domain here, not a value config could carry.
        'ProcessComposerRunner.php',
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

        if (in_array($file->getFilename(), $allowed, true)) {
            continue;
        }

        foreach (file($file->getPathname()) ?: [] as $number => $line) {
            if (str_contains($line, 'getenv(')) {
                $offenders[] = $file->getFilename().':'.($number + 1);
            }
        }
    }

    expect($offenders)->toBe([]);
});

/**
 * No line of PHP source exceeds 400 characters.
 *
 * OpenApiGenerator carried 700-character single-line array literals — an
 * entire public API operation per line — which is how a wrong description
 * or a missing response code hides from every reviewer and diff. Data that
 * wide is written multi-line or built by a named builder (see
 * Delivery\OpenApi\ResponseShapes). The allowlist holds the two files that
 * still exceed the ceiling and is shrink-only: fix a file, remove its line;
 * never add one.
 */
it('writes no PHP source line wider than the ceiling', function (): void {
    $ceiling = 400;
    $allowed = [
        // Shrink-only: both are Admin files queued for the W4 phpstan-debt
        // pass; their wide lines retire with that rewrite.
        'AdminPanelProvider.php',
        'PerformanceSettingsPage.php',
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

        // Blade templates are markup, covered by the blade rules instead.
        if (str_ends_with($file->getFilename(), '.blade.php')) {
            continue;
        }

        if (in_array($file->getFilename(), $allowed, true)) {
            continue;
        }

        foreach (file($file->getPathname()) ?: [] as $number => $line) {
            if (mb_strlen(rtrim($line, "\r\n")) > $ceiling) {
                $offenders[] = $file->getFilename().':'.($number + 1);
            }
        }
    }

    expect($offenders)->toBe([]);
});

/**
 * Blade templates have a ceiling too.
 *
 * system-info.blade.php reached 775 lines, the block editor 524, the
 * plugins page 520 — screens whose markup nobody could review as a whole,
 * which is where an unescaped echo or a wrong wire:model hides. Each is
 * now a thin composition of named partials (admin/partials/system-info,
 * block-editor/partials, admin/partials/plugins); a view that outgrows the
 * ceiling gets the same treatment. The allowlist is shrink-only: fix a
 * file, remove its line; never add one.
 */
it('keeps every Blade view under the view ceiling', function (): void {
    $ceiling = 300;
    $allowed = [
        // Queued for the same partial-split treatment; both shrank is the
        // only direction allowed in the meantime.
        'account-centre.blade.php' => 460,
        'media-list.blade.php' => 420,
    ];

    $srcDir = dirname(__DIR__, 3).'/src/Magna';

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($srcDir, FilesystemIterator::SKIP_DOTS)
    );

    $offenders = [];

    foreach ($iterator as $file) {
        if (! $file instanceof SplFileInfo || ! str_ends_with($file->getFilename(), '.blade.php')) {
            continue;
        }

        $lines = count(file($file->getPathname()) ?: []);
        $limit = $allowed[$file->getFilename()] ?? $ceiling;

        if ($lines > $limit) {
            $offenders[] = $file->getFilename().' ('.$lines.' lines)';
        }
    }

    expect($offenders)->toBe([]);
});

/**
 * The big classes are pinned, and pins only move down.
 *
 * The 600-line ceiling stops a god-class; it does nothing about a large
 * class quietly growing back toward it after a decomposition. Every class
 * currently within reach of the ceiling is pinned at its post-Wave-3 size:
 * exceeding a pin fails CI the same as exceeding the ceiling. After real
 * work shrinks one, lower its pin — never raise one, and never add a new
 * class here (a new class approaching 600 lines is decomposed instead).
 * The global ceiling steps down to 500 once every pin is below it.
 */
it('lets the pinned big classes only shrink', function (): void {
    $pins = [
        // Over the global ceiling and allowlisted there; the pin stops it
        // growing further while it waits for its remaining splits.
        'Admin/Pages/PluginsPage.php' => 710,
        'Licensing/LicenseClient.php' => 592,
        'Blocks/Livewire/BlockEditor.php' => 590,
        'Plugins/PluginManager.php' => 555,
        'Admin/Pages/SystemInfoPage.php' => 545,
        'Content/EntryManager.php' => 510,
        'Admin/AdminPanelProvider.php' => 500,
        'Admin/Pages/SettingsPage.php' => 495,
    ];

    $srcDir = dirname(__DIR__, 3).'/src/Magna';

    $offenders = [];

    foreach ($pins as $relative => $pin) {
        $lines = count(file($srcDir.'/'.$relative) ?: []);

        if ($lines > $pin) {
            $offenders[] = $relative.' ('.$lines.' lines, pinned at '.$pin.')';
        }
    }

    expect($offenders)->toBe([]);
});

/**
 * app() is a ratchet, not a habit.
 *
 * Constructor injection is the rule; app() is tolerated only where the
 * framework leaves no seam (Filament pages and Livewire components cannot
 * constructor-inject) and in the sanctioned typed static gateways
 * (Settings::get(), Entry::type(), User::current() — see the coding
 * standards). The map below pins every file's CURRENT count of app() call
 * sites. A file may only go DOWN or hold: fix a site, lower its pin (or
 * delete the line at zero); a new app() anywhere — a higher count, or any
 * count in an unpinned file — fails here. Pins never go up.
 */
it('never grows the number of app() call sites', function (): void {
    $pins = [
        'Admin/Pages/AccountCentrePage.php' => 8,
        'Admin/Pages/BackupSettingsPage.php' => 1,
        'Admin/Pages/ContentTypeBuilder.php' => 5,
        'Admin/Pages/Dashboard.php' => 2,
        'Admin/Pages/MailSettingsPage.php' => 2,
        'Admin/Pages/PluginsPage.php' => 15,
        'Admin/Pages/ProfilePage.php' => 1,
        'Admin/Pages/SettingsPage.php' => 2,
        'Admin/Pages/SystemInfoPage.php' => 8,
        'Admin/Pages/ThemesPage.php' => 5,
        'Admin/Resources/ApiKey/ManageApiKeys.php' => 1,
        'Admin/Resources/Entry/CreateEntry.php' => 2,
        'Admin/Resources/Entry/EditEntry.php' => 5,
        'Admin/Resources/Entry/ListEntries.php' => 1,
        'Admin/Resources/EntryResource.php' => 7,
        'Admin/Resources/Media/CreateMedia.php' => 1,
        'Admin/Resources/Media/ListMedia.php' => 2,
        'Admin/Resources/MediaResource.php' => 2,
        'Admin/Resources/RoleResource.php' => 1,
        'Admin/Widgets/EntryCounts.php' => 1,
        'Admin/Widgets/UpcomingScheduleWidget.php' => 1,
        'Auth/Captcha/Rules/Captcha.php' => 1,
        'Auth/Filament/Login.php' => 4,
        'Auth/Http/Middleware/AdminCspMiddleware.php' => 1,
        'Auth/Http/Middleware/SecureSessionCookieMiddleware.php' => 1,
        'Backup/BackupService.php' => 1,
        'Blocks/BlockField.php' => 1,
        'Blocks/BlocksServiceProvider.php' => 3,
        'Blocks/Livewire/BlockEditor.php' => 8,
        'Blocks/Livewire/Concerns/RendersEditorChrome.php' => 5,
        'Blocks/Rules/ValidBlockDocument.php' => 2,
        // The sanctioned static gateways themselves.
        'Content/Entry.php' => 1,
        'Settings/Settings.php' => 2,
        'Users/User.php' => 1,
        'Content/FieldTypes/BlocksField.php' => 1,
        'Content/Http/Resources/EntryResource.php' => 1,
        'Delivery/EntryTransformer.php' => 2,
        'Install/ConfigCache.php' => 1,
        'Install/Http/InstallController.php' => 2,
        'Licensing/Concerns/ChecksOutWithRazorpay.php' => 10,
        'Licensing/LicensingServiceProvider.php' => 1,
        'Media/Concerns/IngestsMedia.php' => 1,
        'Media/Http/Resources/MediaResource.php' => 1,
        'Media/Livewire/MediaPickerModal.php' => 1,
        'Plugins/PluginDiscovery.php' => 1,
        'Plugins/PluginManager.php' => 1,
        'System/SystemHealthCollector.php' => 1,
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

        // Blade views are covered by their own no-service-location rule.
        if (str_ends_with($file->getFilename(), '.blade.php')) {
            continue;
        }

        $count = preg_match_all('/\bapp\(/', (string) file_get_contents($file->getPathname()));
        if ($count === 0 || $count === false) {
            continue;
        }

        $relative = str_replace(DIRECTORY_SEPARATOR, '/', substr($file->getPathname(), strlen($srcDir) + 1));

        if ($count > ($pins[$relative] ?? 0)) {
            $offenders[] = $relative.' ('.$count.' app() calls, pinned at '.($pins[$relative] ?? 0).')';
        }
    }

    expect($offenders)->toBe([]);
});

/**
 * Views render; they do not fetch.
 *
 * A Blade file that calls app() or Model::query() is a controller hiding
 * in a template: it cannot be unit-tested, it re-runs its queries on every
 * Livewire re-render, and nothing about a view file invites the reviewer
 * scrutiny a service-location or persistence call deserves.
 * content-type-builder.blade.php ran an Eloquent query in its @php block
 * and the block editor service-located three registries inline — all four
 * now live on their page/component class, where the view reaches them as
 * $this->method(). The allowlist names the two block views that have no
 * backing class at all (they are rendered by the block renderer from data
 * arrays) and is shrink-only.
 */
it('only ever shrinks the phpstan exclusion list', function (): void {
    // Every entry here is analysed-at-level-9 debt being paid down (W4-1).
    // Retiring one is progress: fix the folder, delete it from BOTH
    // phpstan.neon.dist and this list. Adding one is regression: new code is
    // written type-clean, never excluded — there is deliberately no
    // mechanism for growing this list short of editing this test, which is
    // the review conversation the rule exists to force.
    $allowed = [
        'src/Magna/Media/Concerns/IngestsMedia.php',
        'src/Magna/Blocks/Livewire',
    ];

    $neon = (string) file_get_contents(dirname(__DIR__, 3).'/phpstan.neon.dist');

    preg_match('/excludePaths:\n((?:\s+(?:#[^\n]*|- [^\n]+)\n)+)/', $neon, $matches);
    preg_match_all('/- ([^\n]+)/', $matches[1] ?? '', $entries);

    $current = array_map('trim', $entries[1]);

    expect(array_diff($current, $allowed))->toBe([]);
});

it('service-locates and queries nothing from inside a Blade view', function (): void {
    $allowed = [
        // Block views rendered straight from the renderer with a data array
        // — no component class exists to carry the IconRegistry lookup.
        'Blocks/resources/views/blocks/icon.blade.php',
        'Blocks/resources/views/blocks/scheme-toggle.blade.php',
    ];

    $srcDir = dirname(__DIR__, 3).'/src/Magna';

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($srcDir, FilesystemIterator::SKIP_DOTS)
    );

    $offenders = [];

    foreach ($iterator as $file) {
        if (! $file instanceof SplFileInfo || ! str_ends_with($file->getFilename(), '.blade.php')) {
            continue;
        }

        $relative = str_replace(DIRECTORY_SEPARATOR, '/', substr($file->getPathname(), strlen($srcDir) + 1));
        if (in_array($relative, $allowed, true)) {
            continue;
        }

        foreach (file($file->getPathname()) ?: [] as $number => $line) {
            if (preg_match('/\bapp\(|::query\(/', $line) === 1) {
                $offenders[] = $relative.':'.($number + 1);
            }
        }
    }

    expect($offenders)->toBe([]);
});
