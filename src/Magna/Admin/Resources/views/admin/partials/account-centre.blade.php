{{-- Magna Account Centre.
     Layout follows the approved template; colours use Filament's own
     primary/slate scales so it inherits the panel's theme and dark mode
     rather than carrying its own toggle. --}}
<div class="space-y-8">

    @if (session('account_centre_status'))
        <div class="rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800 dark:border-emerald-500/20 dark:bg-emerald-500/10 dark:text-emerald-300">
            {{ session('account_centre_status') }}
        </div>
    @endif
    @if (session('account_centre_error'))
        <div class="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800 dark:border-red-500/20 dark:bg-red-500/10 dark:text-red-300">
            {{ session('account_centre_error') }}
        </div>
    @endif

    @if ($connected)
        {{-- Section 1: account + connected installs --}}
        <div class="grid grid-cols-1 gap-6 md:grid-cols-3">
            <div class="flex flex-col justify-between rounded-xl border border-gray-200 bg-white p-6 shadow-sm dark:border-white/10 dark:bg-white/5">
                <div>
                    <div class="mb-4 flex items-center gap-3">
                        <div class="flex h-12 w-12 shrink-0 items-center justify-center rounded-full bg-primary-600 text-lg font-bold text-white">
                            {{ \Illuminate\Support\Str::of($accountName ?? '?')->explode(' ')->take(2)->map(fn ($p) => \Illuminate\Support\Str::substr($p, 0, 1))->implode('') }}
                        </div>
                        <div class="min-w-0">
                            <h2 class="truncate font-semibold text-gray-900 dark:text-white">{{ $accountName }}</h2>
                            <p class="truncate text-xs text-gray-500 dark:text-gray-400">{{ $accountEmail }}</p>
                        </div>
                    </div>
                    @if ($connectedAt)
                        <div class="mb-4 inline-flex items-center gap-1.5 rounded-full border border-emerald-200 bg-emerald-50 px-2.5 py-1 text-xs font-medium text-emerald-700 dark:border-emerald-500/20 dark:bg-emerald-500/10 dark:text-emerald-300">
                            <span class="h-1.5 w-1.5 rounded-full bg-emerald-500"></span>
                            Connected {{ \Illuminate\Support\Carbon::parse($connectedAt)->diffForHumans() }}
                        </div>
                    @endif
                </div>
                <form method="POST" action="{{ route('account-centre.disconnect') }}">
                    @csrf
                    <button type="submit" class="w-full rounded-lg border border-rose-200 px-4 py-2 text-center text-sm font-medium text-rose-600 transition-colors hover:bg-rose-50 dark:border-rose-900/50 dark:text-rose-400 dark:hover:bg-rose-950/30">
                        Disconnect Account
                    </button>
                </form>
            </div>

            <div class="rounded-xl border border-gray-200 bg-white p-6 shadow-sm md:col-span-2 dark:border-white/10 dark:bg-white/5">
                <h3 class="mb-1 font-semibold text-gray-900 dark:text-white">Other Magna CMS Installs</h3>
                <p class="mb-6 text-xs text-gray-500 dark:text-gray-400">Other website domains associated with this account.</p>

                @if (count($otherSites) === 0)
                    <div class="rounded-xl border-2 border-dashed border-gray-200 p-6 text-center dark:border-white/10">
                        <x-filament::icon icon="heroicon-o-globe-alt" class="mx-auto mb-2 h-8 w-8 text-gray-400 dark:text-gray-500" />
                        <p class="text-sm font-medium text-gray-500 dark:text-gray-400">No other sites are connected to this account yet.</p>
                    </div>
                @else
                    <ul class="divide-y divide-gray-100 rounded-xl border border-gray-200 dark:divide-white/5 dark:border-white/10">
                        @foreach ($otherSites as $site)
                            <li class="flex items-center justify-between px-4 py-3 text-sm">
                                <span class="truncate font-medium text-gray-800 dark:text-gray-100">{{ $site['site_label'] ?? $site['site_url'] }}</span>
                                <span class="shrink-0 text-xs text-gray-500 dark:text-gray-400">{{ $site['last_seen_at'] ? \Illuminate\Support\Carbon::parse($site['last_seen_at'])->diffForHumans() : 'never seen' }}</span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
        </div>

        {{-- Section 2: licences --}}
        @php
            $activeCount = collect($licenses)->whereIn('status', ['active', 'trial'])->count();
        @endphp
        <div class="rounded-xl border border-gray-200 bg-white p-6 shadow-sm dark:border-white/10 dark:bg-white/5">
            <div class="mb-6 flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h2 class="text-lg font-bold text-gray-900 dark:text-white">Your Products &amp; Licenses</h2>
                    <p class="text-xs text-gray-500 dark:text-gray-400">Active purchases, lifetime licences, and renewals.</p>
                </div>
                <div class="flex items-center gap-3">
                    <span class="rounded-full border border-primary-200 bg-primary-50 px-2.5 py-1 text-xs font-semibold text-primary-700 dark:border-primary-500/20 dark:bg-primary-500/10 dark:text-primary-400">
                        {{ $activeCount }} Active {{ \Illuminate\Support\Str::plural('Item', $activeCount) }}
                    </span>
                    {{-- The page itself is readable on settings.view, but every
                         control that touches the wallet needs licensing.manage
                         — showing a button that can only 403 is worse than not
                         showing it. --}}
                    @can('licensing.manage')
                        <form method="POST" action="{{ route('licensing.verify') }}">
                            @csrf
                            <button type="submit" class="text-xs font-medium text-gray-500 hover:text-gray-800 dark:hover:text-gray-200">Re-check now</button>
                        </form>
                    @endcan
                </div>
            </div>

            {{-- One field for every kind of key. The controller recognises a
                 Magna key by its shape and treats anything else as an
                 external purchase code, so the customer never has to know
                 which queue their key belongs in. --}}
            @can('licensing.manage')
                <form method="POST" action="{{ route('licensing.redeem') }}" class="mb-6 flex flex-wrap gap-2">
                    @csrf
                    <input type="text" name="key" required placeholder="MAGNA-XXXX-XXXX-XXXX-XXXX-XXXX-XXXX"
                           class="min-w-0 flex-1 rounded-lg border-gray-300 font-mono text-sm shadow-sm placeholder:text-gray-400 focus:border-primary-500 focus:ring-primary-500 dark:border-white/10 dark:bg-white/5 dark:text-gray-100 dark:placeholder:text-gray-500">
                    <button type="submit" class="shrink-0 rounded-lg bg-primary-600 px-4 py-2 text-sm font-medium text-white shadow-sm transition-colors hover:bg-primary-700">
                        Add licence
                    </button>
                </form>
            @endcan

            @if (count($licenses) === 0)
                <div class="rounded-xl border-2 border-dashed border-gray-200 p-8 text-center dark:border-white/10">
                    <p class="text-sm text-gray-500 dark:text-gray-400">No licences on this account yet. Paid plugins you buy appear here automatically.</p>
                </div>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full border-collapse text-left text-sm">
                        <thead>
                            <tr class="border-b border-gray-200 text-xs font-medium uppercase text-gray-500 dark:border-white/10 dark:text-gray-400">
                                <th class="pb-3">Product</th>
                                <th class="pb-3">Type</th>
                                <th class="pb-3">License Key</th>
                                <th class="pb-3">Status</th>
                                <th class="pb-3 text-right">Action</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                            @foreach ($licenses as $license)
                                @php
                                    $status = $license['status'] ?? 'unknown';
                                    $expiresAt = ($license['license_expires_at'] ?? null)
                                        ? \Illuminate\Support\Carbon::parse($license['license_expires_at']) : null;
                                    $daysLeft = $expiresAt?->isFuture() ? (int) ceil(now()->floatDiffInDays($expiresAt)) : null;

                                    [$statusLabel, $statusClass] = match (true) {
                                        $status === 'revoked' => ['Cancelled', 'bg-red-50 text-red-700 dark:bg-red-500/10 dark:text-red-300'],
                                        $status === 'suspended' => ['Suspended', 'bg-red-50 text-red-700 dark:bg-red-500/10 dark:text-red-300'],
                                        $status === 'expired' => ['Expired', 'bg-red-50 text-red-700 dark:bg-red-500/10 dark:text-red-300'],
                                        $status === 'past_due' => ['Payment overdue', 'bg-amber-50 text-amber-700 dark:bg-amber-500/10 dark:text-amber-300'],
                                        $status === 'trial' => ['Trial'.($daysLeft !== null ? " · {$daysLeft}d left" : ''), 'bg-sky-50 text-sky-700 dark:bg-sky-500/10 dark:text-sky-300'],
                                        $daysLeft !== null && $daysLeft <= 30 => ["Expiring in {$daysLeft} days", 'bg-amber-50 text-amber-700 dark:bg-amber-500/10 dark:text-amber-300'],
                                        $expiresAt === null => ['Lifetime License', 'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300'],
                                        default => ['Active until '.$expiresAt->format('d M Y'), 'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300'],
                                    };

                                    $isTheme = ($license['licensable_type'] ?? 'plugin') === 'theme';
                                    $activeHere = (bool) ($license['active_on_this_site'] ?? false);

                                    // A cancelled, suspended or expired key
                                    // cannot download anything — the licence
                                    // server refuses it — so offering "Install
                                    // here" only produced an error after the
                                    // click. Renew/redeem is the way back for
                                    // those, and both live elsewhere on this
                                    // page.
                                    $installable = ! in_array($status, ['revoked', 'suspended', 'expired'], true);

                                    // Only offer Update when a newer entitled
                                    // version actually exists for this product.
                                    $updateVersion = $productUpdates[$license['product_slug']] ?? null;

                                    // A live auto-debit mandate replaces the manual
                                    // Renew button entirely — the gateway charges on
                                    // its own, so offering Renew beside it would
                                    // double-bill. Once the mandate is cancelled or
                                    // dies, the manual path takes back over.
                                    $subscription = is_array($license['subscription'] ?? null) ? $license['subscription'] : null;
                                    $autoRenews = $subscription !== null
                                        && ($subscription['collection_method'] ?? '') === 'gateway'
                                        && in_array($subscription['status'] ?? '', ['active', 'past_due'], true)
                                        && ($subscription['cancelled_at'] ?? null) === null;
                                    $autoRenewsOn = $autoRenews && ($subscription['current_period_end'] ?? null)
                                        ? \Illuminate\Support\Carbon::parse($subscription['current_period_end'])->format('d M Y')
                                        : null;

                                    // Renewing is offered once a term licence is
                                    // inside its last month, and stays offered after
                                    // it lapses — an expired licence is precisely the
                                    // one someone came here to fix. Lifetime, revoked
                                    // and suspended keys are never renewable.
                                    $renewable = ! $autoRenews
                                        && $expiresAt !== null
                                        && ! in_array($status, ['revoked', 'suspended', 'trial'], true)
                                        && ($daysLeft === null || $daysLeft <= 30);
                                @endphp
                                <tr>
                                    <td class="py-4">
                                        <div class="flex items-center gap-3">
                                            <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded bg-primary-50 text-primary-600 dark:bg-primary-500/10 dark:text-primary-400">
                                                <x-filament::icon :icon="$isTheme ? 'heroicon-m-swatch' : 'heroicon-m-bolt'" class="h-4 w-4" />
                                            </div>
                                            <span class="font-semibold text-gray-900 dark:text-white">{{ $license['product_slug'] }}</span>
                                        </div>
                                    </td>
                                    <td class="py-4 text-gray-600 dark:text-gray-400">{{ $isTheme ? 'Theme' : 'Plugin' }}</td>
                                    <td class="py-4 font-mono text-xs text-gray-500 dark:text-gray-400">{{ $license['key'] }}</td>
                                    <td class="py-4">
                                        <span class="rounded-full px-2.5 py-0.5 text-xs font-medium {{ $statusClass }}">{{ $statusLabel }}</span>
                                    </td>
                                    <td class="py-4">
                                        <div class="flex items-center justify-end gap-3">
                                            @can('licensing.manage')
                                            @if ($autoRenews)
                                                <span class="text-xs text-gray-500 dark:text-gray-400">
                                                    Auto-renews{{ $autoRenewsOn !== null ? ' on '.$autoRenewsOn : '' }}
                                                </span>
                                                <button
                                                    type="button"
                                                    wire:click="cancelAutoRenew({{ (int) ($subscription['id'] ?? 0) }})"
                                                    wire:confirm="Stop auto-renew for this licence? It keeps working until the paid period ends, then renews manually."
                                                    wire:loading.attr="disabled"
                                                    class="text-xs font-semibold text-gray-500 transition-colors hover:text-rose-600 dark:text-gray-400 dark:hover:text-rose-400"
                                                >Cancel auto-renew</button>
                                            @endif
                                            @if ($renewable)
                                                <button
                                                    type="button"
                                                    wire:click="renew({{ (int) $license['id'] }})"
                                                    wire:loading.attr="disabled"
                                                    class="rounded-lg bg-primary-600 px-2.5 py-1 text-xs font-semibold text-white transition-colors hover:bg-primary-700 disabled:opacity-60"
                                                >Renew</button>
                                            @endif
                                            @if ($activeHere)
                                                {{-- A site can hold activations for more than one key of the
                                                     same product (an older one that was never released), and
                                                     the marketplace reports each of those rows as active here.
                                                     Updating through a cancelled key is refused, so only the
                                                     usable ones offer it; Release stays on every active row,
                                                     because releasing is how the stale activation is cleared. --}}
                                                @if ($updateVersion !== null && $installable)
                                                    <form method="POST" action="{{ route('licensing.update') }}">
                                                        @csrf
                                                        <input type="hidden" name="product_slug" value="{{ $license['product_slug'] }}">
                                                        <button type="submit" class="text-xs font-semibold text-primary-600 transition-colors hover:text-primary-700 dark:text-primary-400 dark:hover:text-primary-300">Update to {{ $updateVersion }}</button>
                                                    </form>
                                                @endif
                                                <form method="POST" action="{{ route('licensing.deactivate') }}"
                                                      onsubmit="return confirm('Release this licence from this site? The plugin is disabled here and the seat becomes free for another domain.');">
                                                    @csrf
                                                    <input type="hidden" name="product_slug" value="{{ $license['product_slug'] }}">
                                                    <button type="submit" class="text-xs font-semibold text-gray-500 transition-colors hover:text-rose-600 dark:text-gray-400 dark:hover:text-rose-400">Release</button>
                                                </form>
                                            @elseif ($installable)
                                                <form method="POST" action="{{ route('licensing.install') }}">
                                                    @csrf
                                                    <input type="hidden" name="license_id" value="{{ $license['id'] }}">
                                                    <input type="hidden" name="product_slug" value="{{ $license['product_slug'] }}">
                                                    <button type="submit" class="text-xs font-semibold text-primary-600 transition-colors hover:text-primary-700 dark:text-primary-400 dark:hover:text-primary-300">Install here</button>
                                                </form>
                                            @endif
                                            @endcan
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif

            @if (is_string($panel['refund_terms'] ?? null) && ($panel['refund_terms'] ?? '') !== '')
                <p class="mt-3 text-[11px] leading-snug text-gray-500 dark:text-gray-400">
                    <span class="font-medium text-gray-600 dark:text-gray-300">Refund policy:</span>
                    {{ $panel['refund_terms'] }}
                </p>
            @endif
        </div>

        {{-- Section 3: premium plugins. Operator-controlled: content, order
             and whether it appears at all come from the marketplace. --}}
        @if (($panel['premium_plugins']['enabled'] ?? false) && ($panel['premium_plugins']['plugins'] ?? []) !== [])
            <div>
                <div class="mb-4">
                    <h2 class="text-xl font-bold text-gray-900 dark:text-white">{{ $panel['premium_plugins']['title'] ?? 'Premium Plugins' }}</h2>
                    @if ($panel['premium_plugins']['subtitle'] ?? null)
                        <p class="text-xs text-gray-500 dark:text-gray-400">{{ $panel['premium_plugins']['subtitle'] }}</p>
                    @endif
                </div>

                <div class="flex snap-x snap-mandatory gap-6 overflow-x-auto pb-4 [scrollbar-width:none] [&::-webkit-scrollbar]:hidden">
                    @foreach ($panel['premium_plugins']['plugins'] as $promo)
                        <div class="flex w-80 shrink-0 snap-start flex-col justify-between rounded-xl border border-gray-200 bg-white p-5 shadow-sm dark:border-white/10 dark:bg-white/5">
                            <div>
                                @if ($promo['screenshot'] ?? null)
                                    <img src="{{ $promo['screenshot'] }}" alt="{{ $promo['name'] }}" class="mb-4 h-36 w-full rounded-lg object-cover">
                                @else
                                    <div class="mb-4 flex h-36 w-full items-center justify-center rounded-lg bg-primary-50 dark:bg-primary-500/10">
                                        <x-filament::icon icon="heroicon-o-puzzle-piece" class="h-10 w-10 text-primary-500" />
                                    </div>
                                @endif
                                <h3 class="text-lg font-bold text-gray-900 dark:text-white">{{ $promo['name'] }}</h3>
                                <p class="mt-1 line-clamp-2 text-xs text-gray-500 dark:text-gray-400">{{ $promo['short_description'] }}</p>
                            </div>
                            <div class="mt-6 flex items-center justify-between gap-2">
                                <span class="text-sm font-bold text-gray-900 dark:text-white">
                                    {{ ($promo['price'] ?? null) ? '₹'.number_format($promo['price'] / 100) : 'Free' }}
                                </span>
                                <div class="flex items-center gap-2">
                                    {{-- Only offered when the publisher enabled a trial and this
                                         account does not already hold a licence for it. --}}
                                    @if (($promo['trial_enabled'] ?? false) && ! collect($licenses)->contains('product_slug', $promo['package']) && auth()->user()?->can('licensing.manage'))
                                        <form method="POST" action="{{ route('licensing.start-trial') }}">
                                            @csrf
                                            <input type="hidden" name="product_slug" value="{{ $promo['package'] }}">
                                            <button type="submit" class="rounded-lg border border-gray-300 px-3 py-2 text-xs font-semibold text-gray-700 hover:bg-gray-50 dark:border-white/10 dark:text-gray-200 dark:hover:bg-white/5">
                                                Try {{ $promo['trial_days'] ?? 14 }} days
                                            </button>
                                        </form>
                                    @endif
                                    <a href="{{ $promo['url'] }}" target="_blank" rel="noopener"
                                       class="rounded-lg bg-primary-600 px-4 py-2 text-xs font-semibold text-white shadow-sm transition-colors hover:bg-primary-700">
                                        View
                                    </a>
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif

        {{-- Invoices. Rendered from the marketplace's records, not the site's,
             because a tax invoice belongs to the account rather than to any
             one install. --}}
        @if (count($invoices) > 0)
            <div class="rounded-xl border border-gray-200 bg-white p-6 shadow-sm dark:border-white/10 dark:bg-white/5">
                <h2 class="mb-1 text-lg font-bold text-gray-900 dark:text-white">Invoices</h2>
                <p class="mb-6 text-xs text-gray-500 dark:text-gray-400">Tax invoices for purchases on this account.</p>

                <div class="overflow-x-auto">
                    <table class="w-full border-collapse text-left text-sm">
                        <thead>
                            <tr class="border-b border-gray-200 text-xs font-medium uppercase text-gray-500 dark:border-white/10 dark:text-gray-400">
                                <th class="pb-3">Invoice</th>
                                <th class="pb-3">Date</th>
                                <th class="pb-3">Product</th>
                                <th class="pb-3 text-right">Tax</th>
                                <th class="pb-3 text-right">Total</th>
                                <th class="pb-3"><span class="sr-only">Download</span></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                            @foreach ($invoices as $invoice)
                                <tr>
                                    <td class="py-3 font-mono text-xs text-gray-700 dark:text-gray-300">{{ $invoice['number'] }}</td>
                                    <td class="py-3 text-gray-600 dark:text-gray-400">{{ \Illuminate\Support\Carbon::parse($invoice['issued_at'])->format('d M Y') }}</td>
                                    <td class="py-3 text-gray-600 dark:text-gray-400">{{ $invoice['product_slug'] }}</td>
                                    <td class="py-3 text-right text-xs text-gray-500 dark:text-gray-400">
                                        {{ $invoice['tax_label'] ?? '—' }}
                                        @if (($invoice['tax_amount'] ?? 0) > 0)
                                            · {{ number_format($invoice['tax_amount'] / 100, 2) }}
                                        @endif
                                    </td>
                                    <td class="py-3 text-right font-semibold text-gray-900 dark:text-white">
                                        {{ $invoice['currency'] }} {{ number_format($invoice['total'] / 100, 2) }}
                                    </td>
                                    <td class="py-3 text-right">
                                        {{-- Fetched by this site, not linked to the
                                             marketplace: the token that authorises it
                                             belongs to the server, not the browser. --}}
                                        @if (isset($invoice['id']) && auth()->user()?->can('licensing.manage'))
                                            <a
                                                href="{{ route('licensing.invoice', ['invoice' => $invoice['id']]) }}"
                                                class="text-xs font-semibold text-primary-600 transition-colors hover:text-primary-700 dark:text-primary-400 dark:hover:text-primary-300"
                                            >Download</a>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @endif

        {{-- Section 4: custom software banner --}}
        @if ($panel['custom_software']['enabled'] ?? false)
            <div class="flex flex-col items-center justify-between gap-6 rounded-2xl border border-gray-800 bg-gradient-to-br from-gray-900 to-primary-900 p-6 text-white shadow-md md:flex-row md:p-8 dark:border-white/10">
                <div class="space-y-2 text-center md:text-left">
                    <h2 class="text-xl font-bold md:text-2xl">{{ $panel['custom_software']['title'] ?? 'Need Custom Software or Features?' }}</h2>
                    @if ($panel['custom_software']['body'] ?? null)
                        <p class="max-w-xl text-xs text-gray-200 md:text-sm">{{ $panel['custom_software']['body'] }}</p>
                    @endif
                </div>
                <div class="flex w-full flex-col items-center gap-3 sm:flex-row md:w-auto">
                    @if ($panel['custom_software']['email'] ?? null)
                        <a href="mailto:{{ $panel['custom_software']['email'] }}"
                           class="inline-flex w-full items-center justify-center gap-2 rounded-lg bg-primary-600 px-4 py-2.5 text-xs font-medium text-white shadow-sm transition-colors hover:bg-primary-700 sm:w-auto">
                            <x-filament::icon icon="heroicon-m-envelope" class="h-4 w-4" />
                            {{ $panel['custom_software']['email'] }}
                        </a>
                    @endif
                    @if ($panel['custom_software']['phone'] ?? null)
                        <a href="tel:{{ preg_replace('/[^0-9+]/', '', $panel['custom_software']['phone']) }}"
                           class="inline-flex w-full items-center justify-center gap-2 rounded-lg border border-white/20 bg-white/10 px-4 py-2.5 text-xs font-medium text-white transition-colors hover:bg-white/20 sm:w-auto">
                            <x-filament::icon icon="heroicon-m-phone" class="h-4 w-4" />
                            {{ $panel['custom_software']['phone'] }}
                        </a>
                    @endif
                </div>
            </div>
        @endif
    @else
        {{-- Not connected: the only thing to do here is connect. --}}
        <div class="rounded-xl border border-gray-200 bg-white p-5 dark:border-white/10 dark:bg-white/5">
            <p class="text-sm text-gray-600 dark:text-gray-300">
                Connect with your Magna Account to access the Magna ecosystem, manage your licences,
                and install the plugins you have bought.
            </p>
            <div class="mt-4 flex flex-wrap gap-2">
                <a href="{{ route('account-centre.connect', 'google') }}" class="inline-flex items-center gap-2 rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-white/10 dark:text-gray-200 dark:hover:bg-white/5">
                    <svg class="h-4 w-4" viewBox="0 0 24 24"><path fill="#4285F4" d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92a5.06 5.06 0 0 1-2.2 3.32v2.76h3.57c2.08-1.92 3.28-4.74 3.28-8.09z"/><path fill="#34A853" d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.76c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84A11 11 0 0 0 12 23z"/><path fill="#FBBC05" d="M5.84 14.09a6.6 6.6 0 0 1 0-4.18V7.07H2.18a11 11 0 0 0 0 9.86l3.66-2.84z"/><path fill="#EA4335" d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84C6.71 7.31 9.14 5.38 12 5.38z"/></svg>
                    Connect with Google
                </a>
                <a href="{{ route('account-centre.connect', 'github') }}" class="inline-flex items-center gap-2 rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-white/10 dark:text-gray-200 dark:hover:bg-white/5">
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="currentColor"><path d="M12 .5C5.7.5.5 5.7.5 12c0 5.1 3.3 9.4 7.9 10.9.6.1.8-.3.8-.6v-2c-3.2.7-3.9-1.5-3.9-1.5-.5-1.3-1.3-1.7-1.3-1.7-1.1-.7.1-.7.1-.7 1.2.1 1.8 1.2 1.8 1.2 1 1.8 2.7 1.3 3.4 1 .1-.8.4-1.3.7-1.6-2.6-.3-5.3-1.3-5.3-5.8 0-1.3.5-2.3 1.2-3.1-.1-.3-.5-1.5.1-3.1 0 0 1-.3 3.3 1.2a11.4 11.4 0 0 1 6 0C17 4.6 18 4.9 18 4.9c.6 1.6.2 2.8.1 3.1.8.8 1.2 1.8 1.2 3.1 0 4.5-2.7 5.5-5.3 5.8.4.4.8 1.1.8 2.3v3.3c0 .3.2.7.8.6 4.6-1.5 7.9-5.8 7.9-10.9C23.5 5.7 18.3.5 12 .5Z"/></svg>
                    Connect with GitHub
                </a>
                <a href="{{ route('account-centre.connect', 'microsoft') }}" class="inline-flex items-center gap-2 rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-white/10 dark:text-gray-200 dark:hover:bg-white/5">
                    <svg class="h-4 w-4" viewBox="0 0 24 24"><path fill="#F25022" d="M11.4 11.4H1V1h10.4z"/><path fill="#7FBA00" d="M23 11.4H12.6V1H23z"/><path fill="#00A4EF" d="M11.4 23H1V12.6h10.4z"/><path fill="#FFB900" d="M23 23H12.6V12.6H23z"/></svg>
                    Connect with Microsoft
                </a>
            </div>
        </div>
    @endif
</div>
