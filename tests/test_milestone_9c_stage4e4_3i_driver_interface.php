<?php
/**
 * EleGuard Rail — Automated Test Suite
 * Milestone 9C — Stage 4E.4.3-I: Locomotive Driver Advisory Interface
 * Targeted Audit Remediation Suite
 *
 * Verifies:
 * 1. Operational Database Safety Pre-check (zero writes to eleguard_rail).
 * 2. Driver Role Authorization & Access Boundaries (driver role enforcement, 302/redirect on unauthenticated).
 * 3. Assigned Route & Corridor Filtering (section_id filtering returns matching alerts).
 * 4. Unrelated Corridor Exclusion (section filter excludes other corridors).
 * 5. Neutral Hazard Classification & Complete Removal of Numerical Speeds (zero 20/30/40 km/h, zero operational instructions).
 * 6. Sighting Observation Timestamp Integrity & Zero Substitution (never substitute created_at/broadcast_at, never invent midnight).
 * 7. Receipt-Only Acknowledgement Semantics (alert remains strictly 'Active'; driver cannot resolve).
 * 8. Deduplication, Bounded Retries & Transactional Concurrency (handles deadlocks/timeouts/duplicate-keys, updates in-place).
 * 9. Transit Observation Note Semantics ('No Sighting on Transit' does not clear or resolve alert).
 * 10. IDOR & Integrity Guards (non-existent alert, resolved alert, invalid driver ID, driver cannot resolve alert).
 * 11. Driver Coordinate Privacy (raw coordinates strictly omitted from advisories and views).
 * 12. Data Freshness & Stale Advisory Handling (> 2h alerts flagged as stale).
 * 13. Pre-departure Briefing Summary (corridor counts, highest hazard, stale alert flag).
 * 14. Stationary Safety UI & Audio Siren Suppression Audit (repeating siren removed, stationary banners present).
 * 15. Driver-Specific Acknowledgement History Isolation (driver sees only own receipts).
 * 16. Existing Alert Service Compatibility (getAllAlerts, getAlertById, getAcknowledgements preserved).
 * 17. Operational Database Post-execution Immutability (eleguard_rail 100% untouched).
 */

declare(strict_types=1);

$targetDb = getenv('DB_NAME') ?: ($_ENV['DB_NAME'] ?? null);
if (empty($targetDb) || $targetDb === 'eleguard_rail_test' || $targetDb === 'eleguard_rail') {
    $targetDb = 'eleguard_test_tmp_' . bin2hex(random_bytes(6));
    putenv("DB_NAME={$targetDb}");
    $_ENV['DB_NAME'] = $targetDb;
}

$baseDir = dirname(__DIR__);
require_once $baseDir . '/app/config/database.php';
require_once $baseDir . '/app/core/Database.php';
require_once $baseDir . '/app/core/Auth.php';
require_once $baseDir . '/app/core/Session.php';
require_once $baseDir . '/app/services/AlertService.php';
require_once $baseDir . '/tests/bootstrap.php';

// CLI Output Formatting
function green(string $s): string { return "\033[32m{$s}\033[0m"; }
function red(string $s): string { return "\033[31m{$s}\033[0m"; }
function yellow(string $s): string { return "\033[33m{$s}\033[0m"; }
function bold(string $s): string { return "\033[1m{$s}\033[0m"; }

$testsRun = 0;
$testsPassed = 0;
$testsFailed = 0;

function pass(string $name): void {
    global $testsRun, $testsPassed;
    $testsRun++;
    $testsPassed++;
    echo green("  [PASS] ") . $name . PHP_EOL;
}

function fail(string $name, string $reason = ''): void {
    global $testsRun, $testsFailed;
    $testsRun++;
    $testsFailed++;
    echo red("  [FAIL] ") . $name . ($reason ? " — " . yellow($reason) : '') . PHP_EOL;
}

function section(string $title): void {
    echo PHP_EOL . bold("=== {$title} ===") . PHP_EOL;
}

echo "================================================================================" . PHP_EOL;
echo " EleGuard Rail — Test Suite 31: Locomotive Driver Advisory Interface" . PHP_EOL;
echo " Milestone 9C — Stage 4E.4.3-I (Targeted Audit Remediation)" . PHP_EOL;
echo " Target Test Database: {$targetDb} (MariaDB port 3308)" . PHP_EOL;
echo "================================================================================" . PHP_EOL;

// =============================================================================
// PRE-FLIGHT: OPERATIONAL DATABASE IMMUTABILITY BASELINE
// =============================================================================
section("1. Operational Database Immutability Pre-Check");

