<?php

/*
|--------------------------------------------------------------------------
| Boot guard — the one rollback that needs no framework
|--------------------------------------------------------------------------
|
| Required first thing by bootstrap/app.php. Idle cost: one is_file(). It
| only arms itself while an update has just switched the live files and the
| new code has not yet proven it can boot — the window in which, on a shared
| host with no shell, a release that fatals during bootstrap would otherwise
| leave a site nobody can bring back.
|
| On a fatal inside that window it renames every path the run's journal says
| was swapped back to the previous release, clears the file-driver maintenance
| markers, records what happened in the journal, and answers with a plain
| page. Pure PHP: nothing here may depend on the code that just failed.
|
| Only the file maintenance driver's markers are cleared; a cache-driver
| maintenance mode has nothing on disk to clear and stays on until
| `php artisan up`, which the note in the journal says.
*/

(static function (string $base): void {
    $marker = $base.'/storage/app/magna-updates/boot-guard.json';

    if (! is_file($marker)) {
        return;
    }

    $armed = json_decode((string) @file_get_contents($marker), true);

    if (! is_array($armed) || ! is_string($armed['run'] ?? null) || preg_match('/^[A-Za-z0-9_-]+$/', $armed['run']) !== 1) {
        return;
    }

    $runId = $armed['run'];

    register_shutdown_function(static function () use ($base, $marker, $runId): void {
        $error = error_get_last();

        if ($error === null || ! in_array($error['type'], [E_ERROR, E_PARSE, E_COMPILE_ERROR, E_CORE_ERROR], true)) {
            return;
        }

        // Claim the run once: the first process to trip the guard does the
        // work, every other one finds the marker gone.
        if (! @rename($marker, $marker.'.tripped')) {
            return;
        }

        $journalPath = $base.'/storage/app/magna-updates/runs/'.$runId.'/journal.json';
        $journal = json_decode((string) @file_get_contents($journalPath), true);
        $journal = is_array($journal) ? $journal : [];
        $paths = is_array($journal['paths'] ?? null) ? $journal['paths'] : [];
        $restored = [];
        $troubles = [];

        // Newest first, so a path swapped after another is undone before it.
        foreach (array_reverse($paths, true) as $relative => $info) {
            if (! is_string($relative) || ! is_array($info)) {
                continue;
            }

            $state = $info['state'] ?? null;
            $displaced = is_string($info['displaced'] ?? null) ? $info['displaced'] : null;

            if (! in_array($state, ['swapped', 'removed', 'displacing'], true)) {
                continue;
            }

            $live = $base.'/'.$relative;

            if ($state !== 'removed' && (is_dir($live) || is_file($live))) {
                $failed = dirname($live).'/.'.basename($live).'.failed-'.$runId;

                if (! @rename($live, $failed)) {
                    $troubles[] = "could not move the new {$relative} aside";

                    continue;
                }
            }

            if ($displaced !== null && (is_dir($displaced) || is_file($displaced))) {
                if (@rename($displaced, $live)) {
                    $restored[] = $relative;
                } else {
                    $troubles[] = "could not put the previous {$relative} back";
                }
            }
        }

        @unlink($base.'/storage/framework/down');
        @unlink($base.'/storage/framework/maintenance.php');

        $journal['state'] = 'rolled_back_by_boot_guard';
        $journal['heartbeat_at'] = date('c');
        $journal['boot_guard'] = [
            'tripped_at' => date('c'),
            'error' => $error['message'],
            'file' => $error['file'],
            'line' => $error['line'],
            'restored' => $restored,
            'troubles' => $troubles,
            'note' => 'File-driver maintenance markers were cleared; a cache-driver maintenance mode needs `php artisan up`.',
        ];

        @file_put_contents($journalPath, json_encode($journal, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        if (PHP_SAPI === 'cli') {
            fwrite(STDERR, "The update could not boot and was rolled back to the previous release. See {$journalPath}.\n");

            return;
        }

        if (! headers_sent()) {
            http_response_code(503);
            header('Content-Type: text/html; charset=utf-8');
            header('Retry-After: 5');
        }

        echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>Update rolled back</title>'
            .'<meta name="viewport" content="width=device-width, initial-scale=1"></head>'
            .'<body style="font-family:system-ui,sans-serif;max-width:40rem;margin:4rem auto;padding:0 1rem;color:#222">'
            .'<h1 style="font-size:1.25rem">The update could not start</h1>'
            .'<p>The new release failed while starting, so the previous version was put back. Reload this page in a few seconds.</p>'
            .'<p style="color:#666;font-size:.875rem">Details are in the update run log on the server (run '.htmlspecialchars($runId, ENT_QUOTES).').</p>'
            .'</body></html>';
    });
})(dirname(__DIR__, 2));
