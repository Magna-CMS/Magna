<?php

declare(strict_types=1);

use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Magna\Admin\Support\InitialsAvatarProvider;
use Magna\Users\User;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function (): void {
    Filament::setCurrentPanel(Filament::getPanel('magna'));
});

// Filament's stock provider points the no-photo avatar at ui-avatars.com, which
// the panel CSP blocks — users without a photo saw a broken image instead of
// their initials. The fallback must stay local, so this pins both halves: it is
// a data: URI, and it never reaches for an outside host.
it('draws the fallback avatar locally instead of calling a third-party host', function (): void {
    $user = User::factory()->create(['name' => 'Jishnuraj M', 'avatar_path' => null]);

    $url = (new InitialsAvatarProvider)->get($user);

    expect($url)->toStartWith('data:image/svg+xml;base64,')
        ->and($url)->not->toContain('ui-avatars.com');

    $svg = base64_decode(str($url)->after('base64,')->toString(), true);

    // The only URL in the markup is the SVG namespace; nothing in it is fetched.
    expect($svg)->toBeString()
        ->and($svg)->toContain('>JM<')
        ->and($svg)->not->toContain('<image')
        ->and($svg)->not->toContain('href');
});

it('is the provider the panel actually uses', function (): void {
    expect(Filament::getPanel('magna')->getDefaultAvatarProvider())
        ->toBe(InitialsAvatarProvider::class);
});

// The initials land inside SVG markup, so a name carrying markup characters
// must not be able to close the <text> node it is written into.
it('escapes markup characters in a name', function (): void {
    $user = User::factory()->create(['name' => '<script>alert(1)</script> Doe', 'avatar_path' => null]);

    $url = (new InitialsAvatarProvider)->get($user);
    $svg = base64_decode(str($url)->after('base64,')->toString(), true);

    expect($svg)->toBeString()
        ->and($svg)->not->toContain('<script');
});
