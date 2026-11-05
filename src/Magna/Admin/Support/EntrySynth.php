<?php

declare(strict_types=1);

namespace Magna\Admin\Support;

use Livewire\Mechanisms\HandleComponents\Synthesizers\Synth;
use Magna\Content\Entry;

/**
 * Carries an Entry through a Livewire snapshot with its content type.
 *
 * Livewire's own model synth stores a class and a key, and restores with
 * `new $class`. That is fine for a model with one table. An Entry's table comes
 * from its type, so the restored model pointed at a table that does not exist
 * and every action on the edit screen died on its first query.
 */
final class EntrySynth extends Synth
{
    /** @var string */
    public static $key = 'magna-entry';

    /** @param mixed $target */
    public static function match($target): bool
    {
        return $target instanceof Entry;
    }

    /** @return array{0: null, 1: array{handle: string|null, key?: string}} */
    public function dehydrate(Entry $target): array
    {
        $meta = ['handle' => $target->getHandle()];

        if ($target->exists) {
            $meta['key'] = $target->id;
        }

        return [null, $meta];
    }

    /** @param array{handle?: string|null, key?: string} $meta */
    public function hydrate(mixed $value, array $meta): Entry
    {
        $handle = $meta['handle'] ?? null;

        if ($handle === null) {
            return new Entry;
        }

        $query = Entry::type($handle);

        if (! array_key_exists('key', $meta)) {
            return $query->getModel();
        }

        return $query->useWritePdo()->findOrFail($meta['key']);
    }
}
