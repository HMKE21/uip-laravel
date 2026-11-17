<?php

/** Smart Forms settings. Everything is overridable from .env. */
return [
    'public_path' => '/f/{code}',
    'limits' => [
        'max_fields'          => (int) env('FORMS_MAX_FIELDS', 200),
        'max_options'         => (int) env('FORMS_MAX_OPTIONS', 200),
        'max_text_answer'     => (int) env('FORMS_MAX_TEXT_ANSWER', 20000),
        'max_files_per_field' => (int) env('FORMS_MAX_FILES_PER_FIELD', 10),
        'max_file_kb'         => (int) env('FORMS_MAX_FILE_KB', 204800),
        'max_invited_emails'  => (int) env('FORMS_MAX_INVITED_EMAILS', 2000),
        'max_forms_per_user'  => (int) env('FORMS_MAX_PER_USER', 500),
    ],
    'exam' => ['grace_seconds' => (int) env('FORMS_EXAM_GRACE_SECONDS', 20)],
    'min_fill_seconds' => (int) env('FORMS_MIN_FILL_SECONDS', 2),
    'signed_url_ttl' => (int) env('FORMS_SIGNED_URL_TTL', 600),
    'orphan_upload_hours' => (int) env('FORMS_ORPHAN_UPLOAD_HOURS', 24),
    'icons' => ['form','clipboard','exam','survey','registration','feedback','event','quiz','poll','job','contest','support'],
    'colors' => ['indigo','teal','rose','amber','emerald','sky','violet','slate'],
];
