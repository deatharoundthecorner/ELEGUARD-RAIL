<?php
/**
 * EleGuard Rail — Milestone 9C Stage 4D.1 Test Suite
 * Dashboard Backend & Analytics API Layer Verification
 * 
 * Tests:
 * 0. Pre-flight & Post-flight operational DB invariant checks
 * A. Authentication (401 Unauthorized, session checks)
 * B. Authorization (Role-based access matrix, 403 Forbidden, role manipulation attempts)
 * C. Data Privacy & Coordinate Protection (Hotspots, anti-poaching, spatial sanitization)
 * D. API Correctness & HTTP Contracts (JSON envelope, 405 Method Not Allowed, 422 Validation Errors)
 * E. Database Safety & Engine-Level Read-Only Permissions (eleguard_readonly user verification)
 * F. Scientific Integrity & Governance (No created_at substitution, provenance, advisory AI)
 */

define('ELEGUARD_TEST_SUITE', true);
define('ELEGUARD_TEST_ENVIRONMENT', true);

$baseDir = str_replace('\\', '/', dirname(__DIR__));
require_once $baseDir . '/tests/bootstrap.php';
require_once $baseDir . '/app/config/database.php';
require_once $baseDir . '/app/core/Database.php';
require_once $baseDir . '/app/services/AnalyticsService.php';

