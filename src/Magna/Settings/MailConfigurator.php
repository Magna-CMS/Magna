<?php

declare(strict_types=1);

namespace Magna\Settings;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Container\Container;
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
 * Deliberately shaped like ErpSettings::applyToConfig(): fold over the defaults
 * and stay silent when there is no database yet. Config is still the fallback,
 * so an install that configures SMTP in `.env` and never opens the page keeps
 * working exactly as before.
 *
 * Applied at boot and again on each Octane operation, because a worker outlives
 * the request that changed the settings and Octane restores the configuration it
 * snapshotted at boot before every one of them. See SettingsServiceProvider.
 */
final class MailConfigurator
{
    /**
     * Whether this process has already found the settings table.
     *
     * Memoised because apply() now runs on every Octane request: asking the
     * schema each time is a `SHOW TABLES` per request, for an answer that only
     * ever changes once in a process's life. Only the affirmative is kept — a
     * worker that started before the first migration must keep looking.
     *
     * Static because the instance is not: each Octane operation resolves this
     * from its own sandbox container, so a per-instance flag would memoise
     * nothing. The subject really is the process, and a table that later goes
     * missing still fails safely — the read below is inside the try.
     */
    private static bool $settingsTableFound = false;

    public function __construct(
        private readonly Config $config,
        private readonly Container $app,
    ) {}

    /**
     * Folds the stored settings over the config, and drops any mailer that was
     * built from the values they replace.
     *
     * The second half is what makes this safe to call more than once. MailManager
     * caches the mailer it builds, so under a long-running server an administrator
     * who corrected a password would have gone on sending through the transport
     * built from the wrong one.
     */
    public function apply(): void
    {
        $before = $this->fingerprint();

        $this->applyToConfig();

        if ($before !== $this->fingerprint()) {
            $this->forgetResolvedMailers();
        }
    }

    private function applyToConfig(): void
    {
        try {
            // Before the first migration, during `migrate:fresh`, or with no
            // database at all — the config file's values are exactly right and
            // asking for the table would only throw.
            if (! self::$settingsTableFound) {
                if (! Schema::hasTable('settings')) {
                    return;
                }

                self::$settingsTableFound = true;
            }

            $settings = MailSettings::get();
        } catch (Throwable) {
            return;
        }

        $mailer = $settings->driver === '' ? 'smtp' : $settings->driver;

        /*
         * An unconfigured install still holds the class defaults. Writing those
         * over a working `.env` would break a site that never used the page, so
         * the placeholder host is treated as "nothing was configured here".
         *
         * Asked only of the drivers that have a host. An API driver does not:
         * Resend, SES, Mailgun and Postmark are addressed by token alone, and
         * `host` sitting at its untouched default says nothing about whether
         * they were configured. This used to be checked before the driver was
         * read, so an administrator who picked Resend, pasted the key and
         * saved — never touching a host they had correctly been told nothing
         * about — got a page reporting success, `mail.default` still on SMTP,
         * and mail going to localhost:25. The one field that could have saved
         * them was the one the driver made irrelevant.
         */
        if (self::needsHost($mailer) && ($settings->host === '' || $settings->host === 'localhost')) {
            $this->applyFrom($settings);

            return;
        }

        /*
         * A driver this installation cannot build is worse than no choice at
         * all: `mail.default` would point at a transport whose package is
         * absent, and every send would throw where the previous setting had
         * been working. The page only offers what is installed (see
         * MailTransports), but a value can outlive the package that justified
         * it — a settings row written before a `composer remove`, or a database
         * copied to a leaner server. Falling back to SMTP keeps such a site
         * sending.
         */
        if (! MailTransports::available($mailer)) {
            $mailer = 'smtp';
        }

        $this->config->set('mail.default', $mailer);

        // One builder per transport. The drivers with no builder (log, array,
        // sendmail) are selected by name alone, and writing credentials into
        // them would be meaningless rather than harmless.
        match ($mailer) {
            'smtp' => $this->configureSmtp($settings),
            'ses' => $this->configureSes($settings),
            'mailgun' => $this->configureMailgun($settings),
            'resend' => $this->configureResend($settings),
            'postmark' => $this->configurePostmark($settings),
            default => null,
        };

        $this->applyFrom($settings);
    }

    /** The one transport addressed by host and credentials. */
    private function configureSmtp(MailSettings $settings): void
    {
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

    /*
     * The API drivers below authenticate through `services`, not through the
     * mailer entry, which is why host and port say nothing about them.
     */

    private function configureSes(MailSettings $settings): void
    {
        $this->config->set('services.ses.key', $settings->ses_key);
        $this->config->set('services.ses.secret', $settings->ses_secret);
        $this->config->set('services.ses.region', $settings->ses_region);
    }

    private function configureMailgun(MailSettings $settings): void
    {
        $this->config->set('services.mailgun.domain', $settings->mailgun_domain);
        $this->config->set('services.mailgun.secret', $settings->mailgun_secret);
        $this->config->set('services.mailgun.endpoint', $settings->mailgun_endpoint);

        // config/mail.php carries no `mailgun` entry — the driver was
        // offered by the page and defined nowhere, so choosing it failed
        // with `Mailer [mailgun] is not defined` before any credential was
        // even read. Declared here so the mailer exists wherever the
        // transport package does.
        $this->config->set('mail.mailers.mailgun.transport', 'mailgun');
    }

    private function configureResend(MailSettings $settings): void
    {
        $this->config->set('services.resend.key', $settings->resend_key);
    }

    private function configurePostmark(MailSettings $settings): void
    {
        // `token` first, `key` as the fallback — the order MailManager
        // reads them in, and config/services.php ships the second name.
        $this->config->set('services.postmark.token', $settings->postmark_token);
    }

    /**
     * Whether this driver is addressed by host and port at all.
     *
     * SMTP is, and sendmail takes a local binary path rather than either. The
     * API drivers take a token and nothing else, so an untouched host says
     * nothing about whether they were set up.
     */
    private static function needsHost(string $mailer): bool
    {
        return $mailer === 'smtp';
    }

    /**
     * A cheap stand-in for "the mail configuration as it stands right now".
     *
     * Compared either side of a fold so the manager is only disturbed when a
     * value genuinely moved. Under Octane this runs per request, and rebuilding
     * a transport on every one of them to discover nothing had changed would be
     * a poor trade for a setting an administrator edits twice a year.
     */
    private function fingerprint(): string
    {
        return md5(serialize([
            $this->config->get('mail'),
            $this->config->get('services.ses'),
            $this->config->get('services.mailgun'),
            $this->config->get('services.resend'),
            $this->config->get('services.postmark'),
        ]));
    }

    /**
     * Discards mailers built from the previous configuration.
     *
     * Asked of the container only when something has already resolved the
     * manager: at boot nothing has, and resolving it here to throw its contents
     * away would build it for no reason.
     */
    private function forgetResolvedMailers(): void
    {
        if (! $this->app->resolved('mail.manager')) {
            return;
        }

        $this->app->make('mail.manager')->forgetMailers();
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
