<?php

declare(strict_types=1);

namespace Magna\Admin;

use Filament\Enums\ThemeMode;
use Filament\FontProviders\LocalFontProvider;
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
use Magna\Admin\Pages\BackupSettingsPage;
use Magna\Admin\Pages\ContentTypeBuilder;
use Magna\Admin\Pages\Dashboard;
use Magna\Admin\Pages\MailSettingsPage;
use Magna\Admin\Pages\PerformanceSettingsPage;
use Magna\Admin\Pages\PluginsPage;
use Magna\Admin\Pages\ProfilePage;
use Magna\Admin\Pages\SettingsPage;
use Magna\Admin\Pages\SystemInfoPage;
use Magna\Admin\Pages\ThemesPage;
use Magna\Admin\Resources\ApiKeyResource;
use Magna\Admin\Resources\AuditLogResource;
use Magna\Admin\Resources\BackupResource;
use Magna\Admin\Resources\EntryResource;
use Magna\Admin\Resources\MediaResource;
use Magna\Admin\Resources\RoleResource;
use Magna\Admin\Resources\UserResource;
use Magna\Admin\Support\InitialsAvatarProvider;
use Magna\Admin\Widgets\EntryCounts;
use Magna\Admin\Widgets\RecentActivity;
use Magna\Admin\Widgets\UpcomingScheduleWidget;
use Magna\Auth\Filament\Login;
use Magna\Auth\Http\Middleware\AdminCspMiddleware;
use Magna\Auth\Http\Middleware\EnsureTwoFactorEnrolled;
use Magna\Contracts\LoginCheck;
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
            // Filament's stock provider points the no-photo avatar at
            // ui-avatars.com, which the panel CSP blocks — it rendered as a
            // broken image. InitialsAvatarProvider draws the same initials
            // inline, with no third-party request.
            ->defaultAvatarProvider(InitialsAvatarProvider::class)
            ->defaultThemeMode(ThemeMode::Dark)
            ->darkMode(true)
            // Local provider: Filament's stock Bunny CDN stylesheet is blocked
            // by the panel CSP (style-src 'self'); the published local Inter
            // assets under public/fonts serve the same family without a
            // third-party request.
            ->font(
                'Inter',
                url: asset('fonts/filament/filament/inter/index.css'),
                provider: LocalFontProvider::class,
            )
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
            ->brandLogo(fn (): View => view('magna::filament.brand'))
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
                fn (): View => view('magna::filament.topbar-mobile-brand'),
            )
            // Render any registered login-check widgets (captcha, …) beneath the
            // sign-in form. Each check names a Blade view; a check whose provider
            // is disabled renders nothing, so this is inert until a plugin such
            // as Magna Defence turns captcha on.
            ->renderHook(
                PanelsRenderHook::AUTH_LOGIN_FORM_AFTER,
                fn (): HtmlString => $this->renderLoginCheckWidgets(),
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
                // sub-nav). It absorbed the eight per-domain settings pages
                // that used to be registered here hidden-from-navigation —
                // each of which kept a second, drifting copy of the same
                // save() hydration. Mail and Performance remain as real
                // pages: Mail because MailSettingsPage::persist() is the
                // single writer the unified page delegates to, Performance
                // for its Octane-specific tooling.
                SettingsPage::class,
                MailSettingsPage::class,
                PerformanceSettingsPage::class,
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
            // Cross-panel sidebar-state repair — see the view's own comment.
            ->renderHook(
                PanelsRenderHook::HEAD_END,
                fn (): View => view('magna::filament.sidebar-restore'),
            )
            // Settings sub-nav smooth-scroll + scroll-spy — see the view.
            ->renderHook(
                PanelsRenderHook::BODY_END,
                fn (): View => view('magna::filament.settings-subnav'),
            )
            // Copyright footer shown at the bottom of every admin page.
            ->renderHook(
                PanelsRenderHook::FOOTER,
                fn (): View => view('magna::filament.footer'),
            );
    }

    /** Plugin-contributed resources, pages and widgets — see PluginPanelSurface. */
    private function pluginSurface(): PluginPanelSurface
    {
        return new PluginPanelSurface($this->app);
    }

    /**
     * Concatenated markup of every registered login check's widget for the
     * admin-login surface. Each check names a Blade view; the view self-gates
     * (a disabled provider renders nothing), so this is empty until a plugin
     * turns captcha on. Never throws — a broken check view must not take the
     * login page down.
     */
    private function renderLoginCheckWidgets(): HtmlString
    {
        $checks = $this->app->bound('magna.auth.login_checks')
            ? $this->app->make('magna.auth.login_checks')
            : [];

        if (! is_array($checks)) {
            return new HtmlString('');
        }

        $html = '';

        foreach ($checks as $check) {
            $instance = is_string($check) ? $this->app->make($check) : $check;

            if (! $instance instanceof LoginCheck || $instance->surface() !== Login::SURFACE) {
                continue;
            }

            // The name comes from a plugin, so it is checked against the view
            // finder rather than trusted as a view-string.
            $view = $instance->view();
            if ($view === null || ! view()->exists($view)) {
                continue;
            }

            try {
                $html .= view()->make($view)->render();
            } catch (\Throwable) {
                // A plugin's broken widget view must not break sign-in.
            }
        }

        return new HtmlString($html);
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
