<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Magna\Auth\Role;
use Magna\Media\Livewire\MediaPickerModal;
use Magna\Media\Media;
use Magna\Users\User;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/*
 * Regression: the media picker's public Livewire methods listed the entire
 * media table and ingested device uploads with no permission check, while
 * the HTTP endpoints for the same operations gate on media.view and
 * media.upload. The component renders on the block editor and several
 * plugin editors, so any user who could open an editor — content
 * permissions only, no media.* grant — had the whole library and an
 * unauthorized upload path.
 */
function pickerUserWith(?string $permission): User
{
    $user = User::factory()->create(['two_factor_confirmed_at' => now()]);

    if ($permission !== null) {
        $role = Role::factory()->create();
        $role->grant($permission);
        $user->assignRole($role);
    }

    return $user;
}

it('refuses to list the library without media.view', function (): void {
    Livewire::actingAs(pickerUserWith(null))
        ->test(MediaPickerModal::class)
        ->call('loadFiles')
        ->assertForbidden();
});

it('refuses to open the picker without media.view', function (): void {
    Livewire::actingAs(pickerUserWith(null))
        ->test(MediaPickerModal::class)
        ->call('openPicker')
        ->assertForbidden();
});

it('refuses to resolve a selection without media.view', function (): void {
    Livewire::actingAs(pickerUserWith(null))
        ->test(MediaPickerModal::class)
        ->call('confirm', 'media/x.png')
        ->assertForbidden();
});

it('refuses a device upload without media.upload', function (): void {
    // media.view alone opens the picker; uploading is a separate grant,
    // exactly as it is on the HTTP endpoint. The hook only fires through a
    // property update, so the test attaches a real fake upload.
    Storage::fake('public');

    Livewire::actingAs(pickerUserWith('media.view'))
        ->test(MediaPickerModal::class)
        ->set('deviceUpload', UploadedFile::fake()->image('photo.jpg'))
        ->assertForbidden();

    expect(Media::query()->count())->toBe(0);
});

it('lists the library for a user holding media.view', function (): void {
    Livewire::actingAs(pickerUserWith('media.view'))
        ->test(MediaPickerModal::class)
        ->call('loadFiles')
        ->assertOk();
});

it('ingests a device upload for a user holding media.upload', function (): void {
    Storage::fake('public');
    Queue::fake();

    Livewire::actingAs(pickerUserWith('media.upload'))
        ->test(MediaPickerModal::class)
        ->set('deviceUpload', UploadedFile::fake()->image('photo.jpg'))
        ->assertOk();

    expect(Media::query()->count())->toBe(1);
});
