<?php

declare(strict_types=1);

/**
 * The architecture rules that follow the code into plugins-dev.
 *
 * Every plugin is its own repository, and for a long time that made them a
 * blind spot: core's guardrails stopped at src/Magna while the same hands
 * wrote the same mistakes next door. These rules hold plugin src/ to the
 * discipline core already lives under — strict types everywhere, no debug
 * output, the god-class ceiling, the line-width ceiling — and pin the
 * quality kit each plugin adopts so it cannot quietly regress.
 *
 * Same contract as ArchitectureTest: allowlists and pins are SHRINK-ONLY.
 * An entry may go down or disappear; it never goes up, and a new offender
 * is fixed, not listed. On CI plugins-dev is absent and every rule here
 * skips loudly rather than passing silently — a guardrail that cannot see
 * its subject must say so.
 *
 * (Raw {!! !!} in plugin resources/views is known debt — 16 files, mostly
 * storefront markup — and is deliberately not pinned yet; the rule here
 * covers Blade under src/, which is clean and must stay clean.)
 */
function pluginArchRoots(): array
{
    $base = str_replace('\\', '/', dirname(__DIR__, 3));
    $roots = [];

    foreach (glob($base.'/plugins-dev/*/*') ?: [] as $dir) {
        if (str_contains($dir, 'quarantine') || ! is_dir($dir)) {
            continue;
        }

        if (! is_file($dir.'/composer.json')) {
            continue;
        }

        $roots[basename(dirname($dir)).'/'.basename($dir)] = $dir;
    }

    ksort($roots);

    return $roots;
}

function pluginArchSourceFiles(): array
{
    $files = [];

    foreach (pluginArchRoots() as $plugin => $dir) {
        if (! is_dir($dir.'/src')) {
            continue;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir.'/src', FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file instanceof SplFileInfo && $file->getExtension() === 'php') {
                $files[$plugin.'/src'.str_replace('\\', '/', substr($file->getPathname(), strlen($dir.'/src')))] = $file->getPathname();
            }
        }
    }

    ksort($files);

    return $files;
}

function skipWithoutPluginArchSubjects(): bool
{
    if (pluginArchRoots() !== []) {
        return false;
    }

    test()->markTestSkipped('plugins-dev is not part of this checkout (CI) — plugin architecture rules cannot run here.');
}

it('holds every plugin source file to strict types', function (): void {
    skipWithoutPluginArchSubjects();

    $offenders = [];

    foreach (pluginArchSourceFiles() as $relative => $path) {
        if (str_ends_with($relative, '.blade.php')) {
            continue;
        }

        if (! str_contains((string) file_get_contents($path), 'declare(strict_types=1);')) {
            $offenders[] = $relative;
        }
    }

    expect($offenders)->toBe([]);
});

it('lets no debug output ship from a plugin', function (): void {
    skipWithoutPluginArchSubjects();

    $offenders = [];

    foreach (pluginArchSourceFiles() as $relative => $path) {
        if (preg_match('/\b(?:dd|ray|var_dump|print_r|var_export)\s*\(/', (string) file_get_contents($path)) === 1) {
            $offenders[] = $relative;
        }
    }

    expect($offenders)->toBe([]);
});

it('keeps plugin classes under the god-class ceiling', function (): void {
    skipWithoutPluginArchSubjects();

    // Pinned at their current size, shrink-only: each may only come down.
    // Splitting one is the same treatment PluginManager got in core.
    $pins = [
        'embhas/embhas/src/Console/Demo/DemoSeeder.php' => 716,
        'magna/blog/src/Editor/EditorJsSanitizer.php' => 876,
        'magna/blog/src/Filament/Resources/PostResource/Pages/EditsPostInBuilder.php' => 700,
        'magna/blog/src/Support/BlockRenderer.php' => 992,
        'magna/defence/src/Filament/Pages/DefenceDashboardPage.php' => 1025,
        'magna/defence/src/Filament/Pages/DefenceSettingsPage.php' => 1002,
        'magna/marketplace/src/Filament/Pages/MarketplaceSettingsPage.php' => 712,
        'roya/erp/src/Console/Commands/SeedErpDemoCommand.php' => 867,
        'roya/erp/src/Finance/WorkbookParser.php' => 680,
        'roya/erp/src/RoyaErpPlugin.php' => 788,
    ];

    $ceiling = 600;
    $offenders = [];

    foreach (pluginArchSourceFiles() as $relative => $path) {
        if (str_ends_with($relative, '.blade.php')) {
            continue;
        }

        $lines = count(file($path) ?: []);
        $limit = $pins[$relative] ?? $ceiling;

        if ($lines > $limit) {
            $offenders[] = "{$relative} ({$lines} lines, limit {$limit})";
        }
    }

    expect($offenders)->toBe([]);
});

