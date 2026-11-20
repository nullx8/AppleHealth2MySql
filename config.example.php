<?php
// Copy this file to config.php and fill in your own values.
// config.php is listed in .gitignore and must never be committed.
return [
    'db_dsn'  => 'mysql:host=localhost;dbname=health;charset=utf8mb4',
    'db_user' => 'health',
    'db_pass' => 'change-me',
    'store_dir' => __DIR__ . '/incoming',   // raw uploads are archived here (keep it outside the web root or deny access)
    'timezone'  => 'UTC',                   // your local timezone, e.g. 'Europe/Berlin'
    // ios.php rejects every upload unless this header carries exactly this value.
    // Set the same header in each Health Auto Export automation (Headers section).
    // Use a long random value, e.g. from: php -r 'echo bin2hex(random_bytes(24));'
    'upload_header' => 'x-upload-token',
    'upload_token'  => '',               // empty = all uploads are refused
];
