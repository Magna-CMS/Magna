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

// The install confirmation is the last thing an admin reads before granting a
// plugin full application access, so what it claims about the publisher has to
// match what the registry actually asserted — and the access warning itself must
// survive in every case, official included.
//
// The modal body is read straight off the component: Filament renders action
// modals outside this component's own render, so asserting on the page HTML would
// pass whether or not the notice was correct.
function installNoticeFor(array $catalogExtra): string
{
    fakeTrustCatalog($catalogExtra);

    $component = Livewire::test(PluginsPage::class)
        ->call('setTab', 'addnew')
        ->set('pendingPluginName', 'acme/docs');

    $method = new ReflectionMethod(PluginsPage::class, 'installModalBody');
    $method->setAccessible(true);

    return (string) $method->invoke($component->instance());
}

it('tells the truth about the publisher in the install confirmation', function (): void {
    $this->actingAs(trustBadgeAdmin());

    $html = installNoticeFor(['official' => true]);

    expect($html)->toContain('Official plugin');
    expect($html)->not->toContain('Third-party plugin');
    expect($html)->toContain('full application access');
});

it('keeps the third-party warning for a publisher the registry does not vouch for', function (): void {
    $this->actingAs(trustBadgeAdmin());

    $html = installNoticeFor([]);

    expect($html)->toContain('Third-party plugin');
    expect($html)->not->toContain('Official plugin');
    expect($html)->toContain('full application access');
});

it('marks a verified publisher without dropping the third-party caution', function (): void {
    $this->actingAs(trustBadgeAdmin());

    $html = installNoticeFor(['verified' => true]);

    expect($html)->toContain('Third-party plugin — verified publisher');
    expect($html)->toContain('full application access');
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
