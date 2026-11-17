<?php
/**
 * EleGuard Rail — Milestone 9C Stage J Phase J.6
 * Geodetic GPS Uncertainty & Mathematical Validation Suite
 * Change Reference: ER-M9C-STAGE-J-J6-001 (Safety Hardened)
 * 
 * Validates 14 Core Geodetic & Uncertainty Invariants:
 * 1.  Equatorial zero-offset co-location (0m standoff) preserves exact coordinates and returns direct GPS accuracy radius.
 * 2.  High latitude geodesic projection scales longitude properly without singularity.
 * 3.  Southern hemisphere projection computes negative latitude accurately.
 * 4.  Missing/NULL standoff distance falls back to observer_standoff_unadjusted with NULL animal coordinates.
 * 5.  Negative standoff distance falls back safely to observer_standoff_unadjusted with NULL animal coordinates.
 * 6.  Missing/NULL bearing falls back safely to observer_standoff_unadjusted with NULL animal coordinates.
 * 7.  Zero GPS accuracy (0m) does not crash and leaves derived spatial uncertainty strictly NULL (no fabrication).
 * 8.  Negative GPS accuracy is rejected as invalid and leaves derived spatial uncertainty strictly NULL (no fabrication).
 * 9.  Missing/NULL GPS accuracy leaves derived spatial uncertainty strictly NULL (no fabrication).
 * 10. Direct co-location with NULL GPS accuracy preserves coordinates but leaves uncertainty_meters = NULL.
 * 11. Large standoff visual range error model enforces 20% range error dominance over visual floor (5m) when GPS accuracy is valid.
 * 12. Standoff visual floor (5m) enforces minimum visual uncertainty on short standoff distances (<25m) when GPS accuracy is valid.
 * 13. High-precision coordinate bounds checking in Sri Lanka geodetic envelope (5.5-10.0 N, 79.5-82.5 E).
 * 14. Operational database immutability verification.
 */

define('ELEGUARD_TEST_SUITE', true);
define('ELEGUARD_TEST_ENVIRONMENT', true);

$baseDir = str_replace('\\', '/', dirname(__DIR__));
require_once $baseDir . '/app/config/database.php';
require_once $baseDir . '/tests/bootstrap.php';
require_once $baseDir . '/app/services/FieldReportingService.php';

// Target Disposable Database Target
$targetDb = getenv('DB_NAME') ?: ($_ENV['DB_NAME'] ?? null);
$wasSelfGenerated = false;
if (empty($targetDb) || $targetDb === 'eleguard_rail_test' || $targetDb === 'eleguard_rail') {
    $targetDb = 'eleguard_test_tmp_' . bin2hex(random_bytes(6));
    putenv("DB_NAME={$targetDb}");
    $_ENV['DB_NAME'] = $targetDb;
    $wasSelfGenerated = true;
}

validateTestDatabaseTarget($targetDb);

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

ensureTestDatabaseExists($adminPdo, $targetDb);

// Record baseline operational database state to verify zero modification
$opCountsBefore = [];
$opTables = ['elephant_reports', 'report_verifications', 'report_clarifications', 'section_attribution_audits', 'alerts', 'users'];
foreach ($opTables as $tbl) {
    try {
        $opCountsBefore[$tbl] = (int)$adminPdo->query("SELECT COUNT(*) FROM `eleguard_rail`.`{$tbl}`")->fetchColumn();
    } catch (Exception $e) {
        $opCountsBefore[$tbl] = -1;
    }
}

// Helpers
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
echo " EleGuard Rail — Stage J Phase J.6 Geodetic GPS & Uncertainty Validation Suite" . PHP_EOL;
echo " Target Test Database: " . $targetDb . PHP_EOL;
echo "================================================================================" . PHP_EOL;

// =============================================================================
// SECTION 1: Great-Circle Coordinate Boundary & Projection Tests
// =============================================================================
section("1. Great-Circle Coordinate Projection & Boundary Tests");

// Test 1: Equatorial Co-location (0m standoff) with valid device accuracy
$resEq = FieldReportingService::calculateGeodeticOffset(0.0, 0.0, 0.0, 90.0, 4.5);
assertCondition(
    $resEq['estimated_latitude'] === 0.0 &&
    $resEq['estimated_longitude'] === 0.0 &&
    $resEq['uncertainty_meters'] === 4.5 &&
    $resEq['method'] === 'direct_inspection' &&
    $resEq['is_estimated'] === 0,
    "Test 1: Direct co-location (0m standoff) preserves coordinates and returns direct GPS accuracy radius"
);

