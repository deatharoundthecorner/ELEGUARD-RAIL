<?php
/**
 * EleGuard Rail — Milestone 9C Stage J Phase J.3
 * Privilege Separation Security Verification Suite
 * Change Reference: ER-M9C-STAGE-J-J3-005
 * 
 * Verifies 11 Engine-Level & Application-Level Security Invariants:
 * 1. Restricted writer can perform required CRUD operations on a disposable database.
 * 2. Cannot read eleguard_rail (MariaDB engine access denial).
 * 3. Cannot write to eleguard_rail (MariaDB engine access denial).
 * 4. Cannot read eleguard_rail_test (MariaDB engine access denial).
 * 5. Cannot write to eleguard_rail_test (MariaDB engine access denial).
 * 6. Cannot access unrelated schemas (e.g. mysql.user).
 * 7. Cannot CREATE DATABASE (MariaDB engine access denial).
 * 8. Cannot DROP DATABASE (MariaDB engine access denial).
 * 9. Missing DB_TEST_USER fails closed with RuntimeException (no silent fallback).
 * 10. DB_TEST_USER=root fails closed with RuntimeException (root strictly prohibited).
 * 11. Concurrent worker / child processes connect as eleguard_test_rw (CURRENT_USER).
 */

define('ELEGUARD_TEST_SUITE', true);
define('ELEGUARD_TEST_ENVIRONMENT', true);

$baseDir = str_replace('\\', '/', dirname(__DIR__));
require_once $baseDir . '/app/config/database.php';
require_once $baseDir . '/tests/bootstrap.php';

function pass($desc) { echo "  \033[32m[PASS]\033[0m " . $desc . PHP_EOL; }
function fail($desc, $err = '') { 
    echo "  \033[31m[FAIL]\033[0m " . $desc . ($err ? " — \033[33m" . $err . "\033[0m" : "") . PHP_EOL; 
    $GLOBALS['testsFailed']++;
}
function section($title) { echo PHP_EOL . "--- " . strtoupper($title) . " ---" . PHP_EOL; }

$testsRun = 0;
$testsFailed = 0;

function assertCondition($cond, $desc, $err = '') {
    $GLOBALS['testsRun']++;
    if ($cond) {
        pass($desc);
    } else {
        fail($desc, $err);
    }
}

echo "================================================================================" . PHP_EOL;
echo " EleGuard Rail — Stage J Phase J.3 Privilege Separation Security Suite" . PHP_EOL;
echo "================================================================================" . PHP_EOL;

// -----------------------------------------------------------------------------
// STEP 0: Admin Setup of Temporary Sandbox Database
// -----------------------------------------------------------------------------
$disposableDb = 'eleguard_test_tmp_' . bin2hex(random_bytes(6));
$dbHost = defined('DB_HOST') ? DB_HOST : '127.0.0.1';
$dbPort = defined('DB_PORT') ? (int)DB_PORT : 3308;

$adminConfigFile = getenv('USERPROFILE') ? getenv('USERPROFILE') . '/.eleguard-test-admin.php' : 'C:/Users/user/.eleguard-test-admin.php';
if (file_exists($adminConfigFile)) {
    $adminConfig = require $adminConfigFile;
    $adminUser = $adminConfig['admin_user'];
    $adminPass = $adminConfig['admin_pass'];
} else {
    $adminUser = defined('DB_USER') ? DB_USER : 'root';
    $adminPass = defined('DB_PASS') ? DB_PASS : '';
}

$adminPdo = new PDO("mysql:host={$dbHost};port={$dbPort}", $adminUser, $adminPass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
]);

// Create ephemeral test database for CRUD testing
$adminPdo->exec("CREATE DATABASE `{$disposableDb}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$adminPdo->exec("CREATE TABLE `{$disposableDb}`.test_crud (id INT AUTO_INCREMENT PRIMARY KEY, note VARCHAR(100))");

// Connect as eleguard_test_rw directly to verify engine-level permissions
$rwUser = getenv('DB_TEST_USER') ?: (defined('DB_TEST_USER') ? DB_TEST_USER : 'eleguard_test_rw');
$rwPass = getenv('DB_TEST_PASS') !== false ? getenv('DB_TEST_PASS') : (defined('DB_TEST_PASS') ? DB_TEST_PASS : '');

$rwPdo = new PDO("mysql:host={$dbHost};port={$dbPort};dbname={$disposableDb};charset=utf8mb4", $rwUser, $rwPass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
]);

