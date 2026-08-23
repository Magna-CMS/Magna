<?php

declare(strict_types=1);

/**
 * The logo block.
 *
 * A site's logo is an image when there is one and a wordmark when there is
 * not — plenty of sites are only ever the wordmark, and a header that
 * renders nothing while somebody chooses an image is a header that looks
 * broken.
 */

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Magna\Blocks\BlockRegistry;
use Tests\TestCase;

// The wordmark falls back to the site's name, which lives in settings.
uses(TestCase::class, RefreshDatabase::class);

function renderLogo(array $data = []): string
{
    return trim((string) preg_replace(
        '/<!--\[if (BLOCK|ENDBLOCK)\]><!\[endif\]-->/',
        '',
        Blade::render(
            (string) file_get_contents(
                dirname(__DIR__, 3).'/src/Magna/Blocks/resources/views/blocks/logo.blade.php',
            ),
            ['block' => ['data' => $data]],
        ),
    ));
}

it('is offered as a site element', function (): void {
    $definition = app(BlockRegistry::class)->get('logo');

    expect($definition)->not->toBeNull()
        ->and($definition->category)->toBe('site');
});

it('falls back to the site name rather than rendering nothing', function (): void {
    // No image and no text is the state a logo is in the moment it is
    // added. Rendering nothing there would look like a broken header.
    expect(renderLogo())->toContain('magna-logo__text');
});

it('prefers the wordmark it was given', function (): void {
    expect(renderLogo(['text' => 'Northwind']))->toContain('Northwind');
});

it('links home by default, and sanitises whatever it is given', function (): void {
    expect(renderLogo())->toContain('href="/"');
    expect(str_contains(renderLogo(['url' => 'javascript:alert(1)']), 'javascript:'))->toBeFalse();
});

it('takes a height, and refuses one that is not a length', function (): void {
    expect(renderLogo(['media_id' => null, 'height' => '64px', 'text' => 'X']))
        // No image, so no height to apply — the wordmark is text.
        ->not->toContain('height:64px');

    // A value carrying a declaration of its own never reaches the style.
    foreach (['64px;position:fixed', 'expression(alert(1))', '64'] as $attempt) {
        expect(str_contains(renderLogo(['height' => $attempt]), $attempt))->toBeFalse();
    }
});

it('is a link by default and an h1 only when an editor says so', function (): void {
    // An h1 that is wrong is an accessibility problem, not a style one, so
    // it is never assumed — only the editor knows what the page is.
    expect(str_contains(renderLogo(['text' => 'X']), '<h1'))->toBeFalse();
    expect(renderLogo(['text' => 'X', 'heading' => 'yes']))->toContain('<h1');
});

it('never leaves the link unnamed', function (): void {
    // With an image the alt names it; with neither image nor text the
    // link would otherwise announce as nothing at all.
    expect(renderLogo(['text' => '', 'alt' => '']))->toContain('aria-label="Home"');
});
