<?php
/**
 * Migrations runner. Applies every admin/migrations/NNN_*.sql file that has not
 * been applied yet, in order, and records it in schema_migrations.
 *
 * Browser: http://localhost:8888/visualeditor/admin/install.php  (localhost only)
 * CLI:     php admin/install.php
 */

require dirname(__DIR__) . '/site/lib/db.php';

$isCli = PHP_SAPI === 'cli';
if (!$isCli) {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    if (!in_array($ip, ['127.0.0.1', '::1'], true)) {
        http_response_code(403);
        exit('Installer is available on localhost only.');
    }
    header('Content-Type: text/plain; charset=utf-8');
}

function out(string $line): void
{
    echo $line . PHP_EOL;
}

try {
    $pdo = db();
} catch (PDOException $ex) {
    out('Cannot connect to the database: ' . $ex->getMessage());
    out('Check config/config.php (host, port, name, user, pass) and that MAMP MySQL is running.');
    exit(1);
}

$pdo->exec('CREATE TABLE IF NOT EXISTS schema_migrations (
    version    VARCHAR(191) NOT NULL PRIMARY KEY,
    applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

$applied = $pdo->query('SELECT version FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN);
$files = glob(__DIR__ . '/migrations/*.sql');
sort($files);

$ran = 0;
foreach ($files as $file) {
    $version = basename($file, '.sql');
    if (in_array($version, $applied, true)) {
        out("skip  $version (already applied)");
        continue;
    }

    $sql = file_get_contents($file);
    // Drop comment lines, then split on semicolons that end a line.
    $sql = preg_replace('/^\s*--.*$/m', '', $sql);
    $statements = array_filter(array_map('trim', preg_split('/;\s*(\r?\n|$)/', $sql)));

    try {
        foreach ($statements as $statement) {
            $pdo->exec($statement);
        }
        q('INSERT INTO schema_migrations (version) VALUES (?)', [$version]);
        out("apply $version (" . count($statements) . ' statements)');
        $ran++;
    } catch (PDOException $ex) {
        out("FAILED $version: " . $ex->getMessage());
        out('Fix the migration and run the installer again. Already applied migrations stay applied.');
        exit(1);
    }
}

out($ran ? "Done. $ran migration(s) applied." : 'Database is up to date.');
