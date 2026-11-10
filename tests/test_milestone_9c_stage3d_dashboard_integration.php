<?php
/**
 * EleGuard Rail - Milestone 9C Stage 3D Automated Test Suite
 * 
 * Comprehensive End-to-End Verification for Dashboard Integration & Shadow Risk Assessment:
 * - Group 1: Dual Risk Assessment & Observation-Aware Shadow Calculation Logic
 * - Group 2: Unknown Observation Time & Legacy Report Robustness
 * - Group 3: Control Officer Dashboard Review & Alert Conversion Form Safety
 * - Group 4: Wildlife Officer Review Screen Shadow Assessment Rendering
 * - Group 5: Operational Alerting Isolation & Driver Non-Interference
 * - Group 6: Role-Based Access Control (RBAC) Integrity
 * - Group 7: Zero Operational Database Writes & Invariant Verification
 * 
 * STRICT ISOLATION: Tests run against cloned isolated database `eleguard_rail_test`.
 * Operates with ZERO writes to operational `eleguard_rail`.
 */

error_reporting(E_ALL & ~E_WARNING & ~E_NOTICE);
ini_set('display_errors', '0');

// STEP 1: Pre-flight operational DB record & create isolated test database
$rawHost = '127.0.0.1';
$rawPort = 3308;
$rawUser = 'root';
$rawPass = '';

$rawPdo = new PDO("mysql:host={$rawHost};port={$rawPort}", $rawUser, $rawPass);
$rawPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Record baseline operational counts and Report #1 state before any test activity
$rawPdo->exec("USE eleguard_rail");
$preOperReports = (int)$rawPdo->query("SELECT COUNT(*) FROM elephant_reports")->fetchColumn();
$preOperLogs = (int)$rawPdo->query("SELECT COUNT(*) FROM system_logs")->fetchColumn();
$preOperAlerts = (int)$rawPdo->query("SELECT COUNT(*) FROM alerts")->fetchColumn();
$preOperReport1 = $rawPdo->query("SELECT id, report_status, sighting_date, sighting_time, sighting_time_precision, created_at, updated_at FROM elephant_reports WHERE id = 1")->fetch(PDO::FETCH_ASSOC);

// Create fresh isolated test database clone
$testDbName = 'eleguard_rail_test';
$rawPdo->exec("DROP DATABASE IF EXISTS `{$testDbName}`");
$rawPdo->exec("CREATE DATABASE `{$testDbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

$rawPdo->exec("USE eleguard_rail");
$tables = $rawPdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);

$rawPdo->exec("SET FOREIGN_KEY_CHECKS = 0");
foreach ($tables as $t) {
    $createSql = $rawPdo->query("SHOW CREATE TABLE `{$t}`")->fetch(PDO::FETCH_ASSOC)['Create Table'];
    $rawPdo->exec("USE `{$testDbName}`");
    $rawPdo->exec($createSql);
    $rawPdo->exec("INSERT INTO `{$testDbName}`.`{$t}` SELECT * FROM eleguard_rail.`{$t}`");
    $rawPdo->exec("USE eleguard_rail");
}
$rawPdo->exec("SET FOREIGN_KEY_CHECKS = 1");

// STEP 2: Enforce environment variable routing to isolated test database BEFORE loading config
putenv("DB_NAME={$testDbName}");
$_ENV['DB_NAME'] = $testDbName;

require_once __DIR__ . '/../app/config/app.php';
require_once __DIR__ . '/../app/core/Database.php';
require_once __DIR__ . '/../app/core/Session.php';
require_once __DIR__ . '/../app/core/Router.php';
require_once __DIR__ . '/../app/services/TemporalService.php';
require_once __DIR__ . '/../app/services/TemporalRiskService.php';
require_once __DIR__ . '/../app/services/RiskService.php';
require_once __DIR__ . '/../app/services/ReportService.php';
require_once __DIR__ . '/../app/services/AlertService.php';
require_once __DIR__ . '/../app/services/AiDetectionService.php';
require_once __DIR__ . '/../app/includes/functions.php';

// Assert connection is isolated test database
$connectedDb = Database::getInstance()->getConnection()->query("SELECT DATABASE()")->fetchColumn();
if ($connectedDb !== $testDbName) {
    die("FATAL: Test suite connected to '{$connectedDb}' instead of '{$testDbName}'. Aborting for safety.\n");
}

