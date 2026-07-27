<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Gateway base URL
    |--------------------------------------------------------------------------
    |
    | The Ragno gateway every connection talks to. A per-connection
    | `ragno_base_url` (in config/database.php) overrides this.
    |
    */

    'base_url' => env('RAGNO_BASE_URL', 'https://data.publica.la'),

    /*
    |--------------------------------------------------------------------------
    | HTTP timeouts (seconds)
    |--------------------------------------------------------------------------
    */

    'timeout' => (int) env('RAGNO_TIMEOUT', 30),
    'connect_timeout' => (int) env('RAGNO_CONNECT_TIMEOUT', 10),

    /*
    |--------------------------------------------------------------------------
    | User agent
    |--------------------------------------------------------------------------
    |
    | Sent on every request so the gateway's audit log can attribute traffic to
    | this app. Left unset, the driver sends its own name and version plus your
    | app's `app.name`, e.g. `laravel-ragno/0.7.0 (Acme Books)`. Set this (or a
    | per-connection `ragno_user_agent`) to own the header verbatim.
    |
    */

    'user_agent' => env('RAGNO_USER_AGENT'),

    /*
    |--------------------------------------------------------------------------
    | Read-only statement guard
    |--------------------------------------------------------------------------
    |
    | A local fast-fail that rejects anything but SELECT/WITH/SHOW/DESCRIBE/
    | EXPLAIN before a request leaves the app. This is defense-in-depth and a
    | nicer error — it is NOT the security boundary. The boundary is the SQL
    | GRANT behind your Ragno token (SELECT-only) plus the gateway's own guard.
    | Writes and transactions are refused unconditionally regardless of this.
    |
    */

    'enforce_read_only' => (bool) env('RAGNO_ENFORCE_READ_ONLY', true),

    /*
    |--------------------------------------------------------------------------
    | Max rows
    |--------------------------------------------------------------------------
    |
    | Ragno returns a full result set in one response (no server-side cursor),
    | so the driver buffers every row in memory. Set a ceiling to fail loudly
    | instead of silently materialising a runaway result set. `null` = no cap.
    |
    */

    'max_rows' => ($rows = env('RAGNO_MAX_ROWS')) !== null ? (int) $rows : null,

];
