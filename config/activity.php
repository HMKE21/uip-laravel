<?php

/**
 * Per-user activity timeline (Admin → Users → User details → Activity).
 * ACTIVITY_LOG_ENABLED=false turns both the browser tracker intake and the server-side logger off.
 */
return [
    'enabled'        => (bool) env('ACTIVITY_LOG_ENABLED', true),
    // activity:prune deletes rows older than this many days.
    'retention_days' => (int) env('ACTIVITY_LOG_RETENTION_DAYS', 90),
    // Most events one browser batch may carry.
    'max_batch'      => 60,
];
