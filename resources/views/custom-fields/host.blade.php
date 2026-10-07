<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $title }}</title>
</head>
<body>
    <h1>{{ $title }}</h1>
    <form method="post" action="{{ $action }}">
        @csrf
        @method($method)
        @foreach ($fields as $field)
            <p>
                <label for="cf-{{ $field['id'] }}">{{ $field['name'] }}</label>
                <input type="hidden" name="custom_fields[{{ $loop->index }}][id]" value="{{ $field['id'] }}">
                <input id="cf-{{ $field['id'] }}" type="text" name="custom_fields[{{ $loop->index }}][value]" value="{{ $field['raw'][0] ?? '' }}">
            </p>
        @endforeach
        <button type="submit">Save</button>
    </form>
</body>
</html>
