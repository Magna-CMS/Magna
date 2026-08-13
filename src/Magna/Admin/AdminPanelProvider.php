<?php

declare(strict_types=1);

namespace Magna\Admin;

use Filament\Enums\ThemeMode;
use Filament\Http\Middleware\Authenticate;
use Filament\Navigation\MenuItem;
use Filament\Navigation\NavigationItem;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\View\PanelsRenderHook;
use Illuminate\Contracts\View\View;
use Illuminate\Support\HtmlString;
use Magna\Admin\Pages\AccountCentrePage;
use Magna\Admin\Pages\ApiSettingsPage;
use Magna\Admin\Pages\BackupSettingsPage;
use Magna\Admin\Pages\ContentSettingsPage;
use Magna\Admin\Pages\ContentTypeBuilder;
use Magna\Admin\Pages\Dashboard;
use Magna\Admin\Pages\GeneralSettingsPage;
use Magna\Admin\Pages\LocalizationSettingsPage;
use Magna\Admin\Pages\MailSettingsPage;
use Magna\Admin\Pages\MediaSettingsPage;
use Magna\Admin\Pages\PerformanceSettingsPage;
use Magna\Admin\Pages\PluginsPage;
use Magna\Admin\Pages\ProfilePage;
use Magna\Admin\Pages\SecuritySettingsPage;
use Magna\Admin\Pages\SettingsPage;
use Magna\Admin\Pages\StorageSettingsPage;
use Magna\Admin\Pages\SystemInfoPage;
use Magna\Admin\Pages\ThemesPage;
use Magna\Admin\Pages\UrlSettingsPage;
use Magna\Admin\Resources\ApiKeyResource;
use Magna\Admin\Resources\AuditLogResource;
use Magna\Admin\Resources\BackupResource;
use Magna\Admin\Resources\EntryResource;
use Magna\Admin\Resources\MediaResource;
use Magna\Admin\Resources\RoleResource;
use Magna\Admin\Resources\UserResource;
use Magna\Admin\Widgets\EntryCounts;
use Magna\Admin\Widgets\RecentActivity;
use Magna\Admin\Widgets\UpcomingScheduleWidget;
use Magna\Auth\Filament\Login;
use Magna\Auth\Http\Middleware\AdminCspMiddleware;
use Magna\Auth\Http\Middleware\EnsureTwoFactorEnrolled;
use Magna\Plugins\Plugin;

class AdminPanelProvider extends PanelProvider
{
    /** The one and only panel id — reference this instead of a magic string. */
    public const ID = 'magna';

