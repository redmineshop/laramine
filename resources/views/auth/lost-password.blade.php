<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Lost password — {{ config('app.name') }}</title>
    </head>
    <body>
        <h1>Lost password</h1>
        @if (session('status'))
            <p>{{ session('status') }}</p>
        @endif
        @if ($reset)
            <form method="POST" action="{{ route('password.reset') }}">
                @csrf
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
        @else
            <form method="POST" action="{{ route('password.email') }}">
                @csrf
                <p>
                    <label for="mail">Email</label>
                    <input id="mail" name="mail" type="email" value="{{ old('mail') }}" autocomplete="email" required>
                </p>
                @error('mail')
                    <p>{{ $message }}</p>
                @enderror
                <p>
                    <button type="submit">Submit</button>
                </p>
            </form>
        @endif
    </body>
</html>
