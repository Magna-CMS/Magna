<?php

declare(strict_types=1);

namespace Magna\Settings;

/**
 * Typed reads over an untyped Filament form-state array.
 *
 * Every settings writer needs the same handful of coercions, and three of
 * them (MailSettingsPage, BackupPlanPersister, SettingsPage::save's inline
 * closures) had each grown a private copy — token-identical, which is
 * exactly the drift the earlier remediation flagged. One home; the pages
 * and persisters call these instead of keeping their own.
 */
final class FormStateCoercion
{
    /** @param array<string, mixed> $data */
    public static function string(array $data, string $key, string $default = ''): string
    {
        $value = $data[$key] ?? null;

        return is_string($value) ? $value : $default;
    }

    /**
     * A non-empty string, or null — the "optional field left blank" read.
     *
     * @param  array<string, mixed>  $data
     */
    public static function stringOrNull(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * Like stringOrNull(), but blank in Laravel's filled() sense — the
     * secret-field read, where whitespace is not a new secret.
     *
     * @param  array<string, mixed>  $data
     */
    public static function filledString(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;

        return is_string($value) && trim($value) !== '' ? $value : null;
    }

    /** @param array<string, mixed> $data */
    public static function int(array $data, string $key, int $default = 0): int
    {
        $value = $data[$key] ?? null;

        return is_numeric($value) ? (int) $value : $default;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<string>
     */
    public static function stringList(array $data, string $key): array
    {
        $values = $data[$key] ?? [];
        $strings = [];

        foreach (is_array($values) ? $values : [] as $value) {
            if (is_string($value)) {
                $strings[] = $value;
            }
        }

        return $strings;
    }

    /**
     * A URL field read with any trailing slash dropped.
     *
     * @param  array<string, mixed>  $data
     */
    public static function trimmedUrl(array $data, string $key): string
    {
        return rtrim(self::string($data, $key), '/');
    }
}
