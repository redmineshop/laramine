<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Register — {{ config('app.name') }}</title>
    </head>
    <body>
        <h1>Register</h1>
        <form method="POST" action="{{ route('register') }}">
            @csrf
            <p>
                <label for="login">Login</label>
                <input id="login" name="login" type="text" value="{{ old('login') }}" autocomplete="username" required>
            </p>
            @error('login')
                <p>{{ $message }}</p>
            @enderror
            <p>
                <label for="firstname">First name</label>
                <input id="firstname" name="firstname" type="text" value="{{ old('firstname') }}" required>
            </p>
            @error('firstname')
                <p>{{ $message }}</p>
            @enderror
            <p>
                <label for="lastname">Last name</label>
                <input id="lastname" name="lastname" type="text" value="{{ old('lastname') }}" required>
            </p>
            @error('lastname')
                <p>{{ $message }}</p>
            @enderror
            <p>
                <label for="mail">Email</label>
                <input id="mail" name="mail" type="email" value="{{ old('mail') }}" autocomplete="email" required>
            </p>
            @error('mail')
                <p>{{ $message }}</p>
            @enderror
            <p>
                <label for="password">Password</label>
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
                <button type="submit">Register</button>
            </p>
        </form>
    </body>
</html>
