<?php
/**
 * Project setup: pick the component variants this site uses from the shared
 * bank (config 'library_path') and install them into the project.
 *
 * Installing a set of variants:
 *   - copies each chosen variant and its family base into site/components/<family>/
 *     (pinned: later bank changes reach the site only when setup runs again);
 *     families and variants no longer chosen are removed from site/components/
 *   - copies the bank's core tools into site/scss/core/ (breakpoints only once,
 *     so a project can tune them)
 *   - writes site/project.json, site/scss/_components.scss, site/js/components.js
 *   - vendors GSAP into site/js/vendor/ when a chosen variant needs it
 *   - compiles site/css/site.css with Dart Sass when it is found
 *
 * Browser: http://localhost:8888/visualeditor/admin/tools/setup.php  (localhost only)
 * CLI:     php admin/tools/setup.php                        list the bank, mark what is installed
 *          php admin/tools/setup.php install <id> [<id>...]  install exactly this set, e.g. header/sticky nav/inline
 *          php admin/tools/setup.php update                  re-install the current set from the bank
 *          add --no-compile to skip the Sass compile
 *
 * Only the developer runs this, before editing starts. The site never needs
 * the bank or this tool at runtime.
 */

declare(strict_types=1);

$root = dirname(__DIR__, 2);
require $root . '/site/lib/db.php'; // ve_config() only; no database needed

$isCli = PHP_SAPI === 'cli';
if (!$isCli) {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    if (!in_array($ip, ['127.0.0.1', '::1'], true)) {
        http_response_code(403);
        exit('Setup is available on localhost only.');
    }
}

/* ---------- bank ---------- */

/** Reads the bank: families with their variants, from family.json / variant.json files. */
function bank_read(string $bank): array
{
    $families = [];
    foreach (glob($bank . '/*/family.json') ?: [] as $file) {
        $dir = dirname($file);
        $family = json_decode((string) file_get_contents($file), true);
        if (!is_array($family) || ($family['family'] ?? '') !== basename($dir)) {
            throw new RuntimeException("Invalid family.json in $dir (missing or wrong 'family').");
        }
        $family['dir'] = $dir;
        $family['variants'] = [];
        foreach (glob($dir . '/*/variant.json') ?: [] as $vfile) {
            $variant = json_decode((string) file_get_contents($vfile), true);
            $id = $family['family'] . '/' . basename(dirname($vfile));
            if (!is_array($variant) || ($variant['id'] ?? '') !== $id) {
                throw new RuntimeException("Invalid variant.json in " . dirname($vfile) . " (id must be '$id').");
            }
            $variant['dir'] = dirname($vfile);
            $family['variants'][$id] = $variant;
        }
        ksort($family['variants']);
        $families[$family['family']] = $family;
    }
    ksort($families);
    return $families;
}

/** Current commit of the bank (for project.json), or '' if it is not a git checkout. */
function bank_commit(string $bank): string
{
    $head = @file_get_contents($bank . '/.git/HEAD');
    if ($head === false) return '';
    $head = trim($head);
    if (str_starts_with($head, 'ref: ')) {
        $ref = substr($head, 5);
        $hash = @file_get_contents($bank . '/.git/' . $ref);
        if ($hash === false) {
            // packed refs
            foreach (@file($bank . '/.git/packed-refs') ?: [] as $line) {
                if (str_ends_with(trim($line), ' ' . $ref)) return substr(trim($line), 0, 40);
            }
            return '';
        }
        return trim($hash);
    }
    return $head;
}

/* ---------- files ---------- */

function copy_dir(string $from, string $to): void
{
    if (!is_dir($to)) mkdir($to, 0775, true);
    foreach (scandir($from) ?: [] as $name) {
        if ($name === '.' || $name === '..' || $name === '.DS_Store') continue;
        $src = "$from/$name";
        is_dir($src) ? copy_dir($src, "$to/$name") : copy($src, "$to/$name");
    }
}

