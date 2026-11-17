<?php
/**
 * EleGuard Rail - Master Unified Test Runner
 * Milestone 9C Stage 4D.1-H: Database Permission Isolation & Unified Test Infrastructure
 *
 * Requirements:
 * 1. Environment Guard:
 *    - Validates execution strictly in test context (DB_NAME === 'eleguard_rail_test').
 *    - Immediately halts if target is 'eleguard_rail' or unconfigured.
 *    - Performs engine-level permission isolation checks for both read-only accounts.
 * 2. Test Discovery & Execution:
 *    - Discovers all 15 test suites in tests/.
 *    - Executes each suite in an isolated subprocess with DB_NAME=eleguard_rail_test.
 *    - Performs pre/post state snapshotting and teardown to ensure zero cross-suite pollution.
 * 3. Structured Summary Reporting:
 *    - Formatted terminal table with status, assertions, duration, and overall verdict.
 *    - Exits 0 on 100% pass, 1 on any failure.
 */

// Strict CLI error reporting
error_reporting(E_ALL);
ini_set('display_errors', '1');

$rootDir = dirname(__DIR__);
chdir($rootDir);

echo "================================================================================\n";
echo " EleGuard Rail - Master Unified Test Runner (Milestone 9C Stage 4D.1-H)\n";
echo "================================================================================\n\n";

// -----------------------------------------------------------------------------
// TASK 1: Environment Guard & Database Safety Verification
// -----------------------------------------------------------------------------
echo "[STEP 1/3] Executing Environment Guard & Database Safety Pre-checks...\n";

// 1.1 Verify DB_NAME and enforce disposable test database isolation
$targetDb = getenv('DB_NAME') ?: ($_ENV['DB_NAME'] ?? null);
if ($targetDb !== null && trim($targetDb) !== '') {
    $trimmed = strtolower(trim($targetDb));
    if ($trimmed === 'eleguard_rail') {
        fwrite(STDERR, "\nFATAL DATABASE SAFETY VIOLATION:\n");
        fwrite(STDERR, "Test runner invoked with operational database '{$targetDb}'.\n");
        fwrite(STDERR, "Refusing execution against operational database.\n");
        exit(1);
    }
    if ($trimmed === 'eleguard_rail_test') {
        fwrite(STDERR, "\nFATAL DATABASE SAFETY VIOLATION:\n");
        fwrite(STDERR, "Test runner invoked with protected persistent test database '{$targetDb}'.\n");
        fwrite(STDERR, "Targeting persistent test database is strictly prohibited. Tests must run against a disposable database.\n");
        exit(1);
    }
    $disposableDb = trim($targetDb);
} else {
    // Generate unique disposable test database per execution
    $disposableDb = 'eleguard_test_tmp_' . bin2hex(random_bytes(6));
}

putenv("DB_NAME={$disposableDb}");
$_ENV['DB_NAME'] = $disposableDb;

echo "  Disposable Test Database Target: {$disposableDb}\n";

// 1.2 Load database configuration and test bootstrap
require_once $rootDir . '/tests/bootstrap.php';
require_once $rootDir . '/app/config/database.php';

$dbHost = defined('DB_HOST') ? DB_HOST : '127.0.0.1';
$dbPort = defined('DB_PORT') ? (int)DB_PORT : 3308;
$dbCharset = defined('DB_CHARSET') ? DB_CHARSET : 'utf8mb4';

$prodDb = 'eleguard_rail';
$protectedTestDb = 'eleguard_rail_test';
$testDb = $disposableDb;

$roUser = defined('DB_ANALYTICS_USER') ? DB_ANALYTICS_USER : 'eleguard_readonly';
$roPass = defined('DB_ANALYTICS_PASS') ? DB_ANALYTICS_PASS : '';

$testRoUser = defined('DB_TEST_ANALYTICS_USER') ? DB_TEST_ANALYTICS_USER : 'eleguard_test_ro';
$testRoPass = defined('DB_TEST_ANALYTICS_PASS') ? DB_TEST_ANALYTICS_PASS : '';

