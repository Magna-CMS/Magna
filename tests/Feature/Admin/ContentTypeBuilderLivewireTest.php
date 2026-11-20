<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Magna\Admin\Pages\ContentTypeBuilder;
use Magna\Auth\Role;
use Magna\Content\DiffChange;
use Magna\Content\DiffChangeType;
use Magna\Content\DiffResult;
use Magna\Users\User;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

// ── Wireable round-trip unit checks ─────────────────────────────────────────

it('DiffChange survives a Wireable round-trip', function (): void {
    $original = new DiffChange(
        type: DiffChangeType::CreateTable,
        contentTypeHandle: 'blog_post',
        column: null,
        destructive: false,
        description: 'Create table "content_blog_posts".',
    );

    $wire = $original->toLivewire();
    $restored = DiffChange::fromLivewire($wire);

    expect($restored->type)->toBe($original->type)
        ->and($restored->contentTypeHandle)->toBe($original->contentTypeHandle)
        ->and($restored->column)->toBeNull()
        ->and($restored->destructive)->toBeFalse()
        ->and($restored->description)->toBe($original->description);
});

it('DiffResult survives a Wireable round-trip', function (): void {
    $original = new DiffResult([
        new DiffChange(DiffChangeType::CreateTable, 'blog_post', null, false, 'Create table.'),
        new DiffChange(DiffChangeType::AddColumn, 'blog_post', 'title', false, 'Add column "title".'),
        new DiffChange(DiffChangeType::RemoveColumn, 'blog_post', 'old_col', true, 'Remove column (destructive).'),
    ]);

    $wire = $original->toLivewire();
    $restored = DiffResult::fromLivewire($wire);

    expect($restored->changes)->toHaveCount(3)
        ->and($restored->hasDestructive())->toBeTrue()
        ->and($restored->changes[0]->type)->toBe(DiffChangeType::CreateTable)
        ->and($restored->changes[2]->destructive)->toBeTrue();
});

// ── Livewire component integration ─────────────────────────────────────────

it('ContentTypeBuilder previewDiff succeeds through Livewire without serialization error', function (): void {
    $role = Role::factory()->create([
        'handle' => 'super_admin',
        'name' => 'Super Admin',
        'is_super_admin' => true,
    ]);
    $admin = User::factory()->create();
    $admin->assignRole($role);

    Livewire::actingAs($admin)
        ->test(ContentTypeBuilder::class)
        ->set('typeData.handle', 'test_post')
        ->set('typeData.displayName', 'Test Post')
        ->set('typeData.fields', [
            ['handle' => 'title', 'type' => 'text', 'required' => true],
        ])
        ->call('previewDiff')
        ->assertHasNoErrors()
        ->assertSet('showDiffConfirm', true);
});