// Guarantee teardown on exit
register_shutdown_function(function() use ($adminPdo, $disposableDb) {
    try {
        $adminPdo->exec("DROP DATABASE IF EXISTS `{$disposableDb}`");
    } catch (Throwable $t) {}
});

// =============================================================================
// TEST 1: CRUD on Disposable Database (Engine Allowed)
// =============================================================================
section("1. Disposable Database CRUD Operations");
$crudSuccess = false;
try {
    // INSERT
    $rwPdo->exec("INSERT INTO test_crud (note) VALUES ('test_entry_1')");
    $insertId = (int)$rwPdo->lastInsertId();

    // SELECT
    $stmt = $rwPdo->prepare("SELECT note FROM test_crud WHERE id = ?");
    $stmt->execute([$insertId]);
    $note = $stmt->fetchColumn();

    // UPDATE
    $rwPdo->prepare("UPDATE test_crud SET note = ? WHERE id = ?")->execute(['updated_entry', $insertId]);
    $stmt->execute([$insertId]);
    $updatedNote = $stmt->fetchColumn();

    // DELETE
    $rwPdo->prepare("DELETE FROM test_crud WHERE id = ?")->execute([$insertId]);
    $stmt->execute([$insertId]);
    $deletedRow = $stmt->fetch();

    if ($insertId > 0 && $note === 'test_entry_1' && $updatedNote === 'updated_entry' && $deletedRow === false) {
        $crudSuccess = true;
    }
} catch (Exception $e) {
    $crudSuccess = false;
}
assertCondition($crudSuccess, "1.1 Restricted writer performs INSERT, SELECT, UPDATE, DELETE in disposable database");

// =============================================================================
// TEST 2: Read Access Denial on Operational DB (eleguard_rail)
// =============================================================================
section("2. Operational Database Engine Read Access Denial");
$opReadDenied = false;
$opReadSqlState = '';
try {
    $rwPdo->query("SELECT COUNT(*) FROM `eleguard_rail`.elephant_reports");
} catch (PDOException $e) {
    $opReadSqlState = (string)$e->getCode();
    if ($opReadSqlState === '42000' || strpos($e->getMessage(), 'Access denied') !== false || strpos($e->getMessage(), 'command denied') !== false) {
        $opReadDenied = true;
    }
}
assertCondition($opReadDenied, "2.1 MariaDB engine strictly denies SELECT on operational DB 'eleguard_rail' (SQLSTATE {$opReadSqlState})");

// =============================================================================
// TEST 3: Write Access Denial on Operational DB (eleguard_rail)
// =============================================================================
section("3. Operational Database Engine Write Access Denial");
$opWriteDenied = false;
$opWriteSqlState = '';
try {
    $rwPdo->exec("INSERT INTO `eleguard_rail`.system_logs (action) VALUES ('privilege_violation_test')");
} catch (PDOException $e) {
    $opWriteSqlState = (string)$e->getCode();
    if ($opWriteSqlState === '42000' || strpos($e->getMessage(), 'Access denied') !== false || strpos($e->getMessage(), 'command denied') !== false) {
        $opWriteDenied = true;
    }
}
assertCondition($opWriteDenied, "3.1 MariaDB engine strictly denies INSERT on operational DB 'eleguard_rail' (SQLSTATE {$opWriteSqlState})");

// =============================================================================
// TEST 4: Read Access Denial on Persistent Test DB (eleguard_rail_test)
// =============================================================================
section("4. Persistent Test Database Engine Read Access Denial");
$testReadDenied = false;
$testReadSqlState = '';
try {
    $rwPdo->query("SELECT COUNT(*) FROM `eleguard_rail_test`.elephant_reports");
} catch (PDOException $e) {
    $testReadSqlState = (string)$e->getCode();
    if ($testReadSqlState === '42000' || strpos($e->getMessage(), 'Access denied') !== false || strpos($e->getMessage(), 'command denied') !== false) {
        $testReadDenied = true;
    }
}
assertCondition($testReadDenied, "4.1 MariaDB engine strictly denies SELECT on persistent test DB 'eleguard_rail_test' (SQLSTATE {$testReadSqlState})");