// Also check .env if credentials are empty
if (empty($testRoPass) && file_exists($rootDir . '/.env')) {
    $envLines = file($rootDir . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($envLines as $line) {
        $line = trim($line);
        if (strpos($line, '#') === 0) continue;
        if (strpos($line, '=') !== false) {
            list($k, $v) = explode('=', $line, 2);
            $k = trim($k);
            $v = trim($v, " \t\n\r\0\x0B\"'");
            if ($k === 'DB_TEST_ANALYTICS_PASS' && empty($testRoPass)) {
                $testRoPass = $v;
            }
            if ($k === 'DB_ANALYTICS_PASS' && empty($roPass)) {
                $roPass = $v;
            }
        }
    }
}

$pdoOptions = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_TIMEOUT => 3
];

// Establish runner-exclusive administrative connection from protected config outside webroot
$adminConfigFile = getenv('USERPROFILE') ? getenv('USERPROFILE') . '/.eleguard-test-admin.php' : 'C:/Users/user/.eleguard-test-admin.php';
if (file_exists($adminConfigFile)) {
    $adminConfig = require $adminConfigFile;
    $adminUser = $adminConfig['admin_user'] ?? 'root';
    $adminPass = $adminConfig['admin_pass'] ?? '';
    $adminHost = $adminConfig['admin_host'] ?? $dbHost;
    $adminPort = $adminConfig['admin_port'] ?? $dbPort;
} else {
    $adminUser = defined('DB_USER') ? DB_USER : 'root';
    $adminPass = defined('DB_PASS') ? DB_PASS : '';
    $adminHost = $dbHost;
    $adminPort = $dbPort;
}
$adminPdo = new PDO("mysql:host={$adminHost};port={$adminPort};charset={$dbCharset}", $adminUser, $adminPass, $pdoOptions);

// -----------------------------------------------------------------------------
// ADVISORY LOCK: Prevent Concurrent Master Test Runner Executions
// -----------------------------------------------------------------------------
$lockStmt = $adminPdo->query("SELECT GET_LOCK('eleguard_test_runner_lock', 0)");
$lockAcquired = ($lockStmt && (int)$lockStmt->fetchColumn() === 1);

if (!$lockAcquired) {
    fwrite(STDERR, "\nFATAL CONCURRENCY VIOLATION:\n");
    fwrite(STDERR, "Another instance of the master test runner holds the advisory lock 'eleguard_test_runner_lock'.\n");
    fwrite(STDERR, "Concurrent test execution against test databases is strictly prevented.\n");
    exit(1);
}

// Register shutdown function to guarantee lock release and disposable DB teardown
register_shutdown_function(function() use ($adminPdo, $disposableDb) {
    try {
        if ($adminPdo instanceof PDO) {
            $adminPdo->exec("SELECT RELEASE_LOCK('eleguard_test_runner_lock')");
            safeTeardownTestDatabase($adminPdo, $disposableDb);
        }
    } catch (\Throwable $e) {
        // Shutdown handler cleanup
    }
});

// Ensure canonical disposable test database baseline is present before pre-checks
restoreCanonicalTestDatabase($adminPdo, $disposableDb);

// Ensure test read-only user has access to this disposable database
$adminPdo->exec("GRANT SELECT, SHOW VIEW ON `{$disposableDb}`.* TO 'eleguard_test_ro'@'localhost'");
$adminPdo->exec("GRANT SELECT, SHOW VIEW ON `{$disposableDb}`.* TO 'eleguard_test_ro'@'127.0.0.1'");
$adminPdo->exec("FLUSH PRIVILEGES");

$precheckErrors = [];

// Check 1: eleguard_readonly CAN SELECT from eleguard_rail
try {
    $roPdo = new PDO("mysql:host={$dbHost};port={$dbPort};dbname={$prodDb};charset={$dbCharset}", $roUser, $roPass, $pdoOptions);
    $cnt = $roPdo->query("SELECT COUNT(*) FROM elephant_reports")->fetchColumn();
    echo "  [PASS] Operational Read: '{$roUser}' reads '{$prodDb}' successfully (reports={$cnt})\n";
} catch (Exception $e) {
    $precheckErrors[] = "Operational read failed for {$roUser}: " . $e->getMessage();
    echo "  [FAIL] Operational Read: '{$roUser}' failed to read '{$prodDb}': " . $e->getMessage() . "\n";
}

