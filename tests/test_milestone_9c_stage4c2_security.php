<?php
/**
 * EleGuard Rail — Milestone 9C Stage 4C.2 Test Suite
 * Database Safety, Test Isolation & Analytics SQL Hardening
 * 
 * Verifies all 20 mandatory security hardening and isolation requirements:
 * 1. Missing DB_NAME rejection
 * 2. Empty DB_NAME rejection
 * 3. Operational DB_NAME rejection
 * 4. Approved test DB_NAME acceptance
 * 5. Test database connection failure handling
 * 6. Multiple SQL statements rejection
 * 7. SELECT INTO OUTFILE rejection
 * 8. SELECT INTO DUMPFILE rejection
 * 9. SQL comments safe handling
 * 10. Variable assignment rejection
 * 11. FOR UPDATE rejection
 * 12. LOCK IN SHARE MODE rejection
 * 13. Unsafe function calls rejection
 * 14. Normal SELECT execution
 * 15. Parameterized SELECT execution
 * 16. SHOW and DESCRIBE execution
 * 17. Legacy test bootstrap enforcement
 * 18. Test cleanup targeting safety
 * 19. Operational database write prevention
 * 20. Analytics regression compatibility
 */

require_once __DIR__ . '/bootstrap.php';
ensureTestDatabaseExists();

require_once __DIR__ . '/../app/config/database.php';
require_once __DIR__ . '/../app/core/Database.php';
require_once __DIR__ . '/../app/services/AnalyticsService.php';
require_once __DIR__ . '/../app/services/DuplicateService.php';
require_once __DIR__ . '/../app/services/TemporalService.php';
require_once __DIR__ . '/../app/services/ProximityService.php';

$totalTests = 0;
$passedTests = 0;
$failedTests = 0;

function runTest($description, $condition, &$total, &$passed, &$failed, $details = '') {
    $total++;
    if ($condition) {
        $passed++;
        echo "  [PASS] {$description}\n";
    } else {
        $failed++;
        echo "  [FAIL] {$description}\n";
        if ($details) {
            echo "         Details: {$details}\n";
        }
    }
}

echo "========================================================================\n";
echo "EleGuard Rail — Milestone 9C Stage 4C.2 Test Suite\n";
echo "Database Safety, Test Isolation & Analytics SQL Hardening\n";
echo "========================================================================\n\n";

// -------------------------------------------------------------------------
// PRE-FLIGHT CHECK: Operational Database Baseline State
// -------------------------------------------------------------------------
echo "--- 0. PRE-FLIGHT OPERATIONAL SAFETY AUDIT ---\n";
try {
    $opPdo = new PDO("mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=eleguard_rail;charset=utf8mb4", DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);
    
    $opReports = (int)$opPdo->query("SELECT COUNT(*) FROM elephant_reports")->fetchColumn();
    $opLogs = (int)$opPdo->query("SELECT COUNT(*) FROM system_logs")->fetchColumn();
    $opAlerts = (int)$opPdo->query("SELECT COUNT(*) FROM alerts")->fetchColumn();
    $opRep1 = $opPdo->query("SELECT sighting_date, sighting_time, risk_score, report_status FROM elephant_reports WHERE id = 1")->fetch(PDO::FETCH_ASSOC);

    runTest("0.1 Operational DB elephant_reports baseline is 43", $opReports === 43, $totalTests, $passedTests, $failedTests, "Got: $opReports");
    runTest("0.2 Operational DB system_logs baseline is 185", $opLogs === 185, $totalTests, $passedTests, $failedTests, "Got: $opLogs");
    runTest("0.3 Operational DB alerts baseline is 15", $opAlerts === 15, $totalTests, $passedTests, $failedTests, "Got: $opAlerts");
    runTest("0.4 Operational Report #1 sighting time is NULL", $opRep1['sighting_time'] === null, $totalTests, $passedTests, $failedTests);
    runTest("0.5 Operational Report #1 risk score baseline is Medium", $opRep1['risk_score'] === 'Medium', $totalTests, $passedTests, $failedTests);
} catch (Exception $e) {
    echo "  [FATAL] Operational DB pre-flight check failed: " . $e->getMessage() . "\n";
    exit(1);
}

// Connect to isolated test database
$testPdo = new PDO("mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=eleguard_rail_test;charset=utf8mb4", DB_USER, DB_PASS, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
]);

