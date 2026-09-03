<?php

declare(strict_types=1);

namespace Magna\Settings;

use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Proves the mail settings actually send something.
 *
 * There was no way to find out. An administrator filled in a host, a port and a
 * password, the page said "Mail settings saved", and whether any of it worked
 * was answered days later by somebody asking why they never got an approval
 * email. Every failure mode — a wrong password, a blocked port, a host that
 * does not resolve — looked identical from the panel, which is to say invisible.
 *
 * Sent inline rather than queued, deliberately: the whole point is to see the
 * transport's own error, and a queued job would take the failure to a log the
 * person configuring it is not reading.
 */
final class MailTester
{
    public function __construct(private readonly MailConfigurator $configurator) {}

    /**
     * Sends a test message and reports what happened.
     *
     * The stored settings are folded over the config first. Without that the
     * test would exercise whatever was read at boot — so an administrator who
     * corrected a password and pressed the button would be told the old one
     * still fails.
     *
     * @return array{ok: bool, message: string}
     */
    public function send(string $to): array
    {
        $this->configurator->apply();

        $mailer = config('mail.default');
        $mailer = is_string($mailer) ? $mailer : '';

        if ($mailer === 'log' || $mailer === 'array') {
            return [
                'ok' => false,
                'message' => sprintf(
                    'The mail driver is "%s", which does not send anything. Pick SMTP or a mail service first.',
                    $mailer,
                ),
            ];
        }

        try {
            Mail::raw(
                "This is a test message from Magna.\n\nIf you are reading it, the mail settings on this site work.",
                static function (Message $message) use ($to): void {
                    $message->to($to)->subject('Magna test email');
                },
            );
        } catch (Throwable $exception) {
            // The transport's own words. "Failed to send" tells nobody whether
            // the password is wrong or the port is blocked, and those have
            // completely different fixes.
            return [
                'ok' => false,
                'message' => $exception->getMessage(),
            ];
        }

        return [
            'ok' => true,
            'message' => sprintf('Sent to %s. If it does not arrive, check the spam folder and the sending domain\'s SPF and DKIM records.', $to),
        ];
    }
}
