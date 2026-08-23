<?php

declare(strict_types=1);

/**
 * Pictures, for the builder.
 *
 * Reported as "no option to paste svg code, no option to upload image".
 * A `media` field rendered as a plain text box, which asked an editor to
 * type a media id from memory — so a logo had no way to get a picture at
 * all, and neither did the image block.
 */

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Magna\Auth\Role;
use Magna\Media\Media;
use Magna\Plugins\PluginManager;
use Magna\Testing\PluginTestCase;
use Magna\Users\User;

uses(PluginTestCase::class);

function builderMediaUser(bool $canUpload = true): User
{
    skipWithoutDevPlugin('magna/pages');
    app(PluginManager::class)->enable('magna/pages');

    $user = User::factory()->create();
    $role = Role::factory()->create();
    $grants = ['panel.access', 'pages.content', 'media.view'];
    if ($canUpload) {
        $grants[] = 'media.upload';
    }
    $role->grant(...$grants);
    $user->assignRole($role);

    return $user;
}

it('lists the pictures a builder can choose from', function (): void {
    $user = builderMediaUser();

    $this->actingAs($user)->postJson(url('/pages-builder/media'), [
        'file' => UploadedFile::fake()->image('wordmark.png', 120, 40),
    ])->assertStatus(201);

    $listed = $this->actingAs($user)->getJson(url('/pages-builder/media'))
        ->assertOk()->json('media');

    expect($listed)->toHaveCount(1)
        ->and($listed[0]['name'])->toContain('wordmark')
        ->and($listed[0]['url'])->not->toBeEmpty();
});

it('takes SVG markup pasted straight in', function (): void {
    $user = builderMediaUser();

    $media = $this->actingAs($user)->postJson(url('/pages-builder/media'), [
        'svg' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10"><circle cx="5" cy="5" r="4"/></svg>',
    ])->assertStatus(201)->json();

    expect($media['mime'])->toBe('image/svg+xml')
        ->and(Media::find($media['id']))->not->toBeNull();
});

it('sanitises pasted SVG through the same pipeline an upload uses', function (): void {
    $user = builderMediaUser();

    // The whole reason pasted markup is written to a file and ingested
    // rather than stored directly: a second path would be a second thing
    // to keep safe, and the one skipping the sanitiser is the one somebody
    // pastes a script into.
    $media = $this->actingAs($user)->postJson(url('/pages-builder/media'), [
        'svg' => '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script><circle r="4"/></svg>',
    ])->assertStatus(201)->json();

    $stored = Media::findOrFail($media['id']);
    $contents = (string) Storage::disk($stored->disk)->get($stored->path);

    expect(str_contains($contents, '<script>'))->toBeFalse()
        ->and(str_contains($contents, 'alert(1)'))->toBeFalse();
});

it('refuses a paste that is not SVG at all', function (): void {
    $user = builderMediaUser();

    $this->actingAs($user)->postJson(url('/pages-builder/media'), ['svg' => 'just some words'])
        ->assertStatus(422);
});

it('refuses an empty add', function (): void {
    $user = builderMediaUser();

    $this->actingAs($user)->postJson(url('/pages-builder/media'), [])->assertStatus(422);
});

it('needs the media permissions, not merely the builder', function (): void {
    // Being in the builder is not a way around the media permissions.
    $user = builderMediaUser(canUpload: false);

    $this->actingAs($user)->postJson(url('/pages-builder/media'), [
        'svg' => '<svg xmlns="http://www.w3.org/2000/svg"><circle r="1"/></svg>',
    ])->assertForbidden();

    // Viewing is a separate grant, and this actor has it.
    $this->actingAs($user)->getJson(url('/pages-builder/media'))->assertOk();
});
