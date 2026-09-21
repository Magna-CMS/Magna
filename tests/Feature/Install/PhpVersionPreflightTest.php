<?php

declare(strict_types=1);

/**
 * Composer's platform_check.php throws before anything of Magna's has loaded,
 * so a server below the floor met an uncaught RuntimeException and a stack
 * trace of absolute paths — Requirements' polite version check sits behind the
 * very autoloader that fatals. public/index.php now refuses first, in syntax
 * old enough for the interpreters this has to greet.
 */
function magnaIndexSource(): string
{
    return (string) file_get_contents(base_path('public/index.php'));
}

it('refuses an unsupported PHP version before loading the autoloader', function (): void {
    $source = magnaIndexSource();

    $guard = strpos($source, "version_compare(PHP_VERSION, '8.3.0', '<')");
    $autoload = strpos($source, "require __DIR__.'/../vendor/autoload.php'");

    expect($guard)->not->toBeFalse()
        ->and($autoload)->not->toBeFalse()
        ->and($guard)->toBeLessThan($autoload);
});

it('ships the page the guard renders', function (): void {
    expect(magnaIndexSource())->toContain("require __DIR__.'/../bootstrap/unsupported-php.php'")
        ->and(is_file(base_path('bootstrap/unsupported-php.php')))->toBeTrue();
});

it('states the same minimum version as composer.json and the requirements screen', function (): void {
    /** @var array{require?: array<string, string>} $composer */
    $composer = json_decode((string) file_get_contents(base_path('composer.json')), true);

    expect($composer['require']['php'] ?? null)->toBe('^8.3')
        ->and(magnaIndexSource())->toContain("'8.3.0'")
        ->and((string) file_get_contents(base_path('src/Magna/Install/Requirements.php')))
        ->toContain("'8.3.0'");
});

it('keeps the guard and its page parseable by the old interpreters they greet', function (): void {
    // Both files are read by a PHP that is, by definition, below the floor —
    // and PHP parses a whole file before running a line of it, so the guard is
    // only reached if EVERY statement in index.php, including the ones after
    // it, is syntax that interpreter understands. A parse error here is the
    // blank page this guard exists to prevent.
    $modern = ['?->', 'match (', 'fn (', 'declare(strict_types', '#[', 'enum ', 'readonly '];

    $sources = [
        magnaIndexSource(),
        (string) file_get_contents(base_path('bootstrap/unsupported-php.php')),
    ];

    foreach ($sources as $subject) {
        foreach ($modern as $token) {
            expect($subject)->not->toContain($token);
        }
    }
});
