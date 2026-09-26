<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Magna\Marketplace\Marketplace;
use Magna\Updater\UpdateCheck;
use Magna\Updater\UpdateCheckClient;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('persists a core update as available when the server reports a newer version', function (): void {
    Http::fake([
        Marketplace::API_BASE.'/updates' => Http::response([
            'core' => ['latest_version' => '9.9.9', 'update_available' => true, 'changelog_url' => 'https://example.test/changelog'],
            'plugins' => [],
        ]),
    ]);

    $result = app(UpdateCheckClient::class)->checkIn();

    expect($result?->core?->updateAvailable)->toBeTrue();

    $row = UpdateCheck::core();
    expect($row)->not->toBeNull()
        ->and($row->update_available)->toBeTrue()
        ->and($row->latest_version)->toBe('9.9.9')
        ->and($row->changelog_url)->toBe('https://example.test/changelog');
});

it('records no update when the server reports the current version is latest', function (): void {
    Http::fake([
        Marketplace::API_BASE.'/updates' => Http::response([
            'core' => ['latest_version' => '1.3.0-beta', 'update_available' => false, 'changelog_url' => null],
            'plugins' => [],
        ]),
    ]);

    app(UpdateCheckClient::class)->checkIn();

    expect(UpdateCheck::core()?->update_available)->toBeFalse()
        ->and(UpdateCheck::totalAvailable())->toBe(0);
});

it('sends its opaque site fingerprint and never throws when Update Manager is unreachable', function (): void {
    Http::fake([Marketplace::API_BASE.'/updates' => Http::response('', 503)]);

    $result = app(UpdateCheckClient::class)->checkIn();

    expect($result)->toBeNull();
    Http::assertSent(fn (Request $request): bool => $request['site'] !== null && $request['site'] !== '');
});

// So Update Manager can withhold a release this host or this updater could
// not apply, instead of announcing it and letting the apply fail here.
it('describes its runtime and updater capabilities on check-in', function (): void {
    Http::fake([Marketplace::API_BASE.'/updates' => Http::response(['core' => null, 'plugins' => []])]);

    app(UpdateCheckClient::class)->checkIn();

    Http::assertSent(function (Request $request): bool {
        $extensions = $request['extensions'];
        $updater = $request['updater'];

        return $request['php'] === PHP_VERSION
            && is_array($extensions)
            && in_array('pdo', $extensions, true)
            && $extensions === array_values($extensions)
            && is_array($updater)
            && is_int($updater['manifest'])
            && is_array($updater['engine']);
    });
});

// CoreUpdater refuses to apply a core update without a verified checksum
// (see CoreUpdaterDownloadGuardTest) — this only works end to end if the
// checksum Update Manager sends actually reaches the update_checks row.
it('persists the release checksum alongside the download url', function (): void {
    $hash = str_repeat('a', 64);

    Http::fake([
        Marketplace::API_BASE.'/updates' => Http::response([
            'core' => [
                'latest_version' => '9.9.9',
                'update_available' => true,
                'changelog_url' => 'https://example.test/changelog',
                'zip_url' => 'https://github.com/magna-cms/magna/archive/v9.9.9.zip',
                'zip_sha256' => $hash,
            ],
            'plugins' => [],
        ]),
    ]);

    app(UpdateCheckClient::class)->checkIn();

    expect(UpdateCheck::core()?->download_sha256)->toBe($hash);
});

it('does not persist a malformed checksum', function (): void {
    Http::fake([
        Marketplace::API_BASE.'/updates' => Http::response([
            'core' => [
                'latest_version' => '9.9.9',
                'update_available' => true,
                'changelog_url' => null,
                'zip_url' => 'https://github.com/magna-cms/magna/archive/v9.9.9.zip',
                'zip_sha256' => 'not-a-real-hash',
            ],
            'plugins' => [],
        ]),
    ]);

    app(UpdateCheckClient::class)->checkIn();

    expect(UpdateCheck::core()?->download_sha256)->toBeNull();
});

/*
 * What Update Manager may say beyond where the latest release is: what it
 * needs (hints; the archive's manifest is what the updater enforces) and the
 * verified archive for the version this site ALREADY runs, so a site an older
 * updater left incomplete can repair itself from the panel.
 */
it('persists the release requirements and the repair grant when the server sends them', function (): void {
    $hash = str_repeat('a', 64);
    $installedHash = str_repeat('b', 64);

    Http::fake([
        Marketplace::API_BASE.'/updates' => Http::response([
            'core' => [
                'latest_version' => '9.9.9',
                'update_available' => true,
                'changelog_url' => null,
                'zip_url' => 'https://github.com/magna-cms/magna/releases/download/v9.9.9/magna-cms-v9.9.9.zip',
                'zip_sha256' => $hash,
                'requires' => ['php' => '>=8.3.0', 'min_upgrade_from' => 'v1.2.0'],
                'installed' => [
                    'zip_url' => 'https://github.com/magna-cms/magna/releases/download/v1.4.4/magna-cms-v1.4.4.zip',
                    'zip_sha256' => strtoupper($installedHash),
                    'zip_sha256_signature' => 'c2ln',
                ],
            ],
            'plugins' => [],
        ]),
    ]);

    app(UpdateCheckClient::class)->checkIn();

    $core = UpdateCheck::core();

    expect($core?->requires_php)->toBe('>=8.3.0')
        ->and($core?->min_upgrade_from)->toBe('1.2.0')
        ->and($core?->installed_download_url)->toContain('v1.4.4')
        ->and($core?->installed_download_sha256)->toBe($installedHash)
        ->and($core?->installed_download_sha256_signature)->toBe('c2ln')
        ->and($core?->hasRepairGrant())->toBeTrue();
});

it('records no repair grant when the server sends none', function (): void {
    Http::fake([
        Marketplace::API_BASE.'/updates' => Http::response([
            'core' => ['latest_version' => '9.9.9', 'update_available' => false, 'changelog_url' => null],
            'plugins' => [],
        ]),
    ]);

    app(UpdateCheckClient::class)->checkIn();

    expect(UpdateCheck::core()?->hasRepairGrant())->toBeFalse()
        ->and(UpdateCheck::core()?->requires_php)->toBeNull();
});