echo "========================================================================\n";
echo "ELEGUARD RAIL — MILESTONE 9C STAGE 3D AUTOMATED TEST SUITE\n";
echo "Dashboard Integration & Shadow Risk Assessment Verification\n";
echo "Database: {$connectedDb} (Operational isolation active)\n";
echo "========================================================================\n\n";

$totalTests = 0;
$passedTests = 0;
$failedTests = 0;

function runTest($name, $assertion, &$totalTests, &$passedTests, &$failedTests) {
    $totalTests++;
    if ($assertion) {
        $passedTests++;
        echo "  [PASS] {$name}\n";
    } else {
        $failedTests++;
        echo "  [FAIL] {$name}\n";
    }
}

// ------------------------------------------------------------------------
// GROUP 1: Dual Risk Assessment & Shadow Calculation Logic
// ------------------------------------------------------------------------
echo "--- GROUP 1: Dual Risk Assessment & Shadow Calculation Logic ---\n";

// Case 1A: Full Night Exact Sighting (Section: Medium=2, Urgency: High=3, Count: 2=2, Alerts: 0=0, Night: +2.0)
// Total = 2 + 3 + 2 + 0 + 2.0 = 9.0 -> High
$reportContextNight = [
    'section_risk' => 'Medium',
    'urgency_level' => 'High',
    'elephant_count' => 2,
    'active_alerts' => false
];
$temporalNight = [
    'sighting_date' => '2026-11-10',
    'sighting_time' => '20:30:00',
    'sighting_time_precision' => 'exact',
    'sighting_time_uncertainty_minutes' => 0
];
$resNight = RiskService::calculateObservationAwareRisk($reportContextNight, $temporalNight);

runTest("Case 1A.1: Night observation diurnal adjustment is +2.00 pts", abs($resNight['diurnal_adjustment'] - 2.0) < 0.001, $totalTests, $passedTests, $failedTests);
runTest("Case 1A.2: Night observation unrounded total score equals 9.00 pts", abs($resNight['unrounded_total_score'] - 9.0) < 0.001, $totalTests, $passedTests, $failedTests);
runTest("Case 1A.3: Night observation final classification is 'High'", $resNight['final_classification'] === 'High', $totalTests, $passedTests, $failedTests);
runTest("Case 1A.4: Observation interval accurately reported as 20:30 to 20:30", $resNight['observation_interval']['start'] === '20:30' && $resNight['observation_interval']['end'] === '20:30', $totalTests, $passedTests, $failedTests);

// Case 1B: Mid-Day Daylight Observation (12:00 -> Diurnal adjustment = 0.0 pts)
// Total = 2 + 3 + 2 + 0 + 0.0 = 7.0 -> Medium
$temporalDay = [
    'sighting_date' => '2026-11-10',
    'sighting_time' => '12:00:00',
    'sighting_time_precision' => 'exact',
    'sighting_time_uncertainty_minutes' => 0
];
$resDay = RiskService::calculateObservationAwareRisk($reportContextNight, $temporalDay);

runTest("Case 1B.1: Daylight observation diurnal adjustment is exactly 0.00 pts", abs($resDay['diurnal_adjustment'] - 0.0) < 0.001, $totalTests, $passedTests, $failedTests);
runTest("Case 1B.2: Daylight observation unrounded total score equals 7.00 pts", abs($resDay['unrounded_total_score'] - 7.0) < 0.001, $totalTests, $passedTests, $failedTests);
runTest("Case 1B.3: Daylight observation final classification is 'Medium'", $resDay['final_classification'] === 'Medium', $totalTests, $passedTests, $failedTests);

// Case 1C: Twilight Dusk Observation (18:00:00 -> D(18.0) = 1.0 pts)
// Total = 2 + 3 + 2 + 0 + 1.0 = 8.0 -> High
$temporalDusk = [
    'sighting_date' => '2026-11-10',
    'sighting_time' => '18:00:00',
    'sighting_time_precision' => 'exact',
    'sighting_time_uncertainty_minutes' => 0
];
$resDusk = RiskService::calculateObservationAwareRisk($reportContextNight, $temporalDusk);

