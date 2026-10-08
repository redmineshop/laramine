<?php

return [

    /*
    |--------------------------------------------------------------------------
    | ImageMagick convert
    |--------------------------------------------------------------------------
    |
    | Every thumbnail is refused unless this program answers `-version`.
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

    /*
    |--------------------------------------------------------------------------
    | Issue status-column board
    |--------------------------------------------------------------------------
    |
    | Laramine extension. Redmine 7.0.1 IssueQuery display type is `list`
    | only. `board` on that pin is the ProjectQuery card layout, which this
    | flag does not implement. Default off so the extension stays outside
    | the queries comparison.
    |
    */

    'issue_query_board' => filter_var(env('LARAMINE_ISSUE_QUERY_BOARD', false), FILTER_VALIDATE_BOOL),

];
