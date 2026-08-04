<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Magna\Install\Requirements;
use Magna\Updater\CoreUpdater;
use Magna\Updater\CoreUpdateState;
use Magna\Updater\CoreWritability;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * A read-only src/Magna used to surface halfway through the overlay, and the
 * rollback then failed for the same reason — leaving a core tree mixed between
 * two versions. Writability is now established before anything is written, and
 * reported at install time and on System Info, because permissions drift.
 */
function fakeInstall(bool $writable = true): string
{
    $base = sys_get_temp_dir().'/magna-writability-'.bin2hex(random_bytes(6));

    foreach (CoreUpdater::coreOwnedPaths() as $relative) {
        mkdir($base.'/'.$relative, 0777, true);
        file_put_contents($base.'/'.$relative.'/Sample.php', '<?php // sample');
    }
    mkdir($base.'/storage/app', 0777, true);

    if (! $writable) {
        chmod($base.'/src/Magna/Sample.php', 0444);
    }

    return $base;
}

function removeInstall(string $base): void
{
    if (! is_dir($base)) {
        return;
    }

    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );

    foreach ($items as $item) {
        /** @var SplFileInfo $item */
        @chmod($item->getPathname(), 0777);
        $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
    }

    @rmdir($base);
}

it('reports no blockers when every core-owned path is writable', function (): void {
    $base = fakeInstall();

    $writability = new CoreWritability($base);

    expect($writability->blockers())->toBe([]);
    expect($writability->canApplyUpdate())->toBeTrue();
    expect($writability->summary())->toBeNull();

    removeInstall($base);
});

it('names the unwritable file, relative to the install root', function (): void {
    $base = fakeInstall(writable: false);

    $writability = new CoreWritability($base);

    expect($writability->canApplyUpdate())->toBeFalse();
    expect($writability->blockers())->toHaveKey('src/Magna');
    expect($writability->summary())->toContain('src/Magna/Sample.php');

    removeInstall($base);
});

it('treats a path that does not exist as no blocker, since the overlay creates it', function (): void {
    $base = fakeInstall();
    removeInstall($base.'/routes');

    expect((new CoreWritability($base))->blockers())->toBe([]);

    removeInstall($base);
});

// apply() must refuse before the backup, not partway through the overlay.
it('refuses to apply an update when core files are not writable', function (): void {
    $updater = app(CoreUpdater::class);
    $sample = base_path('src/Magna/MagnaServiceProvider.php');

    // 0444 clears the write bit on POSIX and sets the read-only attribute on
    // Windows, so is_writable() reports false on both.
    chmod($sample, 0444);
    clearstatcache(true, $sample);

    try {
        $state = $updater->apply(
            '9.9.9',
            'https://github.com/magna-cms/magna/archive/v9.9.9.zip',
            str_repeat('a', 64),
        );
    } finally {
        chmod($sample, 0666);
        clearstatcache(true, $sample);
    }

    expect($state)->toBe(CoreUpdateState::Failed);
    expect(CoreUpdater::progress()['message'])->toContain('cannot write to');
    expect(CoreUpdater::progress()['message'])->toContain('nothing was changed');
});

// A message that only says "not writable" leaves the admin guessing which of
// two accounts is wrong. The fix has to name both and print the command.
it('names the PHP user and the owning user in the remedy', function (): void {
    $base = fakeInstall(writable: false);

    $writability = new CoreWritability($base);
    $remedy = $writability->remedy();

    expect($remedy)->not->toBeNull();
    expect($remedy)->toContain($base);
    expect($remedy)->toMatch('/chown -R |chmod /');

    $phpUser = $writability->phpUser();
    if ($phpUser !== null) {
        expect($remedy)->toContain($phpUser);
    }

    removeInstall($base);
});

it('offers no remedy when everything is writable', function (): void {
    $base = fakeInstall();

    expect((new CoreWritability($base))->remedy())->toBeNull();

    removeInstall($base);
});

it('surfaces core writability on the installer requirements screen as advice, not a blocker', function (): void {
    $checks = collect(app(Requirements::class)->check());

    $check = $checks->firstWhere('key', 'writable-core');

    expect($check)->not->toBeNull();
    expect($check->required)->toBeFalse();
    expect(app(Requirements::class)->requiredPass($checks->all()))->toBeTrue();
});
