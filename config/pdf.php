<?php

return [

    /*
    |--------------------------------------------------------------------------
    | PHP PDF parser budget
    |--------------------------------------------------------------------------
    |
    | Smalot decompresses PDF streams in-process. A large scan can exceed a
    | 512 MB PHP memory_limit and fatally abort the request. These caps stop
    | parsing with a readable error before that happens.
    |
    */

    'php_parser_max_bytes' => 80 * 1024 * 1024,

    'php_parser_expansion_factor' => 8.0,

    'php_parser_headroom' => 0.90,

    /*
    | When null, the process memory_limit from PHP is used. Tests may set a
    | smaller string such as "8M" to exercise the remaining-memory guard.
    */
    'php_parser_memory_limit' => null,

    /*
    | Optional absolute path to Poppler pdftotext. When empty, the app looks
    | for /usr/bin/pdftotext, then PATH (`command -v` on Linux, `where` on Windows).
    */
    'pdftotext_binary' => env('PDFTOTEXT_BINARY'),

];
