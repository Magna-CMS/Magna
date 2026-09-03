<?php

declare(strict_types=1);

namespace Magna\Settings;

use Composer\InstalledVersions;

/**
 * Which mail drivers this installation can actually use.
 *
 * The settings page used to offer Amazon SES and Mailgun unconditionally.
 * Neither works on a stock install: SES needs `aws/aws-sdk-php` and Mailgun
 * needs `symfony/mailgun-mailer`, and Magna ships from a zip to servers with no
 * Composer, so on most installs those packages are simply absent. Picking SES
 * threw on the missing client; picking Mailgun failed earlier still with
 * `Mailer [mailgun] is not defined`, because there was no such mailer in
 * config/mail.php at all. Two of the six choices could not send anything, and
 * the page gave no hint of it.
 *
 * So the list is built from what is installed. Feature detection of an optional
 * vendor library — the same thing PdfExporter does for dompdf, and not the
 * plugin-sniffing the architecture rules reject.
 */
final class MailTransports
{
    /**
     * The drivers that need nothing beyond the framework.
     *
     * @var array<string, string>
     */
    private const ALWAYS = [
        'smtp' => 'SMTP',
        'sendmail' => 'Sendmail',
        'log' => 'Log (development)',
        'array' => 'Array (testing)',
    ];

    /**
     * The rest, each against the Composer package its transport needs.
     *
     * Asked of Composer rather than by probing a vendor class name: the real
     * question is whether the package is installed, and `InstalledVersions`
     * answers exactly that on a zip install too — the manifest ships with the
     * vendor directory whether or not Composer ever ran on the server.
     *
     * @var array<string, array{label: string, package: string}>
     */
    private const OPTIONAL = [
        'ses' => [
            'label' => 'Amazon SES',
            'package' => 'aws/aws-sdk-php',
        ],
        'mailgun' => [
            'label' => 'Mailgun',
            'package' => 'symfony/mailgun-mailer',
        ],
        'postmark' => [
            'label' => 'Postmark',
            'package' => 'symfony/postmark-mailer',
        ],
        'resend' => [
            'label' => 'Resend',
            'package' => 'resend/resend-laravel',
        ],
    ];

    /**
     * Driver value => label, for the settings page.
     *
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = self::ALWAYS;

        foreach (self::OPTIONAL as $driver => $transport) {
            if (InstalledVersions::isInstalled($transport['package'])) {
                $options[$driver] = $transport['label'];
            }
        }

        return $options;
    }

    /** Whether this driver can be selected on this installation. */
    public static function available(string $driver): bool
    {
        return array_key_exists($driver, self::options());
    }

    /**
     * What to install to unlock a driver that is not here yet.
     *
     * Shown under the picker rather than hidden in a log: an administrator who
     * wanted SES should be told it takes one Composer package, not left to
     * wonder why the option vanished.
     */
    public static function packageFor(string $driver): ?string
    {
        return self::OPTIONAL[$driver]['package'] ?? null;
    }
}
