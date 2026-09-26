<?php

declare(strict_types=1);

use Magna\Marketplace\ComposerRunner;
use Magna\Updater\Engine\EngineContext;
use Tests\TestCase;

uses(TestCase::class);

/*
 * The engine puts a site's own packages back with `composer require` after
 * the release's vendor/ is in. The arguments were only ever seen by a fake
 * Composer in tests, and one of them was a Composer 1 flag: on the first
 * live site with a Marketplace package, Composer printed its usage, exited
 * 1, and the whole switch was rolled back. The installed Composer is the
 * authority on what it accepts, so ask it.
 */
it('passes composer require only options the installed Composer accepts', function (): void {
    $composer = app(ComposerRunner::class);

    if (! $composer->isAvailable()) {
        $this->markTestSkipped('Composer is not reachable from this process, so its option list cannot be read.');
    }

    $help = $composer->run(['require', '--help', '--no-ansi'], 60);

    expect($help->successful())->toBeTrue($help->output);

    preg_match_all('/--[a-z][a-z-]*/', $help->output, $matches);
    $accepted = array_values(array_unique($matches[0]));

    expect($accepted)->toContain('--no-scripts');

    foreach (EngineContext::COMPOSER_REQUIRE_ARGUMENTS as $argument) {
        if (str_starts_with($argument, '--')) {
            expect($accepted)->toContain($argument);
        }
    }

    expect(EngineContext::COMPOSER_REQUIRE_ARGUMENTS[0])->toBe('require');
});
