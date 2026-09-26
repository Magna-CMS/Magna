<?php

declare(strict_types=1);

use Magna\Updater\Engine\EngineLoader;
use Tests\TestCase;

uses(TestCase::class);

/**
 * The two closures under bootstrap/update travel inside every archive and
 * run before, or beside, the framework. They must stay what they are.
 */
function shippedSource(string $file): string
{
    return (string) file_get_contents(base_path('bootstrap/update/'.$file));
}

/** Names the source uses outside comments and strings. */
function namedSymbols(string $source): array
{
    $names = [];

    foreach (token_get_all($source) as $token) {
        if (is_array($token) && in_array($token[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
            $names[] = ltrim($token[1], '\\');
        }
    }

    return $names;
}

it('ships an engine the loader accepts and that returns a closure', function (): void {
    expect(EngineLoader::refuse(shippedSource('engine.php')))->toBeNull();

    $engine = require base_path('bootstrap/update/engine.php');

    expect($engine)->toBeInstanceOf(Closure::class);
});

it('ships a boot guard that depends on nothing of Magna\'s and declares nothing', function (): void {
    $source = shippedSource('boot-guard.php');

    foreach (namedSymbols($source) as $name) {
        expect($name)->not->toStartWith('Magna\\');
    }

    expect($source)->not->toMatch('/\b(class|interface|trait|enum|namespace)\s+\w/')
        ->and($source)->not->toMatch('/\bfunction\s+\w+\s*\(/')
        ->and($source)->not->toContain('use Illuminate');
});

it('is required before anything else in bootstrap/app.php', function (): void {
    $source = (string) file_get_contents(base_path('bootstrap/app.php'));

    $guard = strpos($source, "require __DIR__.'/update/boot-guard.php';");
    $firstUse = strpos($source, 'use Illuminate');

    expect($guard)->not->toBeFalse()
        ->and($firstUse)->not->toBeFalse()
        ->and($guard)->toBeLessThan($firstUse);
});
