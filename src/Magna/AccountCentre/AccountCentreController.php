<?php

declare(strict_types=1);

namespace Magna\AccountCentre;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Magna\Admin\Pages\AccountCentrePage;
use Magna\Marketplace\Marketplace;
use Magna\Support\InstallFingerprint;

/**
 * The core-side legs of the connect handshake — see
 * docs/account-centre-plan.md for the full sequence. connect() sends the
 * browser out to managemagna.jrstudios.dev; callback() receives it back with
 * a one-time code and exchanges it server-to-server for a bearer token,
 * which is the only thing ever stored locally.
 */
class AccountCentreController
{
    private const SESSION_STATE_KEY = 'magna_account_centre.state';

    /** Matches the providers actually linked from account-centre.blade.php. */
    private const ALLOWED_PROVIDERS = ['google', 'github', 'microsoft'];

    public function connect(Request $request, string $provider): RedirectResponse
    {
        abort_unless(auth()->user()?->can('settings.manage') ?? false, 403);
        abort_unless(in_array($provider, self::ALLOWED_PROVIDERS, true), 404);

        $state = Str::random(40);
        $request->session()->put(self::SESSION_STATE_KEY, $state);

        $query = http_build_query([
            'site_url' => rtrim($this->configuredAppUrl(), '/'),
            'callback' => url('/account-centre/callback'),
            'state' => $state,
        ]);

        return redirect(Marketplace::WEB_BASE."/account/connect/{$provider}?{$query}");
    }

    public function callback(Request $request, AccountCentreClient $client): RedirectResponse
    {
        abort_unless(auth()->user()?->can('settings.manage') ?? false, 403);

        $expectedState = $request->session()->pull(self::SESSION_STATE_KEY);
        $accountPageUrl = AccountCentrePage::getUrl();

        if ($request->query('error') !== null) {
            return redirect($accountPageUrl)->with('account_centre_error', 'The connection attempt failed. Please try again.');
        }

        $state = $request->query('state');
        $code = $request->query('code');

        if (! is_string($expectedState) || ! is_string($state) || ! hash_equals($expectedState, $state)) {
            return redirect($accountPageUrl)->with('account_centre_error', 'The connection attempt could not be verified. Please try again.');
        }

        if (! is_string($code) || $code === '') {
            return redirect($accountPageUrl)->with('account_centre_error', 'The connection attempt is missing its code. Please try again.');
        }

        $appName = config('app.name');

        $result = $client->exchange(
            code: $code,
            siteUrl: rtrim($this->configuredAppUrl(), '/'),
            fingerprint: InstallFingerprint::derive(),
            siteLabel: is_string($appName) && $appName !== '' ? $appName : null,
        );

        if ($result === null) {
            return redirect($accountPageUrl)->with('account_centre_error', "Couldn't complete the connection — Update Manager did not respond as expected.");
        }

        $settings = AccountCentreSettings::get();
        $settings->connected = true;
        $settings->accountName = $result['account']['name'];
        $settings->accountEmail = $result['account']['email'];
        $settings->token = $result['token'];
        $settings->connectedAt = now()->toAtomString();
        $settings->save();

        return redirect($accountPageUrl)->with('account_centre_status', 'Magna Account connected.');
    }

    public function disconnect(AccountCentreClient $client): RedirectResponse
    {
        abort_unless(auth()->user()?->can('settings.manage') ?? false, 403);

        $settings = AccountCentreSettings::get();
        $accountPageUrl = AccountCentrePage::getUrl();

        if ($settings->token !== null) {
            $client->disconnect($settings->token);
        }

        $settings->connected = false;
        $settings->accountName = null;
        $settings->accountEmail = null;
        $settings->token = null;
        $settings->connectedAt = null;
        $settings->save();

        return redirect($accountPageUrl)->with('account_centre_status', 'Magna Account disconnected.');
    }

    private function configuredAppUrl(): string
    {
        $url = config('app.url');

        return is_string($url) ? $url : '';
    }
}
