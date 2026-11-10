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
if (!defined('APPROVED_TEST_DATABASE')) {
    define('APPROVED_TEST_DATABASE', 'eleguard_rail_test');
}
if (!defined('OPERATIONAL_DATABASE')) {
    define('OPERATIONAL_DATABASE', 'eleguard_rail');
}

/**
 * Validates that the targeted database is strictly the approved test database.
 * Fails closed if missing, empty, or targeting the operational database.
 *
 * @param string|null $requestedDb Database name to check
 * @return bool True if valid
 * @throws RuntimeException If safety validation fails
 */
function validateTestDatabaseTarget($requestedDb = '__USE_ENV__') {
    if ($requestedDb === '__USE_ENV__') {
        $requestedDb = getenv('DB_NAME') ?: ($_ENV['DB_NAME'] ?? (defined('DB_NAME') ? DB_NAME : null));
    }

    if ($requestedDb === null || trim($requestedDb) === '') {
        throw new RuntimeException("DATABASE SAFETY VIOLATION: DB_NAME is missing or empty in test environment. Tests must explicitly target '" . APPROVED_TEST_DATABASE . "'.");
    }

    $trimmed = strtolower(trim($requestedDb));

    if ($trimmed === strtolower(OPERATIONAL_DATABASE)) {
        throw new RuntimeException("FATAL DATABASE SAFETY VIOLATION: Automated test attempted to target operational database '" . OPERATIONAL_DATABASE . "'. Test execution strictly halted.");
    }

    if ($trimmed !== strtolower(APPROVED_TEST_DATABASE)) {
        throw new RuntimeException("DATABASE SAFETY VIOLATION: Database '{$requestedDb}' is not an approved test database. Tests must target '" . APPROVED_TEST_DATABASE . "'.");
    }

    return true;
}

/**
 * Enforces test database routing in environment variables.
 */
function enforceTestDatabaseEnvironment() {
    $current = getenv('DB_NAME') ?: ($_ENV['DB_NAME'] ?? null);
    if ($current !== null && strtolower(trim($current)) === strtolower(OPERATIONAL_DATABASE)) {
        throw new RuntimeException("FATAL DATABASE SAFETY VIOLATION: DB_NAME is configured as operational database '" . OPERATIONAL_DATABASE . "'.");
    }

    putenv('DB_NAME=' . APPROVED_TEST_DATABASE);
    $_ENV['DB_NAME'] = APPROVED_TEST_DATABASE;
    validateTestDatabaseTarget(APPROVED_TEST_DATABASE);
}

/**
 * Ensures the isolated test database exists and is populated with baseline tables.
 *
 * @param PDO|null $opPdo
 */
function ensureTestDatabaseExists(PDO $opPdo = null) {
    validateTestDatabaseTarget(APPROVED_TEST_DATABASE);

    if ($opPdo === null) {
        $host = getenv('DB_HOST') ?: (defined('DB_HOST') ? DB_HOST : '127.0.0.1');
        $port = getenv('DB_PORT') ?: (defined('DB_PORT') ? DB_PORT : 3308);
        $user = getenv('DB_USER') ?: (defined('DB_USER') ? DB_USER : 'root');
        $pass = getenv('DB_PASS') ?: (defined('DB_PASS') ? DB_PASS : '');
        $opPdo = new PDO("mysql:host={$host};port={$port};charset=utf8mb4", $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
        ]);
    }

    $testDb = APPROVED_TEST_DATABASE;
    $opPdo->exec("CREATE DATABASE IF NOT EXISTS `{$testDb}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

    $testTables = $opPdo->query("SHOW TABLES FROM `{$testDb}`")->fetchAll(PDO::FETCH_COLUMN);
    if (empty($testTables)) {
        $opDb = OPERATIONAL_DATABASE;
        $tables = $opPdo->query("SHOW TABLES FROM `{$opDb}`")->fetchAll(PDO::FETCH_COLUMN);
        $opPdo->exec("SET FOREIGN_KEY_CHECKS = 0");
        foreach ($tables as $t) {
            $createSql = $opPdo->query("SHOW CREATE TABLE `{$opDb}`.`{$t}`")->fetch(PDO::FETCH_ASSOC)['Create Table'];
            $opPdo->exec("USE `{$testDb}`");
            $opPdo->exec($createSql);
            $opPdo->exec("INSERT INTO `{$testDb}`.`{$t}` SELECT * FROM `{$opDb}`.`{$t}`");
        }
        $opPdo->exec("SET FOREIGN_KEY_CHECKS = 1");
    }
}

/**
 * Safely tears down the test database, strictly prohibiting operational DB deletion.
 *
 * @param PDO $pdo
 * @param string $dbName
 * @throws RuntimeException If attempted on operational database or unapproved target
 */
function safeTeardownTestDatabase(PDO $pdo, $dbName) {
    if (empty($dbName) || strtolower(trim($dbName)) === strtolower(OPERATIONAL_DATABASE)) {
        throw new RuntimeException("FATAL DATABASE SAFETY VIOLATION: Refusing to teardown/drop operational database '" . OPERATIONAL_DATABASE . "'!");
    }
    if (strtolower(trim($dbName)) !== strtolower(APPROVED_TEST_DATABASE)) {
        throw new RuntimeException("DATABASE SAFETY VIOLATION: Refusing to teardown unapproved database '{$dbName}'!");
    }
    $pdo->exec("DROP DATABASE IF EXISTS `{$dbName}`");
}

// Automatically enforce test environment when bootstrap is loaded
enforceTestDatabaseEnvironment();
