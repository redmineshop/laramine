<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Users — {{ config('app.name') }}</title>
    </head>
    <body>
        <h1>Users</h1>
        <ul>
            @foreach ($users as $user)
                <li>{{ $user->login }}</li>
            @endforeach
        </ul>
    </body>
</html>
