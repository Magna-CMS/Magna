<?php

declare(strict_types=1);

use Magna\Updater\Engine\PathGuard;
use Magna\Updater\Engine\StagedSwap;
use Magna\Updater\Run\UpdateJournal;
use Magna\Updater\UpdatePaths;
use Symfony\Component\Filesystem\Filesystem;
use Tests\TestCase;

uses(TestCase::class);

/**
 * Two renames per path, the previous content kept beside the new, every
 * step in the journal — so an interrupted switch can always be described
 * and undone.
 */
function swapFixture(): array
{
    $base = sys_get_temp_dir().'/magna-swap-'.bin2hex(random_bytes(6));
    mkdir($base.'/src/Magna', 0777, true);
    file_put_contents($base.'/src/Magna/Installed.php', '<?php // installed');
    mkdir($base.'/app/Legacy', 0777, true);
    file_put_contents($base.'/app/Legacy/Old.php', '<?php // retired');
    mkdir($base.'/storage/app', 0777, true);

    $archive = $base.'/archive';
    mkdir($archive.'/src/Magna', 0777, true);
    file_put_contents($archive.'/src/Magna/Delivered.php', '<?php // delivered');
    mkdir($archive.'/database/seeders', 0777, true);
    file_put_contents($archive.'/database/seeders/New.php', '<?php // new path');

    $paths = new UpdatePaths($base, $base.'/storage');
    $journal = UpdateJournal::create($paths, 'run1', ['mode' => 'update', 'from' => '1.0.0', 'to' => '2.0.0']);

    return [$paths, $archive, $journal, new StagedSwap(new Filesystem, $paths, new PathGuard, $journal)];
}

function removeSwapFixture(UpdatePaths $paths): void
{
    (new Filesystem)->remove($paths->basePath);
}

it('stages beside the live path, swaps with two renames, and keeps the previous content', function (): void {
    [$paths, $archive, $journal, $swap] = swapFixture();

    try {
        $swap->stage('src/Magna', $archive);

        expect(is_file($paths->base('src/.Magna.incoming-run1/Delivered.php')))->toBeTrue()
            ->and(is_file($paths->base('src/Magna/Installed.php')))->toBeTrue()
            ->and($journal->paths()['src/Magna']['state'] ?? null)->toBe('staged');

        $swap->swap('src/Magna');

        expect(is_file($paths->base('src/Magna/Delivered.php')))->toBeTrue()
            ->and(is_file($paths->base('src/Magna/Installed.php')))->toBeFalse()
            ->and(is_file($paths->base('src/.Magna.replaced-run1/Installed.php')))->toBeTrue()
            ->and(is_dir($paths->base('src/.Magna.incoming-run1')))->toBeFalse()
            ->and($journal->paths()['src/Magna']['state'] ?? null)->toBe('swapped');

        $swap->unswap('src/Magna');

        expect(is_file($paths->base('src/Magna/Installed.php')))->toBeTrue()
            ->and(is_file($paths->base('src/Magna/Delivered.php')))->toBeFalse()
            ->and(is_file($paths->base('src/.Magna.failed-run1/Delivered.php')))->toBeTrue()
            ->and($journal->paths()['src/Magna']['state'] ?? null)->toBe('unswapped');
    } finally {
        removeSwapFixture($paths);
    }
});

it('creates a path the install never had and takes it away again on unswap', function (): void {
    [$paths, $archive, $journal, $swap] = swapFixture();

    try {
        $swap->stage('database/seeders', $archive);
        $swap->swap('database/seeders');

        $recorded = $journal->paths()['database/seeders'];

        expect(is_file($paths->base('database/seeders/New.php')))->toBeTrue()
            ->and(array_key_exists('displaced', $recorded))->toBeTrue()
            ->and($recorded['displaced'])->toBeNull();

        $swap->unswap('database/seeders');

        expect(is_dir($paths->base('database/seeders')))->toBeFalse();
    } finally {
        removeSwapFixture($paths);
    }
});

it('moves a retired path aside and brings it back on unswap', function (): void {
    [$paths, $archive, $journal, $swap] = swapFixture();

    try {
        $swap->remove('app/Legacy');

        expect(is_dir($paths->base('app/Legacy')))->toBeFalse()
            ->and(is_file($paths->base('app/.Legacy.replaced-run1/Old.php')))->toBeTrue()
            ->and($journal->paths()['app/Legacy']['state'] ?? null)->toBe('removed');

        $swap->unswapAll();

        expect(is_file($paths->base('app/Legacy/Old.php')))->toBeTrue();
    } finally {
        removeSwapFixture($paths);
    }
});

it('commits by removing the previous content and any debris', function (): void {
    [$paths, $archive, $journal, $swap] = swapFixture();

    try {
        $swap->stage('src/Magna', $archive);
        $swap->swap('src/Magna');
        $swap->remove('app/Legacy');
        $swap->commit();

        expect(glob($paths->base('src/.Magna.*')) ?: [])->toBe([])
            ->and(glob($paths->base('app/.Legacy.*')) ?: [])->toBe([])
            ->and(is_file($paths->base('src/Magna/Delivered.php')))->toBeTrue()
            ->and($journal->paths()['src/Magna']['state'] ?? null)->toBe('committed');
    } finally {
        removeSwapFixture($paths);
    }
});

it('refuses a path the guard rejects and a swap that was never staged', function (): void {
    [$paths, $archive, $journal, $swap] = swapFixture();

    try {
        expect(fn () => $swap->stage('storage', $archive))->toThrow(RuntimeException::class, 'site-owned')
            ->and(fn () => $swap->swap('src/Magna'))->toThrow(RuntimeException::class, 'never staged');
    } finally {
        removeSwapFixture($paths);
    }
});
