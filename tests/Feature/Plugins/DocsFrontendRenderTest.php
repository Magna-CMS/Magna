<?php

declare(strict_types=1);

use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use Magna\Auth\Role;
use Magna\Docs\Filament\Resources\DocPageResource\Pages\EditDocPage;
use Magna\Docs\Http\Controllers\DeliveryController;
use Magna\Docs\Models\DocPage;
use Magna\Docs\Settings\DocsSettings;
use Magna\Media\Media;
use Magna\Media\MediaIngestor;
use Magna\Testing\PluginTestCase;
use Magna\Users\User;

uses(PluginTestCase::class);

beforeEach(function (): void {
    skipWithoutDevPlugin('magna-cms/docs');
    $this->enablePlugin('magna-cms/docs');
    // Plugin routes are registered after app boot in tests, so the URL
    // generator's name map needs refreshing (not needed in production).
    app('router')->getRoutes()->refreshNameLookups();
});

it('renders the docs home as the first article with featured image and footer', function (): void {
    DocPage::create([
        'title' => 'Getting Started',
        'slug' => 'getting-started',
        'content' => "# Hi\n\nWelcome to the docs.",
        'status' => 'published',
        'order' => 0,
        'featured_image' => 'docs-featured/hero.jpg',
        'show_featured_image' => true,
    ]);

    $settings = DocsSettings::get();
    $settings->copyright_text = '(c) 2026 Test Co';
    $settings->save();

    $this->get('/docs')
        ->assertOk()
        ->assertSee('Getting Started')
        ->assertSee('docs-featured/hero.jpg')          // featured image rendered
        ->assertSee('Made with Magna Docs')            // footer right
        // The repo LINK is asserted, not the org that owns it: the plugin
        // moved from a personal account to the Magna-CMS org and pinning the
        // full URL failed the core suite for a rename that changed nothing a
        // reader sees.
        ->assertSee('/Magna-Docs')                     // repo link
        ->assertSee('(c) 2026 Test Co');               // footer left (copyright)

    $this->get('/docs/getting-started')
        ->assertOk()
        ->assertSee('Getting Started');
});

it('builds the on-this-page list from markdown headings without a trailing hash in the text', function (): void {
    DocPage::create([
        'title' => 'Guide Page',
        'slug' => 'guide-x',
        'content' => "## Getting Started\n\nBody text.\n\n## Key Features\n\nMore.",
        'status' => 'published',
        'order' => 0,
    ]);

    $res = $this->get('/docs/guide-x')->assertOk();

    // TOC populated (not the empty-state), with anchor ids that match the fragment.
    $res->assertSee('Getting Started')
        ->assertSee('Key Features')
        ->assertSee('href="#getting-started"', false)
        ->assertDontSee('No sections on this page');

    // The permalink text node must not be "Getting Started#": the toc <a> text is clean.
    $res->assertSee('class="docs-toc-link ">Getting Started</a>', false);
});

it('renders a translation via ?lang and exposes the language switcher', function (): void {
    $page = DocPage::create([
        'title' => 'Hello World',
        'slug' => 'hello-tr',
        'content' => 'English body here.',
        'status' => 'published',
        'order' => 0,
    ]);
    $page->translations()->create([
        'locale' => 'ml',
        'title' => 'ഹലോ വേൾഡ്',
        'content' => 'മലയാളം ഉള്ളടക്കം.',
    ]);

    // Default (English): switcher is present with the Malayalam option, English content shown.
    $this->get('/docs/hello-tr')
        ->assertOk()
        ->assertSee('Hello World')
        ->assertSee('id="langWrap"', false)
        ->assertSee('Malayalam')
        ->assertSee('?lang=ml', false)
        ->assertDontSee('മലയാളം ഉള്ളടക്കം');

    // Malayalam version renders the translated title + content.
    $this->get('/docs/hello-tr?lang=ml')
        ->assertOk()
        ->assertSee('ഹലോ വേൾഡ്')
        ->assertSee('മലയാളം ഉള്ളടക്കം');
});

it('does not show the language switcher for a page with no translations', function (): void {
    DocPage::create([
        'title' => 'Solo', 'slug' => 'solo', 'content' => 'x', 'status' => 'published', 'order' => 0,
    ]);

    // Switcher wrapper is force-hidden and there are no translation links.
    $this->get('/docs/solo')
        ->assertOk()
        ->assertSee('display:none', false)
        ->assertDontSee('?lang=', false);
});

it('adds, saves and removes a page translation from the editor', function (): void {
    Filament::setCurrentPanel(Filament::getPanel('magna'));
    Route::get('/__t', fn () => '')->name('filament.magna.resources.doc-pages.index');
    Route::get('/__t/{record}/edit', fn () => '')->name('filament.magna.resources.doc-pages.edit');
    $role = Role::factory()->create();
    $role->grant('docs.pages.view', 'docs.pages.manage');
    $user = User::factory()->create(['two_factor_confirmed_at' => now()]);
    $user->assignRole($role);
    $this->actingAs($user);

    $page = DocPage::create([
        'title' => 'Base', 'slug' => 'base-x', 'content' => 'en body', 'status' => 'published', 'order' => 0,
    ]);

    $component = Livewire::test(EditDocPage::class, ['record' => $page->getKey()])
        ->assertSet('editingLocale', 'en')
        ->call('addLanguage', 'ml')
        ->assertSet('editingLocale', 'ml')
        ->set('data.title', 'ML Title')
        ->set('data.content', 'ML body')
        ->call('saveDraft');

    $translation = $page->translations()->where('locale', 'ml')->first();
    expect($translation)->not->toBeNull()
        ->and($translation->title)->toBe('ML Title')
        ->and($translation->content)->toBe('ML body')
        ->and($page->fresh()->content)->toBe('en body'); // English untouched

    // Remove it again.
    $component->call('deleteLanguage')->assertSet('editingLocale', 'en');
    expect($page->translations()->where('locale', 'ml')->exists())->toBeFalse();
});

