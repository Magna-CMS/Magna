<?php

declare(strict_types=1);

namespace Magna\Admin\Pages;

use Filament\Actions\Action;
use Filament\Forms\ComponentContainer;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions as SchemaActions;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\HtmlString;
use Laravel\Octane\OctaneServiceProvider;
use Magna\Settings\PerformanceSettings;

/**
 * @property ComponentContainer $form
 */
class PerformanceSettingsPage extends Page implements HasForms
{
    use InteractsWithForms;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-bolt';

    protected static string|\UnitEnum|null $navigationGroup = 'System';

    // Hidden from the sidebar: consolidated into the unified SettingsPage.
    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $navigationLabel = 'Performance Settings';

    protected static ?string $title = 'Performance Settings';

    protected static ?int $navigationSort = 35;

    protected string $view = 'magna::admin.performance-settings';

    public ?array $data = [];

    public static function canAccess(): bool
    {
        return auth()->user()?->can('settings.manage') ?? false;
    }

    public function mount(): void
    {
        $settings = PerformanceSettings::get();

        $this->form->fill([
            'cache_driver' => $settings->cache_driver,
            'queue_connection' => $settings->queue_connection,
            'redis_host' => $settings->redis_host,
            'redis_port' => $settings->redis_port,
            // redis_password is #[Secret] — never pre-fill; show placeholder.
            'redis_password' => null,
            'redis_database' => $settings->redis_database,
            'octane_server' => $settings->octane_server,
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Select::make('cache_driver')
                    ->label('Cache driver')
                    ->options([
                        'file' => 'File (local disk)',
                        'database' => 'Database',
                        'redis' => 'Redis',
                    ])
                    ->helperText('Redis avoids a SQL round-trip on every cache read/write — the recommended choice once a Redis server is reachable.')
                    ->required()
                    ->live(),

                Select::make('queue_connection')
                    ->label('Queue connection')
                    ->options([
                        'sync' => 'Sync (runs immediately, no background worker)',
                        'database' => 'Database',
                        'redis' => 'Redis',
                    ])
                    ->helperText('Media thumbnail generation and other background jobs use this connection. "Sync" blocks the request until the job finishes — avoid it outside local debugging.')
                    ->required()
                    ->live(),

                TextInput::make('redis_host')
                    ->label('Redis host')
                    ->maxLength(255)
                    ->required(fn (callable $get): bool => in_array('redis', [$get('cache_driver'), $get('queue_connection')], true))
                    ->visible(fn (callable $get): bool => in_array('redis', [$get('cache_driver'), $get('queue_connection')], true)),

                TextInput::make('redis_port')
                    ->label('Redis port')
                    ->numeric()
                    ->minValue(1)
                    ->maxValue(65535)
                    ->required(fn (callable $get): bool => in_array('redis', [$get('cache_driver'), $get('queue_connection')], true))
                    ->visible(fn (callable $get): bool => in_array('redis', [$get('cache_driver'), $get('queue_connection')], true)),

                TextInput::make('redis_password')
                    ->label('Redis password')
                    ->password()
                    ->nullable()
                    ->placeholder('[secret — leave blank to keep current, or if no password is set]')
                    ->helperText('Leave blank to keep the existing password unchanged.')
                    ->visible(fn (callable $get): bool => in_array('redis', [$get('cache_driver'), $get('queue_connection')], true)),

                TextInput::make('redis_database')
                    ->label('Redis database index')
                    ->numeric()
                    ->minValue(0)
                    ->maxValue(15)
                    ->visible(fn (callable $get): bool => in_array('redis', [$get('cache_driver'), $get('queue_connection')], true)),

                static::octaneStatusPlaceholder(),

                Select::make('octane_server')
                    ->label('Octane server (when running under Octane)')
                    ->options([
                        'frankenphp' => 'FrankenPHP (recommended)',
                        'swoole' => 'Swoole',
                        'roadrunner' => 'RoadRunner',
                    ])
                    ->helperText('Only takes effect if the app is started with "php artisan octane:start" — see System Info for whether Octane is currently running. This does not start or stop Octane itself.')
                    ->required(),

                SchemaActions::make([
                    Action::make('saveBottom')
                        ->label('Save settings')
                        ->action(fn () => $this->save()),
                ])->alignEnd(),
            ]);
    }