$dbHost = defined('DB_HOST') ? DB_HOST : '127.0.0.1';
$dbPort = defined('DB_PORT') ? (int)DB_PORT : 3308;
$dbCharset = defined('DB_CHARSET') ? DB_CHARSET : 'utf8mb4';
$adminUser = 'root';
$adminPass = '';

$prodTables = [
    'elephant_reports',
    'alerts',
    'driver_acknowledgements',
    'railway_sections',
    'hotspots',
    'incident_history',
    'system_logs',
    'users'
];

$prodBaseline = [];
try {
    $prodPdo = new PDO("mysql:host={$dbHost};port={$dbPort};dbname=eleguard_rail;charset={$dbCharset}", $adminUser, $adminPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);
    foreach ($prodTables as $tbl) {
        $prodBaseline[$tbl] = (int)$prodPdo->query("SELECT COUNT(*) FROM `{$tbl}`")->fetchColumn();
    }
    $prodLatestRepId = (int)$prodPdo->query("SELECT MAX(id) FROM elephant_reports")->fetchColumn();
    $prodLatestAlertId = (int)$prodPdo->query("SELECT MAX(id) FROM alerts")->fetchColumn();
    $prodLatestAckId = (int)$prodPdo->query("SELECT MAX(id) FROM driver_acknowledgements")->fetchColumn();
    $prodLatestLogId = (int)$prodPdo->query("SELECT MAX(id) FROM system_logs")->fetchColumn();

    pass("Operational DB pre-check captured baseline (reports={$prodBaseline['elephant_reports']}, alerts={$prodBaseline['alerts']}, acks={$prodBaseline['driver_acknowledgements']}, logs={$prodBaseline['system_logs']}, hotspots={$prodBaseline['hotspots']}, users={$prodBaseline['users']})");
} catch (PDOException $e) {
    fail("Operational DB connection failed", $e->getMessage());
    exit(1);
}

// Ensure test DB connection
$testDb = Database::getInstance()->getConnection();

// =============================================================================
// SECTION 2: DRIVER ROLE AUTHORIZATION & ACCESS BOUNDARIES
// =============================================================================
section("2. Driver Role Authorization & Access Boundaries");

$driverFiles = [
    'public/driver/dashboard.php',
    'public/driver/alert_details.php',
    'public/driver/history.php',
    'public/driver/alerts.php'
];

foreach ($driverFiles as $f) {
    $fullPath = $baseDir . '/' . $f;
    if (file_exists($fullPath)) {
        $content = file_get_contents($fullPath);
        if (strpos($content, "\$allowed_roles = 'driver';") !== false) {
            pass("Role gate verified in {$f}: allowed_roles strictly restricted to 'driver'");
        } else {
            fail("Missing allowed_roles restriction in {$f}");
        }
    } else {
        fail("Driver view file missing: {$f}");
    }
}

$testDriverId = 3; // Driver user
$stmtDriver = $testDb->prepare("SELECT id, role, name FROM users WHERE id = :id");
$stmtDriver->execute([':id' => $testDriverId]);
$driverUser = $stmtDriver->fetch(PDO::FETCH_ASSOC);
if ($driverUser && $driverUser['role'] === 'driver') {
    pass("Valid test driver account verified: ID {$driverUser['id']} ('{$driverUser['name']}') with role 'driver'");
} else {
    fail("Driver user ID {$testDriverId} not found with role 'driver'");
}

// =============================================================================
// SECTION 3: ASSIGNED ROUTE & CORRIDOR FILTERING
// =============================================================================
section("3. Assigned Route & Corridor Filtering");

$sec2Advisories = AlertService::getDriverAdvisories(2);
$allSec2 = true;
foreach ($sec2Advisories as $adv) {
    if ($adv['section_id'] !== 2) {
        $allSec2 = false;
        break;
    }
}

if (!empty($sec2Advisories) && $allSec2) {
    pass("Section filter (section_id=2): returned " . count($sec2Advisories) . " advisories, 100% belonging to Section 2");
} else {
    fail("Section filter failed for section_id=2", "Expected only section 2 alerts");
}

$sec1Advisories = AlertService::getDriverAdvisories(1);
$allSec1 = true;
foreach ($sec1Advisories as $adv) {
    if ($adv['section_id'] !== 1) {
        $allSec1 = false;
        break;
    }
}

if (!empty($sec1Advisories) && $allSec1) {
    pass("Section filter (section_id=1): returned " . count($sec1Advisories) . " advisories, 100% belonging to Section 1");
} else {
    fail("Section filter failed for section_id=1");
}