// Set test environment database target
$targetDb = getenv('DB_NAME') ?: ($_ENV['DB_NAME'] ?? null);
$rawPdo = new PDO("mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";charset=utf8mb4", DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
ensureTestDatabaseExists($rawPdo, $targetDb);
$rawPdo->exec("GRANT SELECT, SHOW VIEW ON `{$targetDb}`.* TO 'eleguard_test_ro'@'localhost'");
$rawPdo->exec("GRANT SELECT, SHOW VIEW ON `{$targetDb}`.* TO 'eleguard_test_ro'@'127.0.0.1'");
$rawPdo->exec("FLUSH PRIVILEGES");

if (!getenv('ELEGUARD_MASTER_RUNNER')) {
    register_shutdown_function(function() use ($rawPdo, $targetDb) {
        safeTeardownTestDatabase($rawPdo, $targetDb);
    });
}

// ANSI color codes for readable console reporting
function pass($msg) { echo "  \033[32m[PASS]\033[0m " . $msg . PHP_EOL; }
function fail($msg, $details = '') { 
    echo "  \033[31m[FAIL]\033[0m " . $msg . PHP_EOL; 
    if ($details) echo "         \033[33mDetails: " . $details . "\033[0m" . PHP_EOL;
    $GLOBALS['testsFailed']++;
}
function section($title) { echo PHP_EOL . "--- " . strtoupper($title) . " ---" . PHP_EOL; }

$testsRun = 0;
$testsFailed = 0;

function assertCondition($cond, $desc, $details = '') {
    $GLOBALS['testsRun']++;
    if ($cond) {
        pass($desc);
    } else {
        fail($desc, $details);
    }
}

/**
 * Invokes an analytics API endpoint via isolated child PHP process.
 */
function callAnalyticsApi($scriptName, $role = null, array $queryParams = [], $method = 'GET') {
    $baseDir = str_replace('\\', '/', dirname(__DIR__));
    $scriptPath = $baseDir . '/public/api/analytics/' . $scriptName;
    
    $rolePhp = ($role !== null) 
        ? "Session::start(); Session::set('user_id', 1); Session::set('user_role', '{$role}');" 
        : "";
    
    $queryExport = var_export($queryParams, true);
    $activeDb = getenv('DB_NAME') ?: ($_ENV['DB_NAME'] ?? $GLOBALS['targetDb']);

    $code = <<<PHP
define('ELEGUARD_TEST_ENVIRONMENT', true);
putenv('DB_NAME={$activeDb}');
\$_ENV['DB_NAME'] = '{$activeDb}';
require_once "{$baseDir}/app/core/Session.php";
{$rolePhp}
\$_SERVER['REQUEST_METHOD'] = '{$method}';
\$_SERVER['SCRIPT_FILENAME'] = '{$scriptPath}';
\$_GET = {$queryExport};
include '{$scriptPath}';
PHP;

    $tempFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'test_api_runner_' . uniqid() . '.php';
    file_put_contents($tempFile, "<?php\n" . $code);

    $cmd = "\"C:\\xampp\\php\\php.exe\" \"{$tempFile}\" 2>&1";
    $output = shell_exec($cmd);
    @unlink($tempFile);

    $json = json_decode($output, true);
    return [
        'raw' => $output,
        'json' => $json,
        'success' => $json['success'] ?? false,
        'data' => $json['data'] ?? null,
        'error' => $json['error'] ?? null
    ];
}

/**
 * Reads operational database baseline metrics directly.
 */
function getOperationalBaseline() {
    return captureOperationalDatabaseSnapshot();
}

echo "========================================================================" . PHP_EOL;
echo "EleGuard Rail — Milestone 9C Stage 4D.1 Test Suite" . PHP_EOL;
echo "Dashboard Backend & Analytics API Layer Verification" . PHP_EOL;
echo "========================================================================" . PHP_EOL;

// Clear any stale file cache before running test suite
$staleCaches = glob(sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'eleguard_*.cache');
if ($staleCaches) {
    foreach ($staleCaches as $cf) @unlink($cf);
}

// 0. PRE-FLIGHT BASELINE AUDIT
section("0. Pre-Flight Operational Safety Audit");
$preBaseline = getOperationalBaseline();
assertCondition(isset($preBaseline['tables']['elephant_reports']), "0.1 Operational elephant_reports baseline captured");
assertCondition(isset($preBaseline['tables']['system_logs']), "0.2 Operational system_logs baseline captured");
assertCondition(isset($preBaseline['tables']['alerts']), "0.3 Operational alerts baseline captured");
assertCondition($preBaseline['report1']['sighting_time'] === null, "0.4 Operational Report #1 sighting time is NULL");
assertCondition($preBaseline['report1']['risk_score'] === 'Medium', "0.5 Operational Report #1 risk score is Medium");

// A. AUTHENTICATION TESTS
section("A. Authentication Checks (401 Unauthorized)");
$allEndpoints = ['summary.php', 'temporal.php', 'sections.php', 'hotspots.php', 'duplicates.php', 'quality.php', 'ai_advisory.php'];

foreach ($allEndpoints as $ep) {
    $res = callAnalyticsApi($ep, null); // Unauthenticated
    assertCondition(
        $res['success'] === false && ($res['error']['code'] ?? '') === 'UNAUTHORIZED',
        "A.1 Unauthenticated request to {$ep} returns 401 UNAUTHORIZED",
        "Raw: " . substr($res['raw'], 0, 100)
    );
}

$resAuth = callAnalyticsApi('summary.php', 'admin');
assertCondition($resAuth['success'] === true, "A.2 Valid session authentication succeeds with 200 OK");

// B. ROLE-BASED AUTHORIZATION TESTS
section("B. Role-Based Authorization Checks (403 Forbidden & Matrix)");

// 1. Admin: full access to all 7 endpoints
foreach ($allEndpoints as $ep) {
    $res = callAnalyticsApi($ep, 'admin');
    assertCondition($res['success'] === true, "B.1 Admin authorized for {$ep}");
}

// 2. Control Officer: authorized for 6 endpoints, denied on ai_advisory.php
$controlPermitted = ['summary.php', 'temporal.php', 'sections.php', 'hotspots.php', 'duplicates.php', 'quality.php'];
foreach ($controlPermitted as $ep) {
    $res = callAnalyticsApi($ep, 'control_officer');
    assertCondition($res['success'] === true, "B.2 Control Officer authorized for {$ep}");
}
$resControlAi = callAnalyticsApi('ai_advisory.php', 'control_officer');
assertCondition(
    $resControlAi['success'] === false && ($resControlAi['error']['code'] ?? '') === 'FORBIDDEN',
    "B.3 Control Officer denied on ai_advisory.php (403 FORBIDDEN)"
);

// 3. Wildlife Officer: authorized for all 7 endpoints
foreach ($allEndpoints as $ep) {
    $res = callAnalyticsApi($ep, 'wildlife_officer');
    assertCondition($res['success'] === true, "B.4 Wildlife Officer authorized for {$ep}");
}

// 4. Driver: denied on ALL 7 analytics endpoints
foreach ($allEndpoints as $ep) {
    $res = callAnalyticsApi($ep, 'driver');
    assertCondition(
        $res['success'] === false && ($res['error']['code'] ?? '') === 'FORBIDDEN',
        "B.5 Train Driver strictly denied on {$ep} (403 FORBIDDEN)"
    );
}

// 5. Reporter: denied on ALL 7 analytics endpoints
foreach ($allEndpoints as $ep) {
    $res = callAnalyticsApi($ep, 'reporter');
    assertCondition(
        $res['success'] === false && ($res['error']['code'] ?? '') === 'FORBIDDEN',
        "B.6 Community Reporter strictly denied on {$ep} (403 FORBIDDEN)"
    );
}

// 6. Role manipulation attempts via GET query parameters
$resTamper1 = callAnalyticsApi('summary.php', 'driver', ['role' => 'admin']);
assertCondition(
    $resTamper1['success'] === false && ($resTamper1['error']['code'] ?? '') === 'FORBIDDEN',
    "B.7 Driver role manipulation attempt via ?role=admin strictly fails"
);
$resTamper2 = callAnalyticsApi('ai_advisory.php', 'control_officer', ['user_role' => 'wildlife_officer']);
assertCondition(
    $resTamper2['success'] === false && ($resTamper2['error']['code'] ?? '') === 'FORBIDDEN',
    "B.8 Control Officer role override attempt via ?user_role=wildlife_officer strictly fails"
);

// C. WILDLIFE LOCATION PRIVACY & COORDINATE PROTECTION
section("C. Wildlife Location Privacy & Coordinate Protection");

// 1. Hotspots coordinate delivery for authorized wildlife officer
$resHotspots = callAnalyticsApi('hotspots.php', 'wildlife_officer');
assertCondition($resHotspots['success'] === true, "C.1 Wildlife officer receives hotspot inventory");
$hotspots = $resHotspots['data']['hotspots'] ?? [];
assertCondition(count($hotspots) === 4, "C.2 Exactly 4 registered hotspots returned");
$firstHotspot = $hotspots[0] ?? [];
assertCondition(
    isset($firstHotspot['latitude']) && isset($firstHotspot['longitude']) && $firstHotspot['is_coordinates_masked'] === false,
    "C.3 Authorized wildlife officer receives unmasked hotspot operational coordinates"
);
assertCondition(
    isset($resHotspots['data']['metadata']['coordinate_protection_notice']),
    "C.4 Anti-poaching coordinate protection notice is present in payload"
);

// 2. Verify summary, temporal, sections, quality DO NOT leak individual report sighting records or raw GPS
$resSummary = callAnalyticsApi('summary.php', 'control_officer');
assertCondition(
    !isset($resSummary['data']['elephant_reports']) && !isset($resSummary['data']['reports']),
    "C.5 High-level summary does not leak individual elephant sighting report records"
);

$resSections = callAnalyticsApi('sections.php', 'control_officer');
$sectionsJsonStr = json_encode($resSections['data']);
assertCondition(
    strpos($sectionsJsonStr, '"latitude"') === false && !isset($resSections['data']['reports']),
    "C.6 Section analytics does not expose report GPS coordinates or raw report records"
);

// D. API CORRECTNESS & HTTP CONTRACTS
section("D. API Correctness, HTTP Contracts & Validation");

// 1. Standard success envelope structure
assertCondition(
    isset($resSummary['json']['success']) && isset($resSummary['json']['timestamp']) && isset($resSummary['json']['data']),
    "D.1 Response conforms to standard success envelope {success, timestamp, data}"
);

// 2. HTTP Method restriction (GET only; 405 Method Not Allowed)
$resPost = callAnalyticsApi('summary.php', 'admin', [], 'POST');
assertCondition(
    $resPost['success'] === false && ($resPost['error']['code'] ?? '') === 'METHOD_NOT_ALLOWED',
    "D.2 POST request to summary.php strictly returns 405 METHOD_NOT_ALLOWED"
);
$resDelete = callAnalyticsApi('temporal.php', 'admin', [], 'DELETE');
assertCondition(
    $resDelete['success'] === false && ($resDelete['error']['code'] ?? '') === 'METHOD_NOT_ALLOWED',
    "D.3 DELETE request to temporal.php strictly returns 405 METHOD_NOT_ALLOWED"
);

// 3. Validation: section_id parameter
$resSecBad1 = callAnalyticsApi('sections.php', 'control_officer', ['section_id' => '99']);
assertCondition(
    $resSecBad1['success'] === false && ($resSecBad1['error']['code'] ?? '') === 'VALIDATION_ERROR',
    "D.4 Out-of-bounds section_id (99) returns 422 VALIDATION_ERROR"
);
$resSecBad2 = callAnalyticsApi('sections.php', 'control_officer', ['section_id' => 'abc']);
assertCondition(
    $resSecBad2['success'] === false && ($resSecBad2['error']['code'] ?? '') === 'VALIDATION_ERROR',
    "D.5 Non-integer section_id ('abc') returns 422 VALIDATION_ERROR"
);

// 4. Valid section_id filtering
$resSecGood = callAnalyticsApi('sections.php', 'control_officer', ['section_id' => '2']);
assertCondition(
    $resSecGood['success'] === true && count($resSecGood['data']['sections']) === 1 && $resSecGood['data']['sections'][0]['section_id'] === 2,
    "D.6 Valid section_id=2 filters section statistics to Section 2 only"
);

// 5. Validation: date range parameters
$resDateBad1 = callAnalyticsApi('temporal.php', 'control_officer', ['start_date' => 'not-a-date']);
assertCondition(
    $resDateBad1['success'] === false && ($resDateBad1['error']['code'] ?? '') === 'VALIDATION_ERROR',
    "D.7 Invalid start_date format returns 422 VALIDATION_ERROR"
);
$resDateBad2 = callAnalyticsApi('temporal.php', 'control_officer', ['start_date' => '2026-12-01', 'end_date' => '2026-11-01']);
assertCondition(
    $resDateBad2['success'] === false && ($resDateBad2['error']['code'] ?? '') === 'VALIDATION_ERROR',
    "D.8 end_date preceding start_date returns 422 VALIDATION_ERROR"
);

// 6. Validation: provenance parameter
$resProvBad = callAnalyticsApi('summary.php', 'admin', ['provenance' => 'synthetic_only']);
assertCondition(
    $resProvBad['success'] === false && ($resProvBad['error']['code'] ?? '') === 'VALIDATION_ERROR',
    "D.9 Invalid provenance parameter returns 422 VALIDATION_ERROR"
);

// 7. Validation: unsupported query parameters
$resUnsup = callAnalyticsApi('quality.php', 'admin', ['unknown_param' => 'inject']);
assertCondition(
    $resUnsup['success'] === false && ($resUnsup['error']['code'] ?? '') === 'VALIDATION_ERROR',
    "D.10 Unsupported query parameter returns 422 VALIDATION_ERROR"
);

// E. DATABASE SAFETY & READ-ONLY PERMISSION HARDENING
section("E. Database Safety & Engine-Level Permissions");

// 1. Verify Database::getAnalyticsConnection() provides active PDO
$roPdo = Database::getAnalyticsConnection();
assertCondition($roPdo instanceof PDO, "E.1 Database::getAnalyticsConnection returns valid PDO instance");

// 2. Engine-level privilege enforcement: elegance_readonly cannot write
$insertBlocked = false;
try {
    $roPdo->exec("INSERT INTO elephant_reports (report_status) VALUES ('test_mutation')");
} catch (PDOException $e) {
    if (strpos($e->getMessage(), 'command denied') !== false || $e->getCode() == 42000) {
        $insertBlocked = true;
    }
}
assertCondition($insertBlocked, "E.2 MariaDB engine strictly denies INSERT on analytics connection (SQLSTATE 42000)");

$updateBlocked = false;
try {
    $roPdo->exec("UPDATE elephant_reports SET risk_score = 'Critical' WHERE id = 1");
} catch (PDOException $e) {
    if (strpos($e->getMessage(), 'command denied') !== false || $e->getCode() == 42000) {
        $updateBlocked = true;
    }
}
assertCondition($updateBlocked, "E.3 MariaDB engine strictly denies UPDATE on analytics connection (SQLSTATE 42000)");

// 3. Application-level query inspection: executeReadOnlyQuery blocks DDL/mutations
$appDropBlocked = false;
try {
    AnalyticsService::executeReadOnlyQuery($roPdo, "DROP TABLE elephant_reports");
} catch (RuntimeException $e) {
    if (strpos($e->getMessage(), 'SQL Read-Only Violation') !== false) {
        $appDropBlocked = true;
    }
}
assertCondition($appDropBlocked, "E.4 AnalyticsService::executeReadOnlyQuery blocks DROP statement");

// F. SCIENTIFIC INTEGRITY & TRUTH-IN-DATA
section("F. Scientific Integrity & Governance");

// 1. Temporal: Observation times remain unknown; no created_at substitution
$resTemp = callAnalyticsApi('temporal.php', 'wildlife_officer');
assertCondition($resTemp['success'] === true, "F.1 Temporal analytics query succeeded");
$unrecTimes = $resTemp['data']['unrecorded_times_count'] ?? 0;
$totalEval = $resTemp['data']['total_evaluated'] ?? 0;
assertCondition(
    $totalEval > 0 && $unrecTimes === $totalEval, 
    "F.2 100% of evaluated reports have unrecorded observation times ({$unrecTimes}/{$totalEval})", 
    "Got {$unrecTimes} of {$totalEval}"
);
$hourly = $resTemp['data']['hourly_distribution'] ?? [];
assertCondition(count($hourly) === 24, "F.3 Hourly distribution contains exactly 24 bins");
$totalHourly = array_sum($hourly);
assertCondition(
    $totalHourly === 0,
    "F.4 Ingestion timestamps (created_at) strictly NOT counted as observation hours (total in bins = 0)",
    "Got sum {$totalHourly}"
);

// 2. Duplicates: Zero confirmed duplicates, human review required
$resDup = callAnalyticsApi('duplicates.php', 'wildlife_officer');
assertCondition($resDup['success'] === true, "F.5 Duplicate analytics query succeeded");
$confirmedDups = $resDup['data']['stored_duplicate_metadata']['confirmed_duplicates_count'] ?? -1;
assertCondition($confirmedDups === 0, "F.6 Confirmed duplicates count is strictly 0 without ranger verification");
$dupNotice = $resDup['data']['metadata']['governance_policy'] ?? '';
assertCondition(
    strpos($dupNotice, 'Automated merging, deletion, or report suppression is strictly prohibited') !== false,
    "F.7 Duplicate governance notice prohibits automated merging or deletion"
);

// 3. AI Advisory: Explicitly advisory only; no automated verification
$resAi = callAnalyticsApi('ai_advisory.php', 'wildlife_officer');
assertCondition($resAi['success'] === true, "F.8 AI advisory query succeeded");
$aiNotice = $resAi['data']['metadata']['governance_status'] ?? '';
assertCondition(
    strpos($aiNotice, 'Advisory Only') !== false,
    "F.9 AI results explicitly flagged as Advisory Only"
);

// 4. Data Quality: Provenance breakdown transparently exposed
$resQual = callAnalyticsApi('quality.php', 'admin');
assertCondition($resQual['success'] === true, "F.10 Data quality query succeeded");
$provSummary = $resQual['data']['provenance_summary'] ?? [];
assertCondition(
    ($provSummary['genuine_operational_total'] ?? 0) === 3,
    "F.11 Provenance summary distinguishes 3 genuine operational records",
    "Got " . ($provSummary['genuine_operational_total'] ?? 'null')
);
assertCondition(
    ($provSummary['test_demo_total'] ?? 0) >= 37,
    "F.12 Provenance summary distinguishes development test records (>= 37)",
    "Got " . ($provSummary['test_demo_total'] ?? 'null')
);

// POST-FLIGHT BASELINE AUDIT
section("Post-Flight Operational DB Immutability Verification");
$res = verifyOperationalDatabaseSnapshot($preBaseline);
assertCondition(
    $res['tables']['elephant_reports']['passed'],
    "9.1 elephant_reports count and checksum completely unchanged"
);
assertCondition(
    $res['tables']['system_logs']['passed'],
    "9.2 system_logs count and checksum completely unchanged"
);
assertCondition(
    $res['tables']['alerts']['passed'],
    "9.3 alerts count and checksum completely unchanged"
);
assertCondition(
    $res['tables']['incident_history']['passed'],
    "9.4 incident_history count and checksum completely unchanged"
);
assertCondition(
    $res['tables']['hotspots']['passed'],
    "9.5 hotspots count and checksum completely unchanged"
);
assertCondition(
    $res['tables']['railway_sections']['passed'],
    "9.6 railway_sections count and checksum completely unchanged"
);
assertCondition(
    $res['tables']['users']['passed'],
    "9.7 users count and checksum completely unchanged"
);
assertCondition(
    $res['tables']['driver_acknowledgements']['passed'],
    "9.8 driver_acknowledgements count and checksum completely unchanged"
);
assertCondition(
    $res['post']['report1']['report_status'] === 'Pending' && 
    $res['post']['report1']['risk_score'] === 'Medium' && 
    $res['post']['report1']['sighting_time'] === null,
    "9.9 Report #1 remains pristine: Pending, Medium risk, NULL sighting time"
);

echo PHP_EOL . "========================================================================" . PHP_EOL;
echo "STAGE 4D.1 BACKEND ANALYTICS API TEST RESULTS" . PHP_EOL;
echo "Total Tests:  " . $testsRun . PHP_EOL;
echo "Passed Tests: " . ($testsRun - $testsFailed) . PHP_EOL;
echo "Failed Tests: " . $testsFailed . PHP_EOL;
echo "========================================================================" . PHP_EOL;

if ($testsFailed > 0) {
    exit(1);
}
exit(0);
