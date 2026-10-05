<?php

declare(strict_types=1);

namespace Magna\Settings;

/**
 * Writes the unified settings page's form state back to the typed settings
 * aggregates — everything except Mail, whose single writer stays
 * MailSettingsPage::persist() (the page calls both).
 *
 * Extracted from SettingsPage::save(), which had grown to 129 lines mixing
 * coercion closures, eight aggregate write-backs, config re-application and
 * UI state — the BackupPlanPersister treatment, applied to the page that
 * the coding standards' god-method rule names that persister as the model for.
 */
class GeneralSettingsPersister
{
    /**
     * @param  array<string, mixed>  $data  the page's form state
     * @param  bool  $withFrontendUrls  whether the frontend-URL fields were on the form (magna-cms/pages installed)
     */
    public function persist(array $data, bool $withFrontendUrls): void
    {
        $this->persistGeneral($data);
        $this->persistLocalization($data);
        $this->persistContent($data);
        $this->persistMedia($data);
        $this->persistStorage($data);
        $this->persistUrls($data, $withFrontendUrls);
        $this->persistSecurity($data);
        $this->persistPerformance($data);
    }

    /** @param array<string, mixed> $data */
    private function persistGeneral(array $data): void
    {
        $general = GeneralSettings::get();
        // Defaulted to the shipped value rather than '': the field is required
        // on the form, and a site whose name coerced to empty would render a
        // bare "·" in every page title instead of saying who it is.
        $general->site_name = FormStateCoercion::string($data, 'site_name', 'Magna CMS');
        $general->site_tagline = FormStateCoercion::string($data, 'site_tagline');
        $general->registration_enabled = (bool) ($data['registration_enabled'] ?? false);
        $general->timezone = FormStateCoercion::string($data, 'timezone', 'UTC');
        $general->default_locale = FormStateCoercion::string($data, 'default_locale', 'en');
        $general->date_format = FormStateCoercion::string($data, 'date_format', 'Y-m-d');
        $general->time_format = FormStateCoercion::string($data, 'time_format', 'H:i');
        $general->first_day_of_week = FormStateCoercion::int($data, 'first_day_of_week', 1);
        $general->currency = FormStateCoercion::string($data, 'currency');
        $general->save();
    }

    /** @param array<string, mixed> $data */
    private function persistLocalization(array $data): void
    {
        $localization = LocalizationSettings::get();
        $localization->available_locales = FormStateCoercion::stringList($data, 'available_locales');
        $localization->fallback_locale = FormStateCoercion::string($data, 'fallback_locale', 'en');
        $localization->rtl_locales = FormStateCoercion::stringList($data, 'rtl_locales');
        $localization->save();
    }

    /** @param array<string, mixed> $data */
    private function persistContent(array $data): void
    {
        $content = ContentSettings::get();
        $content->default_status = FormStateCoercion::string($data, 'default_status', 'draft');
        $content->revision_limit = FormStateCoercion::int($data, 'revision_limit', 50);
        $content->autosave_interval = FormStateCoercion::int($data, 'autosave_interval', 60);
        $content->save();
    }

    /** @param array<string, mixed> $data */
    private function persistMedia(array $data): void
    {
        $media = MediaSettings::get();
        $media->max_image_upload_bytes = FormStateCoercion::int($data, 'max_image_upload_bytes');
        $media->max_svg_upload_bytes = FormStateCoercion::int($data, 'max_svg_upload_bytes');
        $media->max_document_upload_bytes = FormStateCoercion::int($data, 'max_document_upload_bytes');
        $media->default_image_quality = FormStateCoercion::int($data, 'default_image_quality', 90);
        $media->webp_enabled = (bool) ($data['webp_enabled'] ?? false);
        $media->avif_enabled = (bool) ($data['avif_enabled'] ?? false);
        $media->save();
    }

    /** @param array<string, mixed> $data */
    private function persistStorage(array $data): void
    {
        $storage = StorageSettings::get();
        $storage->disk = FormStateCoercion::string($data, 'disk', 'local');
        $storage->s3_key = FormStateCoercion::stringOrNull($data, 's3_key');
        $storage->s3_bucket = FormStateCoercion::stringOrNull($data, 's3_bucket');
        $storage->s3_region = FormStateCoercion::stringOrNull($data, 's3_region');
        $storage->s3_url = FormStateCoercion::stringOrNull($data, 's3_url');

        // Secrets arrive blank on every load; only a value typed now
        // overwrites the stored one.
        $secret = FormStateCoercion::filledString($data, 's3_secret');
        if ($secret !== null) {
            $storage->s3_secret = $secret;
        }

        $storage->save();
    }

    /** @param array<string, mixed> $data */
    private function persistUrls(array $data, bool $withFrontendUrls): void
    {
        $url = UrlSettings::get();
        $url->cdn_url = FormStateCoercion::trimmedUrl($data, 'cdn_url');

        if ($withFrontendUrls) {
            $url->frontend_url = FormStateCoercion::trimmedUrl($data, 'frontend_url');
            $url->preview_base_url = FormStateCoercion::trimmedUrl($data, 'preview_base_url');
        }

        $url->save();
    }

    /** @param array<string, mixed> $data */
    private function persistSecurity(array $data): void
    {
        $security = SecuritySettings::get();
        $security->force_https = (bool) ($data['force_https'] ?? false);
        $security->require_email_verification = (bool) ($data['require_email_verification'] ?? false);
        $security->session_lifetime = FormStateCoercion::int($data, 'session_lifetime', 120);
        $security->save();
    }

    /** @param array<string, mixed> $data */
    private function persistPerformance(array $data): void
    {
        $performance = PerformanceSettings::get();

        // The selects only offer these values; anything else is tampered
        // client state and must not reach the typed settings properties.
        $cacheDriver = $data['cache_driver'] ?? null;
        if (in_array($cacheDriver, ['database', 'file', 'redis'], true)) {
            $performance->cache_driver = $cacheDriver;
        }

        $queueConnection = $data['queue_connection'] ?? null;
        if (in_array($queueConnection, ['database', 'redis', 'sync'], true)) {
            $performance->queue_connection = $queueConnection;
        }

        // Only written when actually present: these inputs are ->visible()
        // behind a Redis driver being selected, and Filament omits hidden
        // components from the state. Falling back to a default here would
        // reset a configured Redis host to 127.0.0.1 every time someone saved
        // this page while on the file/database drivers.
        if (array_key_exists('redis_host', $data)) {
            $performance->redis_host = FormStateCoercion::string($data, 'redis_host') ?: '127.0.0.1';
        }
        if (array_key_exists('redis_port', $data)) {
            $performance->redis_port = FormStateCoercion::int($data, 'redis_port') ?: 6379;
        }
        if (array_key_exists('redis_database', $data)) {
            $performance->redis_database = FormStateCoercion::int($data, 'redis_database');
        }

        $octaneServer = $data['octane_server'] ?? null;
        if (in_array($octaneServer, ['frankenphp', 'roadrunner', 'swoole'], true)) {
            $performance->octane_server = $octaneServer;
        }

        $password = FormStateCoercion::filledString($data, 'redis_password');
        if ($password !== null) {
            $performance->redis_password = $password;
        }

        $performance->save();
    }
}
