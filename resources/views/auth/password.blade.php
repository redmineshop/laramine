<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Change password — {{ config('app.name') }}</title>
    </head>
    <body>
        <h1>Change password</h1>
        @if ($mustChange)
            <p>{{ $mustChangeMessage }}</p>
        @endif
        <form method="POST" action="{{ route('password.update') }}">
            @csrf
            <p>
                <label for="current_password">Current password</label>
                <input id="current_password" name="current_password" type="password" autocomplete="current-password" required>
            </p>
            @error('current_password')
                <p>{{ $message }}</p>
            @enderror
            <p>
                <label for="password">New password</label>
                <input id="password" name="password" type="password" autocomplete="new-password" required>
            </p>
            @error('password')
                <p>{{ $message }}</p>
            @enderror
            <p>
                <label for="password_confirmation">Confirmation</label>
                <input id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" required>
            </p>
            @error('password_confirmation')
                <p>{{ $message }}</p>
            @enderror
            <p>
                <button type="submit">Save</button>
            </p>
        </form>
        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button type="submit">Sign out</button>
        </form>
    </body>
</html>
