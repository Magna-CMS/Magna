<?php

declare(strict_types=1);

/**
 * Plugin frontend pages under locale prefixes (Pages 0.5.0). The router
 * strips the prefix before matching a plugin page, so `/fr/guides/{slug}`
 * reaches the same definition as `/guides/{slug}`, and records the locale
 * on core's RenderContext for the provider to read. A result's
 * localeAlternates reach the layout as hreflang links.
 */

use Magna\Frontend\FrontendPage;
use Magna\Frontend\FrontendPageDataProvider;
use Magna\Frontend\FrontendPageRegistry;
use Magna\Frontend\FrontendPageResult;
use Magna\Frontend\RenderContext;
use Magna\Pages\Routing\PageRedirect;
use Magna\Plugins\PluginManager;
use Magna\Settings\LocalizationSettings;
use Magna\Settings\SettingsRepository;
use Magna\Testing\PluginTestCase;

uses(PluginTestCase::class);

/** Records the locale it was rendered in; finds only "hello". */
final class FixtureLocaleAwareGuideProvider implements FrontendPageDataProvider
{
    /** @var list<string> */
    public static array $locales = [];

    public function resolve(array $params): ?FrontendPageResult
    {
        self::$locales[] = app(RenderContext::class)->locale();

        if (($params['slug'] ?? null) !== 'hello') {
            return null;
        }

        return new FrontendPageResult(
            data: ['postTitle' => 'Guide hello', 'body' => 'Guide body'],
            title: 'Guide hello',
            localeAlternates: ['en' => '/guides/hello', 'fr' => '/fr/guides/hello'],
        );
    }
}

function localeFrontendSetup(): void
{
    skipWithoutDevPlugin('magna-cms/pages');
    app(PluginManager::class)->enable('magna-cms/pages');
    FixtureLocaleAwareGuideProvider::$locales = [];

    $settings = LocalizationSettings::get();
    $settings->available_locales = ['en', 'fr'];
    $settings->fallback_locale = 'en';
    app(SettingsRepository::class)->persist($settings);

    view()->addNamespace('fpstub', dirname(__DIR__, 2).'/stubs/frontend');
    app(FrontendPageRegistry::class)->register(new FrontendPage(
        path: 'guides/{slug}', name: 'guides.show', title: 'Guide', view: 'fpstub::post',
        controller: FixtureLocaleAwareGuideProvider::class,
    ));
}

it('serves a plugin page under a locale prefix in that locale', function (): void {
    localeFrontendSetup();

    $html = (string) $this->get('/fr/guides/hello')->assertOk()->getContent();

    expect($html)->toContain('Guide body')
        ->and($html)->toContain('lang="fr')
        ->and(FixtureLocaleAwareGuideProvider::$locales)->toBe(['fr']);
});

it('serves the bare path in the fallback locale', function (): void {
    localeFrontendSetup();

    $this->get('/guides/hello')->assertOk()->assertSee('Guide body');

    expect(FixtureLocaleAwareGuideProvider::$locales)->toBe(['en']);
});

it('prints the result locale alternates as hreflang links', function (): void {
    localeFrontendSetup();

    $html = (string) $this->get('/guides/hello')->assertOk()->getContent();

    expect($html)->toContain('<link rel="alternate" hreflang="en" href="'.url('/guides/hello').'">')
        ->and($html)->toContain('<link rel="alternate" hreflang="fr" href="'.url('/fr/guides/hello').'">');
});

it('falls through to a redirect under the locale prefix when the provider finds nothing', function (): void {
    localeFrontendSetup();
    PageRedirect::query()->create([
        'source_path' => 'guides/gone', 'target_path' => 'guides/hello',
        'status' => 301, 'locale' => '', 'automatic' => false,
    ]);

    $this->get('/fr/guides/gone')
        ->assertStatus(301)
        ->assertHeader('Location', url('/fr/guides/hello'));
});
