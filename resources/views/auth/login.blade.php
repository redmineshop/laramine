<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Sign in — {{ config('app.name') }}</title>
    </head>
    <body>
        <h1>Sign in</h1>
        <form method="POST" action="{{ route('login') }}">
            @csrf
            <p>
                <label for="login">Login or email</label>
                <input id="login" name="login" type="text" value="{{ old('login') }}" autocomplete="username" required>
            </p>
            @error('login')
                <p>{{ $message }}</p>
            @enderror
            <p>
                <label for="password">Password</label>
                <input id="password" name="password" type="password" autocomplete="current-password" required>
            </p>
            <p>
                <button type="submit">Sign in</button>
            </p>
        </form>
    </body>
</html>
