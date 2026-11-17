<?php
/**
 * EleGuard Rail — Central Test Suite Bootstrap & Database Safety Guard
 * 
 * Enforces strict environment separation between operational and test databases.
 * Guarantees that write-capable test suites cannot target or fall back to eleguard_rail.
 * 
 * Milestone 9C Stage 4C.2: Database Safety Hardening
 */

if (!defined('ELEGUARD_TEST_ENVIRONMENT')) {
    define('ELEGUARD_TEST_ENVIRONMENT', true);
}
if (!defined('OPERATIONAL_DATABASE')) {
    define('OPERATIONAL_DATABASE', 'eleguard_rail');
}
if (!defined('PROTECTED_TEST_DATABASE')) {
    define('PROTECTED_TEST_DATABASE', 'eleguard_rail_test');
}
// Legacy alias constant maintained for backwards compatibility, but marked protected
if (!defined('APPROVED_TEST_DATABASE')) {
    define('APPROVED_TEST_DATABASE', 'eleguard_rail_test');
}
if (!defined('APPROVED_EPHEMERAL_DATABASE')) {
    define('APPROVED_EPHEMERAL_DATABASE', 'eleguard_migration_test');
}

/**
 * Checks whether a database name matches an approved disposable test database pattern.
 *
 * @param string|null $dbName Database name to evaluate
 * @return bool True if approved disposable database, false otherwise
 */
function isApprovedDisposableDatabase($dbName): bool {
    if (empty($dbName)) {
        return false;
    }
    $trimmed = strtolower(trim((string)$dbName));
    if ($trimmed === strtolower(OPERATIONAL_DATABASE) || $trimmed === strtolower(PROTECTED_TEST_DATABASE)) {
        return false;
    }
    // Pattern: eleguard_test_tmp_<hex>, eleguard_disposable_<any>, eleguard_migration_test
    if (preg_match('/^eleguard_test_tmp_[a-f0-9]{6,16}$/i', $trimmed)) {
        return true;
    }
    if (preg_match('/^eleguard_disposable_[a-z0-9_]+$/i', $trimmed)) {
        return true;
    }
    if ($trimmed === 'eleguard_migration_test' || $trimmed === 'eleguard_rail_test_disposable') {
        return true;
    }
    return false;
}

/**
 * Validates that the targeted database is strictly an approved disposable test database.
 * Fails closed if missing, empty, or targeting either operational or protected persistent test databases.
 *
 * @param string|null $requestedDb Database name to check
 * @return bool True if valid
 * @throws RuntimeException If safety validation fails
 */
function validateTestDatabaseTarget($requestedDb = '__USE_ENV__') {
    if ($requestedDb === '__USE_ENV__') {
        $requestedDb = getenv('DB_NAME') ?: ($_ENV['DB_NAME'] ?? (defined('DB_NAME') ? DB_NAME : null));
    }

    if ($requestedDb === null || trim((string)$requestedDb) === '') {
        throw new RuntimeException("DATABASE SAFETY VIOLATION: DB_NAME is missing or empty in test environment. Tests must explicitly target an approved disposable test database (e.g. 'eleguard_test_tmp_*').");
    }

    $trimmed = strtolower(trim((string)$requestedDb));

    if ($trimmed === strtolower(OPERATIONAL_DATABASE)) {
        throw new RuntimeException("FATAL DATABASE SAFETY VIOLATION: Automated test attempted to target operational database '" . OPERATIONAL_DATABASE . "'. Test execution strictly halted.");
    }

    if ($trimmed === strtolower(PROTECTED_TEST_DATABASE)) {
        throw new RuntimeException("FATAL DATABASE SAFETY VIOLATION: Automated test attempted to target protected persistent test database '" . PROTECTED_TEST_DATABASE . "'. Tests must target a unique disposable test database.");
    }

    if (!isApprovedDisposableDatabase($trimmed)) {
        throw new RuntimeException("DATABASE SAFETY VIOLATION: Database '{$requestedDb}' is not an approved disposable test database. Tests must target a disposable test database matching 'eleguard_test_tmp_*'.");
    }

    return true;
}

/**
 * Enforces test database routing in environment variables.
 * Guarantees that neither operational database nor persistent test database can be targeted.
 */