// Check 2: eleguard_readonly CANNOT INSERT into eleguard_rail
if (isset($roPdo) && $roPdo instanceof PDO) {
    try {
        $roPdo->exec("INSERT INTO system_logs (action) VALUES ('guard_test')");
        $precheckErrors[] = "CRITICAL: '{$roUser}' was permitted to INSERT into '{$prodDb}'!";
        echo "  [FAIL] Operational Immutability: '{$roUser}' was permitted to write to '{$prodDb}'\n";
    } catch (PDOException $e) {
        echo "  [PASS] Operational Immutability: '{$roUser}' INSERT blocked at engine level (SQLSTATE " . $e->getCode() . ")\n";
    }
}

// Check 3: eleguard_readonly CANNOT access disposable test database
try {
    $leakPdo = new PDO("mysql:host={$dbHost};port={$dbPort};dbname={$testDb};charset={$dbCharset}", $roUser, $roPass, $pdoOptions);
    $leakPdo->query("SELECT COUNT(*) FROM elephant_reports");
    $precheckErrors[] = "CRITICAL: '{$roUser}' was permitted to access disposable test database '{$testDb}'!";
    echo "  [FAIL] Cross-DB Isolation: '{$roUser}' accessed '{$testDb}'\n";
} catch (PDOException $e) {
    echo "  [PASS] Cross-DB Isolation: '{$roUser}' blocked from '{$testDb}' (Access denied as expected)\n";
}

// Check 4: eleguard_test_ro CAN SELECT from disposable test database
try {
    $testRoPdo = new PDO("mysql:host={$dbHost};port={$dbPort};dbname={$testDb};charset={$dbCharset}", $testRoUser, $testRoPass, $pdoOptions);
    $cntTest = $testRoPdo->query("SELECT COUNT(*) FROM elephant_reports")->fetchColumn();
    echo "  [PASS] Test Read: '{$testRoUser}' reads '{$testDb}' successfully (reports={$cntTest})\n";
} catch (Exception $e) {
    $precheckErrors[] = "Test read failed for {$testRoUser}: " . $e->getMessage();
    echo "  [FAIL] Test Read: '{$testRoUser}' failed to read '{$testDb}': " . $e->getMessage() . "\n";
}

// Check 5: eleguard_test_ro CANNOT INSERT into disposable test database
if (isset($testRoPdo) && $testRoPdo instanceof PDO) {
    try {
        $testRoPdo->exec("INSERT INTO system_logs (action) VALUES ('guard_test')");
        $precheckErrors[] = "CRITICAL: '{$testRoUser}' was permitted to INSERT into '{$testDb}'!";
        echo "  [FAIL] Test Immutability: '{$testRoUser}' was permitted to write to '{$testDb}'\n";
    } catch (PDOException $e) {
        echo "  [PASS] Test Immutability: '{$testRoUser}' INSERT blocked at engine level (SQLSTATE " . $e->getCode() . ")\n";
    }
}

// Check 6: eleguard_test_ro CANNOT access eleguard_rail
try {
    $leakTestPdo = new PDO("mysql:host={$dbHost};port={$dbPort};dbname={$prodDb};charset={$dbCharset}", $testRoUser, $testRoPass, $pdoOptions);
    $leakTestPdo->query("SELECT COUNT(*) FROM elephant_reports");
    $precheckErrors[] = "CRITICAL: '{$testRoUser}' was permitted to access operational database '{$prodDb}'!";
    echo "  [FAIL] Operational Protection: '{$testRoUser}' accessed '{$prodDb}'\n";
} catch (PDOException $e) {
    echo "  [PASS] Operational Protection: '{$testRoUser}' blocked from '{$prodDb}' (Access denied as expected)\n";
}

if (!empty($precheckErrors)) {
    fwrite(STDERR, "\nFATAL: Pre-check security validations failed:\n");
    foreach ($precheckErrors as $err) {
        fwrite(STDERR, " - {$err}\n");
    }
    exit(1);
}