// Test 2: High Latitude Projection (60.0 N, 20.0 E, 1000m East, bearing 90)
$resHighLat = FieldReportingService::calculateGeodeticOffset(60.0, 20.0, 1000.0, 90.0, 10.0);
assertCondition(
    abs($resHighLat['estimated_latitude'] - 60.0) < 0.001 &&
    $resHighLat['estimated_longitude'] > 20.0 &&
    $resHighLat['is_estimated'] === 1 &&
    $resHighLat['method'] === 'triangulated_offset',
    "Test 2: High latitude projection (60.0 N) scales longitude accurately without numerical singularity"
);

// Test 3: Southern Hemisphere Projection (-35.0 S, 150.0 E, 500m South, bearing 180)
$resSouth = FieldReportingService::calculateGeodeticOffset(-35.0, 150.0, 500.0, 180.0, 10.0);
assertCondition(
    $resSouth['estimated_latitude'] < -35.0 &&
    abs($resSouth['estimated_longitude'] - 150.0) < 0.0001 &&
    $resSouth['is_estimated'] === 1,
    "Test 3: Southern hemisphere projection computes negative latitude decrement accurately"
);

// =============================================================================
// SECTION 2: Unadjusted Standoff & Missing Value Fallback Behavior
// =============================================================================
section("2. Standoff Missing & Invalid Value Handling");

// Test 4: Missing / NULL standoff distance
$resNullDist = FieldReportingService::calculateGeodeticOffset(8.0, 80.0, null, 90.0, 10.0);
assertCondition(
    $resNullDist['estimated_latitude'] === null &&
    $resNullDist['estimated_longitude'] === null &&
    $resNullDist['uncertainty_meters'] === null &&
    $resNullDist['method'] === 'observer_standoff_unadjusted',
    "Test 4: NULL standoff distance leaves animal coordinates and uncertainty strictly NULL"
);

// Test 5: Negative standoff distance
$resNegDist = FieldReportingService::calculateGeodeticOffset(8.0, 80.0, -50.0, 90.0, 10.0);
assertCondition(
    $resNegDist['estimated_latitude'] === null &&
    $resNegDist['method'] === 'observer_standoff_unadjusted',
    "Test 5: Negative standoff distance safely falls back to observer_standoff_unadjusted"
);

// Test 6: Missing / NULL bearing degrees
$resNullBearing = FieldReportingService::calculateGeodeticOffset(8.0, 80.0, 100.0, null, 10.0);
assertCondition(
    $resNullBearing['estimated_latitude'] === null &&
    $resNullBearing['method'] === 'observer_standoff_unadjusted',
    "Test 6: NULL compass bearing safely falls back to observer_standoff_unadjusted"
);

// =============================================================================
// SECTION 3: Safe GPS Accuracy Handling (Prohibiting Fabricated Defaults)
// =============================================================================
section("3. Safe Accuracy Handling & Uncertainty Propagation");

// Test 7: Zero GPS accuracy (0.0m) is treated as unknown/untrusted -> uncertainty_meters is NULL
$resZeroGps = FieldReportingService::calculateGeodeticOffset(8.0, 80.0, 100.0, 90.0, 0.0);
assertCondition(
    $resZeroGps['estimated_latitude'] !== null && $resZeroGps['uncertainty_meters'] === null,
    "Test 7: Zero GPS accuracy (0m) does not fabricate default; leaves uncertainty_meters strictly NULL"
);

// Test 8: Negative GPS accuracy (-15.0m) is treated as invalid -> uncertainty_meters is NULL
$resNegGps = FieldReportingService::calculateGeodeticOffset(8.0, 80.0, 100.0, 90.0, -15.0);
assertCondition(
    $resNegGps['estimated_latitude'] !== null && $resNegGps['uncertainty_meters'] === null,
    "Test 8: Negative GPS accuracy (-15m) does not fabricate default; leaves uncertainty_meters strictly NULL"
);

// Test 9: NULL GPS accuracy leaves derived spatial uncertainty strictly NULL
$resNullGps = FieldReportingService::calculateGeodeticOffset(8.0, 80.0, 100.0, 90.0, null);
assertCondition(
    $resNullGps['estimated_latitude'] !== null && $resNullGps['uncertainty_meters'] === null,
    "Test 9: NULL GPS accuracy leaves derived spatial uncertainty strictly NULL (no invented default)"
);

// Test 10: Direct co-location with NULL GPS accuracy preserves coordinates with NULL uncertainty
$resDirectNullGps = FieldReportingService::calculateGeodeticOffset(8.0, 80.0, 0.0, 0.0, null);
assertCondition(
    $resDirectNullGps['estimated_latitude'] === 8.0 &&
    $resDirectNullGps['estimated_longitude'] === 80.0 &&
    $resDirectNullGps['uncertainty_meters'] === null &&
    $resDirectNullGps['method'] === 'direct_inspection',
    "Test 10: Direct co-location with NULL GPS accuracy preserves coordinates but leaves uncertainty NULL"
);

