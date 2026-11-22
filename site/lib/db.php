<?php
/**
 * Database helper (PDO). Part of the site runtime, so it works without the admin core.
 */

function ve_config(): array
{
    static $config = null;
    if ($config === null) {
        $file = dirname(__DIR__, 2) . '/config/config.php';
        if (!is_file($file)) {
            http_response_code(500);
            exit('Missing config/config.php. Copy config/config.example.php and fill it in.');
        }
        $config = require $file;
    }
    return $config;
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $c = ve_config()['db'];
    $charset = $c['charset'] ?? 'utf8mb4';

    if (!empty($c['socket'])) {
        $dsn = sprintf('%s:unix_socket=%s;dbname=%s;charset=%s', $c['driver'], $c['socket'], $c['name'], $charset);
    } else {
        $dsn = sprintf('%s:host=%s;port=%d;dbname=%s;charset=%s', $c['driver'], $c['host'], (int) $c['port'], $c['name'], $charset);
    }

    $pdo = new PDO($dsn, $c['user'], $c['pass'], [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
    return $pdo;
}

/** Run a prepared query and return the statement. */
function q(string $sql, array $params = []): PDOStatement
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt;
}