// =============================================================================
// TEST 5: Write Access Denial on Persistent Test DB (eleguard_rail_test)
// =============================================================================
section("5. Persistent Test Database Engine Write Access Denial");
$testWriteDenied = false;
$testWriteSqlState = '';
try {
    $rwPdo->exec("INSERT INTO `eleguard_rail_test`.system_logs (action) VALUES ('privilege_violation_test')");
} catch (PDOException $e) {
    $testWriteSqlState = (string)$e->getCode();
    if ($testWriteSqlState === '42000' || strpos($e->getMessage(), 'Access denied') !== false || strpos($e->getMessage(), 'command denied') !== false) {
        $testWriteDenied = true;
    }
}
assertCondition($testWriteDenied, "5.1 MariaDB engine strictly denies INSERT on persistent test DB 'eleguard_rail_test' (SQLSTATE {$testWriteSqlState})");

// =============================================================================
// TEST 6: Unrelated Schema Access Denial (mysql)
// =============================================================================
section("6. Unrelated Schema Access Denial");
$unrelatedDenied = false;
$unrelatedSqlState = '';
try {
    $rwPdo->query("SELECT User FROM mysql.user LIMIT 1");
} catch (PDOException $e) {
    $unrelatedSqlState = (string)$e->getCode();
    if ($unrelatedSqlState === '42000' || strpos($e->getMessage(), 'Access denied') !== false) {
        $unrelatedDenied = true;
    }
}
assertCondition($unrelatedDenied, "6.1 MariaDB engine strictly denies access to unrelated schema 'mysql' (SQLSTATE {$unrelatedSqlState})");

// =============================================================================
// TEST 7: CREATE DATABASE Denial
// =============================================================================
section("7. CREATE DATABASE Privilege Denial");
$createDbDenied = false;
$createDbSqlState = '';
try {
    $rwPdo->exec("CREATE DATABASE eleguard_test_tmp_unauthorized_createdb");
} catch (PDOException $e) {
    $createDbSqlState = (string)$e->getCode();
    if ($createDbSqlState === '42000' || strpos($e->getMessage(), 'Access denied') !== false) {
        $createDbDenied = true;
    }
}
assertCondition($createDbDenied, "7.1 MariaDB engine strictly denies CREATE DATABASE to restricted writer (SQLSTATE {$createDbSqlState})");

// =============================================================================
// TEST 8: DROP DATABASE Denial
// =============================================================================
section("8. DROP DATABASE Privilege Denial");
$dropDbDenied = false;
$dropDbSqlState = '';
try {
    $rwPdo->exec("DROP DATABASE `{$disposableDb}`");
} catch (PDOException $e) {
    $dropDbSqlState = (string)$e->getCode();
    if ($dropDbSqlState === '42000' || strpos($e->getMessage(), 'Access denied') !== false) {
        $dropDbDenied = true;
    }
}
assertCondition($dropDbDenied, "8.1 MariaDB engine strictly denies DROP DATABASE to restricted writer (SQLSTATE {$dropDbSqlState})");

// =============================================================================
// TEST 9: Missing DB_TEST_USER Fails Closed (No Fallback)
// =============================================================================
section("9. Application Credential Gating: Missing DB_TEST_USER");
$phpCodeMissing = <<<PHP
define('ELEGUARD_TEST_ENVIRONMENT', true);
putenv('ELEGUARD_TEST_CONTEXT=1');
putenv('DB_NAME={$disposableDb}');
putenv('DB_TEST_USER='); // Missing test user
\$_ENV['DB_TEST_USER'] = '';
require_once "{$baseDir}/app/core/Database.php";
try {
    Database::getInstance()->getConnection();
    echo "NO_EXCEPTION";
} catch (RuntimeException \$e) {
    echo \$e->getMessage();
}
PHP;

