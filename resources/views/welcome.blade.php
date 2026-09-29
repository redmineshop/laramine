<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>{{ config('app.name') }}</title>
    </head>
    <body>
        <h1>{{ config('app.name') }}</h1>
        <p>
            Open-source project management core on Laravel.
            P0 tables follow the Redmine 7.0.1 layout. Project trees, membership permissions, and issue workflows are in the domain layer. The HTTP API is not implemented yet.
        </p>
    </body>
</html>
