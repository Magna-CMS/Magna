<?php

declare(strict_types=1);

namespace Magna\Settings;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Folds the admin's mail settings over the framework's config.
 *
 * The Email settings page wrote host, port, credentials and the from address to
 * the settings table, and nothing ever read them back into `config('mail')`. So
 * every value an administrator typed there was inert: mail went out through
 * whatever `.env` said, the page reported success, and a site whose SMTP details
 * were only ever entered in the panel sent nothing at all.
 *
 * That surfaced as "the mentions do not email anybody" from the Roya suite,
 * which was never a Roya problem — the notification was built and handed to a
 * mailer pointed at localhost:25.
 *
 * Deliberately shaped like ErpSettings::applyToConfig(): read once at boot, fold
 * over the defaults, and stay silent when there is no database yet. Config is
 * still the fallback, so an install that configures SMTP in `.env` and never
 * opens the page keeps working exactly as before.
 */
final class MailConfigurator
{
    public function __construct(private readonly Config $config) {}

    public function apply(): void
    {
        try {
            // Before the first migration, during `migrate:fresh`, or with no
            // database at all — the config file's values are exactly right and
            // asking for the table would only throw.
            if (! Schema::hasTable('settings')) {
                return;
            }

            $settings = MailSettings::get();
        } catch (Throwable) {
            return;
        }

        // An unconfigured install still holds the class defaults. Writing those
        // over a working `.env` would break a site that never used the page, so
        // the placeholder host is treated as "nothing was configured here".
        if ($settings->host === '' || $settings->host === 'localhost') {
            $this->applyFrom($settings);

            return;
        }

        $mailer = $settings->driver === '' ? 'smtp' : $settings->driver;

        $this->config->set('mail.default', $mailer);

        // Only the SMTP transport takes host and credentials. The other drivers
        // an admin can pick (log, array) are selected by name alone, and writing
        // a host into them would be meaningless rather than harmless.
        if ($mailer === 'smtp') {
            $this->config->set('mail.mailers.smtp.host', $settings->host);
            $this->config->set('mail.mailers.smtp.port', $settings->port);
            $this->config->set('mail.mailers.smtp.username', $settings->username);
            $this->config->set('mail.mailers.smtp.password', $settings->password);

            // Laravel 11+ names this `scheme`: 'smtps' for implicit TLS, null to
            // let the transport negotiate STARTTLS. The stored value is the older
            // 'tls'/'ssl' vocabulary the settings page offers, so it is
            // translated rather than passed through.
            $this->config->set('mail.mailers.smtp.scheme', match ($settings->encryption) {
                'ssl', 'smtps' => 'smtps',
                default => null,
            });
        }

        $this->applyFrom($settings);
    }

    /**
     * The from address and name, which every driver uses.
     *
     * Applied even when SMTP was never configured: an install relaying through
     * `.env` still wants the sender the admin chose, and "Magna CMS
     * <noreply@example.com>" on a customer's mail is the kind of detail that
     * gets a whole domain marked as spam.
     */
    private function applyFrom(MailSettings $settings): void
    {
        if ($settings->from_address !== '' && $settings->from_address !== 'noreply@example.com') {
            $this->config->set('mail.from.address', $settings->from_address);
        }

        if ($settings->from_name !== '' && $settings->from_name !== 'Magna CMS') {
            $this->config->set('mail.from.name', $settings->from_name);
        }
    }
}
