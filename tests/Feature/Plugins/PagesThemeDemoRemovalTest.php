<?php

declare(strict_types=1);

/**
 * Taking a theme's demo content back out, with the real page builder, Blog
 * and Forms: what is untouched goes, and nothing an admin changed, made,
 * still links to, or collected goes with it.
 *
 * The theme's bundle here is an export of the site itself, so straight after
 * setup every row is exactly as "shipped" — the state right after a demo
 * import — and each test then changes the site the way an owner would.
 */

use Filament\Facades\Filament;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Magna\Admin\Pages\ThemesPage;
use Magna\Auth\Role;
use Magna\Content\Entry;
use Magna\Content\EntryManager;
use Magna\Forms\Models\Form;
use Magna\Forms\Models\Submission;
use Magna\Pages\Builder\Pattern;
use Magna\Pages\Menus\Menu;
use Magna\Pages\Menus\MenuManager;
use Magna\Pages\PagesSettings;
use Magna\Pages\SiteKit\SiteKit;
use Magna\Plugins\PluginManager;
use Magna\Settings\SettingsRepository;
use Magna\Testing\PluginTestCase;
use Magna\Themes\ThemeContent;
use Magna\Themes\ThemeManager;
use Magna\Themes\ThemeSettings;
use Magna\Users\User;
use MagnaCms\Blog\Models\Post;
use Symfony\Component\Filesystem\Filesystem;

uses(PluginTestCase::class);

beforeEach(function (): void {
    skipWithoutDevPlugin('magna-cms/pages');
    skipWithoutDevPlugin('magna-cms/blog');
    skipWithoutDevPlugin('magna-cms/forms');
    app(PluginManager::class)->enable('magna-cms/pages');
    $this->enablePlugin('magna-cms/blog');
    $this->enablePlugin('magna-cms/forms');

    Storage::fake('public');
    Filament::setCurrentPanel(Filament::getPanel('magna'));

    $this->themesRoot = sys_get_temp_dir().'/magna-demo-removal-'.bin2hex(random_bytes(4));
    config(['magna.themes_path' => $this->themesRoot]);

    $role = Role::factory()->create(['is_super_admin' => true]);
    $this->owner = User::factory()->create(['two_factor_confirmed_at' => now()]);
    $this->owner->assignRole($role);
    $this->actingAs($this->owner);
});

afterEach(function (): void {
    // beforeEach skips before it sets themesRoot when a dev plugin is absent
    // (as in CI); a skipped test still runs this hook.
    if (isset($this->themesRoot)) {
        (new Filesystem)->remove($this->themesRoot);
    }
});

function removalTextBlock(string $id, string $html): array
{
    return [['id' => $id, 'type' => 'section', 'settings' => [], 'columns' => [[
        'id' => $id.'-c', 'span' => 12, 'settings' => [],
        'blocks' => [['id' => $id.'-b', 'block' => 'text', 'settings' => [], 'data' => ['body' => $html]]],
    ]]]];
}

/** Write a theme whose demo bundle is the given kit. */
function removalTheme(string $root, string $name, array $kit): void
{
    $dir = $root.'/'.$name;
    (new Filesystem)->mkdir($dir.'/starter');
    file_put_contents($dir.'/theme.json', json_encode([
        'name' => $name, 'displayName' => ucfirst(basename($name)).' Theme', 'version' => '1.0.0', 'compat' => ['magna' => '^1.0'],
    ]));
    file_put_contents($dir.'/starter/demo.json', json_encode($kit));
}

/**
 * The demo, as the site has it right after importing it: four pages, a
 * header part (the site's default header), a menu, a pattern, two posts
 * and a form. Returns the kit, which is the site's own export of all that.
 */