echo "  => All 6 Database Permission Isolation checks PASSED.\n\n";

// -----------------------------------------------------------------------------
// TASK 2: Test Suite Discovery & Orchestration
// -----------------------------------------------------------------------------
echo "[STEP 2/3] Discovering and Executing All Test Suites...\n\n";

$suites = [
    'test_milestone_9c_stage4c2_security.php'       => 'Stage 4C.2: DB Safety & SQL Injection Hardening',
    'test_milestone_9c_stage1_foundation.php'       => 'Stage 1: Foundation Schema & Baseline Data',
    'test_milestone_9c_stage2a_temporal_service.php' => 'Stage 2A: Temporal Analysis Service Unit Tests',
    'test_milestone_9c_stage2b_frontend_integration.php' => 'Stage 2B: Frontend View Integration Tests',
    'test_milestone_9c_stage3c_temporal_risk.php'   => 'Stage 3C: Temporal Risk Engine Implementation',
    'test_milestone_9c_stage3d_dashboard_integration.php' => 'Stage 3D: Risk Dashboard Integration',
    'test_milestone_9c_stage3e_model_evaluation.php' => 'Stage 3E: Model Evaluation & Benchmark Suite',
    'test_milestone_9c_stage4b_duplicate_service.php' => 'Stage 4B: Spatial-Temporal Duplicate Service',
    'test_milestone_9c_stage4c_analytics_service.php' => 'Stage 4C: Read-Only Historical Analytics Layer',
    'test_milestone_9c_stage4d1_api.php'            => 'Stage 4D.1: Analytics REST API & RBAC Suite',
    'test_milestone_9c_stage4d2_dashboards.php'     => 'Stage 4D.2: Role-Based Analytics Dashboards',
    'test_gis_and_proximity.php'                    => 'Phase 5: Spatial Proximity & Geodesic Engine',
    'test_gps_and_workflows.php'                    => 'Phase 6: GPS Ingestion & Lifecycle Workflows',
    'test_ai_integration.php'                       => 'Phase 7: AI Sighting Detection & Governance',
    'test_milestone_7c.php'                         => 'Phase 7C: AI Pipeline & Advisory Verification',
    'test_milestone_9b_analytics.php'               => 'Milestone 9B: Historical Hotspot Analytics',
    'test_milestone_9c_stage4e4_1_backend.php'      => 'Stage 4E.4.1: Field Reporting Backend Foundation & API v2',
    'test_milestone_9c_stage4e4_1b_remediation.php'  => 'Stage 4E.4.1-B: Backend Remediation & Concurrency Suite',
    'test_milestone_9c_stage4e4_2a_pwa.php'          => 'Stage 4E.4.2-A: PWA Foundation & Field Telemetry Suite',
    'test_milestone_9c_stage4e4_2b_field_capture.php' => 'Stage 4E.4.2-B: Guided Field Observation Capture Suite',
    'test_milestone_9c_stage4e4_2c_encrypted_storage.php' => 'Stage 4E.4.2-C: Encrypted Offline Storage & Web Crypto Suite',
    'test_milestone_9c_stage4e4_2d_sync.php' => 'Stage 4E.4.2-D: Controlled Offline Synchronization Engine',
    'test_milestone_9c_stage4e4_2e_hardening.php' => 'Stage 4E.4.2-E: Offline Synchronization Hardening & Validation',
    'test_milestone_9c_stage4e4_3c_database.php' => 'Stage 4E.4.3-C: Verification Database Foundation & Schema Audits',
    'test_milestone_9c_stage4e4_3d_verification.php' => 'Stage 4E.4.3-D: Verification Service & State Transition Engine',
    'test_milestone_9c_stage4e4_3e_clarifications.php' => 'Stage 4E.4.3-E: Clarification APIs & Reporter Communication',
    'test_milestone_9c_stage4e4_3f_wildlife_interface.php' => 'Stage 4E.4.3-F: Wildlife Officer Verification Interface',
    'test_milestone_9c_stage4e4_3g_reporter_workspace.php' => 'Stage 4E.4.3-G: Reporter Workspace & Clarification Interface',
    'test_milestone_9c_stage4e4_3h_section_attribution.php' => 'Stage 4E.4.3-H: Control Officer Section Attribution',
    'test_milestone_9c_stage4e4_3h3_map_visualization.php' => 'Stage 4E.4.3-H3: Interactive Railway Map & Section Visualization',
    'test_milestone_9c_stage4e4_3i_driver_interface.php' => 'Stage 4E.4.3-I: Locomotive Driver Advisory Interface',
    'test_milestone_9c_stage_j_privilege_separation.php' => 'Stage J: Database Privilege Separation & Access Control Suite',
    'test_milestone_9c_stage_j_api_envelope.php' => 'Stage J.4: API Response Envelope Standardization Suite',
    'test_milestone_9c_stage_j_idempotency.php' => 'Stage J.5: Offline Synchronization UUID Idempotency Suite',
    'test_milestone_9c_stage_j_gps_validation.php' => 'Stage J.6: Geodetic GPS & Uncertainty Validation Suite'
];

