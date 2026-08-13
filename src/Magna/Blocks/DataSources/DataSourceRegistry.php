<?php

declare(strict_types=1);

namespace Magna\Blocks\DataSources;

/**
 * Every data source enabled plugins expose, keyed by handle. Same shape as
 * BlockRegistry: plugins contribute at enable-time via the
 * RegistersDataSources contract; consumers (the Loop block, pickers) only
 * ever read.
 */
final class DataSourceRegistry
{
    /** @var array<string, DataSource> */
    private array $sources = [];

    public function register(DataSource $source): void
    {
        $this->sources[$source->handle()] = $source;
    }

    public function get(string $handle): ?DataSource
    {
        return $this->sources[$handle] ?? null;
    }

    /** @return array<string, DataSource> */
    public function all(): array
    {
        return $this->sources;
    }
}
