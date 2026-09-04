<?php

declare(strict_types=1);

namespace Magna\Settings;

use Magna\Settings\Attributes\Secret;

class MailSettings extends Settings
{
    public string $driver = 'smtp';

    public string $host = 'localhost';

    public int $port = 25;

    public ?string $username = null;

    #[Secret]
    public ?string $password = null;

    public ?string $encryption = null;

    public string $from_address = 'noreply@example.com';

    public string $from_name = 'Magna CMS';

    /*
     * Credentials for the API drivers.
     *
     * SES and Mailgun do not read host and port — they authenticate against
     * their own service config, which the page had no fields for. Selecting
     * either therefore set `mail.default` and nothing else, and the send failed
     * on credentials that were never asked for. Nullable throughout, because an
     * install using SMTP has no business being made to fill them in.
     */
    public ?string $ses_key = null;

    #[Secret]
    public ?string $ses_secret = null;

    public string $ses_region = 'us-east-1';

    public ?string $mailgun_domain = null;

    #[Secret]
    public ?string $mailgun_secret = null;

    /** `api.eu.mailgun.net` for a domain created in the EU region. */
    public string $mailgun_endpoint = 'api.mailgun.net';

    /*
     * Resend and Postmark authenticate with a single token apiece.
     *
     * Both were already in the driver list — MailTransports offers them once
     * their package is installed — with nowhere to put the token, so picking
     * either set `mail.default` and left the transport to look for a key in
     * `.env` that a panel-configured install has never written. The send then
     * failed on a credential the page had not asked for, which is the same bug
     * SES and Mailgun had above and the reason those fields exist.
     */
    #[Secret]
    public ?string $resend_key = null;

    #[Secret]
    public ?string $postmark_token = null;
}
