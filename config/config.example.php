<?php
/**
 * Copy this file to config.php and fill in the values for your environment.
 * config.php is git-ignored and never shipped in the core package.
 */
return [
    'db' => [
        'driver'   => 'mysql',        // mysql (MySQL / MariaDB). pgsql later via PDO.
        'host'     => '127.0.0.1',    // MAMP: 127.0.0.1 (TCP). Live server: usually localhost.
                                      // Tested targets: MAMP (MySQL), live PHP 8.4 + MariaDB 10.5.
        'port'     => 8889,           // MAMP default MySQL port. Live server: usually 3306.
        'socket'   => '',             // Optional, e.g. /Applications/MAMP/tmp/mysql/mysql.sock
        'name'     => 'your_database',
        'user'     => 'your_user',
        'pass'     => 'your_password',
        'charset'  => 'utf8mb4',
    ],

    // Leave null to auto-detect from the script location (works in a subfolder or at the root).
    'base_url' => null,

    // Show PHP errors on screen. true for localhost, false on production.
    'debug' => false,

    // Site-wide defaults used when the database has no value yet.
    'site_name' => 'My Portfolio',
    'lang'      => 'en',

    // Largest upload per file in MB (PHP's own upload limits still apply).
    'upload_max_mb' => 64,

    // First admin account, created by admin/install.php if it does not exist yet.
    // Store only a hash. Generate one with:
    //   php -r "echo password_hash('your-password', PASSWORD_DEFAULT), PHP_EOL;"
    'admin' => [
        'email'         => 'you@example.com',
        'password_hash' => '',
    ],

    // Developer only (admin/tools/setup.php): the shared component bank
    // (visualeditor_lib) and Dart Sass. Not needed on a live server.
    'library_path' => '/path/to/visualeditor_lib',
    'sass_path'    => null,              // null = auto-detect (Dart Sass 1.x only, never Ruby Sass)
];
