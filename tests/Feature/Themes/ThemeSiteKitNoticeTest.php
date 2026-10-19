<?php

declare(strict_types=1);

use Magna\Themes\ThemeSiteKit;
use Symfony\Component\Filesystem\Filesystem;
use Tests\TestCase;

uses(TestCase::class);

/**
 * A theme update that ships site content says so.
 *
 * Updating a theme swaps its directory and stops. That is right for a theme
 * whose release is CSS, and silently inert for one whose release is a menu or
 * a page — those live in a `starter/*.json` site kit, and applying one is a
 * separate step.
 *
 * The failure mode is the bad kind. A site was updated, the operator reported
 * the menus unchanged, and every signal said success: the files really were
 * new, the version really had changed, the site reported itself up to date,
 * and the page looked exactly as before. Nothing errored and nothing logged. A
 * theme author never meets it, because they apply the kit by hand while
 * building it.
 *
 * Core does not apply the kit, and that is deliberate twice over: the applier
 * is a command in the page-builder plugin and core does not branch on which
 * plugin is installed, and a kit upserts by node id — so re-applying one over
 * a page an editor has rewritten would overwrite their work. Hence a notice
 * rather than an action.
 */
beforeEach(function (): void {
    $this->themeDir = storage_path('framework/testing/theme-kit-'.uniqid());
    (new Filesystem)->mkdir($this->themeDir);
});

afterEach(function (): void {
    (new Filesystem)->remove($this->themeDir);
});

it('says nothing when the theme ships no site content', function (): void {
    // The common case, and it must stay quiet: an update of a CSS-only theme
    // is complete when its files land, and a notice telling the operator to
    // run a command that would change nothing is worse than silence.
    file_put_contents($this->themeDir.'/theme.json', '{}');

    expect(ThemeSiteKit::bundles($this->themeDir))->toBe([])
        ->and(ThemeSiteKit::updateNotice($this->themeDir, 'acme/aurora'))->toBe('');
});

it('names the bundles and the command that applies them', function (): void {
    (new Filesystem)->mkdir($this->themeDir.'/starter');
    file_put_contents($this->themeDir.'/starter/site.json', '{}');

    $notice = ThemeSiteKit::updateNotice($this->themeDir, 'acme/aurora');

    expect($notice)->toContain('starter/site.json')
        ->toContain('magna:site:sync themes/acme/aurora/starter/site.json')
        // The warning is half the point: the operator deciding whether to
        // apply is the only person who knows what the editors have done since.
        ->toContain('upserts');
});

it('lists every bundle, in a stable order', function (): void {
    (new Filesystem)->mkdir($this->themeDir.'/starter');

    foreach (['pages.json', 'menus.json', 'patterns.json'] as $bundle) {
        file_put_contents($this->themeDir.'/starter/'.$bundle, '{}');
    }

    // An admin comparing two updates must not see the same set in a different
    // order, which is what the filesystem's own order would give.
    expect(ThemeSiteKit::bundles($this->themeDir))
        ->toBe(['starter/menus.json', 'starter/pages.json', 'starter/patterns.json']);
});

it('ignores a starter directory holding nothing it can apply', function (): void {
    (new Filesystem)->mkdir($this->themeDir.'/starter');
    file_put_contents($this->themeDir.'/starter/README.md', 'how this kit was built');

    // A notice naming a file the sync command cannot read would send the
    // operator to a failure.
    expect(ThemeSiteKit::updateNotice($this->themeDir, 'acme/aurora'))->toBe('');
});

it('is returned by a theme update, as a second line the panel shows as the body', function (): void {
    // The seam the panel depends on: LicenseInstaller appends the notice after
    // a newline, and ThemesPage splits title from body on it. Asserted on the
    // shape rather than through a licence grant, which needs a live hub.
    $installer = (string) file_get_contents(base_path('src/Magna/Licensing/LicenseInstaller.php'));
    $page = (string) file_get_contents(base_path('src/Magna/Admin/Pages/ThemesPage.php'));

    expect($installer)->toContain('ThemeSiteKit::updateNotice($targetDir, $manifest->name)')
        ->toContain('"\n".$notice')
        ->and($page)->toContain('explode("\n", $message, 2)')
        // PHP_EOL here would be "\r\n" on Windows and match nothing, printing
        // the whole notice as a toast title on the machines this is built on.
        ->and($page)->not->toContain('explode(PHP_EOL, $message');
});