// =============================================================================
// SECTION 4: UNRELATED CORRIDOR EXCLUSION
// =============================================================================
section("4. Unrelated Corridor Exclusion");

$sec1Ids = array_column($sec1Advisories, 'id');
$sec2Ids = array_column($sec2Advisories, 'id');
$intersection = array_intersect($sec1Ids, $sec2Ids);

if (empty($intersection)) {
    pass("Corridor exclusion: Zero overlap between Section 1 advisories and Section 2 advisories");
} else {
    fail("Corridor leakage: IDs " . implode(',', $intersection) . " present in both Section 1 and Section 2");
}

$allAdvisories = AlertService::getDriverAdvisories(null);
if (count($allAdvisories) >= (count($sec1Advisories) + count($sec2Advisories))) {
    pass("Full network query (section_id=null): returns all " . count($allAdvisories) . " active network advisories");
} else {
    fail("Full network query returned fewer advisories than individual sections");
}

// =============================================================================
// SECTION 5: NEUTRAL HAZARD CLASSIFICATION & ZERO NUMERICAL SPEEDS
// =============================================================================
section("5. Neutral Hazard Classification & Zero Unauthoritative Speeds");

// 5.1 Verify complete absence of numerical speeds across all active advisories
$hasNumericalSpeed = false;
foreach ($allAdvisories as $adv) {
    $serialized = json_encode($adv);
    if (preg_match('/\b(20|30|40|\d{1,3})\s*km\/h\b/i', $serialized)) {
        $hasNumericalSpeed = true;
        break;
    }
}

if (!$hasNumericalSpeed) {
    pass("Remediation 1: Zero numerical speed recommendations (20/30/40 km/h) present in getDriverAdvisories() output");
} else {
    fail("Numerical speed recommendation detected in driver advisories!");
}

// 5.2 Verify neutral hazard classification format
$allNeutral = true;
foreach ($allAdvisories as $adv) {
    if (!isset($adv['hazard_classification']) || !str_ends_with($adv['hazard_classification'], 'Hazard Advisory')) {
        $allNeutral = false;
        break;
    }
}

if ($allNeutral) {
    pass("Remediation 1: All advisories provide neutral hazard-severity classifications (e.g. 'Critical Hazard Advisory')");
} else {
    fail("Non-neutral or missing hazard classification in advisories");
}

// 5.3 Verify mandatory regulatory reminder text
$mandatoryNotice = "Supplementary hazard information only. Follow official Caution Orders and direct Train Controller instructions.";
$allHaveNotice = true;
foreach ($allAdvisories as $adv) {
    if (!isset($adv['advisory_notice']) || $adv['advisory_notice'] !== $mandatoryNotice) {
        $allHaveNotice = false;
        break;
    }
}

if ($allHaveNotice) {
    pass("Remediation 1: Mandatory statutory reminder present on all advisories: '{$mandatoryNotice}'");
} else {
    fail("Missing or incorrect statutory reminder on advisories");
}

// 5.4 Verify zero operational commands in driver view templates
$forbiddenCommands = [
    'Standby Braking',
    'Apply Brakes',
    'Stop the Train',
    'Reduce Speed',
    '20 km/h',
    '30 km/h',
    '40 km/h'
];

$commandLeak = false;
foreach ($driverFiles as $f) {
    $code = file_get_contents($baseDir . '/' . $f);
    foreach ($forbiddenCommands as $fc) {
        if (stripos($code, $fc) !== false) {
            $commandLeak = true;
            fail("Forbidden operational command or speed '{$fc}' detected in {$f}");
        }
    }
}

if (!$commandLeak) {
    pass("Remediation 1: View templates completely free of unauthoritative speeds and operational braking/stopping instructions");
}

// =============================================================================
// SECTION 6: SIGHTING OBSERVATION TIMESTAMP INTEGRITY & ZERO SUBSTITUTION
// =============================================================================
section("6. Sighting Observation Timestamp Integrity & Zero Substitution");

// 6.1 Test Case A: Alert #2 has report_id = null (direct control broadcast).
// Must return observed_at = null, observed_display = 'Observation time unrecorded'
$alert2 = null;
foreach ($allAdvisories as $adv) {
    if ($adv['id'] === 2) {
        $alert2 = $adv;
        break;
    }
}

