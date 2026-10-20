{{-- Plugins being installed or updated, on BOTH tabs.
     The poll used to live in the Add New panel only, so an update started
     from the Installed tab was never watched: no progress, no result, and
     the admin saw nothing happen. --}}
@if (count($installQueue) > 0)
    <div wire:poll.2s="pollInstalls" class="rounded-xl border border-primary-200 dark:border-primary-500/30 bg-primary-50 dark:bg-primary-500/10 px-4 py-3 mb-6 space-y-2.5">
        @foreach ($installQueue as $pkg)
            @php $prog = $installProgress[$pkg] ?? ['state' => 'queued', 'message' => '']; @endphp
            <div class="flex items-center gap-3">
                <svg class="w-4 h-4 shrink-0 animate-spin text-primary-600 dark:text-primary-400" viewBox="0 0 24 24" fill="none">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
                </svg>
                <div class="min-w-0">
                    <p class="text-sm font-medium text-primary-900 dark:text-primary-100">{{ $pkg }}</p>
                    <p class="truncate text-xs text-primary-700 dark:text-primary-300">{{ $prog['message'] ?: 'Queued…' }}</p>
                </div>
            </div>
        @endforeach
    </div>
@endif
