<?php

declare(strict_types=1);

namespace Magna\Updater;

use Illuminate\Support\Facades\Artisan;
use Throwable;

/**
 * Maintenance mode for the duration of a switch, with the admin kept inside.
 *
 * `--secret` is the key CoreUpdateStarter minted for the run: the admin's
 * browser holds the matching bypass cookie, so their progress poll keeps
 * answering while everyone else sees the maintenance page. `--retry` is what
 * that page tells them. Leaving never throws — it runs from finally blocks,
 * where a throw would replace the real outcome with its own.
 */
final class MaintenanceWindow
{
    /** What the maintenance page tells visitors to wait, in seconds (Retry-After). */
    public const RETRY_SECONDS = 15;

    public function __construct(private readonly UpdateRunLog $log) {}

    public function enter(?string $secret): void
    {
        $options = ['--retry' => self::RETRY_SECONDS];

        if ($secret !== null && $secret !== '') {
            $options['--secret'] = $secret;
        }

        Artisan::call('down', $options);
        $this->log->line('Maintenance mode on'.($secret === null || $secret === '' ? '' : ', admin bypass issued'));
    }

    public function leave(): void
    {
        try {
            Artisan::call('up');
            $this->log->line('Maintenance mode off');
        } catch (Throwable $e) {
            $this->log->line('Could not leave maintenance mode: '.$e->getMessage().' — run `php artisan up`.');
        }
    }
}