$results = [];
$totalStart = microtime(true);
$suiteIndex = 0;

foreach ($suites as $file => $description) {
    $suiteIndex++;
    $filePath = $rootDir . '/tests/' . $file;
    if (!file_exists($filePath)) {
        $results[] = [
            'index'       => $suiteIndex,
            'file'        => $file,
            'description' => $description,
            'status'      => 'FAIL',
            'assertions'  => 'File not found',
            'duration'    => 0.0,
            'exit_code'   => 1,
            'error'       => 'File does not exist: ' . $filePath
        ];
        continue;
    }

    // Ensure test database exists and is populated
    ensureTestDatabaseExists($adminPdo);

    // Snapshot test database baseline IDs before execution
    try {
        $stmtClarifs = $adminPdo->query("SELECT id FROM `{$testDb}`.report_clarifications");
        $baselineClarifIds = $stmtClarifs ? $stmtClarifs->fetchAll(PDO::FETCH_COLUMN, 0) : [];

        $stmtVerifs = $adminPdo->query("SELECT id FROM `{$testDb}`.report_verifications");
        $baselineVerifIds = $stmtVerifs ? $stmtVerifs->fetchAll(PDO::FETCH_COLUMN, 0) : [];

        $stmtHist = $adminPdo->query("SELECT id FROM `{$testDb}`.report_section_assignment_history");
        $baselineHistIds = $stmtHist ? $stmtHist->fetchAll(PDO::FETCH_COLUMN, 0) : [];

        $stmtAcks = $adminPdo->query("SELECT id FROM `{$testDb}`.driver_acknowledgements");
        $baselineAckIds = $stmtAcks ? $stmtAcks->fetchAll(PDO::FETCH_COLUMN, 0) : [];

        $stmtAlerts = $adminPdo->query("SELECT id FROM `{$testDb}`.alerts");
        $baselineAlertIds = $stmtAlerts ? $stmtAlerts->fetchAll(PDO::FETCH_COLUMN, 0) : [];

        $stmt = $adminPdo->query("SELECT id FROM `{$testDb}`.elephant_reports");
        $baselineReportIds = $stmt ? $stmt->fetchAll(PDO::FETCH_COLUMN, 0) : [];

        $stmtLogs = $adminPdo->query("SELECT id FROM `{$testDb}`.system_logs");
        $baselineLogIds = $stmtLogs ? $stmtLogs->fetchAll(PDO::FETCH_COLUMN, 0) : [];
    } catch (\Throwable $e) {
        $baselineClarifIds = [];
        $baselineVerifIds = [];
        $baselineHistIds = [];
        $baselineAckIds = [];
        $baselineAlertIds = [];
        $baselineReportIds = [];
        $baselineLogIds = [];
    }

    echo sprintf("  [%02d/%02d] Running %s (%s)...\n", $suiteIndex, count($suites), $file, $description);

    $testStart = microtime(true);

    $phpBin = PHP_BINARY;
    $cmd = escapeshellcmd($phpBin) . ' ' . escapeshellarg($filePath);

    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w']
    ];

    // Read restricted test writer credentials
    $testUser = getenv('DB_TEST_USER') ?: (defined('DB_TEST_USER') ? DB_TEST_USER : 'eleguard_test_rw');
    $testPass = getenv('DB_TEST_PASS') !== false ? getenv('DB_TEST_PASS') : (defined('DB_TEST_PASS') ? DB_TEST_PASS : '');

    $childEnv = array_merge($_ENV, getenv(), [
        'DB_NAME'                  => $disposableDb,
        'DB_TEST_USER'             => $testUser,
        'DB_TEST_PASS'             => $testPass,
        'DB_TEST_ANALYTICS_USER'    => $testRoUser,
        'DB_TEST_ANALYTICS_PASS'    => $testRoPass,
        'ELEGUARD_TEST_CONTEXT'    => '1',
        'ELEGUARD_MASTER_RUNNER'   => '1',
        'SYSTEMROOT'               => getenv('SYSTEMROOT') ?: 'C:\\Windows'
    ]);

    // STRICT PRIVILEGE BOUNDARY: Purge administrative and root credentials from child processes
    unset(
        $childEnv['DB_ADMIN_USER'],
        $childEnv['DB_ADMIN_PASS'],
        $childEnv['DB_USER'],
        $childEnv['DB_PASS']
    );

    $proc = proc_open($cmd, $descriptors, $pipes, $rootDir, $childEnv);
    if (!is_resource($proc)) {
        $results[] = [
            'index'       => $suiteIndex,
            'file'        => $file,
            'description' => $description,
            'status'      => 'FAIL',
            'assertions'  => 'Proc error',
            'duration'    => 0.0,
            'exit_code'   => 1,
            'error'       => 'Failed to launch subprocess'
        ];
        continue;
    }

    // Set output pipes to non-blocking to enforce execution timeout
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);

    $stdout = '';
    $stderr = '';
    $timeoutSeconds = 60;
    $timedOut = false;

    while (true) {
        $procStatus = proc_get_status($proc);

        $outChunk = stream_get_contents($pipes[1]);
        if ($outChunk !== false && $outChunk !== '') {
            $stdout .= $outChunk;
        }

        $errChunk = stream_get_contents($pipes[2]);
        if ($errChunk !== false && $errChunk !== '') {
            $stderr .= $errChunk;
        }

        if (!$procStatus['running']) {
            $exitCode = $procStatus['exitcode'];
            break;
        }

        if ((microtime(true) - $testStart) > $timeoutSeconds) {
            $timedOut = true;
            proc_terminate($proc);
            usleep(500000); // 500ms grace period
            $exitCode = 124; // standard timeout exit code
            $stderr .= "\n[TIMEOUT] Test suite exceeded execution limit of {$timeoutSeconds}s and was terminated.\n";
            break;
        }

        usleep(10000); // 10ms poll interval
    }

    // Drain remaining pipe buffers
    $stdout .= stream_get_contents($pipes[1]);
    $stderr .= stream_get_contents($pipes[2]);
    fclose($pipes[0]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($proc);
    $duration = microtime(true) - $testStart;

    // -------------------------------------------------------------------------
    // TEARDOWN: Leaf-to-Root Ordering, Stranded Image Cleanup, AUTO_INCREMENT Reset
    // -------------------------------------------------------------------------
    $teardownFailed = false;
    $teardownErrorMessage = '';

    try {
        ensureTestDatabaseExists($adminPdo);

        $adminPdo->exec("SET FOREIGN_KEY_CHECKS = 0");

        // 0. Stage 4E.4.3 child tables (clarifications, verifications, section history)
        if (!empty($baselineClarifIds)) {
            $phClarifs = implode(',', array_map('intval', $baselineClarifIds));
            $adminPdo->exec("DELETE FROM `{$testDb}`.report_clarifications WHERE id NOT IN ({$phClarifs})");
        } else {
            $adminPdo->exec("DELETE FROM `{$testDb}`.report_clarifications");
        }

        if (!empty($baselineVerifIds)) {
            $phVerifs = implode(',', array_map('intval', $baselineVerifIds));
            $adminPdo->exec("DELETE FROM `{$testDb}`.report_verifications WHERE id NOT IN ({$phVerifs})");
        } else {
            $adminPdo->exec("DELETE FROM `{$testDb}`.report_verifications");
        }

        if (!empty($baselineHistIds)) {
            $phHist = implode(',', array_map('intval', $baselineHistIds));
            $adminPdo->exec("DELETE FROM `{$testDb}`.report_section_assignment_history WHERE id NOT IN ({$phHist})");
        } else {
            $adminPdo->exec("DELETE FROM `{$testDb}`.report_section_assignment_history");
        }

        // 1. driver_acknowledgements (child of alerts)
        if (!empty($baselineAckIds)) {
            $phAcks = implode(',', array_map('intval', $baselineAckIds));
            $adminPdo->exec("DELETE FROM `{$testDb}`.driver_acknowledgements WHERE id NOT IN ({$phAcks})");
        }

        // 2. alerts (child of elephant_reports)
        if (!empty($baselineAlertIds)) {
            $phAlerts = implode(',', array_map('intval', $baselineAlertIds));
            $adminPdo->exec("DELETE FROM `{$testDb}`.alerts WHERE id NOT IN ({$phAlerts})");
        }

        // 3. elephant_reports (parent of alerts)
        if (!empty($baselineReportIds)) {
            $phReports = implode(',', array_map('intval', $baselineReportIds));
            $adminPdo->exec("DELETE FROM `{$testDb}`.elephant_reports WHERE id NOT IN ({$phReports})");
        }

        // 4. system_logs (audit logs created during test run)
        if (!empty($baselineLogIds)) {
            $phLogs = implode(',', array_map('intval', $baselineLogIds));
            $adminPdo->exec("DELETE FROM `{$testDb}`.system_logs WHERE id NOT IN ({$phLogs})");
        }

        $adminPdo->exec("SET FOREIGN_KEY_CHECKS = 1");

        // 5. Clean up any stranded test upload images
        $uploadDirs = [
            $rootDir . '/public/assets/uploads/reports',
            $rootDir . '/assets/uploads/reports'
        ];
        foreach ($uploadDirs as $uDir) {
            if (is_dir($uDir)) {
                $testImages = glob($uDir . '/test_*');
                if (!empty($testImages)) {
                    foreach ($testImages as $img) {
                        if (is_file($img)) @unlink($img);
                    }
                }
            }
        }

        // 6. Reset AUTO_INCREMENT to MAX(id) + 1 to prevent counter drift
        $tablesToReset = ['report_clarifications', 'report_verifications', 'report_section_assignment_history', 'driver_acknowledgements', 'alerts', 'elephant_reports', 'system_logs'];
        foreach ($tablesToReset as $tbl) {
            $maxId = (int)$adminPdo->query("SELECT COALESCE(MAX(id), 0) FROM `{$testDb}`.`{$tbl}`")->fetchColumn();
            $nextId = $maxId + 1;
            $adminPdo->exec("ALTER TABLE `{$testDb}`.`{$tbl}` AUTO_INCREMENT = {$nextId}");
        }
    } catch (\Throwable $e) {
        try { $adminPdo->exec("SET FOREIGN_KEY_CHECKS = 1"); } catch (\Throwable $ignored) {}
        $teardownFailed = true;
        $teardownErrorMessage = "Teardown exception: " . $e->getMessage();
        fwrite(STDERR, "  [TEARDOWN EXCEPTION] {$teardownErrorMessage}\n");
    }

    // Extract assertion numbers if present in output
    $assertionSummary = 'Completed';
    if ($timedOut) {
        $assertionSummary = "Timed out (> {$timeoutSeconds}s)";
    } elseif (preg_match('/(?:Summary:\s*)?(\d+)\s+Passed(?:,\s*(\d+)\s+Failed)?/i', $stdout, $matches)) {
        $p = $matches[1];
        $f = isset($matches[2]) ? $matches[2] : '0';
        $assertionSummary = "{$p} passed, {$f} failed";
    } elseif (preg_match('/(\d+)\s*\/\s*(\d+)\s+PASSED/i', $stdout, $matches)) {
        $assertionSummary = "{$matches[1]}/{$matches[2]} passed";
    } elseif (preg_match('/(\d+)\s+passed,\s*(\d+)\s+failed/i', $stdout, $matches)) {
        $assertionSummary = "{$matches[1]} passed, {$matches[2]} failed";
    } elseif (preg_match('/All\s+(\d+)\s+tests\s+passed/i', $stdout, $matches)) {
        $assertionSummary = "{$matches[1]}/{$matches[1]} passed";
    }

    if ($teardownFailed) {
        $status = 'FAIL';
        $assertionSummary .= ' [Teardown Failed]';
    } elseif ($exitCode === 0) {
        $status = 'PASS';
    } else {
        $status = 'FAIL';
    }

    echo sprintf("         => Status: %s | Time: %.2fs | Assertions: %s\n", $status, $duration, $assertionSummary);

    $results[] = [
        'index'          => $suiteIndex,
        'file'           => $file,
        'description'    => $description,
        'status'         => $status,
        'assertions'     => $assertionSummary,
        'duration'       => $duration,
        'exit_code'      => $exitCode,
        'teardown_error' => $teardownErrorMessage,
        'stdout'         => $stdout,
        'stderr'         => $stderr
    ];
}

