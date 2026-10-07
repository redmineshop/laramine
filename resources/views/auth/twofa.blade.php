<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Two-factor authentication — {{ config('app.name') }}</title>
    </head>
    <body>
        <h1>Two-factor authentication</h1>
        @if ($mustEnroll)
            <p>Set up an authenticator before continuing.</p>
            <p id="totp-secret">{{ $secret }}</p>
        @endif
        <form method="POST" action="{{ route('twofa.verify') }}">
            @csrf
            <p>
                <label for="code">Code</label>
                <input id="code" name="code" type="text" inputmode="numeric" autocomplete="one-time-code" required>
            </p>
            @error('code')
                <p>{{ $message }}</p>
            @enderror
            <p>
                <button type="submit">Continue</button>
            </p>
        </form>
        @if (! $mustEnroll)
            <form method="POST" action="{{ route('twofa.backup') }}">
                @csrf
                <p>
                    <label for="backup_code">Backup code</label>
                    <input id="backup_code" name="backup_code" type="text" autocomplete="off" required>
                </p>
                @error('backup_code')
                    <p>{{ $message }}</p>
                @enderror
                <p>
                    <button type="submit">Use backup code</button>
                </p>
            </form>
        @endif
    </body>
</html>