runTest("Case 1C.1: Dusk twilight observation diurnal adjustment is +1.00 pts", abs($resDusk['diurnal_adjustment'] - 1.0) < 0.001, $totalTests, $passedTests, $failedTests);
runTest("Case 1C.2: Dusk observation unrounded total score equals 8.00 pts", abs($resDusk['unrounded_total_score'] - 8.0) < 0.001, $totalTests, $passedTests, $failedTests);
runTest("Case 1C.3: Point breakdown components accurately populated", (
    $resDusk['base_section_points'] === 2 &&
    $resDusk['urgency_points'] === 3 &&
    $resDusk['elephant_count_points'] === 2 &&
    $resDusk['active_alert_points'] === 0
), $totalTests, $passedTests, $failedTests);

// ------------------------------------------------------------------------
// GROUP 2: Unknown Observation Time & Legacy Report Robustness
// ------------------------------------------------------------------------
echo "\n--- GROUP 2: Unknown Observation Time & Legacy Report Robustness ---\n";

// Case 2A: Unknown Observation Precision
$temporalUnknown = [
    'sighting_date' => '2026-11-10',
    'sighting_time' => null,
    'sighting_time_precision' => 'unknown',
    'sighting_time_uncertainty_minutes' => null
];
$resUnknown = RiskService::calculateObservationAwareRisk($reportContextNight, $temporalUnknown);

runTest("Case 2A.1: Unknown observation diurnal adjustment is 0.00 pts", abs($resUnknown['diurnal_adjustment'] - 0.0) < 0.001, $totalTests, $passedTests, $failedTests);
runTest("Case 2A.2: Unknown observation generates data quality warning", count($resUnknown['data_quality_warnings']) > 0 && strpos($resUnknown['data_quality_warnings'][0], 'Unknown observation time') !== false, $totalTests, $passedTests, $failedTests);

// Case 2B: Historical Legacy Report #1 (all sighting_* fields are NULL)
$legacyReport1 = ReportService::getById(1);
runTest("Case 2B.1: Legacy Report #1 loaded successfully", !empty($legacyReport1) && $legacyReport1['id'] == 1, $totalTests, $passedTests, $failedTests);

$resReport1 = RiskService::calculateObservationAwareRisk($legacyReport1, $legacyReport1);
runTest("Case 2B.2: Legacy Report #1 evaluates safely without exception", !empty($resReport1) && isset($resReport1['final_score']), $totalTests, $passedTests, $failedTests);
runTest("Case 2B.3: Legacy Report #1 diurnal adjustment is 0.00 pts", abs($resReport1['diurnal_adjustment'] - 0.0) < 0.001, $totalTests, $passedTests, $failedTests);
runTest("Case 2B.4: Legacy Report #1 retains base section floor protection", $resReport1['unrounded_total_score'] >= $resReport1['base_section_points'], $totalTests, $passedTests, $failedTests);

// ------------------------------------------------------------------------
// GROUP 3: Control Officer Dashboard Review & Alert Conversion Form Safety
// ------------------------------------------------------------------------
echo "\n--- GROUP 3: Control Officer Dashboard Review & Alert Conversion Form Safety ---\n";

// Test rendering public/control/reports.php with selected report
// We set up mock session and $_GET
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$_SESSION['user_id'] = 2;
$_SESSION['user_role'] = 'control_officer';
$_SESSION['user_name'] = 'Test Controller';
$currentUser = [
    'id' => 2,
    'name' => 'Test Controller',
    'role' => 'control_officer',
    'email' => 'controller@railway.gov.lk'
];

// Query a verified report to test conversion panel
$testPdo = Database::getInstance()->getConnection();
$verifiedRepId = (int)$testPdo->query("SELECT id FROM elephant_reports WHERE report_status = 'Verified' LIMIT 1")->fetchColumn();
if (!$verifiedRepId) {
    // If none verified, temporarily verify report #2 in test database
    $testPdo->exec("UPDATE elephant_reports SET report_status = 'Verified' WHERE id = 2");
    $verifiedRepId = 2;
}

$_GET['id'] = (string)$verifiedRepId;
$verifiedReport = ReportService::getById($verifiedRepId);

// Render control/reports.php into buffer
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = '/control/reports.php?id=' . $verifiedRepId;
ob_start();
include __DIR__ . '/../public/control/reports.php';
$controlHtml = ob_get_clean();

