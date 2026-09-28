<?php

declare(strict_types=1);

namespace Magna\Admin\Console;

use Illuminate\Console\Command;
use Magna\Admin\PanelPathSwitcher;

/**
 * Read or move the admin panel's path from a shell.
 *
 * The panel is the only place the same setting can be changed from a browser,
 * so this is the way back when it is the panel itself that has become hard to
 * reach — a bookmark pointing at the old path, a front-end plugin removed
 * after the root was handed to it, a colleague who flipped the switch and
 * went home. It reads nothing from the request, so it works with the site in
 * maintenance mode.
 *
 * Usage:
 *   php artisan magna:panel:path
 *   php artisan magna:panel:path --root
 *   php artisan magna:panel:path --admin
 */
class PanelPathCommand extends Command
{
    protected $signature = 'magna:panel:path
        {--root : Serve the panel from the domain root, "/"}
        {--admin : Serve the panel from "/admin", leaving "/" to the site}';

    protected $description = 'Show, or move, the path the admin panel answers on';

    public function handle(PanelPathSwitcher $switcher): int
    {
        $root = (bool) $this->option('root');
        $admin = (bool) $this->option('admin');

        if ($root && $admin) {
            $this->error('Pass --root or --admin, not both.');

            return self::FAILURE;
        }

        if (! $root && ! $admin) {
            $this->line('The admin panel answers on '.$switcher->url());

            return self::SUCCESS;
        }

        $switcher->set($admin);

        $this->info('The admin panel now answers on '.$switcher->url().'.');

        if ($admin) {
            $this->line('"/" is free for a frontend plugin to serve; without one it has nothing to answer with.');
        }

        return self::SUCCESS;
    }
}