function enforceTestDatabaseEnvironment() {
    $current = getenv('DB_NAME') ?: ($_ENV['DB_NAME'] ?? null);
    if ($current !== null && strtolower(trim((string)$current)) === strtolower(OPERATIONAL_DATABASE)) {
        throw new RuntimeException("FATAL DATABASE SAFETY VIOLATION: DB_NAME is configured as operational database '" . OPERATIONAL_DATABASE . "'.");
    }
    if ($current !== null && strtolower(trim((string)$current)) === strtolower(PROTECTED_TEST_DATABASE)) {
        throw new RuntimeException("FATAL DATABASE SAFETY VIOLATION: DB_NAME is configured as protected persistent test database '" . PROTECTED_TEST_DATABASE . "'. Tests must target a disposable test database.");
    }

    if ($current === null || trim((string)$current) === '') {
        $disposableDb = 'eleguard_test_tmp_' . bin2hex(random_bytes(6));
        putenv('DB_NAME=' . $disposableDb);
        $_ENV['DB_NAME'] = $disposableDb;
    } else {
        validateTestDatabaseTarget($current);
    }
}

/**
 * Restores a test database using canonical schema and seed files into an approved disposable database.
 * NEVER reads from or clones the operational database (eleguard_rail) or persistent test database (eleguard_rail_test).
 *
 * @param PDO $pdo Privileged PDO instance for test schema management
 * @param string|null $testDb Target disposable test database name
 * @throws RuntimeException If target is operational or invalid
 */
function restoreCanonicalTestDatabase(PDO $pdo, $testDb = null) {
    if ($testDb === null) {
        $testDb = getenv('DB_NAME') ?: ($_ENV['DB_NAME'] ?? null);
    }
    if (empty($testDb) || strtolower(trim((string)$testDb)) === strtolower(OPERATIONAL_DATABASE)) {
        throw new RuntimeException("FATAL DATABASE SAFETY VIOLATION: Refusing to restore operational database '" . OPERATIONAL_DATABASE . "'!");
    }
    if (strtolower(trim((string)$testDb)) === strtolower(PROTECTED_TEST_DATABASE)) {
        throw new RuntimeException("FATAL DATABASE SAFETY VIOLATION: Refusing to restore protected persistent test database '" . PROTECTED_TEST_DATABASE . "'! Tests must restore into a disposable database.");
    }
    if (!isApprovedDisposableDatabase($testDb)) {
        throw new RuntimeException("DATABASE SAFETY VIOLATION: Refusing to restore unapproved database '{$testDb}'!");
    }

    $backupFile = dirname(__DIR__) . '/tests/fixtures/canonical_baseline.sql';
    if (!file_exists($backupFile)) {
        // Fallback for legacy path if present
        $legacyBackup = dirname(__DIR__) . '/database/backups/backup_before_9c_stage1.sql';
        if (file_exists($legacyBackup)) {
            $backupFile = $legacyBackup;
        }
    }
    $mig03File = dirname(__DIR__) . '/database/migrations/03_add_temporal_intelligence_to_elephant_reports.sql';

    if (!file_exists($backupFile)) {
        throw new RuntimeException("CANONICAL FIXTURE ERROR: Baseline schema backup missing: {$backupFile}");
    }
    if (!file_exists($mig03File)) {
        throw new RuntimeException("CANONICAL FIXTURE ERROR: Migration 03 file missing: {$mig03File}");
    }

    $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$testDb}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo->exec("USE `{$testDb}`");
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 0");

    // 1. Execute baseline schema and fixtures
    $baselineSql = file_get_contents($backupFile);
    $pdo->exec($baselineSql);

    // 2. Execute Migration 03 (temporal intelligence schema columns and indexes)
    $mig03Sql = file_get_contents($mig03File);
    $mig03Sql = preg_replace('/USE\s+eleguard_rail;/i', "USE `{$testDb}`;", $mig03Sql);
    $pdo->exec($mig03Sql);

    // 3. Execute Migration 04 (field reporting system backend foundation & v2 schema)
    $mig04File = dirname(__DIR__) . '/database/migrations/04_add_field_reporting_v2_to_elephant_reports.sql';
    if (file_exists($mig04File)) {
        $mig04Sql = file_get_contents($mig04File);
        $mig04Sql = preg_replace('/USE\s+eleguard_rail;/i', "USE `{$testDb}`;", $mig04Sql);
        $pdo->exec($mig04Sql);
    }

    // 4. Execute Migration 05 (verification database foundation: clarification threads & section audit ledgers)
    $mig05File = dirname(__DIR__) . '/database/migrations/05_add_verification_and_section_audits.sql';
    if (file_exists($mig05File)) {
        $mig05Sql = file_get_contents($mig05File);
        $mig05Sql = preg_replace('/USE\s+eleguard_rail;/i', "USE `{$testDb}`;", $mig05Sql);
        $pdo->exec($mig05Sql);
    }

    // 5. Execute Migration 06 (payload fingerprint column for offline idempotency mutation detection)
    $mig06File = dirname(__DIR__) . '/database/migrations/06_add_payload_hash_to_elephant_reports.sql';
    if (file_exists($mig06File)) {
        $mig06Sql = file_get_contents($mig06File);
        $mig06Sql = preg_replace('/USE\s+eleguard_rail;/i', "USE `{$testDb}`;", $mig06Sql);
        $pdo->exec($mig06Sql);
    }

    $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");
}

