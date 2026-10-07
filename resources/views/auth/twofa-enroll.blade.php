<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Two-factor setup — {{ config('app.name') }}</title>
    </head>
    <body>
        <h1>Two-factor setup</h1>
        @if ($enabled)
            <p>Two-factor authentication is enabled.</p>
        @endif
        <p id="totp-secret">{{ $secret }}</p>
        @if (session('backup_codes'))
            <ul>
                @foreach (session('backup_codes') as $backup)
                    <li>{{ $backup }}</li>
                @endforeach
            </ul>
        @endif
        <form method="POST" action="{{ route('twofa.confirm') }}">
            @csrf
            <p>
                <label for="code">Code</label>
                <input id="code" name="code" type="text" inputmode="numeric" autocomplete="one-time-code" required>
            </p>
            @error('code')
                <p>{{ $message }}</p>
            @enderror
            <p>
                <button type="submit">Enable</button>
            </p>
        </form>
        @if ($enabled)
            <form method="POST" action="{{ route('twofa.destroy') }}">
                @csrf
                @method('DELETE')
                <button type="submit">Disable</button>
            </form>
        @endif
    </body>
</html>
