<?php

/**
 * Access-code gate in front of the hidden staff sign-in (/staff-access).
 *
 * Nobody reaches the staff login (front end) or is accepted by it (back end) without first sending a valid
 * code to POST /api/v1/auth/staff-gate. The code also decides WHICH staff accounts may sign in afterwards:
 *   admin         -> admin
 *   security      -> security_admin, security_officer
 *   data_analyst  -> data_analyst
 * so a "security" code can never open an admin account, even with the admin's correct e-mail + password.
 *
 * STAFF_GATE_CODES (comma separated  code:group  pairs) overrides the defaults below — set it in the hosting
 * environment so the codes do not live in the repository, e.g.  STAFF_GATE_CODES=4821:admin,7310:security,2954:data_analyst
 * A group may have several codes. STAFF_GATE_ENABLED=false removes the gate entirely.
 */
return [
    'enabled' => (bool) env('STAFF_GATE_ENABLED', true),
    'codes'   => (string) env('STAFF_GATE_CODES', '2466:admin,1820:security,1920:data_analyst'),

    'groups' => [
        'admin'        => ['admin'],
        'security'     => ['security_admin', 'security_officer'],
        'data_analyst' => ['data_analyst'],
    ],

    'ttl'          => (int) env('STAFF_GATE_TTL', 1800),        // seconds a passed gate stays valid
    'max_attempts' => (int) env('STAFF_GATE_MAX_ATTEMPTS', 5),  // wrong codes per IP before it is locked out
    'lock_minutes' => (int) env('STAFF_GATE_LOCK_MINUTES', 15), // first lock; doubles on every repeat (max 24h)
    'global_limit' => (int) env('STAFF_GATE_GLOBAL_LIMIT', 100), // wrong codes from ALL IPs per 10 min before everyone waits
];