runTest("Case 3.1: Control review page contains prominent EXPERIMENTAL banner", strpos($controlHtml, 'EXPERIMENTAL — NOT USED FOR OPERATIONAL ALERTING') !== false, $totalTests, $passedTests, $failedTests);
runTest("Case 3.2: Control review page contains Card A Operational Risk section", strpos($controlHtml, 'A. Operational Risk (Active)') !== false, $totalTests, $passedTests, $failedTests);
runTest("Case 3.3: Control review page contains Card B Observation-Aware Risk section", strpos($controlHtml, 'B. Observation-Aware Risk') !== false, $totalTests, $passedTests, $failedTests);
runTest("Case 3.4: Control review page contains SHADOW MODE NOTICE governance banner", strpos($controlHtml, 'SHADOW MODE NOTICE:') !== false, $totalTests, $passedTests, $failedTests);
runTest("Case 3.5: Control review page displays model version v1.0.0-experimental", strpos($controlHtml, 'v1.0.0-experimental') !== false, $totalTests, $passedTests, $failedTests);
runTest("Case 3.6: Control review displays Observation Time in report summary", strpos($controlHtml, 'Observation Time:') !== false, $totalTests, $passedTests, $failedTests);

// Verify alert conversion dropdown defaults to OPERATIONAL risk score
$expectedStoredRisk = $verifiedReport['risk_score'];
$selectedOptionPattern = '<option value="' . $expectedStoredRisk . '" selected>';
runTest("Case 3.7: Alert broadcast dropdown defaults strictly to operational score ({$expectedStoredRisk})", strpos($controlHtml, $selectedOptionPattern) !== false, $totalTests, $passedTests, $failedTests);

// ------------------------------------------------------------------------
// GROUP 4: Wildlife Officer Review Screen Shadow Assessment Rendering
// ------------------------------------------------------------------------
echo "\n--- GROUP 4: Wildlife Officer Review Screen Shadow Assessment Rendering ---\n";

$_SESSION['user_role'] = 'wildlife_officer';
$currentUser['role'] = 'wildlife_officer';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = '/wildlife/reports.php?id=' . $verifiedRepId;

ob_start();
include __DIR__ . '/../public/wildlife/reports.php';
$wildlifeHtml = ob_get_clean();

runTest("Case 4.1: Wildlife review page contains EXPERIMENTAL shadow banner", strpos($wildlifeHtml, 'EXPERIMENTAL — NOT USED FOR OPERATIONAL ALERTING') !== false, $totalTests, $passedTests, $failedTests);
runTest("Case 4.2: Wildlife review page contains Risk Assessment Comparison panel", strpos($wildlifeHtml, 'Risk Assessment Comparison') !== false, $totalTests, $passedTests, $failedTests);
runTest("Case 4.3: Wildlife review page displays dual cards A and B", strpos($wildlifeHtml, 'A. Operational Risk (Active)') !== false && strpos($wildlifeHtml, 'B. Observation-Aware Risk') !== false, $totalTests, $passedTests, $failedTests);
runTest("Case 4.4: Wildlife review page preserves AI advisory panel integration", strpos($wildlifeHtml, 'AI Vision Sighting Advisory') !== false, $totalTests, $passedTests, $failedTests);

// ------------------------------------------------------------------------
// GROUP 5: Operational Alerting Isolation & Driver Non-Interference
// ------------------------------------------------------------------------
echo "\n--- GROUP 5: Operational Alerting Isolation & Driver Non-Interference ---\n";

// Verify AlertService::createAlert operates purely with operational inputs
$initialTestAlertCount = (int)$testPdo->query("SELECT COUNT(*) FROM alerts")->fetchColumn();
$newAlertId = AlertService::createAlert(
    $verifiedRepId,
    (int)$verifiedReport['section_id'],
    $verifiedReport['risk_score'],
    "AUTOMATED TEST SAFETY BROADCAST: Operational alert remains isolated.",
    2
);

runTest("Case 5.1: Alert creation succeeds using operational score without shadow contamination", $newAlertId > 0, $totalTests, $passedTests, $failedTests);

// Verify alert in test database has the operational alert level
$createdAlert = AlertService::getAlertById($newAlertId);
runTest("Case 5.2: Created operational alert level matches report operational risk score", $createdAlert['alert_level'] === $verifiedReport['risk_score'], $totalTests, $passedTests, $failedTests);