/**
 * Ensures the isolated test database exists and is populated with canonical baseline tables.
 * Strictly decoupled from both operational database (eleguard_rail) and persistent test database (eleguard_rail_test).
 *
 * @param PDO|null $opPdo
 * @param string|null $testDb
 */
function ensureTestDatabaseExists(PDO $opPdo = null, $testDb = null) {
    if ($testDb === null) {
        $testDb = getenv('DB_NAME') ?: ($_ENV['DB_NAME'] ?? null);
    }
    if (empty($testDb) || strtolower(trim((string)$testDb)) === strtolower(OPERATIONAL_DATABASE)) {
        throw new RuntimeException("FATAL DATABASE SAFETY VIOLATION: Attempted to initialize operational database as test database!");
    }
    if (strtolower(trim((string)$testDb)) === strtolower(PROTECTED_TEST_DATABASE)) {
        throw new RuntimeException("FATAL DATABASE SAFETY VIOLATION: Attempted to initialize protected persistent database '" . PROTECTED_TEST_DATABASE . "'! Tests must use a disposable database.");
    }
    if (!isApprovedDisposableDatabase($testDb)) {
        throw new RuntimeException("DATABASE SAFETY VIOLATION: Refusing to initialize unapproved database '{$testDb}'!");
    }

    if ($opPdo === null) {
        $host = getenv('DB_HOST') ?: (defined('DB_HOST') ? DB_HOST : '127.0.0.1');
        $port = getenv('DB_PORT') ?: (defined('DB_PORT') ? DB_PORT : 3308);
        $user = getenv('DB_USER') ?: (defined('DB_USER') ? DB_USER : 'root');
        $pass = getenv('DB_PASS') ?: (defined('DB_PASS') ? DB_PASS : '');
        $opPdo = new PDO("mysql:host={$host};port={$port};charset=utf8mb4", $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
        ]);
    }

    $opPdo->exec("CREATE DATABASE IF NOT EXISTS `{$testDb}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

    $testTables = $opPdo->query("SHOW TABLES FROM `{$testDb}`")->fetchAll(PDO::FETCH_COLUMN);
    if (empty($testTables)) {
        restoreCanonicalTestDatabase($opPdo, $testDb);
    }
}

/**
 * Safely tears down the test database, strictly prohibiting operational or persistent test DB deletion.
 *
 * @param PDO|null $pdo
 * @param string|null $dbName
 * @throws RuntimeException If attempted on operational database or unapproved target
 */
function safeTeardownTestDatabase(PDO $pdo = null, $dbName = null) {
    if ($dbName === null) {
        $dbName = getenv('DB_NAME') ?: ($_ENV['DB_NAME'] ?? null);
    }
    if (empty($dbName) || strtolower(trim((string)$dbName)) === strtolower(OPERATIONAL_DATABASE)) {
        throw new RuntimeException("FATAL DATABASE SAFETY VIOLATION: Refusing to teardown/drop operational database '" . OPERATIONAL_DATABASE . "'!");
    }
    if (strtolower(trim((string)$dbName)) === strtolower(PROTECTED_TEST_DATABASE)) {
        throw new RuntimeException("FATAL DATABASE SAFETY VIOLATION: Refusing to teardown/drop protected persistent test database '" . PROTECTED_TEST_DATABASE . "'!");
    }
    if (!isApprovedDisposableDatabase($dbName)) {
        throw new RuntimeException("DATABASE SAFETY VIOLATION: Refusing to teardown unapproved database '{$dbName}'!");
    }
    if ($pdo === null) {
        $host = getenv('DB_HOST') ?: (defined('DB_HOST') ? DB_HOST : '127.0.0.1');
        $port = getenv('DB_PORT') ?: (defined('DB_PORT') ? DB_PORT : 3308);
        $user = getenv('DB_USER') ?: (defined('DB_USER') ? DB_USER : 'root');
        $pass = getenv('DB_PASS') ?: (defined('DB_PASS') ? DB_PASS : '');
        $pdo = new PDO("mysql:host={$host};port={$port};charset=utf8mb4", $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
        ]);
    }
    $pdo->exec("DROP DATABASE IF EXISTS `{$dbName}`");
}