it('keeps plugin source lines readable', function (): void {
    skipWithoutPluginArchSubjects();

    // Same 400-character ceiling as core; the allowlist is shrink-only.
    $allowed = [
        'magna/blog/src/Support/BlockRenderer.php',
        'magna/defence/src/Filament/Pages/DefenceSettingsPage.php',
        'magna/marketplace/src/Filament/Pages/MarketplaceSettingsPage.php',
    ];

    $offenders = [];

    foreach (pluginArchSourceFiles() as $relative => $path) {
        if (in_array($relative, $allowed, true)) {
            continue;
        }

        foreach (file($path) ?: [] as $line) {
            if (strlen($line) > 400) {
                $offenders[] = $relative;
                break;
            }
        }
    }

    expect($offenders)->toBe([]);
});

it('allows no raw Blade output inside plugin src', function (): void {
    skipWithoutPluginArchSubjects();

    $offenders = [];

    foreach (pluginArchSourceFiles() as $relative => $path) {
        if (! str_ends_with($relative, '.blade.php')) {
            continue;
        }

        if (str_contains((string) file_get_contents($path), '{!!')) {
            $offenders[] = $relative;
        }
    }

    expect($offenders)->toBe([]);
});

it('keeps every adopted quality kit whole, and its analysis level a one-way ratchet', function (): void {
    skipWithoutPluginArchSubjects();

    // A plugin that adopted the kit (marked by its pre-commit hook) must
    // keep all four pieces, hold its phpstan level at or above the pinned
    // floor, and may only SHRINK its excludePaths list. Plugins without the
    // kit warn rather than fail — adoption happens on first touch, and a
    // hard failure here would punish plugins nobody is working on.
    $levelFloors = [
        'magna/docs' => 9,
        // Probed 2026-09-25: highest clean level even with its two
        // excludePaths. Level 1 reports 10 errors — the next rung to earn.
        'magna/marketplace' => 0,
        'magna/pages' => 0,
        'magna/plugin-manager' => 3,
        'roya/dms' => 9,
        'roya/erp' => 9,
    ];

    $excludePins = [
        'magna/marketplace' => 2,
    ];

    $kitFiles = ['pint.json', 'phpstan.neon.dist', '.githooks/pre-commit', '.githooks/commit-msg'];

    $problems = [];
    $unadopted = [];

    foreach (pluginArchRoots() as $plugin => $dir) {
        if (! is_file($dir.'/.githooks/pre-commit')) {
            $unadopted[] = $plugin;

            continue;
        }

        foreach ($kitFiles as $file) {
            if (! is_file($dir.'/'.$file)) {
                $problems[] = "{$plugin}: quality kit is missing {$file}";
            }
        }

        if (! is_file($dir.'/phpstan.neon.dist')) {
            continue;
        }

        $neon = (string) file_get_contents($dir.'/phpstan.neon.dist');

        if (preg_match('/^\s*level:\s*(\d+)/m', $neon, $match) !== 1) {
            $problems[] = "{$plugin}: phpstan.neon.dist declares no level";

            continue;
        }

        $floor = $levelFloors[$plugin] ?? 0;

        if ((int) $match[1] < $floor) {
            $problems[] = "{$plugin}: phpstan level {$match[1]} is below its floor of {$floor} — the level only ratchets up";
        }

        $excludeCount = preg_match_all('/^\s+-\s+src\//m', preg_match('/excludePaths:(.*)$/s', $neon, $tail) === 1 ? $tail[1] : '');
        $pin = $excludePins[$plugin] ?? 0;

        if ($excludeCount > $pin) {
            $problems[] = "{$plugin}: excludePaths grew to {$excludeCount} entries (pinned at {$pin}) — exclusions only shrink";
        }
    }

    if ($unadopted !== []) {
        fwrite(STDERR, "\n[plugin quality kit] not yet adopted (adopt on first touch): ".implode(', ', $unadopted)."\n");
    }

    expect($problems)->toBe([]);
});
