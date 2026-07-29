@extends('magna::partials.auth-shell')

@section('title', 'Set up two-factor authentication')

@section('content')
    <h1>Secure your account</h1>
    <p class="lead">Scan this QR code with an authenticator app — Google Authenticator, 1Password, Authy — then enter the 6-digit code it shows.</p>

    <div class="qr">{!! $qrCodeSvg !!}</div>

    <p class="hint" style="margin-top:0;">Can't scan it? Enter this key manually:</p>
    <div class="keybox">{{ $secret }}</div>

    <div class="divider">then</div>

    @error('code')<div class="alert">{{ $message }}</div>@enderror

    <form method="POST" action="{{ route('auth.two-factor.setup.store') }}">
        @csrf
        <div class="field">
            <label for="code">Authentication code</label>
            <input id="code" class="otp" type="text" name="code" maxlength="6" inputmode="numeric"
                   autocomplete="one-time-code" autofocus placeholder="000000" required>
        </div>
        <button type="submit" class="btn">Confirm &amp; enable</button>
    </form>

    <div class="row-between">
        <form method="POST" action="{{ route('auth.logout') }}">
            @csrf
            <button type="submit" class="btn-ghost">← Sign out</button>
        </form>
    </div>
@endsection