// -------------------------------------------------------------------------
// 1. Missing DB_NAME
// -------------------------------------------------------------------------
echo "\n--- 1. MISSING DB_NAME VALIDATION ---\n";
$missingThrew = false;
try {
    validateTestDatabaseTarget(null);
} catch (RuntimeException $e) {
    $missingThrew = (strpos($e->getMessage(), 'missing or empty') !== false);
}
runTest("1.1 Missing DB_NAME strictly throws RuntimeException", $missingThrew, $totalTests, $passedTests, $failedTests);

// -------------------------------------------------------------------------
// 2. Empty DB_NAME
// -------------------------------------------------------------------------
echo "\n--- 2. EMPTY DB_NAME VALIDATION ---\n";
$emptyThrew = false;
try {
    validateTestDatabaseTarget("   ");
} catch (RuntimeException $e) {
    $emptyThrew = (strpos($e->getMessage(), 'missing or empty') !== false);
}
runTest("2.1 Empty/whitespace DB_NAME strictly throws RuntimeException", $emptyThrew, $totalTests, $passedTests, $failedTests);

// -------------------------------------------------------------------------
// 3. Operational DB_NAME
// -------------------------------------------------------------------------
echo "\n--- 3. OPERATIONAL DB_NAME REJECTION ---\n";
$operThrew = false;
try {
    validateTestDatabaseTarget("eleguard_rail");
} catch (RuntimeException $e) {
    $operThrew = (strpos($e->getMessage(), 'operational database') !== false);
}
runTest("3.1 Exact operational DB_NAME 'eleguard_rail' strictly blocked", $operThrew, $totalTests, $passedTests, $failedTests);

$operCaseThrew = false;
try {
    validateTestDatabaseTarget("ELEGUARD_RAIL");
} catch (RuntimeException $e) {
    $operCaseThrew = (strpos($e->getMessage(), 'operational database') !== false);
}
runTest("3.2 Case-insensitive operational DB_NAME 'ELEGUARD_RAIL' strictly blocked", $operCaseThrew, $totalTests, $passedTests, $failedTests);

// -------------------------------------------------------------------------
// 4. Approved Test DB_NAME
// -------------------------------------------------------------------------
echo "\n--- 4. APPROVED TEST DB_NAME ACCEPTANCE ---\n";
$approvedPassed = false;
try {
    $approvedPassed = validateTestDatabaseTarget("eleguard_rail_test");
} catch (Exception $e) {
    $approvedPassed = false;
}
runTest("4.1 Approved test DB_NAME 'eleguard_rail_test' validated successfully", $approvedPassed === true, $totalTests, $passedTests, $failedTests);

$unapprovedThrew = false;
try {
    validateTestDatabaseTarget("random_production_db");
} catch (RuntimeException $e) {
    $unapprovedThrew = (strpos($e->getMessage(), 'not an approved test database') !== false);
}
runTest("4.2 Unapproved database name strictly blocked", $unapprovedThrew, $totalTests, $passedTests, $failedTests);

// -------------------------------------------------------------------------
// 5. Test Database Connection Failure Handling
// -------------------------------------------------------------------------
echo "\n--- 5. TEST DATABASE CONNECTION FAILURE HANDLING ---\n";
$connThrew = false;
try {
    // Attempt connection on invalid port
    $badPdo = new PDO("mysql:host=127.0.0.1;port=9999;dbname=eleguard_rail_test;charset=utf8mb4", "root", "", [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_TIMEOUT => 1
    ]);
} catch (PDOException $e) {
    $connThrew = true;
}
runTest("5.1 Connection failure on unreachable port raises clean catchable exception", $connThrew, $totalTests, $passedTests, $failedTests);

// -------------------------------------------------------------------------
// 6. Multiple SQL Statements
// -------------------------------------------------------------------------
echo "\n--- 6. MULTIPLE SQL STATEMENTS REJECTION ---\n";
$multiThrew = false;
try {
    AnalyticsService::executeReadOnlyQuery($testPdo, "SELECT 1; SELECT 2;");
} catch (RuntimeException $e) {
    $multiThrew = (strpos($e->getMessage(), 'Multiple SQL statements are strictly prohibited') !== false);
}
runTest("6.1 Multiple SQL statements separated by semicolon strictly rejected", $multiThrew, $totalTests, $passedTests, $failedTests);

