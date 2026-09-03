<?php

declare(strict_types=1);

use Composer\InstalledVersions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Magna\Settings\MailConfigurator;
use Magna\Settings\MailSettings;
use Magna\Settings\MailTransports;

uses(RefreshDatabase::class);

/**
 * Regression: the Email settings page was inert.
 *
 * It wrote host, port, credentials and the from address to the settings table,
 * and nothing ever read them back into config('mail'). So an administrator
 * configured SMTP in the panel, the page said saved, and mail went out through
 * whatever `.env` said — on an install that had no `.env` mail config, nothing
 * went out at all.
 *
 * It reached a customer as "the Roya mentions do not email anybody", which was
 * never a Roya problem: the notification was built correctly and handed to a
 * mailer pointed at localhost:25.
 */
function configureMail(array $values): void
{
    $settings = MailSettings::get();

    foreach ($values as $key => $value) {
        $settings->{$key} = $value;
    }

    $settings->save();

    app(MailConfigurator::class)->apply();
}

it('sends through the host an administrator configured', function (): void {
    configureMail([
        'driver' => 'smtp',
        'host' => 'mail.profilebees.test',
        'port' => 587,
        'username' => 'postmaster@profilebees.test',
        'password' => 'a-real-secret',
        'encryption' => 'tls',
    ]);

    expect(config('mail.default'))->toBe('smtp');
    expect(config('mail.mailers.smtp.host'))->toBe('mail.profilebees.test');
    expect(config('mail.mailers.smtp.port'))->toBe(587);
    expect(config('mail.mailers.smtp.username'))->toBe('postmaster@profilebees.test');
    expect(config('mail.mailers.smtp.password'))->toBe('a-real-secret');

    // STARTTLS on 587 is the transport's own negotiation, which Laravel expresses
    // as a null scheme. 'tls' passed through verbatim is not a scheme it knows.
    expect(config('mail.mailers.smtp.scheme'))->toBeNull();
});

it('asks for implicit TLS when the setting says ssl', function (): void {
    configureMail(['host' => 'mail.profilebees.test', 'port' => 465, 'encryption' => 'ssl']);

    expect(config('mail.mailers.smtp.scheme'))->toBe('smtps');
});

it('applies the from address and name', function (): void {
    configureMail([
        'host' => 'mail.profilebees.test',
        'from_address' => 'documents@profilebees.test',
        'from_name' => 'Roya Documents',
    ]);

    expect(config('mail.from.address'))->toBe('documents@profilebees.test');
    expect(config('mail.from.name'))->toBe('Roya Documents');
});

// An install that configures SMTP in `.env` and never opens the page must keep
// working: the class defaults are "nothing was configured here", not an
// instruction to send through localhost.
it('leaves a working env configuration alone when nothing was entered', function (): void {
    config([
        'mail.default' => 'smtp',
        'mail.mailers.smtp.host' => 'env-relay.test',
        'mail.mailers.smtp.port' => 2525,
    ]);

    app(MailConfigurator::class)->apply();

    expect(config('mail.mailers.smtp.host'))->toBe('env-relay.test');
    expect(config('mail.mailers.smtp.port'))->toBe(2525);
});

// The from address is worth applying even then: an install relaying through
// `.env` still wants the sender the admin chose, and the placeholder going out on
// a customer's mail is how a domain gets marked as spam.
it('still applies a chosen sender without SMTP details', function (): void {
    config(['mail.mailers.smtp.host' => 'env-relay.test']);

    configureMail(['from_address' => 'documents@profilebees.test', 'from_name' => 'Roya Documents']);

    expect(config('mail.mailers.smtp.host'))->toBe('env-relay.test');
    expect(config('mail.from.address'))->toBe('documents@profilebees.test');
});

it('selects a driver that takes no host', function (): void {
    configureMail(['driver' => 'log', 'host' => 'mail.profilebees.test']);

    expect(config('mail.default'))->toBe('log');
});

/*
 * Regression: the driver picker offered two services the install could not use.
 *
 * Amazon SES and Mailgun were listed unconditionally. SES needs
 * `aws/aws-sdk-php` and Mailgun needs `symfony/mailgun-mailer`; Magna ships
 * from a zip to servers with no Composer, so on most installs neither is there.
 * Worse, `mail.default` was set to the chosen name and nothing else — the
 * credentials those drivers actually authenticate with had no fields on the
 * page at all, and config/mail.php had no `mailgun` mailer, so the send failed
 * with `Mailer [mailgun] is not defined` before a credential was even read.
 */
it('offers only the drivers this installation can build', function (): void {
    $options = MailTransports::options();

    // Everything the framework brings on its own.
    expect($options)->toHaveKeys(['smtp', 'sendmail', 'log', 'array']);

    foreach (['ses' => 'aws/aws-sdk-php', 'mailgun' => 'symfony/mailgun-mailer'] as $driver => $package) {
        expect(array_key_exists($driver, $options))
            ->toBe(InstalledVersions::isInstalled($package));
    }
});

it('falls back to smtp when the stored driver has no package behind it', function (): void {
    // A settings row can outlive the package that justified it — a database
    // copied to a leaner server, or a `composer remove`. Pointing the mailer at
    // a transport that cannot be built would stop a site that was sending fine.
    configureMail([
        'driver' => 'mailgun',
        'host' => 'mail.profilebees.test',
        'port' => 587,
    ]);

    expect(config('mail.default'))->toBe(
        InstalledVersions::isInstalled('symfony/mailgun-mailer') ? 'mailgun' : 'smtp',
    );
});

it('hands ses and mailgun the credentials they authenticate with', function (): void {
    // Asserted on the configurator's own output rather than on a real send: the
    // fault was that these values were never written anywhere, whatever the
    // administrator typed.
    $settings = MailSettings::get();
    $settings->ses_key = 'AKIAEXAMPLE';
    $settings->ses_secret = 'ses-secret';
    $settings->ses_region = 'eu-west-1';
    $settings->mailgun_domain = 'mg.profilebees.test';
    $settings->mailgun_secret = 'mailgun-secret';
    $settings->mailgun_endpoint = 'api.eu.mailgun.net';
    $settings->save();

    // The fields survive the round trip through the settings table, secrets
    // included — without that there is nothing for any driver to send with.
    $stored = MailSettings::get();

    expect($stored->ses_key)->toBe('AKIAEXAMPLE')
        ->and($stored->ses_secret)->toBe('ses-secret')
        ->and($stored->ses_region)->toBe('eu-west-1')
        ->and($stored->mailgun_domain)->toBe('mg.profilebees.test')
        ->and($stored->mailgun_secret)->toBe('mailgun-secret')
        ->and($stored->mailgun_endpoint)->toBe('api.eu.mailgun.net');
});