function removalDemoOnSite(User $owner, string $root): array
{
    $themeKit = json_decode((string) file_get_contents(dirname(__DIR__, 2).'/Fixtures/demo-themes/testv/demo/starter/demo.json'), true);
    app(SiteKit::class)->applyMissing($themeKit, (string) $owner->id);

    $entries = app(EntryManager::class);
    $entries->create('page', ['title' => 'Services', 'slug' => 'services', 'blocks_data' => removalTextBlock('sv', '<p>Our services</p>')], (string) $owner->id);
    $entries->create('page', ['title' => 'Team', 'slug' => 'team', 'blocks_data' => removalTextBlock('tm', '<p>The team</p>')], (string) $owner->id);
    $header = $entries->create('pages_template', ['title' => 'Demo header', 'slug' => 'demo-header', 'kind' => 'part', 'role' => 'header', 'blocks_data' => []], (string) $owner->id);

    $settings = PagesSettings::get();
    $settings->default_header_id = (string) $header->getKey();
    app(SettingsRepository::class)->persist($settings);

    Pattern::query()->create(['name' => 'Demo hero', 'kind' => Pattern::KIND_SECTION, 'document' => removalTextBlock('ph', '<p>Hero</p>')[0]]);
    Form::query()->create([
        'handle' => 'demo-contact', 'name' => 'Contact',
        'fields' => [['handle' => 'email', 'label' => 'Email', 'type' => 'email', 'required' => true]],
    ]);

    $kit = app(SiteKit::class)->build();
    removalTheme($root, 'testv/removal', $kit);

    return $kit;
}

it('removes every untouched row, and the settings that pointed at them', function (): void {
    removalDemoOnSite($this->owner, $this->themesRoot);
    $content = app(ThemeManager::class)->content();

    expect($content->onSite('testv/removal'))->toBeTrue()
        ->and(PagesSettings::get()->home_page_id)->not->toBeNull();

    $totals = $content->removeDemo('testv/removal', (string) $this->owner->id);

    expect($totals)->toMatchArray(['pages' => 4, 'templates' => 1, 'menus' => 1, 'patterns' => 1, 'blog:posts' => 2, 'forms:forms' => 1, 'kept' => 0])
        ->and(Entry::type('page')->count())->toBe(0)
        ->and(Entry::type('pages_template')->count())->toBe(0)
        ->and(Menu::query()->count())->toBe(0)
        ->and(Pattern::query()->count())->toBe(0)
        ->and(Form::query()->count())->toBe(0)
        ->and(Post::withTrashed()->count())->toBe(0)
        ->and(Storage::disk('public')->allFiles())->toBe([])
        ->and(PagesSettings::get()->home_page_id)->toBeNull()
        ->and(PagesSettings::get()->default_header_id)->toBeNull()
        ->and($content->onSite('testv/removal'))->toBeFalse()
        ->and(ThemeContent::removalSummary($totals))->toBe('Removed 4 pages, 1 header/footer part, 1 menu, 1 pattern, 2 posts, 1 form.');
});