// -------------------------------------------------------------------------
// 7. SELECT INTO OUTFILE
// -------------------------------------------------------------------------
echo "\n--- 7. SELECT INTO OUTFILE REJECTION ---\n";
$outfileThrew = false;
try {
    AnalyticsService::executeReadOnlyQuery($testPdo, "SELECT * FROM elephant_reports INTO OUTFILE '/tmp/dump.csv'");
} catch (RuntimeException $e) {
    $outfileThrew = (strpos($e->getMessage(), 'File export operations') !== false);
}
runTest("7.1 SELECT INTO OUTFILE statement strictly rejected", $outfileThrew, $totalTests, $passedTests, $failedTests);

// -------------------------------------------------------------------------
// 8. SELECT INTO DUMPFILE
// -------------------------------------------------------------------------
echo "\n--- 8. SELECT INTO DUMPFILE REJECTION ---\n";
$dumpfileThrew = false;
try {
    AnalyticsService::executeReadOnlyQuery($testPdo, "SELECT * FROM elephant_reports INTO DUMPFILE '/tmp/dump.bin'");
} catch (RuntimeException $e) {
    $dumpfileThrew = (strpos($e->getMessage(), 'File export operations') !== false);
}
runTest("8.1 SELECT INTO DUMPFILE statement strictly rejected", $dumpfileThrew, $totalTests, $passedTests, $failedTests);

// -------------------------------------------------------------------------
// 9. SQL Comments Safe Handling
// -------------------------------------------------------------------------
echo "\n--- 9. SQL COMMENTS SAFE HANDLING ---\n";
// Safe comments stripped and executed
$commentRes = AnalyticsService::executeReadOnlyQuery($testPdo, "/* safe comment */ SELECT id FROM railway_sections WHERE id = 1");
runTest("9.1 Valid query with block comments executes cleanly after normalization", !empty($commentRes) && (int)$commentRes[0]['id'] === 1, $totalTests, $passedTests, $failedTests);

$lineCommentRes = AnalyticsService::executeReadOnlyQuery($testPdo, "-- inline comment\n SELECT id FROM railway_sections WHERE id = 1");
runTest("9.2 Valid query with line comment executes cleanly after normalization", !empty($lineCommentRes) && (int)$lineCommentRes[0]['id'] === 1, $totalTests, $passedTests, $failedTests);

// Disguised mutation inside comments rejected
$disguisedThrew = false;
try {
    AnalyticsService::executeReadOnlyQuery($testPdo, "/* comment */ DROP TABLE users;");
} catch (RuntimeException $e) {
    $disguisedThrew = true;
}
runTest("9.3 Mutation hidden behind comments strictly rejected", $disguisedThrew, $totalTests, $passedTests, $failedTests);

// -------------------------------------------------------------------------
// 10. Variable Assignment
// -------------------------------------------------------------------------
echo "\n--- 10. VARIABLE ASSIGNMENT REJECTION ---\n";
$varAssignThrew = false;
try {
    AnalyticsService::executeReadOnlyQuery($testPdo, "SELECT @leak := id FROM users LIMIT 1");
} catch (RuntimeException $e) {
    $varAssignThrew = (strpos($e->getMessage(), 'Variable assignment is strictly prohibited') !== false);
}
runTest("10.1 In-query variable assignment (@var :=) strictly rejected", $varAssignThrew, $totalTests, $passedTests, $failedTests);

// -------------------------------------------------------------------------
// 11. FOR UPDATE
// -------------------------------------------------------------------------
echo "\n--- 11. FOR UPDATE REJECTION ---\n";
$forUpdateThrew = false;
try {
    AnalyticsService::executeReadOnlyQuery($testPdo, "SELECT * FROM elephant_reports FOR UPDATE");
} catch (RuntimeException $e) {
    $forUpdateThrew = (strpos($e->getMessage(), 'Row-locking clauses') !== false);
}
runTest("11.1 FOR UPDATE row-locking clause strictly rejected", $forUpdateThrew, $totalTests, $passedTests, $failedTests);

