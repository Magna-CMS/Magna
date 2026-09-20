<?php

declare(strict_types=1);

/**
 * Token values are raw text inside an inline <style> element on every public
 * page. A browser terminates that element's raw text at `</style` and at
 * nothing else — so the old filter (no ';', no '(') let
 * `red</style><script src=//evil/x.js>` through as a stored XSS while
 * containing neither banned character. ThemeTokens::valueIsSafe() is now the
 * single filter, applied both where StyleManager accepts a Design-tab write
 * and where ThemeTokens reads values back out of storage, so a hostile value
 * already in the database never reaches markup either.
 */

use Magna\Pages\Themes\ThemeTokens;
use Magna\Testing\PluginTestCase;

uses(PluginTestCase::class);

it('refuses a token value that could escape the style element', function (string $value): void {
    skipWithoutDevPlugin('magna/pages');

    expect(ThemeTokens::valueIsSafe($value))->toBeFalse();
})->with([
    'style-element breakout' => ['red</style><script src=//evil/x.js></script>'],
    'bare angle bracket' => ['12px<'],
    'closing bracket' => ['bold>'],
    'function smuggling' => ['url(javascript:alert(1))'],
    'declaration smuggling' => ['red;position:fixed'],
    'empty' => [''],
]);

it('accepts ordinary CSS token values', function (string $value): void {
    skipWithoutDevPlugin('magna/pages');

    expect(ThemeTokens::valueIsSafe($value))->toBeTrue();
})->with([
    'hex color' => ['#1a2b3c'],
    'length' => ['1.25rem'],
    'font stack' => ["'Inter', sans-serif"],
    'keyword pair' => ['600 normal'],
]);
