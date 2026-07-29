@extends('magna::partials.auth-shell')

@section('title', 'Two-step verification')

@section('content')
    <h1>Two-step verification</h1>
    <p class="lead">Enter the 6-digit code from your authenticator app to continue.</p>

    @error('code')<div class="alert">{{ $message }}</div>@enderror
    @error('recovery_code')<div class="alert">{{ $message }}</div>@enderror

    <form method="POST" action="{{ route('auth.two-factor.challenge.verify') }}">
        @csrf
        <div class="field">
            <label for="code">Authentication code</label>
            <input id="code" class="otp" type="text" name="code" maxlength="6" inputmode="numeric"
                   autocomplete="one-time-code" autofocus placeholder="000000">
        </div>

        <details>
            <summary class="btn-ghost" style="cursor:pointer;">Lost your device? Use a recovery code</summary>
            <div class="field" style="margin-top:12px;">
                <label for="recovery_code">Recovery code</label>
                <input id="recovery_code" type="text" name="recovery_code" autocomplete="off"
                       placeholder="xxxxxxxx-xxxxxxxx">
            </div>
        </details>

        <button type="submit" class="btn">Verify</button>
    </form>

    <div class="row-between">
        <a href="{{ route(\Magna\Admin\AdminPanelProvider::loginRoute()) }}" class="btn-ghost">← Back to sign in</a>
    </div>
@endsection