    /** Route name of the single sign-in page — one source of truth for redirects. */
    public static function loginRoute(): string
    {
        return 'filament.'.self::ID.'.auth.login';
    }

    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id(self::ID)
            // Sole panel → mark it default so Filament::getDefaultPanel() /
            // getCurrentOrDefaultPanel() resolve outside a panel request
            // context (e.g. the account-centre OAuth callback, plain web
            // routes), instead of throwing NoDefaultPanelSetException.
            ->default()
            // Root domain: the admin panel lives at "/" — no "/admin" prefix.
            ->path('')
            // ── Design Guide §9.1: navy-tinted color palette ─────────────────
            ->colors([
                'primary' => Color::hex('#7c3aed'),
                'info' => Color::hex('#0ea5e9'),
                'success' => Color::hex('#10b981'),
                'warning' => Color::hex('#f59e0b'),
                'danger' => Color::hex('#f43f5e'),
                'gray' => [
                    50 => '#f8fafc',
                    100 => '#f1f5f9',
                    200 => '#e2e8f0',
                    300 => '#cbd5e1',
                    400 => '#94a3b8',
                    500 => '#64748b',
                    600 => '#475569',
                    700 => '#334155',
                    800 => '#1e293b',
                    900 => '#141b2d',
                    950 => '#0b0f19',
                ],
            ])
            ->defaultThemeMode(ThemeMode::Dark)
            ->darkMode(true)
            ->font('Inter')
            ->viteTheme('resources/css/filament/magna/theme.css')
            // ── Auth ──────────────────────────────────────────────────────────
            //   authMiddleware is REQUIRED — without it every panel page is
            //   publicly accessible. Authenticate redirects guests to ->login().
            ->authGuard('web')
            // AdminCsp actually ON the panel — the middleware existed and
            // was aliased for months while attached to nothing, so the
            // admin ran with no Content-Security-Policy at all.
            ->middleware(['web', AdminCspMiddleware::class])
            // S1-06: EnsureTwoFactorEnrolled forces any authenticated user
            // whose role requires 2FA, but who hasn't confirmed enrollment
            // yet, to the mandatory setup page before reaching any other
            // panel page — closes the gap where 2FA was configured as
            // "mandatory" per-role but never actually enforced.
            ->authMiddleware([Authenticate::class, EnsureTwoFactorEnrolled::class])
            // Custom login page that enforces Magna's two-factor challenge —
            // Filament's default login ignores it. See Magna\Auth\Filament\Login.
            ->login(Login::class)
            // ── Layout ───────────────────────────────────────────────────────
            //   SPA mode: navigation uses Livewire wire:navigate, so clicking a
            //   sidebar item swaps content client-side instead of a full page
            //   reload — no re-download of CSS/JS, no Alpine re-boot.
            //   hasPrefetching: true actually turns on hover-prefetch (the
            //   comment here previously claimed this was already on, but the
            //   flag was never passed) — Livewire starts fetching a link's
            //   target the moment the cursor hovers it, so the response is
            //   often already in flight by the time the user clicks. Safe
            //   here since this is a small trusted admin user base, not a
            //   public site where over-fetching would matter.
            ->spa(hasPrefetching: true)
            ->sidebarCollapsibleOnDesktop()
            // Filament defaults the sidebar to a wide 20rem; trim it so the
            // content area (dashboard, tables) gets that space back.
            ->sidebarWidth('14rem')
            ->maxContentWidth('full')
            // brandName intentionally omitted: the brand logo view already
            // renders the "Magna" wordmark, so setting brandName too would
            // duplicate it on the login header.
            ->brandLogo(fn (): View => view('filament.magna.brand'))
            ->brandLogoHeight('1.75rem')
            ->favicon(asset('favicon.svg'))
            // Filament's own topbar logo slot (.fi-topbar-start) is
            // desktop-only (display:none below the lg breakpoint) — the
            // brand mark otherwise only appears once the mobile sidebar
            // drawer is opened. This adds a second, mobile-only copy
            // directly in the topbar itself, positioned via CSS `order`
            // (theme.css) between the sidebar-toggle button and the search
            // bar; hidden again at lg+ so desktop still shows just the one.
            ->renderHook(
                PanelsRenderHook::TOPBAR_START,
                fn (): View => view('filament.magna.topbar-mobile-brand'),
            )
            // ── User menu ────────────────────────────────────────────────────
            //   Make the account row (the user's name + avatar at the top of the
            //   menu) link straight to the profile page, with a matching icon so
            //   it reads as a menu item alongside "Sign out".
            ->userMenuItems([
                'account' => MenuItem::make()
                    ->icon('heroicon-o-user-circle')
                    ->url(fn (): string => ProfilePage::getUrl()),
            ])
            // ── Settings submenu ─────────────────────────────────────────────
            //   The unified settings page registers its own "All Settings" item
            //   (navigationGroup 'Settings'); these children jump to each section
            //   anchor on that page.
            ->navigationItems($this->settingsNavigationItems())
            // ── Global search ─────────────────────────────────────────────────
            ->globalSearch()
            // ── Notification bell ───────────────────────────────────────────────
            //   Filament-native bell/unread-count/dropdown, default Topbar position
            //   already sits between global search and the user menu.
            ->databaseNotifications()
            // ── Resources ────────────────────────────────────────────────────
            ->resources(array_merge([
                EntryResource::class,
                MediaResource::class,
                UserResource::class,
                RoleResource::class,
                AuditLogResource::class,
                ApiKeyResource::class,
                BackupResource::class,
            ], $this->pluginSurface()->resources()))
            // ── Custom pages ─────────────────────────────────────────────────
            ->pages(array_merge([
                Dashboard::class,
                ContentTypeBuilder::class,
                // Unified settings page (one scrollable page with a section
                // sub-nav). The individual *SettingsPage classes below stay
                // registered for their routes but are hidden from the sidebar.
                SettingsPage::class,
                GeneralSettingsPage::class,
                UrlSettingsPage::class,
                LocalizationSettingsPage::class,
                ContentSettingsPage::class,
                MailSettingsPage::class,
                StorageSettingsPage::class,
                MediaSettingsPage::class,
                PerformanceSettingsPage::class,
                ApiSettingsPage::class,
                SecuritySettingsPage::class,
                SystemInfoPage::class,
                // Not folded into the unified SettingsPage — keeps its own
                // sidebar entry (last in System, below System Info). See
                // docs/backup-manager-plan.md, Decision #4.
                BackupSettingsPage::class,
                PluginsPage::class,
                ThemesPage::class,
                AccountCentrePage::class,
                ProfilePage::class,
            ], $this->pluginSurface()->pages()))
            // ── Widgets ──────────────────────────────────────────────────────
            ->widgets(array_merge([
                EntryCounts::class,
                RecentActivity::class,
                UpcomingScheduleWidget::class,
            ], $this->pluginSurface()->widgets()))
            // ── Fix: Alpine $persist uses global localStorage keys ('isOpen',
            //    'isOpenDesktop') shared across all Filament panels on the same
            //    origin. If lovelink's sidebar is collapsed, those keys are 'false'
            //    and magna-cms opens with an icon-only sidebar.
            //
            //    Solution: on the first page-load of each browser session for this
            //    panel, listen for alpine:initialized and directly call
            //    $store.sidebar.open(). This fires AFTER Alpine has read $persist
            //    values but BEFORE the user has interacted, so the reactive x-show
            //    on every sidebar label immediately updates to visible.
            ->renderHook(
                PanelsRenderHook::HEAD_END,
                fn (): HtmlString => new HtmlString(<<<'HTML'
                    <script>
                    (function () {
                        var SESSION_KEY = 'magna_sb_session_v1';
                        if (sessionStorage.getItem(SESSION_KEY)) {
                            return; // respect user's collapsed preference within session
                        }
                        sessionStorage.setItem(SESSION_KEY, '1');
                        // Pre-set localStorage so Alpine $persist reads 'true' on init
                        try {
                            localStorage.setItem('isOpen', 'true');
                            localStorage.setItem('isOpenDesktop', 'true');
                        } catch (e) {}
                        // Belt-and-suspenders: also set via the store after Alpine boots
                        document.addEventListener('alpine:initialized', function () {
                            try {
                                var store = window.Alpine && window.Alpine.store('sidebar');
                                if (store && typeof store.open === 'function') {
                                    store.open();
                                }
                            } catch (e) {}
                        });
                    })();
                    </script>
                HTML),
            )
            // Settings sub-nav: intercept clicks on the section links so they
            // smooth-scroll (Filament's SPA navigation otherwise swallows the
            // anchor), and drive a scroll-spy that moves the active highlight in
            // the sidebar as the user scrolls through sections. Lives in a
            // persistent render hook and re-runs on every Livewire navigation.
            ->renderHook(
                PanelsRenderHook::BODY_END,
                fn (): HtmlString => new HtmlString(<<<'HTML'
                    <script>
                    (function () {
                        // Filament overrides custom section ids, so we map sidebar
                        // links to sections by their heading text instead.
                        var SLUGS = {
                            general: 'General', localization: 'Localization', content: 'Content',
                            media: 'Media', email: 'Email', storage: 'Storage',
                            urls: 'URLs & Frontend', security: 'Security', performance: 'Performance'
                        };
                        var HEADINGS = {};
                        Object.keys(SLUGS).forEach(function (s) { HEADINGS[SLUGS[s].toLowerCase()] = s; });
                        var observer = null;

                        function norm(t) { return (t || '').replace(/\s+/g, ' ').trim(); }

                        function sectionForSlug(slug) {
                            var heading = SLUGS[slug];
                            if (! heading) return null;
                            var secs = document.querySelectorAll('.fi-section');
                            for (var i = 0; i < secs.length; i++) {
                                var h = secs[i].querySelector('.fi-section-header-heading');
                                if (h && norm(h.textContent) === heading) return secs[i];
                            }
                            return null;
                        }

                        function scrollParent(el) {
                            var p = el.parentElement;
                            while (p && p !== document.body) {
                                var oy = getComputedStyle(p).overflowY;
                                if ((oy === 'auto' || oy === 'scroll') && p.scrollHeight > p.clientHeight) return p;
                                p = p.parentElement;
                            }
                            return null;
                        }

                        // scrollIntoView({behavior:'smooth'}) proved unreliable
                        // here, so compute the target position and scroll the
                        // real scroller (window or an overflow container) directly.
                        function scrollToSection(el) {
                            var offset = 90; // clear the sticky topbar
                            var sp = scrollParent(el);
                            if (sp) {
                                sp.scrollTo({ top: el.offsetTop - offset, behavior: 'smooth' });
                            } else {
                                window.scrollTo({ top: el.getBoundingClientRect().top + window.scrollY - offset, behavior: 'smooth' });
                            }
                        }

                        // Livewire's wire:navigate is a document capture-phase
                        // handler that fires before ours and doesn't stop the
                        // event, so it would navigate even when we scroll. The
                        // only reliable defense is to keep the attribute off the
                        // section links entirely — and re-strip it the instant
                        // Livewire re-adds it while morphing the sidebar (which is
                        // exactly what happens after an "All Settings" click).
                        function stripNavigate() {
                            document.querySelectorAll('a[href*="#settings-"]').forEach(function (l) {
                                l.removeAttribute('wire:navigate');
                                l.removeAttribute('wire:navigate.hover');
                            });
                        }
                        stripNavigate();
                        // Observe the whole body so the guard survives Livewire
                        // replacing the sidebar element on navigation.
                        new MutationObserver(stripNavigate).observe(document.body, {
                            childList: true, subtree: true, attributes: true,
                            attributeFilter: ['wire:navigate', 'wire:navigate.hover']
                        });

                        // Delegated capture-phase click interceptor for the
                        // in-page smooth scroll (and to cancel the native hash
                        // jump). With wire:navigate stripped above, no SPA
                        // navigation fires, so there is no page reload.
                        document.addEventListener('click', function (e) {
                            var link = e.target.closest ? e.target.closest('a[href*="#settings-"]') : null;
                            if (! link) return;
                            var m = (link.getAttribute('href') || '').match(/#settings-([\w-]+)/);
                            if (! m) return;
                            var el = sectionForSlug(m[1]);
                            if (! el) return; // section not on this page — allow normal navigation
                            e.preventDefault();
                            e.stopImmediatePropagation();
                            scrollToSection(el);
                            history.replaceState(null, '', '#settings-' + m[1]);
                        }, true);

                        function setupSpy() {
                            if (observer) { observer.disconnect(); observer = null; }

                            var sections = Array.prototype.slice.call(document.querySelectorAll('.fi-section')).filter(function (s) {
                                var h = s.querySelector('.fi-section-header-heading');
                                return h && HEADINGS[norm(h.textContent).toLowerCase()];
                            });
                            if (! sections.length) return;

                            var linkBySlug = {};
                            Array.prototype.slice.call(document.querySelectorAll('a[href*="#settings-"]')).forEach(function (link) {
                                var m = (link.getAttribute('href') || '').match(/#settings-([\w-]+)/);
                                if (m) linkBySlug[m[1]] = link;
                            });

                            observer = new IntersectionObserver(function (entries) {
                                entries.forEach(function (entry) {
                                    if (! entry.isIntersecting) return;
                                    var h = entry.target.querySelector('.fi-section-header-heading');
                                    var slug = h ? HEADINGS[norm(h.textContent).toLowerCase()] : null;
                                    if (! slug) return;
                                    Object.keys(linkBySlug).forEach(function (k) { linkBySlug[k].classList.remove('magna-nav-active'); });
                                    if (linkBySlug[slug]) linkBySlug[slug].classList.add('magna-nav-active');
                                });
                            }, { rootMargin: '-15% 0px -75% 0px', threshold: 0 });
                            sections.forEach(function (s) { observer.observe(s); });

                            if (window.location.hash.indexOf('#settings-') === 0) {
                                var el = sectionForSlug(window.location.hash.replace('#settings-', ''));
                                if (el) requestAnimationFrame(function () { scrollToSection(el); });
                            }
                        }

                        document.addEventListener('DOMContentLoaded', setupSpy);
                        document.addEventListener('livewire:navigated', setupSpy);
                    })();
                    </script>
                HTML),
            )
            // Copyright footer shown at the bottom of every admin page.
            ->renderHook(
                PanelsRenderHook::FOOTER,
                fn (): HtmlString => new HtmlString(
                    '<div class="mx-auto flex w-full max-w-7xl flex-wrap items-center justify-between gap-2 px-4 py-3 text-xs text-gray-400 dark:text-gray-500">'
                    .'<span class="inline-flex items-center gap-1.5">'
                    .'<span>&copy; '.date('Y').' Magna CMS</span>'
                    .'<a href="https://github.com/Magna-CMS" target="_blank" rel="noopener noreferrer" aria-label="Magna CMS on GitHub" class="inline-flex items-center text-gray-400 transition hover:text-gray-700 dark:text-gray-500 dark:hover:text-gray-200">'
                    .'<svg viewBox="0 0 24 24" fill="currentColor" class="h-4 w-4" aria-hidden="true"><path d="M12 .5C5.7.5.5 5.7.5 12c0 5.1 3.3 9.4 7.9 10.9.6.1.8-.3.8-.6 0-.3 0-1 0-2-3.2.7-3.9-1.5-3.9-1.5-.5-1.3-1.3-1.7-1.3-1.7-1.1-.7.1-.7.1-.7 1.2.1 1.8 1.2 1.8 1.2 1 1.8 2.7 1.3 3.4 1 .1-.8.4-1.3.7-1.6-2.6-.3-5.3-1.3-5.3-5.8 0-1.3.5-2.3 1.2-3.1-.1-.3-.5-1.5.1-3.1 0 0 1-.3 3.3 1.2a11.4 11.4 0 0 1 6 0C17 4.6 18 4.9 18 4.9c.6 1.6.2 2.8.1 3.1.8.8 1.2 1.8 1.2 3.1 0 4.5-2.7 5.5-5.3 5.8.4.4.8 1.1.8 2.3 0 1.6 0 2.9 0 3.3 0 .3.2.7.8.6 4.6-1.5 7.9-5.8 7.9-10.9C23.5 5.7 18.3.5 12 .5Z"/></svg>'
                    .'</a>'
                    .'</span>'
                    .'<span>Developed by <a href="https://jrstudios.dev" target="_blank" rel="noopener noreferrer" class="font-medium text-primary-600 hover:underline dark:text-primary-400">JR Studios</a></span>'
                    .'</div>',
                ),
            );
    }

    /** Plugin-contributed resources, pages and widgets — see PluginPanelSurface. */
    private function pluginSurface(): PluginPanelSurface
    {
        return new PluginPanelSurface($this->app);
    }

    /**
     * Child items under the "Settings" sidebar group — one per section on the
     * unified settings page. Each jumps to its anchor. isActiveWhen is false so
     * they don't all highlight (they share the /settings path).
     *
     * @return array<int, NavigationItem>
     */
    private function settingsNavigationItems(): array
    {
        $sections = [
            ['general', 'General', 'heroicon-o-cog-6-tooth'],
            ['localization', 'Localization', 'heroicon-o-language'],
            ['content', 'Content', 'heroicon-o-document-text'],
            ['media', 'Media', 'heroicon-o-photo'],
            ['email', 'Email', 'heroicon-o-envelope'],
            ['storage', 'Storage', 'heroicon-o-circle-stack'],
            ['urls', 'URLs & Frontend', 'heroicon-o-link'],
            ['security', 'Security', 'heroicon-o-shield-check'],
            ['performance', 'Performance', 'heroicon-o-bolt'],
        ];

        $items = [];
        foreach ($sections as $i => [$id, $label, $icon]) {
            $items[] = NavigationItem::make($label)
                ->group('Settings')
                ->icon($icon)
                ->sort($i + 1)
                ->url('/settings#settings-'.$id)
                ->isActiveWhen(fn (): bool => false);
        }

        return $items;
    }
}