// -------------------------------------------------------------------------
// 12. LOCK IN SHARE MODE
// -------------------------------------------------------------------------
echo "\n--- 12. LOCK IN SHARE MODE REJECTION ---\n";
$shareModeThrew = false;
try {
    AnalyticsService::executeReadOnlyQuery($testPdo, "SELECT * FROM elephant_reports LOCK IN SHARE MODE");
} catch (RuntimeException $e) {
    $shareModeThrew = (strpos($e->getMessage(), 'Locking clauses') !== false);
}
runTest("12.1 LOCK IN SHARE MODE clause strictly rejected", $shareModeThrew, $totalTests, $passedTests, $failedTests);

// -------------------------------------------------------------------------
// 13. Unsafe Function Calls
// -------------------------------------------------------------------------
echo "\n--- 13. UNSAFE FUNCTION CALLS REJECTION ---\n";
$sleepThrew = false;
try {
    AnalyticsService::executeReadOnlyQuery($testPdo, "SELECT SLEEP(2)");
} catch (RuntimeException $e) {
    $sleepThrew = (strpos($e->getMessage(), 'Side-effecting or resource-exhausting functions') !== false);
}
runTest("13.1 SLEEP() function call strictly rejected", $sleepThrew, $totalTests, $passedTests, $failedTests);

$benchmarkThrew = false;
try {
    AnalyticsService::executeReadOnlyQuery($testPdo, "SELECT BENCHMARK(1000000, MD5(1))");
} catch (RuntimeException $e) {
    $benchmarkThrew = (strpos($e->getMessage(), 'Side-effecting or resource-exhausting functions') !== false);
}
runTest("13.2 BENCHMARK() function call strictly rejected", $benchmarkThrew, $totalTests, $passedTests, $failedTests);

// -------------------------------------------------------------------------
// 14. Normal SELECT Execution
// -------------------------------------------------------------------------
echo "\n--- 14. NORMAL SELECT EXECUTION ---\n";
$normalRes = AnalyticsService::executeReadOnlyQuery($testPdo, "SELECT id, section_name, district FROM railway_sections ORDER BY id ASC");
runTest("14.1 Standard analytical SELECT executes cleanly", count($normalRes) === 4 && isset($normalRes[0]['section_name']), $totalTests, $passedTests, $failedTests);

// -------------------------------------------------------------------------
// 15. Parameterized SELECT Execution
// -------------------------------------------------------------------------
echo "\n--- 15. PARAMETERIZED SELECT EXECUTION ---\n";
$paramRes = AnalyticsService::executeReadOnlyQuery($testPdo, "SELECT id, section_name FROM railway_sections WHERE id = ?", [2]);
runTest("15.1 Parameterized query executes cleanly with accurate parameter binding", count($paramRes) === 1 && (int)$paramRes[0]['id'] === 2, $totalTests, $passedTests, $failedTests);

// -------------------------------------------------------------------------
// 16. SHOW and DESCRIBE Execution
// -------------------------------------------------------------------------
echo "\n--- 16. SHOW AND DESCRIBE EXECUTION ---\n";
$showRes = AnalyticsService::executeReadOnlyQuery($testPdo, "SHOW TABLES");
runTest("16.1 SHOW TABLES query executes cleanly in read-only layer", !empty($showRes), $totalTests, $passedTests, $failedTests);

$descRes = AnalyticsService::executeReadOnlyQuery($testPdo, "DESCRIBE railway_sections");
runTest("16.2 DESCRIBE table query executes cleanly in read-only layer", !empty($descRes) && isset($descRes[0]['Field']), $totalTests, $passedTests, $failedTests);

// -------------------------------------------------------------------------
// 17. Legacy Test Bootstrap Enforcement
// -------------------------------------------------------------------------
echo "\n--- 17. LEGACY TEST BOOTSTRAP ENFORCEMENT ---\n";
// Verify that Database singleton rejects connection if DB_NAME is set to eleguard_rail in test mode
Database::resetInstance();
putenv('DB_NAME=eleguard_rail');
$_ENV['DB_NAME'] = 'eleguard_rail';

$bootstrapEnforced = false;
try {
    Database::getInstance();
} catch (RuntimeException $e) {
    $bootstrapEnforced = (strpos($e->getMessage(), 'operational database') !== false);
}

// Restore safe test database
putenv('DB_NAME=eleguard_rail_test');
$_ENV['DB_NAME'] = 'eleguard_rail_test';
Database::resetInstance();