    /**
     * A live installed/running/server badge row — shared between this page
     * and the Performance section of the unified SettingsPage. Uses the same
     * signals as SystemInfoPage: package presence via class_exists(), and the
     * LARAVEL_OCTANE env var Octane's own start commands set on the worker
     * process (there is no other reliable way to detect "currently running").
     */
    public static function octaneStatusPlaceholder(): Placeholder
    {
        return Placeholder::make('octane_status')
            ->label('Octane status')
            ->content(function (): HtmlString {
                $installed = class_exists(OctaneServiceProvider::class);
                $running = filter_var(getenv('LARAVEL_OCTANE'), FILTER_VALIDATE_BOOLEAN);
                $server = (string) config('octane.server', 'frankenphp');

                $badge = function (string $label, string $color): string {
                    return '<span class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-medium '.$color.'">'.e($label).'</span>';
                };

                $installedBadge = $installed
                    ? $badge('Package installed', 'bg-success-50 text-success-700 ring-1 ring-inset ring-success-600/20 dark:bg-success-400/10 dark:text-success-400 dark:ring-success-400/20')
                    : $badge('Package not installed', 'bg-danger-50 text-danger-700 ring-1 ring-inset ring-danger-600/20 dark:bg-danger-400/10 dark:text-danger-400 dark:ring-danger-400/20');

                $runningBadge = $running
                    ? $badge('Running ('.$server.')', 'bg-success-50 text-success-700 ring-1 ring-inset ring-success-600/20 dark:bg-success-400/10 dark:text-success-400 dark:ring-success-400/20')
                    : $badge('Not running (plain PHP-FPM/CLI)', 'bg-gray-100 text-gray-600 ring-1 ring-inset ring-gray-500/10 dark:bg-gray-400/10 dark:text-gray-400 dark:ring-gray-400/20');

                return new HtmlString(
                    '<div class="flex flex-wrap items-center gap-2">'.$installedBadge.$runningBadge.'</div>'
                );
            });
    }

    public function save(): void
    {
        /** @var array{cache_driver: string, queue_connection: string, redis_host?: string, redis_port?: int|string, redis_password?: ?string, redis_database?: int|string, octane_server: string} $data */
        $data = $this->form->getState();

        $settings = PerformanceSettings::get();
        $settings->cache_driver = $data['cache_driver'];
        $settings->queue_connection = $data['queue_connection'];
        $settings->octane_server = $data['octane_server'];

        // The Redis inputs are ->visible() only while a Redis driver is
        // selected, and Filament leaves hidden components out of getState()
        // altogether — so reading them unconditionally threw "Undefined array
        // key redis_host" for anyone on file/database drivers. Defaulting
        // them would be worse than throwing: switching the cache driver to
        // file and pressing Save would quietly overwrite a working Redis host
        // with 127.0.0.1. Absent means "not being edited", so leave it alone.
        if (array_key_exists('redis_host', $data)) {
            $settings->redis_host = $data['redis_host'] ?: '127.0.0.1';
        }

        if (array_key_exists('redis_port', $data)) {
            $settings->redis_port = (int) ($data['redis_port'] ?: 6379);
        }

        if (array_key_exists('redis_database', $data)) {
            $settings->redis_database = (int) $data['redis_database'];
        }

        if (filled($data['redis_password'] ?? null)) {
            $settings->redis_password = $data['redis_password'];
        }

        $settings->save();

        Notification::make()
            ->title('Performance settings saved.')
            ->body('Takes effect on the next request.')
            ->success()
            ->send();
    }

