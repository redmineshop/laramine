<?php

return [

    /*
    |--------------------------------------------------------------------------
    | ImageMagick convert
    |--------------------------------------------------------------------------
    |
    | PDF thumbnails are refused unless this program answers `-version`.
    | An empty string means the converter is not configured. When the
    | environment value is missing, `convert` is taken from PATH.
    |
    */

    'imagemagick_convert_command' => env('REDMINE_IMAGEMAGICK_CONVERT', 'convert'),

    /*
    |--------------------------------------------------------------------------
    | Ghostscript
    |--------------------------------------------------------------------------
    |
    | Rasterizes the first page of a PDF thumbnail. An empty string means
    | Ghostscript is not configured. When the environment value is missing,
    | `gs` is taken from PATH.
    |
    */

    'gs_command' => env('REDMINE_GS_COMMAND', 'gs'),

    /*
    |--------------------------------------------------------------------------
    | Thumbnail generation timeout
    |--------------------------------------------------------------------------
    |
    | Seconds allowed for one Ghostscript or convert invocation. Values
    | below 1 are treated as 10.
    |
    */

    'thumbnails_generation_timeout' => (int) env('REDMINE_THUMBNAILS_TIMEOUT', 10),

];
