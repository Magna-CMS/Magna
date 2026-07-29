{{-- Shown on every admin page while any licensed product is locked. The
     product has already been stopped by LicenseEnforcer; this is the part
     that tells the client WHY, so a site that suddenly lost a feature isn't
     a mystery. Reads cached state only — renders fine during an outage. --}}
<div class="mb-6 space-y-3">
    @foreach ($locked as $slug => $entry)
        <div class="rounded-lg border border-red-200 bg-red-50 px-4 py-3 dark:border-red-500/20 dark:bg-red-500/10">
            <p class="text-sm font-semibold text-red-800 dark:text-red-300">
                {{ $slug }} has been disabled
            </p>
            <p class="mt-0.5 text-sm text-red-700 dark:text-red-400">
                {{ $entry->reason() }}
                @if ($entry->licenseType === 'corporate')
                    Contact the publisher who issued this licence to restore access.
                @else
                    Renew from your Magna Account to restore access.
                @endif
            </p>
        </div>
    @endforeach
</div>