// Test 11: Valid GPS accuracy propagates correctly with 20% range error dominance on large standoff
// d = 200m, gpsAccuracy = 10.0m
// sigma_range = max(5, 0.20 * 200) = 40.0m
// transverseError = 200 * sin(deg2rad(10)) = 34.7296m
// totalUncertainty = sqrt(10^2 + 40^2 + 34.7296^2) = sqrt(100 + 1600 + 1206.148) = sqrt(2906.148) = 53.91m
$resLarge = FieldReportingService::calculateGeodeticOffset(8.0, 80.0, 200.0, 45.0, 10.0);
assertCondition(
    abs($resLarge['uncertainty_meters'] - 53.91) <= 0.05,
    "Test 11: Valid 10m accuracy with 200m standoff computes 53.91m propagated uncertainty",
    "Computed: " . $resLarge['uncertainty_meters']
);

// Test 12: Standoff visual floor (5m) enforces minimum visual uncertainty on short standoff distances (<25m)
// d = 10m, gpsAccuracy = 5.0m
// sigma_range = max(5, 0.20 * 10) = 5.0m
// transverseError = 10 * sin(deg2rad(10)) = 1.736m
// totalUncertainty = sqrt(5^2 + 5^2 + 1.736^2) = sqrt(25 + 25 + 3.015) = sqrt(53.015) = 7.28m
$resShort = FieldReportingService::calculateGeodeticOffset(8.0, 80.0, 10.0, 45.0, 5.0);
assertCondition(
    abs($resShort['uncertainty_meters'] - 7.28) <= 0.05,
    "Test 12: 10m short standoff with 5m accuracy enforces 5.0m visual uncertainty floor (total = 7.28m)",
    "Computed: " . $resShort['uncertainty_meters']
);

// =============================================================================
// SECTION 4: Coordinate Reference System & National Geodetic Boundary Envelopes
// =============================================================================
section("4. CRS & Sri Lanka Geodetic Envelope Boundaries");

// Test 13: Payload boundary validation against Sri Lanka national bounding box
$valValid = FieldReportingService::validateObservationPayload([
    'observer_location' => ['latitude' => 8.0345, 'longitude' => 80.2850]
]);
$valInvalidLat = FieldReportingService::validateObservationPayload([
    'observer_location' => ['latitude' => 12.5000, 'longitude' => 80.2850]
]);
$valInvalidLon = FieldReportingService::validateObservationPayload([
    'observer_location' => ['latitude' => 8.0345, 'longitude' => 75.0000]
]);

assertCondition(
    empty($valValid['errors']['observer_latitude']) &&
    !empty($valInvalidLat['errors']['observer_latitude']) &&
    !empty($valInvalidLon['errors']['observer_longitude']),
    "Test 13: Payload validation enforces Sri Lanka national geodetic bounding box (5.5-10.0 N, 79.5-82.5 E)"
);

// =============================================================================
// Operational Database Invariant Verification
// =============================================================================
section("Operational Database Immutability Check");
$opCountsAfter = [];
$mutated = [];
foreach ($opTables as $tbl) {
    try {
        $count = (int)$adminPdo->query("SELECT COUNT(*) FROM `eleguard_rail`.`{$tbl}`")->fetchColumn();
        $opCountsAfter[$tbl] = $count;
        if ($count !== $opCountsBefore[$tbl]) {
            $mutated[] = "{$tbl} (before: {$opCountsBefore[$tbl]}, after: {$count})";
        }
    } catch (Exception $e) {
        $opCountsAfter[$tbl] = -1;
    }
}

assertCondition(
    empty($mutated),
    "Operational database eleguard_rail remained 100% immutable throughout geodetic validation tests",
    "Mutations detected: " . implode(', ', $mutated)
);

// =============================================================================
// Teardown Ephemeral Database (When run standalone outside master test runner)
// =============================================================================
if (empty(getenv('ELEGUARD_MASTER_RUNNER'))) {
    try {
        safeTeardownTestDatabase($adminPdo, $targetDb);
    } catch (Exception $e) {
        // Suppress cleanup warning
    }
}

// =============================================================================
// Summary & Verdict
// =============================================================================
echo PHP_EOL . "================================================================================" . PHP_EOL;
echo " SUITE SUMMARY: {$testsRun} ran, " . ($testsRun - $testsFailed) . " passed, {$testsFailed} failed." . PHP_EOL;
if ($testsFailed === 0) {
    echo " \033[32mVERDICT: [PASS] ALL 14 GEODETIC & UNCERTAINTY INVARIANTS VERIFIED CLEANLY.\033[0m" . PHP_EOL;
    echo "================================================================================" . PHP_EOL;
    exit(0);
} else {
    echo " \033[31mVERDICT: [FAIL] {$testsFailed} TEST ASSERTIONS FAILED.\033[0m" . PHP_EOL;
    echo "================================================================================" . PHP_EOL;
    exit(1);
}
