<?php

declare(strict_types=1);

namespace Magna\Updater\Run;

use Magna\Support\AtomicFile;
use Magna\Updater\UpdatePaths;
use RuntimeException;

/**
 * The durable record of one update run: where it is, what it has done to
 * which path, and who is working on it.
 *
 * A file, not a cache entry, on purpose. The apply clears caches partway
 * through and the cache store may be `array`; a journal that vanished with
 * the cache would leave a half-switched tree that nothing could describe.
 * Written beside then renamed into place, so a reader never sees half a
 * file; heartbeat on every write, so a run whose process died can be told
 * from one that is merely slow.
 *
 * Any process on the site may pick a run up — the admin's poll, a queue
 * worker, the scheduler, a shell — which is what makes an update survive the
 * process that started it. The lock file keeps two of them from doing so at
 * once; it is an flock, so it dies with its holder.
 */
final class UpdateJournal
{
    public const FILENAME = 'journal.json';

    public const LOCK_FILENAME = 'journal.lock';

    public const SCHEMA = 1;

    /** A non-terminal run with no heartbeat for this long has lost its process. */
    public const ABANDONED_AFTER_SECONDS = 600;

    /** @var resource|null */
    private $lockHandle = null;

    /** @param  array<string, mixed>  $data */
    private function __construct(
        private readonly string $directory,
        private array $data,
    ) {}

    /** @param  array<string, mixed>  $data  everything the run knows at birth: mode, from, to, secret, archive */
    public static function create(UpdatePaths $paths, string $runId, array $data): self
    {
        $directory = $paths->runsDir().'/'.$runId;

        if (! is_dir($directory) && ! @mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RuntimeException("Could not create the update run directory {$directory}.");
        }

        $journal = new self($directory, array_replace($data, [
            'schema' => self::SCHEMA,
            'run_id' => $runId,
            'state' => RunState::Created->value,
            'started_at' => date('c'),
            'heartbeat_at' => date('c'),
            'paths' => [],
            'finalize' => ['steps' => [], 'done' => [], 'attempts' => 0, 'last_error' => null],
            'outcome' => null,
        ]));

        $journal->persist();

        return $journal;
    }

    public static function open(UpdatePaths $paths, string $runId): ?self
    {
        return self::read($paths->runsDir().'/'.$runId);
    }

    /**
     * The newest run that is not over. Run directories are named by
     * timestamp, so the newest sorts last.
     */
    public static function latestPending(UpdatePaths $paths): ?self
    {
        $runsDir = $paths->runsDir();

        if (! is_dir($runsDir)) {
            return null;
        }

        $names = array_values(array_filter(
            scandir($runsDir) ?: [],
            static fn (string $name): bool => $name !== '.' && $name !== '..' && is_dir($runsDir.'/'.$name),
        ));

        rsort($names);

        foreach ($names as $name) {
            $journal = self::read($runsDir.'/'.$name);

            if ($journal !== null && ! $journal->state()->isTerminal()) {
                return $journal;
            }
        }

        return null;
    }

    public function runId(): string
    {
        $id = $this->data['run_id'] ?? null;

        return is_string($id) ? $id : basename($this->directory);
    }

    public function directory(): string
    {
        return $this->directory;
    }

    public function state(): RunState
    {
        $state = $this->data['state'] ?? null;

        return is_string($state) ? (RunState::tryFrom($state) ?? RunState::Failed) : RunState::Failed;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    public function string(string $key): ?string
    {
        $value = $this->data[$key] ?? null;

        return is_string($value) ? $value : null;
    }

    /** @param  array<string, mixed>  $changes */
    public function set(array $changes): void
    {
        $this->data = array_replace($this->data, $changes, ['heartbeat_at' => date('c')]);
        $this->persist();
    }

    /** @param  array<string, mixed>  $changes */
    public function transition(RunState $state, array $changes = []): void
    {
        $this->set(array_replace($changes, ['state' => $state->value]));
    }

    /**
     * Everything known about one path this run touches: where it was staged,
     * where the previous content went, what state it is in.
     *
     * @param  array<string, mixed>  $fields
     */
    public function recordPath(string $relative, array $fields): void
    {
        $paths = $this->paths();
        $paths[$relative] = array_replace($paths[$relative] ?? [], $fields);

        $this->set(['paths' => $paths]);
    }

    /** @return array<string, array<string, mixed>> */
    public function paths(): array
    {
        $paths = $this->data['paths'] ?? [];

        if (! is_array($paths)) {
            return [];
        }

        $clean = [];

        foreach ($paths as $relative => $fields) {
            if (is_string($relative) && is_array($fields)) {
                $clean[$relative] = $fields;
            }
        }

        return $clean;
    }

    public function isAbandoned(int $seconds = self::ABANDONED_AFTER_SECONDS): bool
    {
        if ($this->state()->isTerminal()) {
            return false;
        }

        $raw = $this->data['heartbeat_at'] ?? null;
        $heartbeat = is_string($raw) ? strtotime($raw) : false;

        return $heartbeat === false || $heartbeat < time() - $seconds;
    }

    /** Claim the run for this process. False when another process holds it. */
    public function acquire(): bool
    {
        if ($this->lockHandle !== null) {
            return true;
        }

        $handle = @fopen($this->directory.'/'.self::LOCK_FILENAME, 'c');

        if ($handle === false) {
            return false;
        }

        if (! flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);

            return false;
        }

        $this->lockHandle = $handle;

        return true;
    }

    public function release(): void
    {
        if ($this->lockHandle === null) {
            return;
        }

        flock($this->lockHandle, LOCK_UN);
        fclose($this->lockHandle);
        $this->lockHandle = null;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->data;
    }

    private static function read(string $directory): ?self
    {
        $file = $directory.'/'.self::FILENAME;

        if (! is_file($file)) {
            return null;
        }

        $decoded = json_decode((string) @file_get_contents($file), true);

        if (! is_array($decoded)) {
            return null;
        }

        /** @var array<string, mixed> $decoded */
        return new self($directory, $decoded);
    }

    private function persist(): void
    {
        $payload = json_encode($this->data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        if (! is_string($payload)) {
            throw new RuntimeException('The update journal could not be encoded.');
        }

        $file = $this->directory.'/'.self::FILENAME;

        if (! AtomicFile::write($file, $payload)) {
            throw new RuntimeException("Could not write the update journal at {$file}.");
        }
    }
}
