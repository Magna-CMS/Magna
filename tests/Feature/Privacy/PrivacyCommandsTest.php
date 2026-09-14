<?php

declare(strict_types=1);

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Magna\Contracts\HandlesPersonalData;
use Magna\Plugins\PluginManager;
use Magna\Users\User;
use Magna\Users\UserStatus;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/*
 * First direct coverage of src/Magna/Privacy (W5 test-gap item): the GDPR
 * export, erasure and export-purge commands. The plugin manager is swapped
 * for a stub so the contract semantics — a plugin without
 * HandlesPersonalData blocks an erasure — are pinned without booting real
 * plugins.
 */

/**
 * @param  array<string, object>  $enabled
 */
function fakePluginManager(array $enabled): void
{
    $manager = Mockery::mock(PluginManager::class);
    $manager->shouldReceive('getEnabled')->andReturn($enabled);
    app()->instance(PluginManager::class, $manager);
}

function privacySubject(): User
{
    $user = User::factory()->create(['name' => 'Grace Hopper', 'email' => 'grace@example.com']);
    $user->createToken('phone-app', ['delivery'], now()->addDay());

    return $user;
}

it('exports a personal-data archive with core profile, tokens and plugin data', function (): void {
    Storage::fake('local');

    $compliant = new class implements HandlesPersonalData
    {
        public function exportPersonalData(Authenticatable $user): array
        {
            return ['orders' => 3];
        }

        public function erasePersonalData(Authenticatable $user): void {}
    };
    fakePluginManager(['acme/shop' => $compliant]);

    $user = privacySubject();

    $this->artisan('magna:privacy:export', ['identifier' => 'grace@example.com'])
        ->assertExitCode(0);

    $files = Storage::disk('local')->files('privacy');
    expect($files)->toHaveCount(1);

    $export = json_decode((string) Storage::disk('local')->get($files[0]), true);
    expect($export['core']['profile']['email'])->toBe('grace@example.com')
        ->and($export['plugins']['acme/shop'])->toBe(['orders' => 3])
        ->and($export['core']['api_tokens'][0]['name'])->toBe('phone-app')
        // The token hash must never leave the tokens table.
        ->and($export['core']['api_tokens'][0])->not->toHaveKey('token');

    expect($user->getKey())->toBe($export['user_id']);
});

it('fails the export for an unknown identifier', function (): void {
    fakePluginManager([]);

    $this->artisan('magna:privacy:export', ['identifier' => 'nobody@example.com'])
        ->assertExitCode(1);
});

it('blocks an erasure while an enabled plugin does not implement HandlesPersonalData', function (): void {
    fakePluginManager(['acme/opaque' => new stdClass]);

    $user = privacySubject();

    $this->artisan('magna:privacy:erase', ['identifier' => 'grace@example.com'])
        ->assertExitCode(1);

    // Nothing irreversible happened: the record and its tokens are intact.
    $user->refresh();
    expect($user->name)->toBe('Grace Hopper')
        ->and($user->tokens()->count())->toBe(1);
});

it('erases with --force, anonymising the record but keeping the row', function (): void {
    $erased = new stdClass;
    $erased->called = false;

    $compliant = new class($erased) implements HandlesPersonalData
    {
        public function __construct(private readonly stdClass $flag) {}

        public function exportPersonalData(Authenticatable $user): array
        {
            return [];
        }

        public function erasePersonalData(Authenticatable $user): void
        {
            $this->flag->called = true;
        }
    };
    fakePluginManager(['acme/shop' => $compliant, 'acme/opaque' => new stdClass]);

    $user = privacySubject();
    $userId = $user->id;

    $this->artisan('magna:privacy:erase', ['identifier' => 'grace@example.com', '--force' => true])
        ->assertExitCode(0);

    $user->refresh();
    expect($erased->called)->toBeTrue()
        ->and($user->name)->toBe('Deleted User')
        ->and($user->email)->toBe("deleted_{$userId}@invalid")
        ->and($user->status)->toBe(UserStatus::Suspended)
        ->and($user->two_factor_confirmed_at)->toBeNull()
        ->and($user->tokens()->count())->toBe(0)
        // The row survives for referential integrity (audit logs point at it).
        ->and(User::query()->whereKey($userId)->exists())->toBeTrue();
});

it('purges only export archives older than the TTL', function (): void {
    Storage::fake('local');
    $disk = Storage::disk('local');

    $disk->put('privacy/export-old.json', '{}');
    $disk->put('privacy/export-new.json', '{}');
    $disk->put('privacy/not-an-export.json', '{}');

    // Age two of the files past the 30-day default.
    $old = strtotime('-40 days');
    touch($disk->path('privacy/export-old.json'), (int) $old);
    touch($disk->path('privacy/not-an-export.json'), (int) $old);

    $this->artisan('magna:privacy:purge-exports')->assertExitCode(0);

    expect($disk->exists('privacy/export-old.json'))->toBeFalse()
        ->and($disk->exists('privacy/export-new.json'))->toBeTrue()
        // Only files this command wrote are its business.
        ->and($disk->exists('privacy/not-an-export.json'))->toBeTrue();
});