    public function testRedisConnection(): void
    {
        /** @var array{redis_host?: ?string, redis_port?: int|string|null, redis_password?: ?string, redis_database?: int|string|null} $data */
        $data = $this->form->getState();

        // This action stays clickable even when no Redis driver is selected
        // and the Redis inputs are therefore hidden and absent from
        // getState() — see save(). Test what is stored rather than throwing.
        $settings = PerformanceSettings::get();

        config([
            'database.redis.default.host' => ($data['redis_host'] ?? null) ?: $settings->redis_host,
            'database.redis.default.port' => ($data['redis_port'] ?? null) ?: $settings->redis_port,
            'database.redis.default.password' => filled($data['redis_password'] ?? null)
                ? $data['redis_password']
                : $settings->redis_password,
            'database.redis.default.database' => $data['redis_database'] ?? $settings->redis_database,
        ]);

        try {
            Redis::connection('default')->ping();

            Notification::make()
                ->title('Redis connection succeeded')
                ->success()
                ->send();
        } catch (\Throwable $e) {
            Notification::make()
                ->title('Redis connection failed')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }

    public function restartQueueWorkers(): void
    {
        Artisan::call('queue:restart');

        Notification::make()
            ->title('Restart signal sent')
            ->body('Running queue:work processes will finish their current job, then exit and need to be restarted by your process supervisor.')
            ->success()
            ->send();
    }

    /** @return array<int, Action> */
    protected function getHeaderActions(): array
    {
        return [
            static::learnMoreAction(),

            Action::make('testRedis')
                ->label('Test Redis connection')
                ->color('gray')
                ->icon('heroicon-o-signal')
                ->action(fn () => $this->testRedisConnection()),

            Action::make('restartQueue')
                ->label('Restart queue workers')
                ->color('gray')
                ->icon('heroicon-o-arrow-path')
                ->action(fn () => $this->restartQueueWorkers()),

            Action::make('save')
                ->label('Save settings')
                ->action(fn () => $this->save()),
        ];
    }

    /**
     * A "Learn more" modal explaining cache/queue drivers, Redis, and Octane
     * in plain language — shared between this standalone page and the
     * Performance section of the unified SettingsPage.
     */
    public static function learnMoreAction(): Action
    {
        return Action::make('performanceGuide')
            ->label('Learn more')
            ->icon('heroicon-o-question-mark-circle')
            ->color('gray')
            ->modalHeading('Performance settings — what these do')
            ->modalWidth('2xl')
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Close')
            ->modalContent(fn (): HtmlString => static::guideHtml());
    }

    private static function guideHtml(): HtmlString
    {
        return new HtmlString(<<<'HTML'
            <div class="space-y-6 text-sm text-gray-600 dark:text-gray-300 max-h-[70vh] overflow-y-auto pr-1">

                <section>
                    <h3 class="font-semibold text-gray-900 dark:text-white mb-1">Cache driver</h3>
                    <p>Every page load reads and writes small cached values (settings, permission checks, rate limits). "Database" stores these as SQL rows — reliable, but every read/write is a database query. "Redis" stores them in memory instead, which is far faster and takes load off your database. "File" writes to local disk — fine for a single small server, but doesn't work if you run multiple app servers behind a load balancer (each would have its own separate cache).</p>
                </section>

                <section>
                    <h3 class="font-semibold text-gray-900 dark:text-white mb-1">Queue connection</h3>
                    <p>Background jobs (like generating the small/medium/large versions of an uploaded image) run on this connection. "Sync" runs the job immediately, in the same request — simplest, but it makes the user wait for e.g. thumbnail generation before their upload finishes. "Database" or "Redis" queue the job and a separate <code class="px-1 rounded bg-gray-100 dark:bg-gray-800">php artisan queue:work</code> process picks it up in the background. Redis is faster and lower-overhead than Database for this.</p>
                </section>

                <section>
                    <h3 class="font-semibold text-gray-900 dark:text-white mb-1">Redis connection fields</h3>
                    <p>Only needed if you set Cache driver or Queue connection to "Redis" above. Point these at a real Redis server — use "Test Redis connection" (on the standalone Performance Settings page) before saving, so you don't switch the driver to something unreachable and lock yourself out of the cache/queue.</p>
                </section>

                <section>
                    <h3 class="font-semibold text-gray-900 dark:text-white mb-1">What is Octane?</h3>
                    <p><a href="https://laravel.com/docs/octane" target="_blank" rel="noopener noreferrer" class="text-primary-600 dark:text-primary-400 hover:underline">Laravel Octane</a> keeps the application booted in memory between requests, instead of rebuilding the entire framework from scratch on every single page load. On a normal PHP-FPM setup, every request pays that boot cost; under Octane, a request is often several times faster because that cost is paid once and reused.</p>
                    <p class="mt-2">The "Octane server" setting here only chooses <em>which engine</em> Octane uses (FrankenPHP is the simplest to run) — it does <strong>not</strong> turn Octane on. This app currently runs on plain PHP-FPM/CLI; see the badge on the System Info page for whether Octane is actually running right now.</p>
                </section>

                <section>
                    <h3 class="font-semibold text-gray-900 dark:text-white mb-1">Why can't this page just turn Octane on?</h3>
                    <p>Octane isn't a setting you flip — it replaces <em>how the app is served</em>. Instead of your web server starting a fresh PHP process per request, you run one long-lived command that must keep running forever (and restart itself if it crashes or the server reboots). That's a hosting/infrastructure decision, not something a web request can safely do to itself.</p>
                </section>

                <section>
                    <h3 class="font-semibold text-gray-900 dark:text-white mb-1">How to actually activate Octane</h3>
                    <ol class="list-decimal list-inside space-y-1.5 mt-1">
                        <li>On the server (not from this admin panel), install the FrankenPHP binary: <code class="px-1 rounded bg-gray-100 dark:bg-gray-800">php artisan octane:install --server=frankenphp</code></li>
                        <li>Set the server choice above to match, and save.</li>
                        <li>Set up a process supervisor (systemd, Supervisor, or your hosting platform's process manager) to run <code class="px-1 rounded bg-gray-100 dark:bg-gray-800">php artisan octane:frankenphp</code> and automatically restart it if it stops. See <code class="px-1 rounded bg-gray-100 dark:bg-gray-800">docs/DEPLOYMENT.md</code> for a ready-made systemd unit.</li>
                        <li>Point your web server (nginx/Caddy) at Octane's port instead of PHP-FPM.</li>
                        <li>Confirm it worked: reload System Info — the Octane card should switch to "Running".</li>
                    </ol>
                    <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">Not required for local development or a low-traffic site — plain PHP-FPM works fine. Octane is worth the setup effort once you have real production traffic.</p>
                </section>
            </div>
        HTML);
    }
}
