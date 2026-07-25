@extends('magna-install::layout')

@section('content')
    <svg class="success-icon" viewBox="0 0 64 64" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
        <defs>
            <linearGradient id="sg" x1="0" y1="0" x2="64" y2="64">
                <stop stop-color="#34d399"/><stop offset="1" stop-color="#6366f1"/>
            </linearGradient>
        </defs>
        <circle cx="32" cy="32" r="29" stroke="url(#sg)" stroke-width="3.5" fill="rgba(52,211,153,.08)"/>
        <path d="M20 33.5 28.5 42 45 24" stroke="url(#sg)" stroke-width="4" stroke-linecap="round" stroke-linejoin="round"/>
    </svg>

    <h1 style="text-align:center;">Magna is installed 🎉</h1>
    <p class="lead" style="text-align:center;">Your content platform is ready. The installer has locked itself and can't be run again.</p>

    <ul class="summary">
        <li><span>Site</span> <strong>{{ config('app.name') }}</strong></li>
        <li><span>Content API</span> <code>{{ rtrim((string) config('app.url'), '/') }}/api/v1</code></li>
        <li><span>Admin panel</span> <code>/</code> <span class="hint">(sign in to manage your site)</span></li>
    </ul>

    <a class="btn" href="/">Visit your site</a>

    <div class="group-title">Recommended for production</div>
    <ul class="checks">
        <li>
            <div>
                <div class="check-label">Redis for cache &amp; queues</div>
                <div class="check-help">Faster than the default database driver. Set up a Redis server, then configure it in <strong>Settings &rarr; Performance</strong> inside the admin panel &mdash; setting <code>CACHE_STORE</code>/<code>QUEUE_CONNECTION</code> in <code>.env</code> alone is not enough.</div>
            </div>
        </li>
        <li>
            <div>
                <div class="check-label">Octane (FrankenPHP)</div>
                <div class="check-help">Keeps the app booted in memory between requests &mdash; the single biggest speed win. Requires a Linux server; see <code>docs/DEPLOYMENT.md</code> section 3A.</div>
            </div>
        </li>
        <li>
            <div>
                <div class="check-label">A running queue worker</div>
                <div class="check-help">Media thumbnails and other background jobs need <code>php artisan queue:work</code> running continuously under a process supervisor. See <code>docs/DEPLOYMENT.md</code> section 4.</div>
            </div>
        </li>
        <li>
            <div>
                <div class="check-label">The scheduler cron</div>
                <div class="check-help">Drives update checks, dashboard announcements, backups, and cleanup. Add one cron entry:<br><code>* * * * * cd {{ base_path() }} &amp;&amp; php artisan schedule:run &gt;&gt; /dev/null 2&gt;&amp;1</code><br>Announcements and updates still refresh when an admin opens the dashboard, but the cron keeps everything current on its own.</div>
            </div>
        </li>
    </ul>

    <p class="foot" style="margin-top:16px;">
        Next: model your first content type &middot; read the docs to build a frontend against the API
    </p>
@endsection
