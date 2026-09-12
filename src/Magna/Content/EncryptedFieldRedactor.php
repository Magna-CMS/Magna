<?php

declare(strict_types=1);

namespace Magna\Content;

/**
 * How encrypted schema fields (`encrypted: true`) leave the system.
 *
 * The `encrypted` cast decrypts transparently on attribute access, so any
 * serializer that loops columnFields() emits the PLAINTEXT unless it decides
 * otherwise. DeliveryQueryBuilder already excludes these fields from filters,
 * calling exposure "a disclosure risk" — but the values themselves went out
 * through the delivery payload, the management resource, and (via the
 * management resource's snapshots) into audit_logs.before/after, which
 * turned the audit trail into a plaintext archive of every secret ever
 * edited.
 *
 * The rule now: the delivery API omits encrypted fields entirely (a public
 * consumer was never entitled to them), the management resource emits
 * {@see self::PLACEHOLDER} for a set value (write-only secret semantics, the
 * same shape Settings #[Secret] fields use in the panel), and EntryManager
 * strips placeholder round-trips on write so a client that reads, edits an
 * unrelated field, and resubmits can never overwrite a stored secret with
 * the placeholder literal.
 */
final class EncryptedFieldRedactor
{
    /**
     * What a management API consumer sees in place of a set encrypted value.
     * Deliberately implausible as real data so a round-trip is recognisable.
     */
    public const PLACEHOLDER = '__MAGNA_ENCRYPTED__';

    /**
     * Drop encrypted-field entries whose value is the redaction placeholder —
     * the client is saying "unchanged", not "store this literal".
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function stripPlaceholders(array $data, ContentType $type): array
    {
        foreach ($type->encryptedFieldHandles() as $handle) {
            if (($data[$handle] ?? null) === self::PLACEHOLDER) {
                unset($data[$handle]);
            }
        }

        return $data;
    }
}
