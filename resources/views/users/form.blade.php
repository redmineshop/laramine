<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>User — {{ config('app.name') }}</title>
    </head>
    <body>
        <h1>{{ $subject ? $subject->login : 'New user' }}</h1>
        <form method="POST" action="{{ $subject ? route('users.update', $subject) : route('users.store') }}">
            @csrf
            @if ($subject)
                @method('PUT')
            @endif
            <p>
                <label for="login">Login</label>
                <input id="login" name="login" type="text" value="{{ old('login', $subject->login ?? '') }}" required>
            </p>
            <p>
                <label for="firstname">First name</label>
                <input id="firstname" name="firstname" type="text" value="{{ old('firstname', $subject->firstname ?? '') }}" required>
            </p>
            <p>
                <label for="lastname">Last name</label>
                <input id="lastname" name="lastname" type="text" value="{{ old('lastname', $subject->lastname ?? '') }}" required>
            </p>
            <p>
                <label for="mail">Mail</label>
                <input id="mail" name="mail" type="email" value="{{ old('mail', $subject?->emailAddresses->firstWhere('is_default', true)?->address) }}" required>
            </p>
            <p>
                <label for="password">Password</label>
                <input id="password" name="password" type="password" @if (! $subject) required @endif>
            </p>
            <p>
                <label for="mail_notification">Mail notification</label>
                <input id="mail_notification" name="mail_notification" type="text" value="{{ old('mail_notification', $subject->mail_notification ?? 'only_my_events') }}">
            </p>
            <p>
                <button type="submit">Save</button>
            </p>
        </form>
        @if ($subject)
            <form method="POST" action="{{ route('users.lock', $subject) }}">
                @csrf
                <button type="submit">Lock</button>
            </form>
            <form method="POST" action="{{ route('users.unlock', $subject) }}">
                @csrf
                <button type="submit">Unlock</button>
            </form>
            <form method="POST" action="{{ route('users.activate', $subject) }}">
                @csrf
                <button type="submit">Activate</button>
            </form>
        @endif
    </body>
</html>
