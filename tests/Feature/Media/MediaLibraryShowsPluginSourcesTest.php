<?php

declare(strict_types=1);

use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Magna\Admin\Resources\Media\ListMedia;
use Magna\Auth\Role;
use Magna\Contracts\MediaSource;
use Magna\Contracts\MediaSourceItem;
use Magna\Contracts\RegistersMediaSources;
use Magna\Plugins\PluginManager;
use Magna\Users\User;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function (): void {
    Filament::setCurrentPanel(Filament::getPanel('magna'));
});

/**
 * The library screen itself, end to end: a plugin declares files it holds, and
 * an operator on /media can see that they exist without the plugin handing
 * over a single byte.
 *
 * The confidential half is the part worth pinning down. Listing a file and
 * serving it are one small step apart in markup, and the step is easy to take
 * by accident — an <img src> added later "so the grid looks consistent" would
 * publish scans of people's identity documents to every operator. So the
 * absence of a URL is asserted, not assumed.
 */
final class LibraryTestSource implements MediaSource
{
    public function key(): string
    {
        return 'test.documents';
    }

    public function label(): string
    {
        return 'Empanelment documents';
    }

    public function permission(): ?string
    {
        return null;
    }

    public function isConfidential(): bool
    {
        return true;
    }

    public function count(): int
    {
        return 1;
    }

    public function items(int $page, int $perPage, string $search = ''): array
    {
        if ($search !== '' && ! str_contains('aadhaar-front.jpg', $search)) {
            return [];
        }

        return [new MediaSourceItem(
            id: 'doc-1',
            name: 'aadhaar-front.jpg',
            mimeType: 'image/jpeg',
            sizeBytes: 204_800,
            uploadedAt: new DateTimeImmutable('2026-08-01'),
            ownerLabel: 'Anita Rao',
            manageUrl: '/embhas-documents',
            thumbnailUrl: null,
        )];
    }
}

final class LibraryTestPlugin implements RegistersMediaSources
{
    public function mediaSources(): iterable
    {
        return [new LibraryTestSource];
    }
}

function mediaLibraryOperator(): User
{
    $role = Role::factory()->create(['handle' => 'librarian', 'name' => 'Librarian']);
    $role->grant('media.view');
    $user = User::factory()->create(['two_factor_confirmed_at' => now()]);
    $user->assignRole($role);

    return $user;
}

function withPluginMediaSource(): void
{
    $manager = Mockery::mock(PluginManager::class);
    $manager->shouldReceive('getEnabled')->andReturn([new LibraryTestPlugin]);

    app()->instance(PluginManager::class, $manager);
}

it('offers a tab for files a plugin holds outside the library', function (): void {
    withPluginMediaSource();
    $this->actingAs(mediaLibraryOperator());

    Livewire::test(ListMedia::class)
        ->assertSee('Empanelment documents');
});

it('lists the plugin files once that tab is selected', function (): void {
    withPluginMediaSource();
    $this->actingAs(mediaLibraryOperator());

    Livewire::test(ListMedia::class)
        ->call('selectSource', 'test.documents')
        ->assertSee('aadhaar-front.jpg')
        ->assertSee('Anita Rao');
});

it('never renders a way to open a confidential file from the library', function (): void {
    withPluginMediaSource();
    $this->actingAs(mediaLibraryOperator());

    $html = Livewire::test(ListMedia::class)
        ->call('selectSource', 'test.documents')
        ->html();

    // The file is named, and there is no image tag and no storage URL for it —
    // only the link back into the plugin that owns it.
    expect($html)->toContain('aadhaar-front.jpg')
        ->and($html)->not->toContain('<img src="/storage')
        ->and($html)->toContain('/embhas-documents');
});

it('searches within the selected source', function (): void {
    withPluginMediaSource();
    $this->actingAs(mediaLibraryOperator());

    Livewire::test(ListMedia::class)
        ->call('selectSource', 'test.documents')
        ->set('gallerySearch', 'passport')
        ->assertDontSee('aadhaar-front.jpg')
        ->assertSee('No files match your search.');
});

it('returns to the library itself when the tab is cleared', function (): void {
    withPluginMediaSource();
    $this->actingAs(mediaLibraryOperator());

    Livewire::test(ListMedia::class)
        ->call('selectSource', 'test.documents')
        ->call('selectSource', null)
        ->assertDontSee('aadhaar-front.jpg');
});

it('ignores a source key the user was never shown', function (): void {
    // No plugin declares anything, so the key cannot resolve — the page must
    // fall back to the library rather than erroring or rendering an empty
    // source panel that implies the source exists.
    $manager = Mockery::mock(PluginManager::class);
    $manager->shouldReceive('getEnabled')->andReturn([]);
    app()->instance(PluginManager::class, $manager);

    $this->actingAs(mediaLibraryOperator());

    Livewire::test(ListMedia::class)
        ->call('selectSource', 'test.documents')
        ->assertSee('No media uploaded yet.');
});