/**
 * Captures an immutable before-snapshot of the operational database (eleguard_rail).
 * Includes row counts and cryptographic SHA-256 hashes of all rows.
 *
 * @param PDO|null $pdo Optional existing connection
 * @return array Snapshot data
 */
function captureOperationalDatabaseSnapshot(PDO $pdo = null): array {
    if ($pdo === null) {
        $host = getenv('DB_HOST') ?: (defined('DB_HOST') ? DB_HOST : '127.0.0.1');
        $port = getenv('DB_PORT') ?: (defined('DB_PORT') ? DB_PORT : 3308);
        $user = getenv('DB_ANALYTICS_USER') ?: (defined('DB_ANALYTICS_USER') ? DB_ANALYTICS_USER : 'eleguard_readonly');
        $pass = getenv('DB_ANALYTICS_PASS') ?: (defined('DB_ANALYTICS_PASS') ? DB_ANALYTICS_PASS : 'ReadOnly_Guard#2026');
        $pdo = new PDO("mysql:host={$host};port={$port};dbname=eleguard_rail;charset=utf8mb4", $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]);
    }
    
    $tables = ['elephant_reports', 'system_logs', 'alerts', 'driver_acknowledgements', 'incident_history', 'hotspots', 'railway_sections', 'users'];
    $snapshot = ['tables' => []];
    foreach ($tables as $t) {
        $count = (int)$pdo->query("SELECT COUNT(*) FROM `eleguard_rail`.`{$t}`")->fetchColumn();
        $rows = $pdo->query("SELECT * FROM `eleguard_rail`.`{$t}` ORDER BY 1 ASC")->fetchAll(PDO::FETCH_ASSOC);
        $hash = hash('sha256', json_encode($rows));
        $snapshot['tables'][$t] = [
            'count' => $count,
            'hash' => $hash
        ];
    }
    try {
        $snapshot['report1'] = $pdo->query("SELECT id, sighting_date, sighting_time, risk_score, report_status FROM `eleguard_rail`.elephant_reports WHERE id = 1")->fetch(PDO::FETCH_ASSOC);
    } catch (\Throwable $e) {
        $snapshot['report1'] = null;
    }
    return $snapshot;
}

/**
 * Compares an after-snapshot against a before-snapshot to verify zero operational writes or drift.
 *
 * @param array $preSnapshot Snapshot taken before test execution
 * @param PDO|null $pdo Optional existing connection
 * @return array Verification results
 */
function verifyOperationalDatabaseSnapshot(array $preSnapshot, PDO $pdo = null): array {
    $postSnapshot = captureOperationalDatabaseSnapshot($pdo);
    $diffs = [];
    $tableResults = [];
    $allPassed = true;

    foreach ($preSnapshot['tables'] as $t => $preInfo) {
        $postInfo = $postSnapshot['tables'][$t] ?? ['count' => -1, 'hash' => ''];
        $countMatch = ($preInfo['count'] === $postInfo['count']);
        $hashMatch = ($preInfo['hash'] === $postInfo['hash']);
        $tablePassed = ($countMatch && $hashMatch);
        if (!$tablePassed) {
            $allPassed = false;
            $diffs[$t] = [
                'pre_count' => $preInfo['count'],
                'post_count' => $postInfo['count'],
                'pre_hash' => $preInfo['hash'],
                'post_hash' => $postInfo['hash']
            ];
        }
        $tableResults[$t] = [
            'passed' => $tablePassed,
            'count_match' => $countMatch,
            'hash_match' => $hashMatch,
            'count' => $postInfo['count']
        ];
    }

    if (isset($preSnapshot['report1']) && isset($postSnapshot['report1'])) {
        if ($preSnapshot['report1'] !== $postSnapshot['report1']) {
            $allPassed = false;
            $diffs['report1'] = [
                'pre' => $preSnapshot['report1'],
                'post' => $postSnapshot['report1']
            ];
        }
    }

    return [
        'passed' => $allPassed,
        'diffs' => $diffs,
        'tables' => $tableResults,
        'post' => $postSnapshot
    ];
}

// Automatically enforce test environment when bootstrap is loaded
enforceTestDatabaseEnvironment();
