<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Activity</title>
</head>
<body>
    <h1>Activity</h1>
    <p>Signed in as {{ $login }}@if ($project) on {{ $project }}@endif.</p>
    @if ($events === [])
        <p>No events.</p>
    @else
        <ol>
            @foreach ($events as $event)
                <li>{{ $event->at }} {{ $event->kind }} {{ $event->id }} {{ $event->title }}</li>
            @endforeach
        </ol>
    @endif
</body>
</html>
