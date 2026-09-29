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
            This install is the application skeleton. Projects, issues, permissions, and custom fields are not implemented yet.
        </p>
    </body>
</html>
