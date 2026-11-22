<?php
/**
 * Copy this file to config.php and fill in the values for your environment.
 * config.php is git-ignored and never shipped in the core package.
 */
return [
    'db' => [
        'driver'   => 'mysql',        // mysql (MySQL / MariaDB). pgsql later via PDO.
        'host'     => '127.0.0.1',    // MAMP: 127.0.0.1 (TCP). Live server: usually localhost.
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
];
