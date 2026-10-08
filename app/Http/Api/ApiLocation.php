<?php

namespace App\Http\Api;

use Illuminate\Http\Request;

/**
 * Absolute Location URL for a created REST resource, using the request format.
 */
final class ApiLocation
{
    public static function to(Request $request, string $path): string
    {
        $format = $request->route('format') === 'xml' ? 'xml' : 'json';

        return url('/'.ltrim($path, '/').'.'.$format);
    }
}
