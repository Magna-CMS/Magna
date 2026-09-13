<?php

declare(strict_types=1);

use Magna\Updater\CoreUpdater;
use Magna\Updater\CoreUpdateState;
use Tests\TestCase;

uses(TestCase::class);

// The download/verify/extract mechanics themselves (host allowlist, checksum,
// signed-checksum policy) live on Magna\Updater\ReleaseArchive and are
// specced in ReleaseArchiveTest — no reflection into privates needed since
// the collaborator's methods are public. What stays here is the
// ORCHESTRATOR's own guarantee: apply() fails closed before any network,
// lock or backup machinery runs when the release carries no usable checksum.

it('fails apply() immediately when no checksum is supplied', function (): void {
    $updater = app(CoreUpdater::class);

    $state = $updater->apply('9.9.9', 'https://github.com/magna-cms/magna/archive/v9.9.9.zip', null);

    expect($state)->toBe(CoreUpdateState::Failed);
    expect(CoreUpdater::progress()['message'])->toContain('no verified checksum');
});

it('fails apply() immediately when the checksum is not a valid sha256 hex string', function (): void {
    $updater = app(CoreUpdater::class);

    $state = $updater->apply('9.9.9', 'https://github.com/magna-cms/magna/archive/v9.9.9.zip', 'not-a-real-checksum');

    expect($state)->toBe(CoreUpdateState::Failed);
});
