<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Sign in — {{ config('app.name') }}</title>
    </head>
    <body>
        <h1>Sign in</h1>
        @if ($notice)
            <p>{{ $notice }}</p>
        @endif
        <form method="POST" action="{{ $submitUrl }}">
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
            @if ($autologinDays > 0)
                <p>
                    <label for="autologin">Stay logged in</label>
                    <input id="autologin" name="autologin" type="checkbox" value="1">
                </p>
            @endif
            <p>
                <button type="submit">Sign in</button>
            </p>
        </form>
        @if ($lostPasswordUrl)
            <p><a href="{{ $lostPasswordUrl }}">Lost password</a></p>
        @endif
        @if ($registerUrl)
            <p><a href="{{ $registerUrl }}">Register</a></p>
        @endif
        @if ($activationEmailUrl)
            <form method="POST" action="{{ $activationEmailUrl }}">
                @csrf
                <button type="submit">Send the activation email again</button>
            </form>
        @endif
    </body>
</html>
