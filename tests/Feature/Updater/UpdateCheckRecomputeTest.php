<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Magna\Auth\Role;
use Magna\Marketplace\Marketplace;
use Magna\Plugins\PluginRecord;
use Magna\Updater\InstalledVersionRecorder;
use Magna\Updater\UpdateCheck;
use Magna\Updater\UpdateCheckClient;
use Magna\Users\User;
use Tests\TestCase;

/*
 * The hub once compared a plugin's git tag ("v1.1.0") against a site's bare
 * installed version and answered update_available:false for every tagged
 * release — and the site stored that verdict verbatim, so no plugin update
 * was ever offered. Whether a newer release exists is now decided on this
 * side; the hub stays authoritative only for the latest version and the
 * licence verdict. The same prefix mismatch inverted the recorder and
 * rendered "vv1.1.0" in the bell.
 */

uses(TestCase::class, RefreshDatabase::class);

function recomputePlugin(string $name, string $version): void
{
    PluginRecord::query()->create([
        'name' => $name,
        'display_name' => 'Blog',
        'version' => $version,
        'enabled' => true,
        'base_path' => sys_get_temp_dir().'/'.str_replace('/', '-', $name),
        'manifest' => [
            'name' => $name, 'displayName' => 'Blog', 'description' => 'x', 'version' => $version,
            'author' => 'Acme', 'license' => 'MIT', 'entry' => 'Acme\\Blog\\Plugin',
            'compat' => ['magna' => '^1.0'], 'permissions' => [], 'provides' => [],
        ],
    ]);
}

/** @param array<string, mixed> $entry */
function hubSays(string $slug, array $entry): void
{
    Http::fake([Marketplace::API_BASE.'/updates' => Http::response([
        'core' => null,
        'plugins' => [$slug => $entry + ['changelog_url' => null, 'license_required' => false]],
        'notices' => [],
    ])]);
}

it('offers a newer tagged release even when the hub says there is none', function (): void {
    recomputePlugin('magna-cms/blog', '1.0.0');
    hubSays('magna-cms/blog', ['latest_version' => 'v1.1.0', 'update_available' => false]);

    app(UpdateCheckClient::class)->checkIn();

    $row = UpdateCheck::query()->where('slug', 'magna-cms/blog')->sole();
    expect($row->update_available)->toBeTrue()
        ->and($row->license_required)->toBeFalse();
});

it('offers nothing for the release already installed, whatever the hub says', function (): void {
    recomputePlugin('magna-cms/blog', '1.1.0');
    hubSays('magna-cms/blog', ['latest_version' => 'v1.1.0', 'update_available' => true]);

    app(UpdateCheckClient::class)->checkIn();

    expect(UpdateCheck::query()->where('slug', 'magna-cms/blog')->sole()->update_available)->toBeFalse();
});

it('keeps a licence-blocked release a renew prompt, never an update', function (): void {
    recomputePlugin('acme/paid', '1.0.0');
    hubSays('acme/paid', ['latest_version' => 'v2.0.0', 'update_available' => false, 'license_required' => true]);

    app(UpdateCheckClient::class)->checkIn();

    $row = UpdateCheck::query()->where('slug', 'acme/paid')->sole();
    expect($row->update_available)->toBeFalse()
        ->and($row->license_required)->toBeTrue();
});

it('writes exactly one v in the bell, whatever spelling the hub sent', function (): void {
    $role = Role::factory()->create(['is_super_admin' => true]);
    $admin = User::factory()->create();
    $admin->assignRole($role);
    recomputePlugin('magna-cms/blog', '1.0.0');
    hubSays('magna-cms/blog', ['latest_version' => 'v1.1.0', 'update_available' => true]);

    app(UpdateCheckClient::class)->checkIn();

    $bell = json_decode((string) DB::table('notifications')->where('notifiable_id', $admin->id)->value('data'), true);
    $body = (string) ($bell['body'] ?? '');

    expect($body)->toContain('v1.1.0')
        ->and($body)->not->toContain('vv1.1.0');
});

it('records an install of a tagged version as up to date, not still behind', function (): void {
    UpdateCheck::query()->create([
        'type' => 'plugin', 'slug' => 'magna-cms/blog', 'current_version' => '1.0.0',
        'latest_version' => 'v1.1.0', 'update_available' => true, 'checked_at' => now(),
    ]);

    // The installer passes the catalog's tag; the row holds the hub's tag.
    app(InstalledVersionRecorder::class)->record('magna-cms/blog', 'v1.1.0');

    $row = UpdateCheck::query()->where('slug', 'magna-cms/blog')->sole();
    expect($row->update_available)->toBeFalse()
        ->and($row->current_version)->toBe('1.1.0');
});

it('records a bare install against a tagged newer latest as still behind', function (): void {
    UpdateCheck::query()->create([
        'type' => 'plugin', 'slug' => 'magna-cms/blog', 'current_version' => '1.0.0',
        'latest_version' => 'v1.2.0', 'update_available' => true, 'checked_at' => now(),
    ]);

    app(InstalledVersionRecorder::class)->record('magna-cms/blog', '1.1.0');

    expect(UpdateCheck::query()->where('slug', 'magna-cms/blog')->sole()->update_available)->toBeTrue();
});
