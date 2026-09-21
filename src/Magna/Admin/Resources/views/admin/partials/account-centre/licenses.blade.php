{{--
    The licence table from the Magna Account page.

    Split out of account-centre.blade.php when the seat column arrived: that
    file was nine lines under its ceiling, and a view nobody can add to is a
    view that grows somewhere worse.

    Every value here is prepared by AccountCentrePage — `seats` and
    `seats_used` by Magna\Licensing\SeatSummary — so this decides presentation
    and nothing else.
--}}
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
                    <th class="pb-3">Sites</th>
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

                        // A cancelled, suspended or expired key cannot download
                        // anything — the licence server refuses it — so offering
                        // "Install here" only produced an error after the click.
                        // Renew/redeem is the way back for those, and both live
                        // elsewhere on this page.
                        $installable = ! in_array($status, ['revoked', 'suspended', 'expired'], true);

                        // Only offer Update when a newer entitled version
                        // actually exists for this product.
                        $updateVersion = $productUpdates[$license['product_slug']] ?? null;

                        // The seat being active here is the marketplace's view;
                        // whether the product is still installed is this site's.
                        // Uninstalling does not release the seat, so the two
                        // disagree exactly when it matters — an uninstalled
                        // plugin must offer "Install here", never "Update".
                        // Themes have no plugin record, so for them the seat
                        // stands in.
                        $installedHere = $isTheme
                            ? $activeHere
                            : in_array($license['product_slug'], $installedProducts ?? [], true);

                        // A live auto-debit mandate replaces the manual Renew
                        // button entirely — the gateway charges on its own, so
                        // offering Renew beside it would double-bill. Once the
                        // mandate is cancelled or dies, the manual path takes
                        // back over.
                        $subscription = is_array($license['subscription'] ?? null) ? $license['subscription'] : null;
                        $autoRenews = $subscription !== null
                            && ($subscription['collection_method'] ?? '') === 'gateway'
                            && in_array($subscription['status'] ?? '', ['active', 'past_due'], true)
                            && ($subscription['cancelled_at'] ?? null) === null;
                        $autoRenewsOn = $autoRenews && ($subscription['current_period_end'] ?? null)
                            ? \Illuminate\Support\Carbon::parse($subscription['current_period_end'])->format('d M Y')
                            : null;

                        // Renewing is offered once a term licence is inside its
                        // last month, and stays offered after it lapses — an
                        // expired licence is precisely the one someone came here
                        // to fix. Lifetime, revoked and suspended keys are never
                        // renewable.
                        $renewable = ! $autoRenews
                            && $expiresAt !== null
                            && ! in_array($status, ['revoked', 'suspended', 'trial'], true)
                            && ($daysLeft === null || $daysLeft <= 30);

                        $seats = $license['seats'] ?? [];
                        $seatLimit = (int) ($license['activation_limit'] ?? 0);
                        $seatsUsed = (int) ($license['seats_used'] ?? 0);
                        $seatsFull = $seatLimit > 0 && $seatsUsed >= $seatLimit;
                    @endphp
                    <tr>
                        <td class="py-4 align-top">
                            <div class="flex items-center gap-3">
                                <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded bg-primary-50 text-primary-600 dark:bg-primary-500/10 dark:text-primary-400">
                                    <x-filament::icon :icon="$isTheme ? 'heroicon-m-swatch' : 'heroicon-m-bolt'" class="h-4 w-4" />
                                </div>
                                <span class="font-semibold text-gray-900 dark:text-white">{{ $license['product_slug'] }}</span>
                            </div>
                        </td>
                        <td class="py-4 align-top text-gray-600 dark:text-gray-400">{{ $isTheme ? 'Theme' : 'Plugin' }}</td>
                        <td class="py-4 align-top font-mono text-xs text-gray-500 dark:text-gray-400">{{ $license['key'] }}</td>
                        <td class="py-4 align-top">
                            <span class="rounded-full px-2.5 py-0.5 text-xs font-medium {{ $statusClass }}">{{ $statusLabel }}</span>
                        </td>
                        @include('magna::admin.partials.account-centre.seats')
                        <td class="py-4 align-top">
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
                                    {{-- Both buttons go through licensing.install: the wallet
                                         licence id mints a fresh activation token together with
                                         the download grant in one signed response. The old
                                         licensing.update path spent whatever token this site had
                                         stored — and a stale one produced "did not authorise a
                                         download" with a healthy licence sitting right here. --}}
                                    @if ($installedHere && $updateVersion !== null && $installable)
                                        <form method="POST" action="{{ route('licensing.install') }}">
                                            @csrf
                                            <input type="hidden" name="license_id" value="{{ $license['id'] }}">
                                            <input type="hidden" name="product_slug" value="{{ $license['product_slug'] }}">
                                            <button type="submit" class="text-xs font-semibold text-primary-600 transition-colors hover:text-primary-700 dark:text-primary-400 dark:hover:text-primary-300">Update to {{ $updateVersion }}</button>
                                        </form>
                                    @elseif (! $installedHere && $installable)
                                        <form method="POST" action="{{ route('licensing.install') }}">
                                            @csrf
                                            <input type="hidden" name="license_id" value="{{ $license['id'] }}">
                                            <input type="hidden" name="product_slug" value="{{ $license['product_slug'] }}">
                                            <button type="submit" class="text-xs font-semibold text-primary-600 transition-colors hover:text-primary-700 dark:text-primary-400 dark:hover:text-primary-300">Install here</button>
                                        </form>
                                    @endif
                                    <form method="POST" action="{{ route('licensing.deactivate') }}"
                                          onsubmit="return confirm('Release this licence from this site? The plugin is disabled here and the seat becomes free for another domain.');">
                                        @csrf
                                        <input type="hidden" name="product_slug" value="{{ $license['product_slug'] }}">
                                        <button type="submit" class="text-xs font-semibold text-gray-500 transition-colors hover:text-rose-600 dark:text-gray-400 dark:hover:text-rose-400">Release</button>
                                    </form>
                                @elseif ($installable)
                                    {{-- Full seats are refused by the licence server, not here:
                                         the refusal names the domains holding them, which is more
                                         use than a greyed-out button. The warning beside the count
                                         is the heads-up. --}}
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
