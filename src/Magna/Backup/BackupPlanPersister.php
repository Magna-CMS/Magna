<?php

declare(strict_types=1);

namespace Magna\Backup;

use Cron\CronExpression;
use Magna\Settings\BackupSettings;
use Magna\Settings\FormStateCoercion;
use Magna\Settings\StorageSettings;
use Throwable;

/**
 * Persisting the backup plan, end to end: hydrate BackupSettings from the
 * form state, refuse every configuration that would quietly defeat the
 * feature (a destination on the live media disk, a secondary that is really
 * the primary again, an offsite copy leaving the server unencrypted, a cron
 * expression that would never fire), then save.
 *
 * Extracted from BackupSettingsPage::save() (per the collaborator pattern):
 * a Livewire method carrying 25 field hydrations and five business rules
 * was the god-method rule's textbook violation, and none of the rules could
 * be tested without driving the whole page. The page keeps the notification
 * wiring; the rules live here.
 */
class BackupPlanPersister
{
    /**
     * @param  array<string, mixed>  $data
     * @return array{ok: bool, title: string, message: string|null}
     */
    public function persist(array $data): array
    {
        $settings = BackupSettings::get();

        $this->hydrateDestinations($settings, $data);

        $rejection = $this->rejectUnsafePlan($settings, $data);
        if ($rejection !== null) {
            return $rejection;
        }

        $this->hydrateSchedule($settings, $data);

        $settings->save();

        return ['ok' => true, 'title' => 'Backup settings saved.', 'message' => null];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function hydrateDestinations(BackupSettings $settings, array $data): void
    {
        $settings->enabled = (bool) ($data['enabled'] ?? false);
        $settings->disk = FormStateCoercion::string($data, 'disk', 'local');
        $settings->s3_key = FormStateCoercion::stringOrNull($data, 's3_key');
        $settings->s3_bucket = FormStateCoercion::stringOrNull($data, 's3_bucket');
        $settings->s3_region = FormStateCoercion::stringOrNull($data, 's3_region');
        $settings->s3_url = FormStateCoercion::stringOrNull($data, 's3_url');

        // Secrets follow the leave-blank-to-keep convention: an empty field
        // means "unchanged", never "clear".
        $secret = FormStateCoercion::filledString($data, 's3_secret');
        if ($secret !== null) {
            $settings->s3_secret = $secret;
        }

        $settings->secondary_disk = FormStateCoercion::stringOrNull($data, 'secondary_disk');
        $settings->secondary_s3_key = FormStateCoercion::stringOrNull($data, 'secondary_s3_key');
        $settings->secondary_s3_bucket = FormStateCoercion::stringOrNull($data, 'secondary_s3_bucket');
        $settings->secondary_s3_region = FormStateCoercion::stringOrNull($data, 'secondary_s3_region');
        $settings->secondary_s3_url = FormStateCoercion::stringOrNull($data, 'secondary_s3_url');

        $secondarySecret = FormStateCoercion::filledString($data, 'secondary_s3_secret');
        if ($secondarySecret !== null) {
            $settings->secondary_s3_secret = $secondarySecret;
        }

        $encryptionPassword = FormStateCoercion::filledString($data, 'encryption_password');
        if ($encryptionPassword !== null) {
            $settings->encryption_password = $encryptionPassword;
        }

        $sizeWarning = $data['size_warning_mb'] ?? null;
        $settings->size_warning_mb = is_numeric($sizeWarning) ? (int) $sizeWarning : null;
    }

    /**
     * The plan-level refusals, checked against the hydrated (but unsaved)
     * settings. See docs/backup-manager-plan.md, Decision #1: a backup
     * destination identical to the live media disk is a single point of
     * failure and is rejected outright, not just warned about. Stage 7
     * extends the same rule to the secondary destination, plus a secondary
     * that's really just the primary again and a bucket-based destination
     * with no encryption password. Stage 8 adds the cron check: an invalid
     * expression must fail at save time, because BackupSchedule::isDueNow()
     * failing closed on one only ever surfaces as "backups quietly stopped
     * running" — exactly the failure this feature exists to prevent.
     *
     * @param  array<string, mixed>  $data
     * @return array{ok: bool, title: string, message: string|null}|null
     */
    private function rejectUnsafePlan(BackupSettings $settings, array $data): ?array
    {
        if ($settings->collidesWithMediaDisk(StorageSettings::get())) {
            return $this->rejected(
                'The backup destination resolves to the same disk/bucket as the Storage settings media disk. Backups must be written somewhere independent of live media — pick a different disk, bucket, or path.',
                title: 'Backup destination not saved',
            );
        }

        if ($settings->secondaryCollidesWithMediaDisk(StorageSettings::get())) {
            return $this->rejected('The secondary destination resolves to the same disk/bucket as the Storage settings media disk.');
        }

        if ($settings->secondaryCollidesWithPrimary()) {
            return $this->rejected('The secondary destination resolves to the same place as the primary — that is not a second copy. Pick a genuinely different disk, bucket, or path.');
        }

        if ($settings->encryptionMisconfigured()) {
            return $this->rejected('A bucket-based destination (S3/S3-compatible) is configured without an encryption password. Set one before saving.');
        }

        if (FormStateCoercion::string($data, 'frequency', 'daily') === 'custom_cron') {
            $cronExpression = FormStateCoercion::stringOrNull($data, 'cron_expression');

            if ($cronExpression === null) {
                return $this->rejected('A cron expression is required when frequency is set to "Custom cron expression".');
            }

            try {
                new CronExpression($cronExpression);
            } catch (Throwable) {
                return $this->rejected("'{$cronExpression}' is not a valid cron expression.");
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function hydrateSchedule(BackupSettings $settings, array $data): void
    {
        $settings->frequency = FormStateCoercion::string($data, 'frequency', 'daily');
        $settings->cron_expression = FormStateCoercion::stringOrNull($data, 'cron_expression');
        $settings->run_at = FormStateCoercion::string($data, 'run_at', '02:00');
        $settings->retention_count = FormStateCoercion::int($data, 'retention_count', 7);
        $settings->retention_days = FormStateCoercion::int($data, 'retention_days', 30);
        $settings->include_database = (bool) ($data['include_database'] ?? true);
        $settings->include_files = (bool) ($data['include_files'] ?? true);
        $settings->include_config = (bool) ($data['include_config'] ?? true);
        $settings->excluded_tables = FormStateCoercion::stringList($data, 'excluded_tables');
        $settings->notify_emails = FormStateCoercion::stringList($data, 'notify_emails');
    }

    /**
     * @return array{ok: bool, title: string, message: string|null}
     */
    private function rejected(string $message, string $title = 'Backup settings not saved'): array
    {
        return ['ok' => false, 'title' => $title, 'message' => $message];
    }
}
