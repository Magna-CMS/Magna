<?php

declare(strict_types=1);

namespace Magna\Updater\Run;

/**
 * What a carrier learned when it tried to move a run along.
 *
 * `StaleCode` is the one that needs saying out loud: the process that tried
 * to finish an update still runs the previous release's classes (a queue
 * worker that has not restarted, a request whose opcache has not noticed)
 * and must not finish it — another process on the new code will.
 */
final readonly class RunOutcome
{
    public const NOTHING = 'nothing';

    public const COMPLETED = 'completed';

    public const FAILED = 'failed';

    public const NEEDS_ATTENTION = 'needs_attention';

    public const ROLLED_BACK = 'rolled_back';

    public const STALE_CODE = 'stale_code';

    public const BUSY = 'busy';

    private function __construct(
        public string $kind,
        public string $message,
    ) {}

    public static function nothing(): self
    {
        return new self(self::NOTHING, 'No update is waiting.');
    }

    public static function completed(string $message): self
    {
        return new self(self::COMPLETED, $message);
    }

    public static function failed(string $message): self
    {
        return new self(self::FAILED, $message);
    }

    public static function needsAttention(string $message): self
    {
        return new self(self::NEEDS_ATTENTION, $message);
    }

    public static function rolledBack(string $message): self
    {
        return new self(self::ROLLED_BACK, $message);
    }

    public static function staleCode(string $running, string $expected): self
    {
        return new self(self::STALE_CODE, "This process still runs v{$running}; the update to v{$expected} has to be finished by a process on the new code.");
    }

    public static function busy(): self
    {
        return new self(self::BUSY, 'Another process is already finishing this update.');
    }

    public function is(string $kind): bool
    {
        return $this->kind === $kind;
    }
}