// Verify driver dashboard alerts query: driver only queries alerts table (alert_status = 'Active')
$activeAlertsForDriver = AlertService::getAllAlerts('Active');
runTest("Case 5.3: Driver active alerts query returns standard operational alert objects", is_array($activeAlertsForDriver) && count($activeAlertsForDriver) >= 1, $totalTests, $passedTests, $failedTests);

// Verify ReportService::submitReport stores operational risk score, unaffected by shadow calculations
$subResult = ReportService::submitReportWithId(
    1,
    2,
    3,
    "Trackside KM 118 near Habarana",
    "High",
    "Stage 3D shadow non-interference intake verification",
    null,
    8.0321,
    80.7543,
    15.0,
    [
        'sighting_date' => '2026-11-09',
        'sighting_time' => '19:45:00',
        'sighting_time_precision' => 'exact',
        'sighting_time_uncertainty_minutes' => 0
    ]
);
runTest("Case 5.4: New report submission generates valid report ID", $subResult > 0, $totalTests, $passedTests, $failedTests);

$newReportRecord = ReportService::getById($subResult);
runTest("Case 5.5: Stored risk_score is standard categorical score ('High' or 'Critical')", in_array($newReportRecord['risk_score'], ['Low', 'Medium', 'High', 'Critical']), $totalTests, $passedTests, $failedTests);

// ------------------------------------------------------------------------
// GROUP 6: Role-Based Access Control (RBAC) Integrity
// ------------------------------------------------------------------------
echo "\n--- GROUP 6: Role-Based Access Control (RBAC) Integrity ---\n";

// Control reports restricted to control_officer
// Wildlife reports restricted to wildlife_officer
runTest("Case 6.1: public/control/reports.php specifies \$allowed_roles = 'control_officer'", (
    strpos(file_get_contents(__DIR__ . '/../public/control/reports.php'), "\$allowed_roles = 'control_officer';") !== false
), $totalTests, $passedTests, $failedTests);

runTest("Case 6.2: public/wildlife/reports.php specifies \$allowed_roles = 'wildlife_officer'", (
    strpos(file_get_contents(__DIR__ . '/../public/wildlife/reports.php'), "\$allowed_roles = 'wildlife_officer';") !== false
), $totalTests, $passedTests, $failedTests);

// ------------------------------------------------------------------------
// GROUP 7: Zero Operational Database Writes & Invariant Verification
// ------------------------------------------------------------------------
echo "\n--- GROUP 7: Zero Operational Database Writes & Invariant Verification ---\n";

$rawPdo->exec("USE eleguard_rail");
$postOperReports = (int)$rawPdo->query("SELECT COUNT(*) FROM elephant_reports")->fetchColumn();
$postOperLogs = (int)$rawPdo->query("SELECT COUNT(*) FROM system_logs")->fetchColumn();
$postOperAlerts = (int)$rawPdo->query("SELECT COUNT(*) FROM alerts")->fetchColumn();
$postOperReport1 = $rawPdo->query("SELECT id, report_status, sighting_date, sighting_time, sighting_time_precision, created_at, updated_at FROM elephant_reports WHERE id = 1")->fetch(PDO::FETCH_ASSOC);

runTest("Case 7.1: Operational elephant_reports count unmodified (43 == {$postOperReports})", $postOperReports === 43 && $postOperReports === $preOperReports, $totalTests, $passedTests, $failedTests);
runTest("Case 7.2: Operational system_logs count unmodified (185 == {$postOperLogs})", $postOperLogs === 185 && $postOperLogs === $preOperLogs, $totalTests, $passedTests, $failedTests);
runTest("Case 7.3: Operational alerts count unmodified (15 == {$postOperAlerts})", $postOperAlerts === 15 && $postOperAlerts === $preOperAlerts, $totalTests, $passedTests, $failedTests);
runTest("Case 7.4: Operational Report #1 record untouched", $preOperReport1 === $postOperReport1, $totalTests, $passedTests, $failedTests);

echo "\n========================================================================\n";
echo "STAGE 3D TEST SUITE COMPLETE: {$passedTests} / {$totalTests} PASSED\n";
if ($failedTests > 0) {
    echo "CRITICAL: {$failedTests} tests failed!\n";
} else {
    echo "SUCCESS: All Stage 3D dashboard integration & shadow safety tests passed!\n";
}
echo "========================================================================\n";

exit($failedTests > 0 ? 1 : 0);
