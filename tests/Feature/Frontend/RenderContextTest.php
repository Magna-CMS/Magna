<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Magna\Frontend\LocaleUrls;
use Magna\Frontend\RenderContext;
use Magna\Settings\LocalizationSettings;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function (): void {
    $settings = LocalizationSettings::get();
    $settings->available_locales = ['en', 'fr'];
    $settings->fallback_locale = 'en';
    $settings->save();
});

function contextFor(string $uri, ?string $locale = null): RenderContext
{
    $context = app(RenderContext::class);
    $context->setRequest(Request::create($uri), $locale);

    return $context;
}

it('answers the defaults outside a public request', function (): void {
    $context = app(RenderContext::class);

    expect($context->locale())->toBe('en')
        ->and($context->page())->toBe(1)
        ->and($context->path())->toBe('/')
        ->and($context->paginated())->toBeFalse()
        ->and($context->outOfRange())->toBeFalse();
});

it('reads the locale and page the request asks for', function (): void {
    $context = contextFor('/fr/blog?page=3', 'fr');

    expect($context->locale())->toBe('fr')
        ->and($context->isFallbackLocale())->toBeFalse()
        ->and($context->page())->toBe(3)
        ->and($context->path())->toBe('/fr/blog');
});

it('reads anything but plain digits as page one', function (string $raw): void {
    expect(contextFor('/blog?page='.$raw)->page())->toBe(1);
})->with(['abc', '-2', '1.5', '', '0', '000']);

it('marks a page past the listing as out of range once it paginates', function (): void {
    $context = contextFor('/blog?page=5');

    $pagination = $context->paginate(total: 35, perPage: 10);

    expect($pagination->page)->toBe(5)
        ->and($context->paginated())->toBeTrue()
        ->and($context->outOfRange())->toBeTrue();
});

it('keeps an in-range page in range', function (): void {
    $context = contextFor('/blog?page=4');
    $context->paginate(total: 35, perPage: 10);

    expect($context->outOfRange())->toBeFalse();
});

it('serves page one of an empty listing rather than a 404', function (): void {
    $context = contextFor('/blog');
    $context->paginate(total: 0, perPage: 10);

    expect($context->outOfRange())->toBeFalse();
});

it('treats a page past the cap as out of range without a huge offset', function (): void {
    $context = contextFor('/blog?page=99999999999999999999');

    expect($context->page())->toBe(RenderContext::MAX_PAGE)
        ->and($context->outOfRange())->toBeFalse();

    $context->paginate(total: 10_000, perPage: 1);

    expect($context->outOfRange())->toBeTrue();
});

it('leaves a page with nothing paginated in range, whatever ?page says', function (): void {
    expect(contextFor('/about?page=101')->outOfRange())->toBeFalse();
});

it('starts every request it is handed afresh', function (): void {
    $context = contextFor('/blog?page=9');
    $context->paginate(total: 20, perPage: 10);

    $context->setRequest(Request::create('/blog?page=2'));

    expect($context->paginated())->toBeFalse()
        ->and($context->outOfRange())->toBeFalse()
        ->and($context->page())->toBe(2);
});

it('is scoped to one request', function (): void {
    contextFor('/blog?page=2')->paginate(20, 10);

    app()->forgetScopedInstances();

    expect(app(RenderContext::class)->paginated())->toBeFalse()
        ->and(app(RenderContext::class)->page())->toBe(1);
});

it('splits and builds locale-prefixed urls by the site settings', function (): void {
    $urls = app(LocaleUrls::class);

    expect($urls->split('/fr/blog/hello'))->toBe(['locale' => 'fr', 'path' => 'blog/hello'])
        ->and($urls->split('/de/blog'))->toBe(['locale' => null, 'path' => 'de/blog'])
        ->and($urls->split('/en/blog'))->toBe(['locale' => null, 'path' => 'en/blog'])
        ->and($urls->split('/fr'))->toBe(['locale' => 'fr', 'path' => ''])
        ->and($urls->urlFor('fr', 'blog/hello'))->toBe('/fr/blog/hello')
        ->and($urls->urlFor('fr', '/'))->toBe('/fr')
        ->and($urls->urlFor('en', 'blog/hello'))->toBe('/blog/hello')
        ->and($urls->effective(null))->toBe('en')
        ->and($urls->available())->toBe(['en', 'fr']);
});