it('registers an ingested upload into the central media library', function (): void {
    if (! function_exists('imagecreatetruecolor')) {
        $this->markTestSkipped('GD not available.');
    }
    $tmp = tempnam(sys_get_temp_dir(), 'img').'.png';
    $image = imagecreatetruecolor(4, 4);
    imagepng($image, $tmp);
    imagedestroy($image);

    $before = Media::count();
    $media = app(MediaIngestor::class)->ingest($tmp, 'docs-upload.png', 'public');
    @unlink($tmp);

    expect(Media::count())->toBe($before + 1)
        ->and($media->path)->toStartWith('media/');
});

it('strips raw HTML and unsafe links from rendered markdown (no stored XSS)', function (): void {
    DocPage::create([
        'title' => 'XSS Attempt',
        'slug' => 'xss-attempt',
        'content' => "Hello\n\n<script>alert('xss')</script>\n\n<img src=x onerror=\"alert(1)\">\n\n[click me](javascript:alert(2))",
        'status' => 'published',
        'order' => 0,
    ]);

    $res = $this->get('/docs/xss-attempt')->assertOk();

    // The raw <script>/<img onerror> markup must not survive into the page.
    $res->assertDontSee('<script>alert', false)
        ->assertDontSee('onerror=', false)
        // The javascript: link must not be emitted as an href.
        ->assertDontSee('href="javascript:', false);

    // Legitimate text still renders.
    $res->assertSee('Hello');
});

it('serves only published pages from the delivery API (status is authoritative)', function (): void {
    DocPage::create([
        'title' => 'Public Page', 'slug' => 'api-public', 'content' => 'body',
        'status' => 'published', 'order' => 0,
    ]);
    // Draft with a stale is_published=true must still be treated as unpublished.
    DocPage::create([
        'title' => 'Secret Draft', 'slug' => 'api-draft', 'content' => 'secret',
        'status' => 'draft', 'is_published' => true, 'order' => 1,
    ]);

    $controller = new DeliveryController;

    // Published page is returned...
    expect($controller->show('api-public')->getData(true)['data']['slug'])->toBe('api-public');

    // ...the draft is not exposed, even though is_published is true.
    expect(fn () => $controller->show('api-draft'))
        ->toThrow(ModelNotFoundException::class);

    $slugs = array_column($controller->index()->getData(true)['data'], 'slug');
    expect($slugs)->toContain('api-public')->not->toContain('api-draft');
});

it('accepts page feedback only via POST for a real published page', function (): void {
    DocPage::create([
        'title' => 'FB', 'slug' => 'fb-page', 'content' => 'x', 'status' => 'published', 'order' => 0,
    ]);

    // Valid vote on a published page is accepted.
    $this->postJson(route('docs.web.feedback'), ['slug' => 'fb-page', 'vote' => 'yes'])
        ->assertOk()
        ->assertJson(['ok' => true]);

    // Unknown / non-published slug is rejected (no arbitrary cache keys).
    $this->postJson(route('docs.web.feedback'), ['slug' => 'does-not-exist', 'vote' => 'yes'])
        ->assertStatus(422)
        ->assertJson(['ok' => false]);

    // Invalid vote value is rejected.
    $this->postJson(route('docs.web.feedback'), ['slug' => 'fb-page', 'vote' => 'maybe'])
        ->assertStatus(422);
});

it('serves the plugin pre-built front-end assets and rejects anything unlisted', function (): void {
    $this->get(route('docs.web.asset', 'docs.css'))->assertOk();
    $this->get(route('docs.web.asset', 'prism.js'))->assertOk();
    $this->get(route('docs.web.asset', 'prism-tomorrow.css'))->assertOk();

    // Only whitelisted filenames are served — no arbitrary file reads.
    $this->get(route('docs.web.asset', 'composer.json'))->assertNotFound();
});

it('does not hang building a breadcrumb for a page with a cyclic parent', function (): void {
    $a = DocPage::create(['title' => 'A', 'slug' => 'cyc-a', 'content' => 'a', 'status' => 'published', 'order' => 0]);
    $b = DocPage::create(['title' => 'B', 'slug' => 'cyc-b', 'content' => 'b', 'status' => 'published', 'order' => 1, 'parent_id' => $a->id]);
    // Close the loop: A's parent becomes B (A→B→A).
    $a->update(['parent_id' => $b->id]);

    $trail = $a->fresh()->breadcrumb();

    // Terminates, and never repeats a page.
    $slugs = array_column($trail, 'slug');
    expect($slugs)->toBe(array_unique($slugs));
});

it('hides the featured image when the page toggle is off', function (): void {
    DocPage::create([
        'title' => 'No Image Page',
        'slug' => 'no-image',
        'content' => '# Body',
        'status' => 'published',
        'order' => 0,
        'featured_image' => 'docs-featured/secret.jpg',
        'show_featured_image' => false,
    ]);

    $this->get('/docs/no-image')
        ->assertOk()
        ->assertDontSee('docs-featured/secret.jpg');
});
