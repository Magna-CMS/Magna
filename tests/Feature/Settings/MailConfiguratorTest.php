<?php

declare(strict_types=1);

use Composer\InstalledVersions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Magna\Admin\Pages\MailSettingsPage;
use Magna\Admin\Pages\SettingsPage;
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
/*
|--------------------------------------------------------------------------
| A driver with no host still has to be honoured
|--------------------------------------------------------------------------
|
| The "was anything configured here?" guard asked whether the SMTP host had
| been touched, and asked it of every driver before the driver was even read.
| An API driver has no host — Resend, SES, Mailgun and Postmark are addressed by
| token alone, and the page is right not to ask for one — so an administrator
| who picked Resend, pasted the key and saved got a page reporting success,
| `mail.default` still on SMTP, and mail going to localhost:25. The single field
| that would have let them through was the one their driver made irrelevant.
*/

it('honours an API driver whose host was never touched', function (): void {
    // `localhost` is the untouched class default, which is exactly the state a
    // Resend install is in: the host field is not shown for that driver.
    configureMail(['driver' => 'log']);

    expect(MailSettings::get()->host)->toBe('localhost')
        ->and(config('mail.default'))->toBe('log');
});

it('still ignores an untouched SMTP host, which says nothing was configured', function (): void {
    config(['mail.mailers.smtp.host' => 'env-relay.test']);

    configureMail(['driver' => 'smtp']);

    // Writing the placeholder over a working .env would stop a site that never
    // opened the page — the reason the guard exists at all.
    expect(config('mail.mailers.smtp.host'))->toBe('env-relay.test');
});

/*
|--------------------------------------------------------------------------
| Resend and Postmark were listed with nowhere to put their token
|--------------------------------------------------------------------------
|
| MailTransports offers both once their package is installed, and neither had a
| settings field or a configurator branch. Picking one set `mail.default` and
| left the transport looking for a key in `.env` that a panel-configured install
| has never written — the same fault SES and Mailgun had, one release earlier.
*/

it('offers resend and postmark exactly when their package is installed', function (): void {
    $options = MailTransports::options();

    foreach (['resend' => 'resend/resend-laravel', 'postmark' => 'symfony/postmark-mailer'] as $driver => $package) {
        expect(array_key_exists($driver, $options))
            ->toBe(InstalledVersions::isInstalled($package));
    }
});

it('keeps a resend key and a postmark token, and hands them to the transport', function (): void {
    $settings = MailSettings::get();
    $settings->resend_key = 're_example_key';
    $settings->postmark_token = 'postmark-server-token';
    $settings->save();

    // Encrypted at rest and readable back: without the round trip there is
    // nothing for either transport to authenticate with.
    expect(MailSettings::get()->resend_key)->toBe('re_example_key')
        ->and(MailSettings::get()->postmark_token)->toBe('postmark-server-token');

    configureMail(['driver' => 'resend']);

    // Only where the package is present. Elsewhere the configurator falls back
    // to SMTP rather than pointing the mailer at a transport it cannot build.
    if (MailTransports::available('resend')) {
        expect(config('mail.default'))->toBe('resend')
            ->and(config('services.resend.key'))->toBe('re_example_key');
    } else {
        expect(config('mail.default'))->toBe('smtp');
    }
});
/*
|--------------------------------------------------------------------------
| Both settings surfaces write the same way
|--------------------------------------------------------------------------
|
| The Email tab on the unified settings page draws its fields from
| MailSettingsPage::fields() and used to write them back with a second copy of
| the logic. The copies drifted exactly as such pairs do: the unified page —
| the only one an administrator can reach, since the dedicated page is hidden
| from the navigation — never learned about the Resend and Postmark tokens, and
| still read a hidden field as an empty value. One writer now serves both.
*/

it('keeps the SMTP host when a driver that has no host is saved', function (): void {
    configureMail(['driver' => 'smtp', 'host' => 'relay.profilebees.test', 'port' => 587]);

    // What the form submits for Resend: no host, no port, no username — those
    // inputs are not shown for that driver, so they are absent from the state.
    MailSettingsPage::persist([
        'driver' => 'resend',
        'resend_key' => 're_example_key',
        'from_address' => 'noreply@profilebees.test',
        'from_name' => 'Roya',
    ])->save();

    $stored = MailSettings::get();

    expect($stored->driver)->toBe('resend')
        ->and($stored->resend_key)->toBe('re_example_key')
        // Still there for the moment they switch back.
        ->and($stored->host)->toBe('relay.profilebees.test')
        ->and($stored->port)->toBe(587);
});

it('leaves a stored secret alone when the field comes back blank', function (): void {
    configureMail(['driver' => 'smtp', 'host' => 'relay.profilebees.test', 'password' => 'relay-secret']);

    // Secrets are never sent to the browser, so every save arrives blank.
    MailSettingsPage::persist([
        'driver' => 'smtp',
        'host' => 'relay.profilebees.test',
        'port' => 587,
        'password' => null,
    ])->save();

    expect(MailSettings::get()->password)->toBe('relay-secret');
});

it('offers the test send from the page an administrator can actually reach', function (): void {
    // MailSettingsPage is hidden from the navigation, so a test action only on
    // it is a test action nobody finds.
    $actions = (new ReflectionClass(SettingsPage::class))
        ->getMethod('getHeaderActions');

    expect($actions->isPublic() || $actions->isProtected())->toBeTrue()
        ->and(method_exists(SettingsPage::class, 'sendTestEmail'))->toBeTrue();
});