runTest("17.1 Database singleton strictly refuses connection to operational DB in test context", $bootstrapEnforced, $totalTests, $passedTests, $failedTests);

// -------------------------------------------------------------------------
// 18. Test Cleanup Targeting Safety
// -------------------------------------------------------------------------
echo "\n--- 18. TEST CLEANUP TARGETING SAFETY ---\n";
$cleanupOperationalBlocked = false;
try {
    safeTeardownTestDatabase($testPdo, "eleguard_rail");
} catch (RuntimeException $e) {
    $cleanupOperationalBlocked = (strpos($e->getMessage(), 'operational database') !== false);
}
runTest("18.1 safeTeardownTestDatabase strictly refuses to drop operational database", $cleanupOperationalBlocked, $totalTests, $passedTests, $failedTests);

$cleanupUnapprovedBlocked = false;
try {
    safeTeardownTestDatabase($testPdo, "production_warehouse");
} catch (RuntimeException $e) {
    $cleanupUnapprovedBlocked = (strpos($e->getMessage(), 'unapproved database') !== false);
}
runTest("18.2 safeTeardownTestDatabase strictly refuses to drop arbitrary unapproved database", $cleanupUnapprovedBlocked, $totalTests, $passedTests, $failedTests);

// -------------------------------------------------------------------------
// 19. Operational Database Write Prevention
// -------------------------------------------------------------------------
echo "\n--- 19. OPERATIONAL DATABASE WRITE PREVENTION ---\n";
$postReports = (int)$opPdo->query("SELECT COUNT(*) FROM elephant_reports")->fetchColumn();
$postLogs = (int)$opPdo->query("SELECT COUNT(*) FROM system_logs")->fetchColumn();
$postAlerts = (int)$opPdo->query("SELECT COUNT(*) FROM alerts")->fetchColumn();
$postRep1 = $opPdo->query("SELECT sighting_date, sighting_time, risk_score, report_status FROM elephant_reports WHERE id = 1")->fetch(PDO::FETCH_ASSOC);

runTest("19.1 Operational elephant_reports count completely untouched (43 == {$postReports})", $postReports === 43, $totalTests, $passedTests, $failedTests);
runTest("19.2 Operational system_logs count completely untouched (185 == {$postLogs})", $postLogs === 185, $totalTests, $passedTests, $failedTests);
runTest("19.3 Operational alerts count completely untouched (15 == {$postAlerts})", $postAlerts === 15, $totalTests, $passedTests, $failedTests);
runTest("19.4 Operational Report #1 sighting time remains NULL", $postRep1['sighting_time'] === null, $totalTests, $passedTests, $failedTests);
runTest("19.5 Operational Report #1 risk score remains Medium", $postRep1['risk_score'] === 'Medium', $totalTests, $passedTests, $failedTests);

// -------------------------------------------------------------------------
// 20. Analytics Regression Compatibility
// -------------------------------------------------------------------------
echo "\n--- 20. ANALYTICS REGRESSION COMPATIBILITY ---\n";
$fullSummary = AnalyticsService::getFullAnalyticsSummary($testPdo);
runTest("20.1 getFullAnalyticsSummary completes cleanly through hardened SQL guard", 
    isset($fullSummary['general_statistics']) && 
    isset($fullSummary['temporal_analytics']) && 
    isset($fullSummary['section_analytics']) && 
    isset($fullSummary['hotspot_analytics']) && 
    isset($fullSummary['duplicate_analytics']) && 
    isset($fullSummary['reporting_latency']) && 
    isset($fullSummary['data_quality']), 
    $totalTests, $passedTests, $failedTests
);

$temporalSummary = AnalyticsService::getTemporalAnalytics($testPdo);
runTest("20.2 getTemporalAnalytics generates 24 hourly bins without error", 
    count($temporalSummary['hourly_distribution']) === 24, 
    $totalTests, $passedTests, $failedTests
);

// =========================================================================
// SUMMARY
// =========================================================================
echo "\n========================================================================\n";
echo "STAGE 4C.2 SECURITY HARDENING TEST RESULTS\n";
echo "Total Tests:  {$totalTests}\n";
echo "Passed Tests: {$passedTests}\n";
echo "Failed Tests: {$failedTests}\n";
echo "========================================================================\n";

exit($failedTests > 0 ? 1 : 0);
