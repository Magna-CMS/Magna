<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Magna\Settings\MailConfigurator;
use Magna\Settings\MailSettings;

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
