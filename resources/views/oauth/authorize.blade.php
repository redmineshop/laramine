<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Authorize — {{ config('app.name') }}</title>
    </head>
    <body>
        <h1>Authorize application</h1>
        <form method="POST" action="{{ route('oauth.approve') }}">
            @csrf
            @foreach ($query as $name => $value)
                @if (is_string($name) && (is_string($value) || is_numeric($value)))
                    <input type="hidden" name="{{ $name }}" value="{{ $value }}">
                @endif
            @endforeach
            <p>
                <button type="submit" name="approve" value="1">Approve</button>
            </p>
            <p>
                <button type="submit" name="approve" value="0">Deny</button>
            </p>
        </form>
    </body>
</html>
