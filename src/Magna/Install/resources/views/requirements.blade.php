@extends('magna-install::layout')

@section('content')
    <h1>Welcome to Magna</h1>
    <p class="lead">Let's make sure your server is ready. Required items must pass before installation can begin — recommended items can be fixed later.</p>

    @php($required = array_filter($checks, fn ($c) => $c->required))
    @php($recommended = array_filter($checks, fn ($c) => ! $c->required))

    <div class="group-title">Required</div>
    <ul class="checks">
        @foreach ($required as $check)
            <li>
                <div>
                    <div class="check-label">{{ $check->label }}</div>
                    @unless ($check->passed)
                        <div class="check-help">{{ $check->help }}</div>
                    @endunless
                </div>
                <span class="pill {{ $check->passed ? 'pill-ok' : 'pill-fail' }}">{{ $check->passed ? 'PASS' : 'FAIL' }}</span>
            </li>
        @endforeach
    </ul>

    <div class="group-title">Recommended</div>
    <ul class="checks">
        @foreach ($recommended as $check)
            <li>
                <div>
                    <div class="check-label">{{ $check->label }}</div>
                    @unless ($check->passed)
                        <div class="check-help">{{ $check->help }}</div>
                    @endunless
                </div>
                <span class="pill {{ $check->passed ? 'pill-ok' : 'pill-warn' }}">{{ $check->passed ? 'PASS' : 'INFO' }}</span>
            </li>
        @endforeach
    </ul>

    <div class="actions">
        <a class="btn btn-ghost" href="/install">Re-check</a>
        @if ($canContinue)
            <a class="btn" href="/install/site?install_token={{ urlencode($installToken) }}">Continue</a>
        @else
            <button class="btn" disabled>Fix required items to continue</button>
        @endif
    </div>
@endsection