it('keeps what the owner changed, made, links to, built under, or collected', function (): void {
    removalDemoOnSite($this->owner, $this->themesRoot);
    $entries = app(EntryManager::class);
    $about = Entry::type('page')->where('slug', 'about')->firstOrFail();
    $services = Entry::type('page')->where('slug', 'services')->firstOrFail();
    $team = Entry::type('page')->where('slug', 'team')->firstOrFail();

    $entries->update($about, ['title' => 'About us, really']);
    $pricing = $entries->create('page', ['title' => 'Pricing', 'slug' => 'pricing', 'blocks_data' => removalTextBlock('pr', '<p>See <a href="/demo-home#plans">plans</a></p>')], (string) $this->owner->id);
    $footer = app(MenuManager::class)->create('footer', 'Footer');
    app(MenuManager::class)->syncItems($footer, [['label' => 'Services', 'type' => 'page', 'page_id' => (string) $services->getKey(), 'children' => []]]);
    $entries->create('page', ['title' => 'Ada', 'slug' => 'ada', 'parent_id' => (string) $team->getKey(), 'blocks_data' => []], (string) $this->owner->id);
    Submission::query()->create(['form_id' => Form::query()->where('handle', 'demo-contact')->value('id'), 'data' => ['email' => 'ada@example.com']]);

    $preview = app(ThemeManager::class)->content()->removalPreview('testv/removal');
    $kept = collect($preview['keep'])->mapWithKeys(fn (array $r): array => [$r['label'] => $r['reason']])->all();

    expect($kept)->toBe([
        'page about' => 'you changed it',
        'form demo-contact' => 'has submissions',
        'page demo-home' => 'linked from pricing',
        'page services' => 'linked from the Footer menu',
        'page team' => 'has pages under it',
    ]);

    $totals = app(ThemeManager::class)->content()->removeDemo('testv/removal', (string) $this->owner->id);

    expect($totals['kept'])->toBe(5)
        ->and(Entry::type('page')->pluck('slug')->sort()->values()->all())->toBe(['about', 'ada', 'demo-home', 'pricing', 'services', 'team'])
        ->and(Entry::type('page')->whereKey($pricing->getKey())->exists())->toBeTrue()
        ->and(Menu::query()->pluck('handle')->all())->toBe(['footer'])
        ->and(Form::query()->where('handle', 'demo-contact')->exists())->toBeTrue()
        ->and(Post::query()->count())->toBe(0);
});

// The first run against a real demo (Lekha's) kept 14 of its pages: one
// edited FAQ linked to the rest, and they linked onward. Links between the
// demo's own pages, edited or not, must not hold the demo in place.
it('does not let the demo hold itself in place through its own links', function (): void {
    $entries = app(EntryManager::class);
    $faq = $entries->create('page', ['title' => 'FAQ', 'slug' => 'faq', 'blocks_data' => removalTextBlock('fq', '<p><a href="/services">Services</a> and <a href="/team">team</a></p>')], (string) $this->owner->id);
    removalDemoOnSite($this->owner, $this->themesRoot);
    $entries->update(Entry::type('page')->whereKey($faq->getKey())->firstOrFail(), ['title' => 'FAQ, edited']);

    $kept = collect(app(ThemeManager::class)->content()->removalPreview('testv/removal')['keep'])->pluck('reason', 'label')->all();

    expect($kept)->toBe(['page faq' => 'you changed it']);
});

it('brings everything back on a fresh import of the same demo', function (): void {
    removalDemoOnSite($this->owner, $this->themesRoot);
    $content = app(ThemeManager::class)->content();

    $content->removeDemo('testv/removal', (string) $this->owner->id);
    $content->import('testv/removal', (string) $this->owner->id);

    expect(Entry::type('page')->count())->toBe(4)
        ->and(Post::query()->count())->toBe(2)
        ->and(Menu::query()->where('handle', 'primary')->exists())->toBeTrue()
        ->and($content->onSite('testv/removal'))->toBeTrue();
});

it('lets a switched-to theme bring its own pages where the old demo held the slugs', function (): void {
    $kit = removalDemoOnSite($this->owner, $this->themesRoot);
    app(ThemeManager::class)->activate('testv/removal');

    $next = $kit;
    foreach ($next['pages'] as $i => $row) {
        $next['pages'][$i]['title'] = 'Next: '.$row['title'];
    }
    removalTheme($this->themesRoot, 'testv/next', $next);

    Livewire::test(ThemesPage::class)
        ->call('askActivate', 'testv/next')
        ->assertSet('demoMode', 'switch')
        ->set('demoRemove', true)
        ->set('demoImport', true)
        ->call('confirmDemoDialog')
        ->assertNotified('Next Theme is now the active theme.');

    expect(ThemeSettings::get()->active)->toBe('testv/next')
        ->and(Entry::type('page')->where('slug', 'about')->value('title'))->toBe('Next: About')
        ->and(PagesSettings::get()->home_page_id)->toBe((string) Entry::type('page')->where('slug', 'demo-home')->value('id'));
});
