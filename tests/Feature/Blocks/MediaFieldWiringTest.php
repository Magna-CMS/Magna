<?php

declare(strict_types=1);

/**
 * Media picker wiring for block `media` fields — previously they fell
 * through to a plain text input while the global picker modal
 * (<livewire:magna-media-picker />) sat unused in the editor view.
 * Stored value is the Media ULID (image.blade.php resolves by id).
 */

use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Magna\Blocks\Livewire\BlockEditor;
use Magna\Media\Livewire\MediaPickerModal;
use Magna\Media\Media;
use Magna\Users\User;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function mediaWiringDocument(): string
{
    return (string) json_encode([[
        'id' => 'sec-1', 'type' => 'section', 'settings' => ['tokenOverrides' => []],
        'columns' => [[
            'id' => 'col-1', 'span' => 12, 'settings' => [],
            'blocks' => [[
                'id' => 'blk-img', 'block' => 'image', 'settings' => [],
                'data' => ['media_id' => null, 'alt' => '', 'caption' => '', 'display' => 'full'],
            ]],
        ]],
    ]]);
}

it('stores the selected media id when the picker echoes a block-field target', function (): void {
    $media = Media::factory()->create();

    Livewire::actingAs(User::factory()->create())
        ->test(BlockEditor::class, ['blocksData' => mediaWiringDocument()])
        ->dispatch('magna:media-selected',
            path: $media->path, url: 'https://x/y.png', disk: $media->disk,
            target: 'block-field:0:0:0:media_id', id: $media->id,
        )
        ->assertSet('sections.0.columns.0.blocks.0.data.media_id', $media->id);
});

it('ignores selections for other picker consumers and malformed targets', function (): void {
    $media = Media::factory()->create();

    $component = Livewire::actingAs(User::factory()->create())
        ->test(BlockEditor::class, ['blocksData' => mediaWiringDocument()]);

    $component->dispatch('magna:media-selected',
        path: $media->path, url: 'u', disk: $media->disk, target: 'logo', id: $media->id);
    $component->assertSet('sections.0.columns.0.blocks.0.data.media_id', null);

    $component->dispatch('magna:media-selected',
        path: $media->path, url: 'u', disk: $media->disk, target: 'block-field:zero:0:0:media_id', id: $media->id);
    $component->assertSet('sections.0.columns.0.blocks.0.data.media_id', null);

    // Missing id (legacy picker payload) must not store a disk path.
    $component->dispatch('magna:media-selected',
        path: $media->path, url: 'u', disk: $media->disk, target: 'block-field:0:0:0:media_id');
    $component->assertSet('sections.0.columns.0.blocks.0.data.media_id', null);
});

it('clears a media field', function (): void {
    $media = Media::factory()->create();

    Livewire::actingAs(User::factory()->create())
        ->test(BlockEditor::class, ['blocksData' => mediaWiringDocument()])
        ->dispatch('magna:media-selected',
            path: $media->path, url: 'u', disk: $media->disk,
            target: 'block-field:0:0:0:media_id', id: $media->id)
        ->call('clearMediaField', 0, 0, 0, 'media_id')
        ->assertSet('sections.0.columns.0.blocks.0.data.media_id', null);
});

it('resolves thumbnail and label helpers by media id', function (): void {
    $media = Media::factory()->create([
        'mime_type' => 'image/png',
        'original_filename' => 'team-photo.png',
        'title' => null,
    ]);

    $editor = Livewire::actingAs(User::factory()->create())
        ->test(BlockEditor::class, ['blocksData' => mediaWiringDocument()])
        ->instance();

    expect($editor->mediaLabel($media->id))->toBe('team-photo.png')
        ->and($editor->mediaThumbUrl($media->id))->toBeString()
        ->and($editor->mediaThumbUrl('01NOTAREALMEDIAIDXXXXXXXXX'))->toBeNull()
        ->and($editor->mediaLabel(null))->toBeNull();
});

it('picker confirm dispatches the media id alongside path and disk', function (): void {
    $media = Media::factory()->create();

    Livewire::actingAs(User::factory()->create())
        ->test(MediaPickerModal::class)
        ->call('confirm', $media->path, $media->disk)
        ->assertDispatched('magna:media-selected', id: $media->id, path: $media->path, disk: $media->disk);
});