function remove_dir(string $dir): void
{
    if (!is_dir($dir)) return;
    foreach (scandir($dir) ?: [] as $name) {
        if ($name === '.' || $name === '..') continue;
        $path = "$dir/$name";
        is_dir($path) && !is_link($path) ? remove_dir($path) : unlink($path);
    }
    rmdir($dir);
}

function write_file(string $path, string $content): void
{
    if (!is_dir(dirname($path))) mkdir(dirname($path), 0775, true);
    if (file_put_contents($path, $content) === false) {
        throw new RuntimeException("Cannot write $path");
    }
}

/** A JS identifier from a variant/family id: 'menu-button/x' -> 'menuButton_x'. */
function js_name(string $id): string
{
    $name = preg_replace_callback('/-([a-z0-9])/', fn($m) => strtoupper($m[1]), $id);
    return preg_replace('/[^A-Za-z0-9_]/', '_', $name);
}

/* ---------- Sass ---------- */

/** Path of Dart Sass (1.x), never the old Ruby Sass (3.x), or null. */
function find_sass(array $config): ?string
{
    if (!function_exists('exec')) return null;
    $candidates = [$config['sass_path'] ?? null, '/opt/homebrew/bin/sass', '/usr/local/bin/sass'];
    $which = [];
    @exec('command -v sass 2>/dev/null', $which);
    $candidates[] = $which[0] ?? null;
    foreach (array_unique(array_filter($candidates)) as $path) {
        if (!is_executable($path)) continue;
        $version = [];
        @exec(escapeshellarg($path) . ' --version 2>/dev/null', $version);
        if (preg_match('/^1\.\d+/', trim($version[0] ?? ''))) return $path;
    }
    return null;
}

function compile_css(string $root, ?string $sass, callable $out): bool
{
    $args = '--no-source-map --load-path=site/scss site/scss/main.scss site/css/site.css';
    if ($sass === null) {
        $out('Dart Sass not found (set sass_path in config/config.php). Compile by hand:');
        $out("  sass $args");
        return false;
    }
    $lines = [];
    $code = 0;
    exec('cd ' . escapeshellarg($root) . ' && ' . escapeshellarg($sass) . " $args 2>&1", $lines, $code);
    foreach ($lines as $line) $out('  ' . $line);
    $out($code === 0 ? 'Compiled site/css/site.css.' : 'Sass compile FAILED (see above). site/css/site.css was not updated.');
    return $code === 0;
}

/* ---------- install ---------- */

/**
 * Installs exactly $ids (variant ids) into the project, logging through $out.
 * Throws on an invalid selection.
 */