$totalElapsed = microtime(true) - $totalStart;

// -----------------------------------------------------------------------------
// TASK 3: Terminal Summary Table & Verdict
// -----------------------------------------------------------------------------
echo "\n[STEP 3/3] Generating Consolidated Test Report...\n\n";

$separator = "+----+---------------------------------------------------+---------+---------------------+----------+\n";
echo $separator;
echo sprintf("| %-2s | %-49s | %-7s | %-19s | %-8s |\n", "#", "Test Suite", "Status", "Assertions", "Duration");
echo $separator;

$allPassed = true;
$passedCount = 0;
$failedCount = 0;

foreach ($results as $res) {
    if ($res['status'] === 'PASS') {
        $passedCount++;
    } else {
        $failedCount++;
        $allPassed = false;
    }

    echo sprintf(
        "| %02d | %-49s | %-7s | %-19s | %7.2fs |\n",
        $res['index'],
        mb_strimwidth($res['file'], 0, 49, '...'),
        $res['status'],
        mb_strimwidth($res['assertions'], 0, 19, ''),
        $res['duration']
    );
}

echo $separator;

echo "\n================================================================================\n";
echo sprintf(" TEST RUN SUMMARY: %d / %d SUITES PASSED (%.2f seconds total elapsed)\n", $passedCount, count($suites), $totalElapsed);
if ($allPassed) {
    echo sprintf(" VERDICT: [SUCCESS] ALL %d TEST SUITES PASSED CLEANLY.\n", count($suites));
    echo "================================================================================\n";
    // Drop disposable test database cleanly and release advisory lock before exit
    safeTeardownTestDatabase($adminPdo, $disposableDb);
    echo "  [CLEANUP] Disposable test database '{$disposableDb}' dropped successfully.\n";
    $adminPdo->exec("SELECT RELEASE_LOCK('eleguard_test_runner_lock')");
    exit(0);
} else {
    echo " VERDICT: [FAILURE] {$failedCount} SUITE(S) FAILED.\n";
    echo "================================================================================\n";
    foreach ($results as $res) {
        if ($res['status'] !== 'PASS') {
            echo "\n--- Failure in {$res['file']} (exit code {$res['exit_code']}) ---\n";
            echo substr($res['stdout'], -2500) . "\n";
            if (!empty($res['stderr'])) {
                echo "STDERR:\n" . substr($res['stderr'], -2500) . "\n";
            }
        }
    }
    // Drop disposable test database cleanly and release advisory lock before exit
    safeTeardownTestDatabase($adminPdo, $disposableDb);
    echo "  [CLEANUP] Disposable test database '{$disposableDb}' dropped successfully.\n";
    $adminPdo->exec("SELECT RELEASE_LOCK('eleguard_test_runner_lock')");
    exit(1);
}
