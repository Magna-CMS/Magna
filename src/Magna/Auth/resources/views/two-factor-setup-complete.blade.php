@extends('magna::partials.auth-shell')

@section('title', 'Two-factor authentication enabled')

@section('content')
    <div style="text-align:center; margin-bottom:6px;">
        <svg width="46" height="46" viewBox="0 0 24 24" fill="none" stroke="#34d399" stroke-width="1.8" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/>
        </svg>
    </div>
    <h1>Two-factor is on</h1>
    <p class="lead">Save these recovery codes somewhere safe. Each works once if you lose your authenticator — <strong>they won't be shown again</strong>.</p>

    <ul class="codes">
        @foreach ($recoveryCodes as $code)
            <li>{{ $code }}</li>
        @endforeach
    </ul>

    <a href="{{ route('dashboard') }}" class="btn" style="margin-top:22px;">I've saved them — continue</a>
@endsection
