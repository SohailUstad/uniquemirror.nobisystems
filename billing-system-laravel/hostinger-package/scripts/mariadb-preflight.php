#!/usr/bin/env php
<?php
declare(strict_types=1);

/** Read-only application preflight; the only database object created is a session temporary table. */

function fail(string $message): never
{
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
}

function report(string $label, bool $ok, string $detail): void
{
    printf("%s: %s - %s\n", $ok ? 'PASS' : 'FAIL', $label, $detail);
}

function dotenv(string $path): array
{
    if (!is_file($path) || !is_readable($path)) return [];
    $values = [];
    foreach (file($path, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        if (preg_match('/^\s*(?:export\s+)?([A-Za-z_][A-Za-z0-9_]*)\s*=\s*(.*?)\s*$/', $line, $m) !== 1) continue;
        $value = trim($m[2]);
        if (strlen($value) >= 2 && (($value[0] === '"' && str_ends_with($value, '"')) || ($value[0] === "'" && str_ends_with($value, "'")))) {
            $value = substr($value, 1, -1);
        } else {
            $value = preg_replace('/\s+#.*$/', '', $value) ?? $value;
        }
        $values[$m[1]] = $value;
    }
    return $values;
}

function envValue(array $file, string $key, string $default = ''): string
{
    $value = getenv($key);
    return $value !== false ? $value : ($file[$key] ?? $default);
}

$ok = true;
$requiredExtensions = ['pdo_mysql', 'openssl', 'mbstring', 'tokenizer', 'xml', 'ctype', 'json', 'bcmath', 'fileinfo'];
$missing = array_values(array_filter($requiredExtensions, static fn(string $ext): bool => !extension_loaded($ext)));
$phpOk = PHP_VERSION_ID >= 80200 && $missing === [];
report('PHP CLI', $phpOk, PHP_VERSION . ($missing ? '; missing extensions: ' . implode(', ', $missing) : '; required extensions loaded'));
$ok = $ok && $phpOk;

$appRoot = getcwd();
$envPath = strtolower(basename(dirname($appRoot))) === 'public_html'
    ? dirname(dirname($appRoot)) . DIRECTORY_SEPARATOR . 'billing-system-runtime' . DIRECTORY_SEPARATOR . '.env'
    : $appRoot . DIRECTORY_SEPARATOR . '.env';
$env = dotenv($envPath);
$driver = envValue($env, 'DB_CONNECTION', 'mysql');
if (!in_array($driver, ['mysql', 'mariadb'], true)) fail('DB_CONNECTION must be mysql or mariadb; no database connection attempted.');
$host = envValue($env, 'DB_HOST');
$port = envValue($env, 'DB_PORT', '3306');
$database = envValue($env, 'DB_DATABASE');
$username = envValue($env, 'DB_USERNAME');
$password = envValue($env, 'DB_PASSWORD');
if ($host === '' || $database === '' || $username === '') fail('Set DB_HOST, DB_DATABASE, and DB_USERNAME in the runtime environment file. Credentials are never printed.');
if (!preg_match('/^[A-Za-z0-9_.:-]+$/', $host) || !preg_match('/^[0-9]{1,5}$/', $port) || !preg_match('/^[A-Za-z0-9_$-]+$/', $database)) {
    fail('Connection settings contain unsupported characters; refusing to connect.');
}
if (!extension_loaded('pdo_mysql')) fail('pdo_mysql is required; database connection not attempted.');

$pdo = null;
$table = 'codex_preflight_' . bin2hex(random_bytes(6));
$quotedTable = '`' . $table . '`';
$tableCreated = false;
try {
    $pdo = new PDO("mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4", $username, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_TIMEOUT => 10,
    ]);
    $version = (string)$pdo->query('SELECT VERSION()')->fetchColumn();
    $isMaria = stripos($version, 'mariadb') !== false || stripos((string)$pdo->query('SELECT @@version_comment')->fetchColumn(), 'mariadb') !== false;
    $numbers = [];
    // MariaDB 10.x can prefix its handshake version with 5.5.5 for old clients.
    preg_match('/([0-9]+)\.([0-9]+)\.([0-9]+)/', preg_replace('/^5\.5\.5-/', '', $version), $numbers);
    $versionOk = $isMaria && $numbers && ((int)$numbers[1] > 10 || ((int)$numbers[1] === 10 && (int)$numbers[2] >= 3));
    report('MariaDB server version', (bool)$versionOk, $version . ($versionOk ? ' (MariaDB 10.3+)' : ' (expected MariaDB 10.3+)'));
    $ok = $ok && (bool)$versionOk;

    // Temporary tables are scoped to this connection and never touch application tables.
    $pdo->exec("CREATE TEMPORARY TABLE {$quotedTable} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        source_name VARCHAR(80) COLLATE utf8mb4_bin NOT NULL,
        active TINYINT NOT NULL DEFAULT 0,
        worker_ref BIGINT UNSIGNED NOT NULL,
        lower_name VARCHAR(80) GENERATED ALWAYS AS (LOWER(source_name)) STORED,
        active_key BIGINT UNSIGNED GENERATED ALWAYS AS (CASE WHEN active = 1 THEN worker_ref ELSE NULL END) STORED,
        UNIQUE KEY uq_lower_name (lower_name),
        UNIQUE KEY uq_active_key (active_key),
        UNIQUE KEY uq_source_name (source_name)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $tableCreated = true;
    $metadata = $pdo->query("SHOW COLUMNS FROM {$quotedTable}")->fetchAll(PDO::FETCH_ASSOC);
    $stored = 0;
    foreach ($metadata as $column) {
        if (in_array(strtolower((string)$column['Field']), ['lower_name', 'active_key'], true) && stripos((string)$column['Extra'], 'stored generated') !== false) $stored++;
    }
    $indexRows = $pdo->query("SHOW INDEX FROM {$quotedTable}")->fetchAll(PDO::FETCH_ASSOC);
    $uniqueIndexes = [];
    foreach ($indexRows as $row) if ((int)$row['Non_unique'] === 0) $uniqueIndexes[(string)$row['Key_name']] = true;
    $structureOk = $stored === 2 && isset($uniqueIndexes['uq_lower_name'], $uniqueIndexes['uq_active_key'], $uniqueIndexes['uq_source_name']);
    report('Stored generated columns and unique indexes', $structureOk, $stored . '/2 stored generated columns; ' . count($uniqueIndexes) . ' unique indexes observed');
    $ok = $ok && $structureOk;

    $binaryOk = (int)$pdo->query("SELECT 'CaseSensitive' COLLATE utf8mb4_bin = 'casesensitive' COLLATE utf8mb4_bin")->fetchColumn() === 0;
    report('utf8mb4_bin collation behavior', $binaryOk, $binaryOk ? 'case-distinct values compare differently' : 'case-distinct values compare equally');
    $ok = $ok && $binaryOk;

    $pdo->exec("INSERT INTO {$quotedTable} (source_name, active, worker_ref) VALUES ('UniqueProbe', 1, 99)");
    $duplicateRejected = false;
    try { $pdo->exec("INSERT INTO {$quotedTable} (source_name, active, worker_ref) VALUES ('uniqueprobe', 0, 100)"); }
    catch (PDOException $e) { $duplicateRejected = (string)$e->getCode() === '23000'; }
    report('Generated-column unique enforcement', $duplicateRejected, $duplicateRejected ? 'LOWER(name) duplicate rejected by unique index' : 'duplicate was not rejected');
    $ok = $ok && $duplicateRejected;
    $activeDuplicateRejected = false;
    try { $pdo->exec("INSERT INTO {$quotedTable} (source_name, active, worker_ref) VALUES ('AnotherName', 1, 99)"); }
    catch (PDOException $e) { $activeDuplicateRejected = (string)$e->getCode() === '23000'; }
    report('Nullable generated unique enforcement', $activeDuplicateRejected, $activeDuplicateRejected ? 'second active key rejected; repeated NULL keys allowed' : 'duplicate active key was not rejected');
    $ok = $ok && $activeDuplicateRejected;
} catch (Throwable $e) {
    // Do not include exception text; PDO errors can contain host, database, or SQL details.
    report('MariaDB preflight connection/schema probe', false, 'connection or probe failed; details suppressed to protect credentials');
    $ok = false;
} finally {
    if ($pdo instanceof PDO && $tableCreated) {
        try { $pdo->exec("DROP TEMPORARY TABLE IF EXISTS {$quotedTable}"); }
        catch (Throwable $e) { fwrite(STDERR, "FAIL: temporary table cleanup failed; close the database session immediately.\n"); $ok = false; }
    }
}

exit($ok ? 0 : 1);