$tmpMissing = sys_get_temp_dir() . '/test_missing_user_' . uniqid() . '.php';
file_put_contents($tmpMissing, "<?php\n" . $phpCodeMissing);
$outMissing = shell_exec("\"C:\\xampp\\php\\php.exe\" \"{$tmpMissing}\" 2>&1");
@unlink($tmpMissing);

$missingFailedClosed = (strpos($outMissing, 'Dedicated test writer user (DB_TEST_USER) is not configured') !== false);
assertCondition($missingFailedClosed, "9.1 Missing DB_TEST_USER fails closed immediately with RuntimeException (no root fallback)");

// =============================================================================
// TEST 10: DB_TEST_USER=root Fails Closed (Root Prohibited)
// =============================================================================
section("10. Application Credential Gating: Root Prohibited");
$phpCodeRoot = <<<PHP
define('ELEGUARD_TEST_ENVIRONMENT', true);
putenv('ELEGUARD_TEST_CONTEXT=1');
putenv('DB_NAME={$disposableDb}');
putenv('DB_TEST_USER=root'); // Prohibited root user
\$_ENV['DB_TEST_USER'] = 'root';
require_once "{$baseDir}/app/core/Database.php";
try {
    Database::getInstance()->getConnection();
    echo "NO_EXCEPTION";
} catch (RuntimeException \$e) {
    echo \$e->getMessage();
}
PHP;

$tmpRoot = sys_get_temp_dir() . '/test_root_user_' . uniqid() . '.php';
file_put_contents($tmpRoot, "<?php\n" . $phpCodeRoot);
$outRoot = shell_exec("\"C:\\xampp\\php\\php.exe\" \"{$tmpRoot}\" 2>&1");
@unlink($tmpRoot);

$rootFailedClosed = (strpos($outRoot, 'Application test execution using \'root\' is strictly prohibited') !== false);
assertCondition($rootFailedClosed, "10.1 DB_TEST_USER=root fails closed immediately with RuntimeException");

// =============================================================================
// TEST 11: Subprocess Workers Connect as eleguard_test_rw
// =============================================================================
section("11. Subprocess Worker & Application Credential Inheritance");
$phpCodeWorker = <<<PHP
define('ELEGUARD_TEST_ENVIRONMENT', true);
putenv('ELEGUARD_TEST_CONTEXT=1');
putenv('DB_NAME={$disposableDb}');
putenv('DB_TEST_USER={$rwUser}');
putenv('DB_TEST_PASS={$rwPass}');
\$_ENV['DB_TEST_USER'] = '{$rwUser}';
\$_ENV['DB_TEST_PASS'] = '{$rwPass}';
require_once "{$baseDir}/app/core/Database.php";
\$pdo = Database::getInstance()->getConnection();
\$currUser = \$pdo->query("SELECT CURRENT_USER()")->fetchColumn();
echo \$currUser;
PHP;

$tmpWorker = sys_get_temp_dir() . '/test_worker_user_' . uniqid() . '.php';
file_put_contents($tmpWorker, "<?php\n" . $phpCodeWorker);
$outWorker = trim(shell_exec("\"C:\\xampp\\php\\php.exe\" \"{$tmpWorker}\" 2>&1"));
@unlink($tmpWorker);

$workerConnectedAsRw = (strpos($outWorker, 'eleguard_test_rw@') === 0);
assertCondition($workerConnectedAsRw, "11.1 Subprocess worker connects as 'eleguard_test_rw' (Got: {$outWorker})");

// Teardown sandbox database
$adminPdo->exec("DROP DATABASE IF EXISTS `{$disposableDb}`");

echo PHP_EOL . "================================================================================" . PHP_EOL;
echo " PRIVILEGE SEPARATION TEST RESULTS: {$testsRun} Run, " . ($testsRun - $testsFailed) . " Passed, {$testsFailed} Failed" . PHP_EOL;
echo "================================================================================" . PHP_EOL;

if ($testsFailed === 0) {
    echo "  \033[32m[SUCCESS] ALL 11 PRIVILEGE SEPARATION TESTS PASSED CLEANLY.\033[0m" . PHP_EOL;
    exit(0);
} else {
    echo "  \033[31m[FAILURE] {$testsFailed} SECURITY TEST(S) FAILED.\033[0m" . PHP_EOL;
    exit(1);
}
