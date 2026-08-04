<?php

declare(strict_types=1);

use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Magna\Admin\Pages\PluginsPage;
use Magna\Auth\Role;
use Magna\Marketplace\Marketplace;
use Magna\Marketplace\PluginListing;
use Magna\Users\User;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function (): void {
    Filament::setCurrentPanel(Filament::getPanel('magna'));
    Cache::flush();
});

function trustBadgeAdmin(): User
{
    $role = Role::factory()->create(['handle' => 'super_admin', 'name' => 'Super Admin', 'is_super_admin' => true]);
    $user = User::factory()->create(['two_factor_confirmed_at' => now()]);
    $user->assignRole($role);

    return $user;
}

/** @param array<string, mixed> $extra */
function fakeTrustCatalog(array $extra = []): void
{
    Http::fake([Marketplace::API_BASE.'/*' => Http::response([
        [
            'package' => 'acme/docs',
            'name' => 'Acme Docs',
            'shortDescription' => 'Documentation site',
            'version' => '1.0.0',
            'compat' => '^1.0',
        ] + $extra,
    ])]);
}

// The reported bug: a plugin published by a developer account the registry marks
// "official" was shown as "Third party", because the badge was derived from the
// install path (Composer vs plugins-dev/) and nothing carried publisher trust.
it('shows an Official badge for a listing published by an official developer', function (): void {
    fakeTrustCatalog(['official' => true]);
    $this->actingAs(trustBadgeAdmin());

    Livewire::test(PluginsPage::class)
        ->call('setTab', 'addnew')
        ->assertSee('Official')
        ->assertDontSee('Third party');
});

it('shows a Verified badge for a verified-but-not-official developer', function (): void {
    fakeTrustCatalog(['verified' => true]);
    $this->actingAs(trustBadgeAdmin());

    Livewire::test(PluginsPage::class)
        ->call('setTab', 'addnew')
        ->assertSee('Verified')
        ->assertDontSee('Third party');
});

it('still shows Third party when the registry vouches for nothing', function (): void {
    fakeTrustCatalog();
    $this->actingAs(trustBadgeAdmin());

    Livewire::test(PluginsPage::class)
        ->call('setTab', 'addnew')
        ->assertSee('Third party');
});

// Trust is a claim about a publisher and it arrives over the network, so it is
// only honoured when the registry states it as a real boolean — a truthy string
// from a compromised or sloppy registry must not paint a listing "Official".
it('only treats a strict boolean true as a trust flag', function (): void {
    $listing = PluginListing::fromArray([
        'package' => 'acme/forum',
        'name' => 'Acme Forum',
        'version' => '1.0.0',
        'compat' => '^1.0',
        'official' => '1',
        'verified' => 1,
    ]);

    expect($listing?->official)->toBeFalse();
    expect($listing?->verified)->toBeFalse();
});

it('defaults both trust flags to false for a catalog that predates them', function (): void {
    $listing = PluginListing::fromArray([
        'package' => 'acme/forum',
        'name' => 'Acme Forum',
        'version' => '1.0.0',
        'compat' => '^1.0',
    ]);

    expect($listing?->official)->toBeFalse();
    expect($listing?->verified)->toBeFalse();
});
