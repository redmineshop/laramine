<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Account — {{ config('app.name') }}</title>
    </head>
    <body>
        <h1>Account</h1>
        @if ($apiKey)
            <p id="api-key">{{ $apiKey }}</p>
        @endif
        @if ($atomKey)
            <p id="atom-key">{{ $atomKey }}</p>
        @endif
        <form method="POST" action="{{ route('account.update') }}">
            @csrf
            <p>
                <label for="mail_notification">Mail notification</label>
                <select id="mail_notification" name="mail_notification">
                    @foreach (['all', 'selected', 'only_my_events', 'only_assigned', 'only_owner', 'none'] as $option)
                        <option value="{{ $option }}" @selected($user->mail_notification === $option)>{{ $option }}</option>
                    @endforeach
                </select>
            </p>
            <p>
                <label for="hide_mail">Hide mail</label>
                <input id="hide_mail" name="hide_mail" type="checkbox" value="1" @checked($hideMail)>
            </p>
            <p>
                <label for="time_zone">Time zone</label>
                <input id="time_zone" name="time_zone" type="text" value="{{ $timeZone }}">
            </p>
            <p>
                <label for="comments_sorting">Comment order</label>
                <select id="comments_sorting" name="comments_sorting">
                    <option value="">Default</option>
                    <option value="asc" @selected(($others['comments_sorting'] ?? '') === 'asc')>asc</option>
                    <option value="desc" @selected(($others['comments_sorting'] ?? '') === 'desc')>desc</option>
                </select>
            </p>
            <p>
                <button type="submit">Save</button>
            </p>
        </form>
        <form method="POST" action="{{ route('account.api-key') }}">
            @csrf
            <button type="submit">Reset API key</button>
        </form>
        <form method="POST" action="{{ route('account.atom-key') }}">
            @csrf
            <button type="submit">Reset Atom key</button>
        </form>
    </body>
</html>
