<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Legacy import safety acknowledgement
    |--------------------------------------------------------------------------
    |
    | All values default to deny. They must be set explicitly in the local
    | environment of an authorised disposable import database. They are never
    | committed with real values and are read only by
    | App\Support\DatabaseSafety::assertImportAllowed().
    |
    */

    'import' => [
        'allow' => env('YAMAK_IMPORT_ALLOW', ''),
        'database' => env('YAMAK_IMPORT_DATABASE', ''),
        'allow_destructive' => env('YAMAK_IMPORT_ALLOW_DESTRUCTIVE', ''),
    ],

];
