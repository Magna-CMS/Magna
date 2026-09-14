<?php

declare(strict_types=1);

namespace Magna\AccountCentre;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Magna\Admin\Pages\AccountCentrePage;
use Magna\Marketplace\Marketplace;
use Magna\Support\InstallFingerprint;
use Magna\Support\OriginResolver;

/**
 * The core-side legs of the connect handshake — see
 * docs/account-centre-plan.md for the full sequence. connect() sends the
 * browser out to managemagna.jrstudios.dev; callback() receives it back with
 * a one-time code and exchanges it server-to-server for a bearer token,
 * which is the only thing ever stored locally.
 */
class AccountCentreController extends Controller
{
    private const SESSION_STATE_KEY = 'magna_account_centre.state';

    /** Matches the providers actually linked from account-centre.blade.php. */
    private const ALLOWED_PROVIDERS = ['google', 'github', 'microsoft'];

    public function connect(Request $request, string $provider): RedirectResponse
    {
        Gate::authorize('settings.manage');
        abort_unless(in_array($provider, self::ALLOWED_PROVIDERS, true), 404);

        $siteUrl = $this->handshakeOrigin($request);

        $state = Str::random(40);
        $request->session()->put(self::SESSION_STATE_KEY, $state);

        $query = http_build_query([
            'site_url' => $siteUrl,
            'callback' => $siteUrl.'/account-centre/callback',
            'state' => $state,
        ]);

        return redirect(Marketplace::WEB_BASE."/account/connect/{$provider}?{$query}");
    }

    public function callback(Request $request, AccountCentreClient $client): RedirectResponse
    {
        Gate::authorize('settings.manage');

        $expectedState = $request->session()->pull(self::SESSION_STATE_KEY);
        $accountPageUrl = AccountCentrePage::getUrl();

        $error = $request->query('error');
        if ($error !== null) {
            // Update Manager names the reason, and each one needs a
            // different thing done about it. Reporting them all as "the
            // connection attempt failed" sent people round the same loop:
            // retrying is the right answer for one of these and useless for
            // the rest.
            //
            // Each message says only what the person reading it can act on.
            // This is a panel a customer administers, so nothing here names
            // our internals, points at a server log they cannot read, or
            // assigns blame between the two sides — when the fault is ours,
            // the detail belongs in Update Manager's own log, where support
            // can reach it.
            return redirect($accountPageUrl)->with('account_centre_error', match ($error) {
                'provider_unavailable' => 'That sign-in method is not available at the moment. Please try another one.',
                'no_email' => 'That account did not share an email address, and a Magna Account needs one. Try a different sign-in method, or make your email address public on that provider.',
                'email_in_use' => 'A Magna Account already exists for that email address, registered with a different sign-in method. Please sign in with the method you used originally.',
                default => 'The connection attempt did not complete. Please try again.',
            });
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
            // Same derivation connect() used. The browser has just been sent
            // back to this origin, so this is the value the code was issued
            // for — and Update Manager rejects the exchange if it is not.
            siteUrl: $this->handshakeOrigin($request),
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
        Gate::authorize('settings.manage');

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

    /**
     * The origin the whole handshake is conducted on: scheme, host and port of
     * the request the administrator is actually making.
     *
     * Update Manager refuses a connect whose callback is not same-origin with
     * its site_url — that guard is what stops a caller pointing the exchange
     * code at somewhere else entirely, so it is not going anywhere. site_url
     * used to be read from APP_URL while the callback was built by url(),
     * which follows the browser. On an install reached at a second address —
     * a LAN IP, a staging alias, a panel on its own hostname — the two
     * disagreed, the guard refused, and the browser landed on the Magna
     * Account sign-in page with nothing to explain it.
     *
     * Using the browsing origin for both is what makes it work rather than
     * merely agree. It is the one origin that is definitely reachable (the
     * administrator is on it) and the one whose session holds the state nonce
     * that has to come back — APP_URL is neither of those things when the
     * panel is somewhere else. It costs nothing in identity terms: a site is
     * recognised by its fingerprint, derived from APP_KEY, and site_url is
     * label text in the account's site list.
     *
     * Both legs must agree on it, so callback() derives the site_url it sends
     * to the exchange endpoint from here too — Update Manager checks that the
     * value presented at exchange matches the one the code was issued for.
     *
     * Bounded by TrustHosts (see OriginResolver): "the origin the browser is
     * on" can never be a host this install does not trust.
     */
    private function handshakeOrigin(Request $request): string
    {
        return OriginResolver::requestOrigin($request);
    }
}
