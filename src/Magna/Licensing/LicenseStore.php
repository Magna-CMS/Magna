<?php

declare(strict_types=1);

namespace Magna\Licensing;

use JsonException;

/**
 * Reads and writes the encrypted licence cache (LicenseSettings). The only
 * place the JSON shape is known — everything else deals in LicenseEntry.
 */
class LicenseStore
{
    /**
     * Decoded entries for the life of the request.
     *
     * LicenseGate::stateOf() runs once per enabled plugin on every request,
     * and each call used to decrypt the settings blob and re-parse its JSON —
     * so a site with ten licensed plugins paid for ten decrypt+parse cycles
     * per request, plus one more per entry whenever the admin banner rendered.
     *
     * @var array<string, LicenseEntry>|null
     */
    private ?array $memo = null;

    /** @return array<string, LicenseEntry> keyed by product slug */
    public function all(): array
    {
        if ($this->memo !== null) {
            return $this->memo;
        }

        $raw = LicenseSettings::get()->entries;

        if (! is_string($raw) || $raw === '') {
            return $this->memo = [];
        }

        try {
            /** @var mixed $decoded */
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            // A corrupted cache must never brick the admin panel: treat it as
            // empty and let the next verify repopulate it from the server.
            return $this->memo = [];
        }

        if (! is_array($decoded)) {
            return $this->memo = [];
        }

        $entries = [];

        foreach ($decoded as $slug => $data) {
            if (! is_string($slug) || ! is_array($data)) {
                continue;
            }

            $fields = [];
            foreach ($data as $key => $value) {
                $fields[(string) $key] = $value;
            }

            $entry = LicenseEntry::fromArray($slug, $fields);

            if ($entry !== null) {
                $entries[$slug] = $entry;
            }
        }

        return $this->memo = $entries;
    }

    public function get(string $productSlug): ?LicenseEntry
    {
        return $this->all()[$productSlug] ?? null;
    }

    public function put(LicenseEntry $entry): void
    {
        $entries = $this->all();
        $entries[$entry->productSlug] = $entry;

        $this->persist($entries);
    }

    public function forget(string $productSlug): void
    {
        $entries = $this->all();
        unset($entries[$productSlug]);

        $this->persist($entries);
    }

    /** @param array<string, LicenseEntry> $entries */
    private function persist(array $entries): void
    {
        $settings = LicenseSettings::get();
        $settings->entries = json_encode(
            array_map(static fn (LicenseEntry $entry): array => $entry->toArray(), $entries),
            JSON_THROW_ON_ERROR,
        );
        $settings->save();

        // The next read must see what was just written, not the pre-write
        // snapshot — LicenseEnforcer::sync() writes and re-reads within one
        // request.
        $this->memo = $entries;
    }
}
