<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Magna\Auth\Role;
use Magna\Contracts\MediaSource;
use Magna\Contracts\MediaSourceItem;
use Magna\Contracts\RegistersMediaSources;
use Magna\Media\MediaSourceRegistry;
use Magna\Plugins\PluginManager;
use Magna\Users\User;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * Sources declared by plugins are how the media library learns about files it
 * does not hold. Two things have to be true of that mechanism: an operator
 * sees the sources they are entitled to, and never one they are not — the file
 * names in a confidential source ("aadhaar-front.jpg") disclose things on
 * their own, so "the tab is empty for you" is not good enough. It must not be
 * drawn at all, and a key typed straight into the query string must not reach
 * it either.
 */
final class FakeMediaSource implements MediaSource
{
    public function __construct(
        private readonly string $key,
        private readonly ?string $permission,
        private readonly bool $confidential = true,
    ) {}

    public function key(): string
    {
        return $this->key;
    }

    public function label(): string
    {
        return 'Fake '.$this->key;
    }

    public function permission(): ?string
    {
        return $this->permission;
    }

    public function isConfidential(): bool
    {
        return $this->confidential;
    }

    public function count(): int
    {
        return 1;
    }

    public function items(int $page, int $perPage, string $search = ''): array
    {
        return [new MediaSourceItem(id: '1', name: 'secret.pdf', mimeType: 'application/pdf')];
    }
}

/** A plugin whose entry class explodes when asked what it stores. */
final class BrokenMediaSourcePlugin implements RegistersMediaSources
{
    public function mediaSources(): iterable
    {
        throw new RuntimeException('this plugin is broken');
    }
}

final class WorkingMediaSourcePlugin implements RegistersMediaSources
{
    public function mediaSources(): iterable
    {
        return [new FakeMediaSource('working.files', null)];
    }
}

/**
 * Stand in for the booted-plugin list, which is what the registry reads.
 *
 * @param  array<int, object>  $plugins
 */
function fakeEnabledPlugins(array $plugins): void
{
    $manager = Mockery::mock(PluginManager::class);
    $manager->shouldReceive('getEnabled')->andReturn($plugins);

    app()->instance(PluginManager::class, $manager);
}

function mediaSourceUserWithGrants(string ...$grants): User
{
    $role = Role::factory()->create(['handle' => 'media-'.Str::random(6), 'name' => 'Media']);
    $role->grant(...$grants);
    $user = User::factory()->create();
    $user->assignRole($role);

    return $user;
}

it('hides a source whose permission the user does not hold', function (): void {
    fakeEnabledPlugins([new class implements RegistersMediaSources
    {
        public function mediaSources(): iterable
        {
            return [new FakeMediaSource('embhas.documents', 'media.delete')];
        }
    }]);

    $this->actingAs(mediaSourceUserWithGrants('media.view'));

    expect(app(MediaSourceRegistry::class)->visible())->toBe([]);
});

it('shows a source whose permission the user holds', function (): void {
    fakeEnabledPlugins([new class implements RegistersMediaSources
    {
        public function mediaSources(): iterable
        {
            return [new FakeMediaSource('embhas.documents', 'media.delete')];
        }
    }]);

    $this->actingAs(mediaSourceUserWithGrants('media.delete'));

    $visible = app(MediaSourceRegistry::class)->visible();

    expect($visible)->toHaveCount(1)
        ->and($visible[0]->key())->toBe('embhas.documents');
});

it('refuses to resolve a forbidden source by key', function (): void {
    fakeEnabledPlugins([new class implements RegistersMediaSources
    {
        public function mediaSources(): iterable
        {
            return [new FakeMediaSource('embhas.documents', 'media.delete')];
        }
    }]);

    $this->actingAs(mediaSourceUserWithGrants('media.view'));

    // The tab was never drawn for this user; typing its key must not be a way in.
    expect(app(MediaSourceRegistry::class)->find('embhas.documents'))->toBeNull();
});

it('hides a permissioned source from a guest', function (): void {
    fakeEnabledPlugins([new class implements RegistersMediaSources
    {
        public function mediaSources(): iterable
        {
            return [new FakeMediaSource('embhas.documents', 'media.delete')];
        }
    }]);

    expect(app(MediaSourceRegistry::class)->visible())->toBe([]);
});

it('keeps listing other sources when one plugin throws', function (): void {
    fakeEnabledPlugins([new BrokenMediaSourcePlugin, new WorkingMediaSourcePlugin]);

    // The media library is where an operator looks when something is already
    // wrong. One broken plugin must not be what takes it away from them.
    $visible = app(MediaSourceRegistry::class)->visible();

    expect($visible)->toHaveCount(1)
        ->and($visible[0]->key())->toBe('working.files');
});

it('ignores a second plugin claiming a key already taken', function (): void {
    fakeEnabledPlugins([new WorkingMediaSourcePlugin, new WorkingMediaSourcePlugin]);

    expect(app(MediaSourceRegistry::class)->all())->toHaveCount(1);
});

it('ignores plugins that declare no sources', function (): void {
    fakeEnabledPlugins([new stdClass]);

    expect(app(MediaSourceRegistry::class)->all())->toBe([]);
});