function install(string $root, string $bank, array $families, array $ids, bool $compile, ?string $sass, callable $out): void
{
    $ids = array_values(array_unique($ids));
    $chosen = []; // family => [variant id => variant]
    foreach ($ids as $id) {
        $family = explode('/', $id)[0];
        if (!isset($families[$family]['variants'][$id])) {
            throw new RuntimeException("Unknown variant '$id'. Run setup without arguments to list the bank.");
        }
        $chosen[$family][$id] = $families[$family]['variants'][$id];
    }
    ksort($chosen);

    $components = $root . '/site/components';
    if (!is_dir($components)) mkdir($components, 0775, true);

    // Remove families and variants no longer chosen (site/components is owned by setup).
    foreach (scandir($components) ?: [] as $name) {
        if ($name === '.' || $name === '..' || !is_dir("$components/$name")) continue;
        if (!isset($chosen[$name])) {
            remove_dir("$components/$name");
            $out("remove  $name (family no longer used)");
            continue;
        }
        foreach (scandir("$components/$name") ?: [] as $sub) {
            if ($sub === '.' || $sub === '..' || !is_dir("$components/$name/$sub")) continue;
            if (!isset($chosen[$name]["$name/$sub"])) {
                remove_dir("$components/$name/$sub");
                $out("remove  $name/$sub");
            }
        }
    }

    // Core tools (always refreshed) and breakpoints (only once: a project may tune them).
    copy_dir($bank . '/core/tools', $root . '/site/scss/core/tools');
    $bp = $root . '/site/scss/core/settings/_breakpoints.scss';
    if (!is_file($bp)) {
        write_file($bp, (string) file_get_contents($bank . '/core/settings/_breakpoints.scss'));
        $out('copy    core/settings/_breakpoints.scss (edit it here to tune breakpoints; setup keeps it)');
    }

    $scss = [];
    $js = [];     // [js name => relative path]
    $gsapJs = []; // inits that need GSAP loaded first
    $needs = [];
    $record = [];

    foreach ($chosen as $familyId => $variants) {
        $family = $families[$familyId];
        $target = "$components/$familyId";

        // Family files: family.json + base files.
        write_file("$target/family.json", (string) file_get_contents($family['dir'] . '/family.json'));
        foreach (['scss', 'js'] as $kind) {
            $file = $family['base'][$kind] ?? null;
            if ($file && is_file($family['dir'] . '/' . $file)) {
                copy($family['dir'] . '/' . $file, "$target/$file");
            }
        }
        if (!empty($family['base']['js'])) {
            $js[js_name($familyId . '/base')] = "../components/$familyId/" . $family['base']['js'];
        }

        foreach ($variants as $id => $variant) {
            $name = basename($id);
            remove_dir("$target/$name");
            copy_dir($variant['dir'], "$target/$name");
            $out(sprintf('install %-20s %s', $id, $variant['version'] ?? ''));

            if (!empty($variant['scss'])) {
                $scss[] = "@use '../components/$id/" . preg_replace('/^_|\.scss$/', '', $variant['scss']) . "';";
            }
            $variantNeeds = $variant['needs'] ?? [];
            $needs = array_merge($needs, $variantNeeds);
            if (!empty($variant['js'])) {
                $jsName = js_name($id);
                $js[$jsName] = "../components/$id/" . $variant['js'];
                if (in_array('gsap', $variantNeeds, true)) $gsapJs[] = $jsName;
            }
            $record[$id] = [
                'version' => $variant['version'] ?? null,
                'class'   => $variant['class'] ?? null,
                'label'   => $variant['label'] ?? $id,
            ];
        }
    }
    $needs = array_values(array_unique($needs));
    sort($needs);

    $commit = bank_commit($bank);
    $short = $commit !== '' ? substr($commit, 0, 7) : date('YmdHis');

    // GSAP, vendored only when a chosen variant needs it.
    if (in_array('gsap', $needs, true)) {
        $src = $bank . '/assets/js/libs/gsap.min.js';
        if (!is_file($src)) throw new RuntimeException("A variant needs GSAP but $src is missing.");
        write_file($root . '/site/js/vendor/gsap.min.js', (string) file_get_contents($src));
        $out('vendor  gsap -> site/js/vendor/gsap.min.js');
    }

    // site/scss/_components.scss
    $head = "// Component variants installed in this project.\n"
          . "// Generated by admin/tools/setup.php from the bank" . ($commit ? " (commit $short)" : '') . "; do not edit by hand.\n";
    write_file($root . '/site/scss/_components.scss', $head . ($scss ? "\n" . implode("\n", $scss) . "\n" : "// No variants installed.\n"));

    // site/js/components.js
    $lines = [
        '// Component variants installed in this project: runs their JS.',
        '// Generated by admin/tools/setup.php' . ($commit ? " from the bank (commit $short)" : '') . '; do not edit by hand.',
        '// Imported by site.js. Each module exports a default init(root).',
        '',
    ];
    foreach ($js as $name => $path) {
        $lines[] = "import $name from '$path?v=$short';";
    }
    if ($gsapJs) {
        $lines[] = '';
        $lines[] = '// GSAP is a classic script: load it once before the variants that need it.';
        $lines[] = "await new Promise((resolve, reject) => {";
        $lines[] = "\tconst script = document.createElement('script');";
        $lines[] = "\tscript.src = new URL('./vendor/gsap.min.js', import.meta.url).href;";
        $lines[] = "\tscript.onload = resolve;";
        $lines[] = "\tscript.onerror = reject;";
        $lines[] = "\tdocument.head.append(script);";
        $lines[] = '}).catch(() => console.warn(\'GSAP failed to load\'));';
    }
    $lines[] = '';
    if ($js) {
        $lines[] = '[' . implode(', ', array_keys($js)) . '].forEach((init) => {';
        $lines[] = "\ttry { init(document); } catch (err) { console.error(err); }";
        $lines[] = '});';
    } else {
        $lines[] = '// No variant JS installed.';
        $lines[] = 'export {};';
    }
    write_file($root . '/site/js/components.js', implode("\n", $lines) . "\n");

    // site/project.json
    $families_used = [];
    foreach ($chosen as $familyId => $variants) $families_used[$familyId] = array_keys($variants);
    $project = [
        'note'         => 'Component variants chosen for this project. Written by admin/tools/setup.php; re-run it to change or update them.',
        'bank_commit'  => $commit ?: null,
        'installed_at' => date('c'),
        'needs'        => $needs,
        'families'     => $families_used ?: new stdClass(),
        'variants'     => $record ?: new stdClass(),
    ];
    write_file($root . '/site/project.json', json_encode($project, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
    $out('write   site/project.json, site/scss/_components.scss, site/js/components.js');

    if ($compile) compile_css($root, $sass, $out);
}

function project_read(string $root): array
{
    $file = $root . '/site/project.json';
    $data = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
    return is_array($data) ? $data : [];
}

/* ---------- run ---------- */

$config = ve_config();
$bank = rtrim((string) ($config['library_path'] ?? ''), '/');
$bankError = null;
$families = [];
if ($bank === '' || !is_dir($bank)) {
    $bankError = "Bank not found. Set 'library_path' in config/config.php to the visualeditor_lib folder"
               . ($bank !== '' ? " (now: $bank)" : '') . '.';
} else {
    try {
        $families = bank_read($bank);
    } catch (RuntimeException $ex) {
        $bankError = $ex->getMessage();
    }
}
$project = project_read($root);
$installed = array_keys($project['variants'] ?? []);

if ($isCli) {
    $out = function (string $line): void { echo $line, PHP_EOL; };
    $args = array_slice($argv, 1);
    $compile = !in_array('--no-compile', $args, true);
    $args = array_values(array_filter($args, fn($a) => $a !== '--no-compile'));
    if ($bankError) { $out($bankError); exit(1); }

    $command = $args[0] ?? 'list';
    try {
        if ($command === 'install') {
            install($root, $bank, $families, array_slice($args, 1), $compile, find_sass($config), $out);
        } elseif ($command === 'update') {
            if (!$installed) { $out('Nothing installed yet. Use: setup.php install <id> [<id>...]'); exit(1); }
            install($root, $bank, $families, $installed, $compile, find_sass($config), $out);
        } else {
            $out("Bank: $bank" . (($c = bank_commit($bank)) ? ' (commit ' . substr($c, 0, 7) . ')' : ''));
            if (!empty($project['bank_commit'])) $out('Installed from commit ' . substr($project['bank_commit'], 0, 7));
            foreach ($families as $familyId => $family) {
                $out('');
                $out(($family['label'] ?? $familyId) . " ($familyId)");
                foreach ($family['variants'] as $id => $variant) {
                    $mark = in_array($id, $installed, true) ? '*' : ' ';
                    $out(sprintf('  %s %-20s %s', $mark, $id, $variant['label'] ?? ''));
                }
            }
            $out('');
            $out('* = installed. Install a set: php admin/tools/setup.php install header/sticky menu-button/x nav/inline footer/default');
        }
    } catch (RuntimeException $ex) {
        $out('ERROR: ' . $ex->getMessage());
        exit(1);
    }
    exit(0);
}

/* ---------- browser page ---------- */

session_name('ve_setup');
session_start();
if (empty($_SESSION['setup_token'])) $_SESSION['setup_token'] = bin2hex(random_bytes(16));
$token = $_SESSION['setup_token'];

$log = [];
$failed = false;
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && !$bankError) {
    if (!hash_equals($token, (string) ($_POST['token'] ?? ''))) {
        http_response_code(400);
        $log[] = 'The form expired. Reload the page and try again.';
        $failed = true;
    } else {
        $ids = array_map('strval', (array) ($_POST['variants'] ?? []));
        try {
            install($root, $bank, $families, $ids, !empty($_POST['compile']), find_sass($config), function (string $line) use (&$log) { $log[] = $line; });
            $project = project_read($root);
            $installed = array_keys($project['variants'] ?? []);
        } catch (RuntimeException $ex) {
            $log[] = 'ERROR: ' . $ex->getMessage();
            $failed = true;
        }
    }
}

$h = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Setup – component variants</title>
<style>
    body { margin: 0; padding: 32px 16px; font: 15px/1.5 ui-sans-serif, system-ui, sans-serif; background: #f6f5f2; color: #161616; }
    main { max-width: 760px; margin: 0 auto; }
    h1 { font-size: 22px; margin: 0 0 4px; }
    .muted { color: #6b6b6b; font-size: 13px; }
    fieldset { margin: 20px 0 0; padding: 12px 16px 14px; border: 1px solid #ddd; border-radius: 8px; background: #fff; }
    legend { font-weight: 600; padding: 0 6px; }
    label { display: flex; gap: 10px; align-items: baseline; padding: 6px 0; cursor: pointer; }
    label span { flex: 1; }
    code { font-size: 12px; color: #6b6b6b; }
    .actions { margin-top: 20px; display: flex; gap: 16px; align-items: center; flex-wrap: wrap; }
    button { font: inherit; padding: 8px 18px; border: 0; border-radius: 999px; background: #161616; color: #fff; cursor: pointer; }
    pre { margin-top: 20px; padding: 12px 14px; background: #161616; color: #ececec; border-radius: 8px; font-size: 12px; overflow-x: auto; white-space: pre-wrap; }
    pre.failed { background: #5a1212; }
    .error { padding: 12px 14px; background: #fde8e8; border-radius: 8px; }
</style>
</head>
<body>
<main>
    <h1>Component variants</h1>
    <p class="muted">Pick the variants this site uses. Installing copies them into <code>site/components/</code>
        (pinned to this bank version), regenerates <code>_components.scss</code> and <code>components.js</code>
        and removes what is no longer ticked. Tick more than one in a family to let the owner switch between them.</p>

<?php if ($bankError): ?>
    <p class="error"><?= $h($bankError) ?></p>
<?php else: ?>
    <p class="muted">Bank: <code><?= $h($bank) ?></code>
        <?php if ($c = bank_commit($bank)): ?> · commit <code><?= $h(substr($c, 0, 7)) ?></code><?php endif; ?>
        <?php if (!empty($project['bank_commit'])): ?> · installed from <code><?= $h(substr($project['bank_commit'], 0, 7)) ?></code><?php endif; ?>
    </p>
    <form method="post">
        <input type="hidden" name="token" value="<?= $h($token) ?>">
        <?php foreach ($families as $familyId => $family): ?>
        <fieldset>
            <legend><?= $h($family['label'] ?? $familyId) ?></legend>
            <?php foreach ($family['variants'] as $id => $variant): ?>
            <label>
                <input type="checkbox" name="variants[]" value="<?= $h($id) ?>"<?= in_array($id, $installed, true) ? ' checked' : '' ?>>
                <span><?= $h($variant['label'] ?? $id) ?>
                    <?php if (!empty($variant['description'])): ?><br><span class="muted"><?= $h($variant['description']) ?></span><?php endif; ?>
                </span>
                <code><?= $h($id) ?> <?= $h($variant['version'] ?? '') ?><?= !empty($variant['needs']) ? ' · needs ' . $h(implode(', ', $variant['needs'])) : '' ?></code>
            </label>
            <?php endforeach; ?>
        </fieldset>
        <?php endforeach; ?>
        <div class="actions">
            <button type="submit">Install selected</button>
            <label><input type="checkbox" name="compile" value="1" checked> Compile <code>site/css/site.css</code></label>
        </div>
    </form>
<?php endif; ?>

<?php if ($log): ?>
    <pre class="<?= $failed ? 'failed' : '' ?>"><?= $h(implode("\n", $log)) ?></pre>
<?php endif; ?>
</main>
</body>
</html>
