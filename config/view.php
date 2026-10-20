<?php

return [

    'paths' => [
        resource_path('views'),
    ],

    // No realpath() here: it returns false when the directory doesn't exist
    // yet (e.g. on a fresh Railway build), which breaks `php artisan view:cache`.
    'compiled' => env('VIEW_COMPILED_PATH', storage_path('framework/views')),

];