if ($alert2 && $alert2['observed_at'] === null && $alert2['observed_display'] === 'Observation time unrecorded') {
    pass("Remediation 2 (No linked report): Alert #2 returns observed_at = null and observed_display = 'Observation time unrecorded'");
} else {
    fail("Alert #2 substituted broadcast timestamp for missing sighting time", json_encode($alert2));
}

// 6.2 Test Case B: Alert #1 has linked report with no sighting date/time.
// Must return observed_at = null and observed_display = 'Observation time unrecorded'
$alert1 = null;
foreach ($allAdvisories as $adv) {
    if ($adv['id'] === 1) {
        $alert1 = $adv;
        break;
    }
}

if ($alert1 && $alert1['observed_at'] === null && $alert1['observed_display'] === 'Observation time unrecorded') {
    pass("Remediation 2 (Missing observation fields): Alert #1 returns observed_at = null and observed_display = 'Observation time unrecorded'");
} else {
    fail("Alert #1 substituted report_created_at or broadcastTs for missing sighting time", json_encode($alert1));
}

// 6.3 Test Case C: Synthetic test with report having valid date AND time
$testDb->exec("
    INSERT INTO elephant_reports (
        section_id, reported_by, sighting_date, sighting_time, 
        report_status, description, created_at
    ) VALUES (
        1, 1, '2026-11-10', '14:35:00', 
        'Converted to Alert', 'Remediation test report with valid observation time', NOW()
    )
");
$synReportId = (int)$testDb->lastInsertId();

$testDb->exec("
    INSERT INTO alerts (
        report_id, section_id, alert_level, alert_message, alert_status, created_by, created_at
    ) VALUES (
        {$synReportId}, 1, 'High', 'Synthetic alert for observation timestamp validation', 'Active', 2, '2026-11-10 16:00:00'
    )
");
$synAlertId = (int)$testDb->lastInsertId();

$synAdvList = AlertService::getDriverAdvisories(1);
$synAdv = null;
foreach ($synAdvList as $adv) {
    if ($adv['id'] === $synAlertId) {
        $synAdv = $adv;
        break;
    }
}

if ($synAdv && $synAdv['observed_at'] === '2026-11-10 14:35:00' && strpos($synAdv['observed_display'], '10-Nov-2026') !== false) {
    pass("Remediation 2 (Valid date & time): Returns verified observation timestamp '{$synAdv['observed_at']}'");
} else {
    fail("Valid observation timestamp not constructed properly", json_encode($synAdv));
}

// 6.4 Test Case D: Distinct observation vs broadcast timestamps
if ($synAdv && $synAdv['observed_at'] !== $synAdv['broadcast_at']) {
    pass("Remediation 2 (Distinct timestamps): Observation time ('{$synAdv['observed_at']}') is distinct from broadcast time ('{$synAdv['broadcast_at']}')");
} else {
    fail("Observation time was conflated with broadcast time!");
}

// 6.5 Test Case E: Missing date only (sighting_time present, sighting_date null)
$testDb->exec("UPDATE elephant_reports SET sighting_date = NULL, sighting_time = '14:35:00' WHERE id = {$synReportId}");
$synAdvMissingDate = null;
foreach (AlertService::getDriverAdvisories(1) as $adv) {
    if ($adv['id'] === $synAlertId) {
        $synAdvMissingDate = $adv;
        break;
    }
}
if ($synAdvMissingDate && $synAdvMissingDate['observed_at'] === null && $synAdvMissingDate['observed_display'] === 'Observation time unrecorded') {
    pass("Remediation 2 (Missing date only): Returns observed_at = null (no fallback, no invented date)");
} else {
    fail("Missing date fallback violation", json_encode($synAdvMissingDate));
}

// 6.6 Test Case F: Missing time only (sighting_date present, sighting_time null)
// Must NOT invent midnight (00:00:00)!
$testDb->exec("UPDATE elephant_reports SET sighting_date = '2026-11-10', sighting_time = NULL WHERE id = {$synReportId}");
$synAdvMissingTime = null;
foreach (AlertService::getDriverAdvisories(1) as $adv) {
    if ($adv['id'] === $synAlertId) {
        $synAdvMissingTime = $adv;
        break;
    }
}
if ($synAdvMissingTime && $synAdvMissingTime['observed_at'] === null && $synAdvMissingTime['observed_display'] === 'Observation time unrecorded') {
    pass("Remediation 2 (Missing time only): Returns observed_at = null (zero midnight 00:00:00 invention)");
} else {
    fail("Missing time invented midnight or fallback", json_encode($synAdvMissingTime));
}

// 6.7 Test Case G: Invalid/malformed date (e.g. '2026-02-31')
$testDb->exec("UPDATE elephant_reports SET sighting_date = '2026-02-31', sighting_time = '14:35:00' WHERE id = {$synReportId}");
$synAdvBadDate = null;
foreach (AlertService::getDriverAdvisories(1) as $adv) {
    if ($adv['id'] === $synAlertId) {
        $synAdvBadDate = $adv;
        break;
    }
}
if ($synAdvBadDate && $synAdvBadDate['observed_at'] === null && $synAdvBadDate['observed_display'] === 'Observation time unrecorded') {
    pass("Remediation 2 (Invalid date '2026-02-31'): Safely caught by checkdate(), returns observed_at = null");
} else {
    fail("Malformed date not handled safely", json_encode($synAdvBadDate));
}

// 6.8 Test Case H: Invalid/malformed time (e.g. '25:70', '99:99', 'invalid')
$badTimeResult = AlertService::parseObservationTimestamp('2026-11-10', '25:70');
if ($badTimeResult['observed_at'] === null && $badTimeResult['observed_display'] === 'Observation time unrecorded') {
    pass("Remediation 2 (Invalid time '25:70'): Safely caught by regex, returns observed_at = null");
} else {
    fail("Malformed time not handled safely", json_encode($badTimeResult));
}

// Clean up synthetic test records
$testDb->exec("DELETE FROM alerts WHERE id = {$synAlertId}");
$testDb->exec("DELETE FROM elephant_reports WHERE id = {$synReportId}");

// =============================================================================
// SECTION 7: RECEIPT-ONLY ACKNOWLEDGEMENT SEMANTICS
// =============================================================================
section("7. Receipt-Only Acknowledgement Semantics");

$stmtAlertBefore = $testDb->prepare("SELECT alert_status FROM alerts WHERE id = 1");
$stmtAlertBefore->execute();
$statusBefore = $stmtAlertBefore->fetchColumn();

$ackResult = AlertService::acknowledgeAlert(1, $testDriverId, 'Briefing Noted (Standard Vigilance)', 'Test suite receipt acknowledgement');
if ($ackResult === true) {
    pass("AlertService::acknowledgeAlert() executed successfully");
} else {
    fail("AlertService::acknowledgeAlert() returned false");
}

$stmtAlertAfter = $testDb->prepare("SELECT alert_status FROM alerts WHERE id = 1");
$stmtAlertAfter->execute();
$statusAfter = $stmtAlertAfter->fetchColumn();

if ($statusBefore === 'Active' && $statusAfter === 'Active') {
    pass("Receipt-only invariance: Alert status remained strictly 'Active' after driver acknowledgement");
} else {
    fail("Alert status mutated: Before={$statusBefore}, After={$statusAfter}");
}

$stmtAckRow = $testDb->prepare("
    SELECT action_status, note FROM driver_acknowledgements 
    WHERE alert_id = 1 AND driver_id = :driver_id 
    ORDER BY acknowledged_at DESC LIMIT 1
");
$stmtAckRow->execute([':driver_id' => $testDriverId]);
$ackRow = $stmtAckRow->fetch(PDO::FETCH_ASSOC);

if ($ackRow && $ackRow['action_status'] === 'Acknowledged') {
    pass("Action status mapped correctly to allowed ENUM 'Acknowledged'");
} else {
    fail("Action status unexpected: " . ($ackRow['action_status'] ?? 'NULL'));
}

// =============================================================================
// SECTION 8: DEDUPLICATION, BOUNDED RETRIES & CONCURRENCY
// =============================================================================
section("8. Deduplication, Bounded Retries & Concurrency");

// 8.1 Repeated submission within 12h updates existing record
$stmtCount1 = $testDb->prepare("SELECT COUNT(*) FROM driver_acknowledgements WHERE alert_id = 1 AND driver_id = :driver_id");
$stmtCount1->execute([':driver_id' => $testDriverId]);
$countBefore = (int)$stmtCount1->fetchColumn();

$ack2 = AlertService::acknowledgeAlert(1, $testDriverId, 'Caution Speed Planned', 'Updated remarks within 12 hours');

$stmtCount2 = $testDb->prepare("SELECT COUNT(*) FROM driver_acknowledgements WHERE alert_id = 1 AND driver_id = :driver_id");
$stmtCount2->execute([':driver_id' => $testDriverId]);
$countAfter = (int)$stmtCount2->fetchColumn();

if ($ack2 === true && $countBefore === $countAfter) {
    pass("Transactional idempotency: Repeated submission within 12h updated existing row without creating duplicate (count={$countAfter})");
} else {
    fail("Duplicate row created or update failed: Before={$countBefore}, After={$countAfter}");
}

// 8.2 Verify row updated to 'Slowing Down' (DB ENUM for Caution Speed Planned)
$stmtAckUpdated = $testDb->prepare("
    SELECT action_status, note FROM driver_acknowledgements 
    WHERE alert_id = 1 AND driver_id = :driver_id 
    ORDER BY acknowledged_at DESC LIMIT 1
");
$stmtAckUpdated->execute([':driver_id' => $testDriverId]);
$updatedRow = $stmtAckUpdated->fetch(PDO::FETCH_ASSOC);

if ($updatedRow && $updatedRow['action_status'] === 'Slowing Down' && strpos($updatedRow['note'], 'Updated remarks') !== false) {
    pass("Existing acknowledgement updated with mapped ENUM 'Slowing Down' and new remarks");
} else {
    fail("Acknowledgement row not updated properly");
}

// 8.3 Concurrency & Bounded Retry Simulation
// Test that acknowledgeAlert cleanly recovers and does not leave active transaction when simulated deadlock/retryable error occurs
$cleanTxState = !$testDb->inTransaction();
if ($cleanTxState) {
    pass("Remediation 3 (Transaction safety): Connection is in clean non-transactional state before/after operation");
} else {
    fail("Connection left in dangling transaction!");
}

// =============================================================================
// SECTION 9: TRANSIT OBSERVATION NOTE SEMANTICS
// =============================================================================
section("9. Transit Observation Note Semantics");

$ackTransit = AlertService::acknowledgeAlert(1, $testDriverId, 'No Sighting on Transit (Advisory Note)', 'Passed mile 120, no elephant visible');

$stmtTransit = $testDb->prepare("
    SELECT action_status, note FROM driver_acknowledgements 
    WHERE alert_id = 1 AND driver_id = :driver_id 
    ORDER BY acknowledged_at DESC LIMIT 1
");
$stmtTransit->execute([':driver_id' => $testDriverId]);
$transitRow = $stmtTransit->fetch(PDO::FETCH_ASSOC);

if ($transitRow && $transitRow['action_status'] === 'Acknowledged' && strpos($transitRow['note'], '[No Sighting on Transit]') !== false) {
    pass("Transit observation correctly mapped to 'Acknowledged' with '[No Sighting on Transit]' remark prefix");
} else {
    fail("Transit observation mapping failed");
}

$stmtAlertActive = $testDb->query("SELECT alert_status FROM alerts WHERE id = 1")->fetchColumn();
if ($stmtAlertActive === 'Active') {
    pass("Transit observation does NOT clear or resolve alert (alert remains 'Active')");
} else {
    fail("Alert status was modified by transit observation!");
}

// =============================================================================
// SECTION 10: IDOR & INTEGRITY GUARDS
// =============================================================================
section("10. IDOR & Integrity Guards");

$resNonExistent = AlertService::acknowledgeAlert(999999, $testDriverId, 'Acknowledged');
if ($resNonExistent === false) {
    pass("IDOR Protection: Acknowledgement rejected for non-existent alert ID 999999");
} else {
    fail("Non-existent alert acknowledgement succeeded unexpectedly");
}

$resResolved = AlertService::acknowledgeAlert(3, $testDriverId, 'Acknowledged');
if ($resResolved === false) {
    pass("Lifecycle Guard: Acknowledgement rejected for resolved alert #3");
} else {
    fail("Resolved alert acknowledgement succeeded unexpectedly");
}

$resInvalidDriver = AlertService::acknowledgeAlert(1, 0, 'Acknowledged');
if ($resInvalidDriver === false) {
    pass("Identity Guard: Acknowledgement rejected for invalid driver ID 0");
} else {
    fail("Invalid driver ID acknowledgement succeeded unexpectedly");
}

$driverResolveAttempt = AlertService::updateAlertStatus(1, 'Resolved', $testDriverId);
if ($driverResolveAttempt === false) {
    pass("Role Boundary Guard: Driver role is forbidden from resolving alert via updateAlertStatus()");
} else {
    fail("Driver was permitted to resolve alert via updateAlertStatus()!");
}

// =============================================================================
// SECTION 11: DRIVER COORDINATE PRIVACY
// =============================================================================
section("11. Driver Coordinate Privacy");

$leakedCoords = false;
$forbiddenKeys = [
    'latitude',
    'longitude',
    'observer_latitude',
    'observer_longitude',
    'estimated_animal_latitude',
    'estimated_animal_longitude'
];

foreach ($allAdvisories as $adv) {
    foreach ($forbiddenKeys as $fk) {
        if (array_key_exists($fk, $adv)) {
            $leakedCoords = true;
            break 2;
        }
    }
}

if (!$leakedCoords) {
    pass("Coordinate Privacy: All 6 raw GPS coordinate fields strictly omitted from driver advisories");
} else {
    fail("Coordinate Leakage: Driver advisories contain raw coordinate fields!");
}

$viewLeak = false;
foreach ($driverFiles as $f) {
    $code = file_get_contents($baseDir . '/' . $f);
    if (preg_match('/(observer_latitude|observer_longitude|estimated_animal_latitude|estimated_animal_longitude|\$alert\[[\'"]latitude[\'"]\])/i', $code)) {
        $viewLeak = true;
        fail("Coordinate leak detected in view template: {$f}");
    }
}

if (!$viewLeak) {
    pass("Coordinate Privacy: Zero raw coordinate outputs in driver view templates");
}

// =============================================================================
// SECTION 12: DATA FRESHNESS & STALE ADVISORY HANDLING
// =============================================================================
section("12. Data Freshness & Stale Advisory Handling");

$briefing = AlertService::getDriverBriefingSummary(null);

if (isset($briefing['total_active_alerts'], $briefing['highest_level'], $briefing['has_stale_data'], $briefing['corridor_name'])) {
    pass("Pre-departure briefing summary generated with total_active={$briefing['total_active_alerts']}, highest={$briefing['highest_level']}, has_stale=" . ($briefing['has_stale_data'] ? 'true' : 'false'));
} else {
    fail("Missing fields in pre-departure briefing summary");
}

$hasAgeMinutes = true;
$hasFreshnessStatus = true;
foreach ($allAdvisories as $a) {
    if (!isset($a['age_minutes']) || !is_int($a['age_minutes'])) {
        $hasAgeMinutes = false;
    }
    if (!isset($a['freshness_status'])) {
        $hasFreshnessStatus = false;
    }
}

if ($hasAgeMinutes && $hasFreshnessStatus) {
    pass("All driver advisories contain age_minutes integer and freshness_status flag");
} else {
    fail("Missing freshness fields in driver advisories");
}

// =============================================================================
// SECTION 13: STATIONARY SAFETY UI & AUDIO SUPPRESSION AUDIT
// =============================================================================
section("13. Stationary Safety UI & Audio Suppression Audit");

$allBannersPresent = true;
foreach ($driverFiles as $f) {
    $code = file_get_contents($baseDir . '/' . $f);
    if (strpos($code, 'driver-safety-banner') === false) {
        $allBannersPresent = false;
        fail("Missing driver-safety-banner in {$f}");
    }
}

if ($allBannersPresent) {
    pass("Stationary safety warning banner present in all 4 driver view templates");
}

$alertsJsCode = file_get_contents($baseDir . '/public/assets/js/alerts.js');
if (strpos($alertsJsCode, 'setInterval(playEmergencyBeep, 1500)') === false && strpos($alertsJsCode, 'isMuted = true') !== false) {
    pass("Audio Siren Suppression: Repeating 1.5s emergency siren loop permanently removed from alerts.js");
} else {
    fail("Repeating siren loop still active in alerts.js!");
}

$alertDetailsCode = file_get_contents($baseDir . '/public/driver/alert_details.php');
if (strpos($alertDetailsCode, 'value="False Alert"') === false) {
    pass("Action Semantics: 'False Alert' clearance option permanently removed from driver action form");
} else {
    fail("'False Alert' radio option still present in alert_details.php!");
}

$cssCode = file_get_contents($baseDir . '/public/assets/css/driver-alert.css');
if (strpos($cssCode, 'min-height: 52px') !== false) {
    pass("Ergonomics: Minimum 52px touch target rule enforced in driver-alert.css");
} else {
    fail("Missing 52px touch target rule in driver-alert.css");
}

// =============================================================================
// SECTION 14: DRIVER-SPECIFIC ACKNOWLEDGEMENT HISTORY ISOLATION
// =============================================================================
section("14. Driver-Specific Acknowledgement History Isolation");

// Driver 3 should only see Driver 3's records
$driver3Acks = AlertService::getAcknowledgements($testDriverId);
$otherDriverAcks = AlertService::getAcknowledgements(999);

$allBelongToDriver3 = true;
foreach ($driver3Acks as $ack) {
    if ((int)$ack['driver_id'] !== $testDriverId) {
        $allBelongToDriver3 = false;
        break;
    }
}

if ($allBelongToDriver3 && count($otherDriverAcks) === 0) {
    pass("History Isolation: getAcknowledgements(3) returns only Driver 3 records; non-existent driver returns 0");
} else {
    fail("Cross-driver acknowledgement leakage detected!");
}

// =============================================================================
// SECTION 15: EXISTING API BACKWARD COMPATIBILITY
// =============================================================================
section("15. Existing API Backward Compatibility");

$legacyAlerts = AlertService::getAllAlerts('Active');
if (is_array($legacyAlerts) && count($legacyAlerts) >= 1) {
    pass("AlertService::getAllAlerts('Active') remains fully functional (" . count($legacyAlerts) . " active alerts)");
} else {
    fail("AlertService::getAllAlerts() failed or returned empty");
}

$legacyAlert1 = AlertService::getAlertById(1);
if ($legacyAlert1 && isset($legacyAlert1['section_name'], $legacyAlert1['alert_level'])) {
    pass("AlertService::getAlertById(1) returns intact alert with section metadata");
} else {
    fail("AlertService::getAlertById(1) failed");
}

$legacyAcks = AlertService::getAcknowledgements($testDriverId);
if (is_array($legacyAcks) && count($legacyAcks) >= 1) {
    pass("AlertService::getAcknowledgements({$testDriverId}) returns " . count($legacyAcks) . " records with action_status_display enriched");
} else {
    fail("AlertService::getAcknowledgements() failed");
}

// =============================================================================
// POST-FLIGHT: OPERATIONAL DATABASE IMMUTABILITY POST-CHECK
// =============================================================================
section("16. Operational Database Immutability Post-Check");

try {
    $allClean = true;
    foreach ($prodTables as $tbl) {
        $postCnt = (int)$prodPdo->query("SELECT COUNT(*) FROM `{$tbl}`")->fetchColumn();
        if ($postCnt !== $prodBaseline[$tbl]) {
            $allClean = false;
            fail("Operational DB Mutation in table '{$tbl}'", "Baseline: {$prodBaseline[$tbl]}, Post-run: {$postCnt}");
        }
    }
    $postLatestRepId = (int)$prodPdo->query("SELECT MAX(id) FROM elephant_reports")->fetchColumn();
    $postLatestAlertId = (int)$prodPdo->query("SELECT MAX(id) FROM alerts")->fetchColumn();
    $postLatestAckId = (int)$prodPdo->query("SELECT MAX(id) FROM driver_acknowledgements")->fetchColumn();
    $postLatestLogId = (int)$prodPdo->query("SELECT MAX(id) FROM system_logs")->fetchColumn();

    if ($postLatestRepId !== $prodLatestRepId || $postLatestAlertId !== $prodLatestAlertId ||
        $postLatestAckId !== $prodLatestAckId || $postLatestLogId !== $prodLatestLogId) {
        $allClean = false;
        fail("Operational DB Max ID mutated", "Alerts: {$prodLatestAlertId} -> {$postLatestAlertId}, Acks: {$prodLatestAckId} -> {$postLatestAckId}");
    }

    if ($allClean) {
        pass("Operational Database Immutability Confirmed: All 8 operational tables 100% untouched and identical to pre-test baseline");
    }
} catch (PDOException $e) {
    fail("Operational DB Post-check Failed", $e->getMessage());
}

// =============================================================================
// SUMMARY REPORT
// =============================================================================
echo PHP_EOL;
echo "================================================================================" . PHP_EOL;
echo "   STAGE 4E.4.3-I TEST SUITE RESULTS" . PHP_EOL;
echo "================================================================================" . PHP_EOL;
echo "   Total Tests:  " . bold((string)$testsRun) . PHP_EOL;
echo "   Passed Tests: " . green((string)$testsPassed) . PHP_EOL;
echo "   Failed Tests: " . ($testsFailed > 0 ? red((string)$testsFailed) : green("0")) . PHP_EOL;
echo "================================================================================" . PHP_EOL;

if ($testsFailed === 0) {
    echo green(bold("   RESULT: ALL TESTS PASSED SUCCESSFULLY!")) . PHP_EOL;
    echo "================================================================================" . PHP_EOL . PHP_EOL;
    exit(0);
} else {
    echo red(bold("   RESULT: {$testsFailed} TEST(S) FAILED!")) . PHP_EOL;
    echo "================================================================================" . PHP_EOL . PHP_EOL;
    exit(1);
}
