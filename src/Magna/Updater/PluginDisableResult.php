<?php

declare(strict_types=1);

namespace Magna\Updater;

/** Outcome of auto-disabling plugins made incompatible by a forced core update. */
final readonly class PluginDisableResult
{
    /**
     * @param  list<string>  $disabled
     * @param  list<string>  $failed
     */
    public function __construct(
        public array $disabled,
        public array $failed,
    ) {}

    /** The success line with what was disabled — and what the admin still has to — appended. */
    public function describe(string $message): string
    {
        if ($this->disabled !== []) {
            $message .= ' Automatically disabled (incompatible with this version): '.implode(', ', $this->disabled).'.';
        }

        if ($this->failed !== []) {
            $message .= ' WARNING: could not disable these incompatible plugins — disable them manually now: '.implode(', ', $this->failed).'.';
        }

        return $message;
    }
}
