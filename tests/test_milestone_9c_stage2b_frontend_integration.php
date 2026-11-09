<?php
/**
 * EleGuard Rail - Milestone 9C Stage 2B Automated Test Suite
 * 
 * End-to-End Integration & Verification for Frontend Temporal Reporting:
 * - Group 1: Display Formatting Helpers in TemporalService
 * - Group 2: Reporter Form Submission Workflow & Temporal Normalization
 * - Group 3: Wildlife Officer Form Submission & Auto-Elevation Workflow
 * - Group 4: Edge Cases & Input Validation Error Rejections
 * - Group 5: Wildlife Officer Review Display & Historical Record Segregation
 * - Group 6: Operational Database Zero-Write Invariant
 * 
 * STRICT ISOLATION: Database operations run exclusively on `eleguard_rail_test`.
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

// Record baseline operational counts before any test activity
$rawPdo->exec("USE eleguard_rail");
$preOperReports = (int)$rawPdo->query("SELECT COUNT(*) FROM elephant_reports")->fetchColumn();
$preOperLogs = (int)$rawPdo->query("SELECT COUNT(*) FROM system_logs")->fetchColumn();

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
require_once __DIR__ . '/../app/core/Auth.php';
require_once __DIR__ . '/../app/includes/functions.php';
require_once __DIR__ . '/../app/services/TemporalService.php';
require_once __DIR__ . '/../app/services/ReportService.php';
require_once __DIR__ . '/../app/services/AlertService.php';

// Assert connection is isolated test database
$connectedDb = Database::getInstance()->getConnection()->query("SELECT DATABASE()")->fetchColumn();
if ($connectedDb !== $testDbName) {
    die("FATAL: Test suite connected to '{$connectedDb}' instead of '{$testDbName}'. Aborting for safety.\n");
}

echo "========================================================\n";
echo " EleGuard Rail - Milestone 9C Stage 2B Test Suite\n";
echo " Frontend Temporal Reporting & Review Display Integration\n";
echo " Connected DB: {$connectedDb}\n";
echo "========================================================\n\n";

$passed = 0;
$failed = 0;

function assertTest($condition, $description) {
    global $passed, $failed;
    if ($condition) {
        echo " [PASS] {$description}\n";
        $passed++;
    } else {
        echo " [FAIL] {$description}\n";
        $failed++;
    }
}

// -------------------------------------------------------------------------------------------------
// GROUP 1: Display Formatting Helpers in TemporalService
// -------------------------------------------------------------------------------------------------
echo "-- Group 1: Display Formatting Helpers in TemporalService --\n";

// 1.1 Exact observation
$exactRep = [
    'sighting_date' => '2026-11-08',
    'sighting_time' => '14:30:00',
    'sighting_time_precision' => 'exact',
    'sighting_time_window' => null,
    'sighting_time_uncertainty_minutes' => 5
];
$sum1 = TemporalService::formatSightingSummary($exactRep);
assertTest(strpos($sum1, '8 Nov 2026 at 14:30') !== false && strpos($sum1, 'Exact') !== false,
    "1.1 Exact observation formatted: '{$sum1}'");

// 1.2 Approximate preset (Dusk)
$duskRep = [
    'sighting_date' => '2026-11-08',
    'sighting_time' => '18:15:00',
    'sighting_time_precision' => 'approximate',
    'sighting_time_window' => 'dusk',
    'sighting_time_uncertainty_minutes' => 45
];
$sum2 = TemporalService::formatSightingSummary($duskRep);
assertTest(strpos($sum2, '8 Nov 2026, approx. Dusk') !== false && strpos($sum2, '18:15') !== false,
    "1.2 Approximate Dusk preset formatted: '{$sum2}'");

// 1.3 Approximate custom window
$customRep = [
    'sighting_date' => '2026-11-08',
    'sighting_time' => '15:30:00',
    'sighting_time_precision' => 'approximate',
    'sighting_time_window' => 'custom',
    'sighting_time_uncertainty_minutes' => 45
];
$sum3 = TemporalService::formatSightingSummary($customRep);
assertTest(strpos($sum3, '8 Nov 2026, approx. 15:30') !== false && strpos($sum3, '45m') !== false,
    "1.3 Approximate custom window formatted: '{$sum3}'");

// 1.4 Date known, time unknown
$dateOnlyRep = [
    'sighting_date' => '2026-11-08',
    'sighting_time' => null,
    'sighting_time_precision' => 'unknown',
    'sighting_time_window' => null,
    'sighting_time_uncertainty_minutes' => null
];
$sum4 = TemporalService::formatSightingSummary($dateOnlyRep);
assertTest($sum4 === '8 Nov 2026 (Time unrecorded)',
    "1.4 Date known, time unknown formatted: '{$sum4}'");

// 1.5 Date and time unknown (historical record)
$histRep = [
    'sighting_date' => null,
    'sighting_time' => null,
    'sighting_time_precision' => 'unknown',
    'sighting_time_window' => null,
    'sighting_time_uncertainty_minutes' => null
];
$sum5 = TemporalService::formatSightingSummary($histRep);
assertTest($sum5 === 'Date and time unrecorded (Historical / Unknown)',
    "1.5 Date and time unrecorded formatted: '{$sum5}'");

// 1.6 Operational summary formatting
$opRep = [
    'created_at' => '2026-11-08 15:00:00',
    'gps_captured_at' => '2026-11-08 14:59:45',
    'latitude' => 8.035412,
    'longitude' => 80.751104,
    'ai_status' => 'processed',
    'ai_processed_at' => '2026-11-08 15:00:10'
];
$opSummary = TemporalService::formatOperationalSummary($opRep);
assertTest(strpos($opSummary['filed_at'], '8 Nov 2026') !== false &&
           strpos($opSummary['gps_captured_at'], 'Hardware Sensor') !== false &&
           strpos($opSummary['ai_processed_at'], 'Processed') !== false,
    "1.6 Operational summary formats hardware GPS, ingestion time, and AI status");

// 1.7 Precision badges
$bExact = TemporalService::renderPrecisionBadge('exact', null, 5);
$bApprox = TemporalService::renderPrecisionBadge('approximate', 'dawn', 60);
$bUnknown = TemporalService::renderPrecisionBadge('unknown');
assertTest(strpos($bExact, 'badge-success') !== false &&
           strpos($bApprox, 'badge-warning') !== false &&
           strpos($bUnknown, 'Time Unknown') !== false,
    "1.7 Precision badges render appropriate CSS classes and titles");

// 1.8 Reporting delay calculation
$delRealtime = TemporalService::calculateReportingDelay([
    'sighting_date' => '2026-11-08',
    'sighting_time' => '14:50:00',
    'created_at' => '2026-11-08 14:58:00'
]);
$delDelayed = TemporalService::calculateReportingDelay([
    'sighting_date' => '2026-11-08',
    'sighting_time' => '08:00:00',
    'created_at' => '2026-11-08 14:30:00'
]);
assertTest($delRealtime['is_realtime'] === true &&
           $delDelayed['is_delayed'] === true &&
           strpos($delDelayed['label'], '6h 30m') !== false,
    "1.8 Reporting delay calculates real-time (<15m) and delayed hours correctly");

echo "\n";

// -------------------------------------------------------------------------------------------------
// GROUP 2: Reporter Form Submission Workflow & Temporal Normalization
// -------------------------------------------------------------------------------------------------
echo "-- Group 2: Reporter Form Submission Workflow & Temporal Normalization --\n";

// Get test user (reporter ID 2) and section (ID 1)
$reporterId = 2;
$sectionId = 1;

// 2.1 Exact observation with browser geolocation GPS epoch ms
$postPayloadExact = [
    'sighting_date' => '2026-11-08',
    'sighting_time' => '11:15',
    'sighting_time_precision' => 'exact',
    'gps_timestamp' => '1794117300000', // Epoch ms -> 2026-11-08 11:15:00 UTC -> 16:45:00 Colombo
    'sighting_time_window' => null,
    'custom_time' => null,
    'custom_uncertainty' => null
];
$normExact = TemporalService::validateAndNormalizeTemporalInput($postPayloadExact);
assertTest($normExact['valid'] === true, "2.1 Exact form payload successfully normalized");

$subExact = ReportService::submitReport(
    $sectionId, $reporterId, 2, 'Near curve test 2.1', 'Medium',
    'Test observation exact', null, '8.035412', '80.751104', '15', $normExact['data']
);
$exactRepId = (int)Database::getInstance()->getConnection()->lastInsertId();
$fetchedExact = ReportService::getById($exactRepId);
assertTest($fetchedExact['sighting_date'] === '2026-11-08' &&
           $fetchedExact['sighting_time'] === '11:15:00' &&
           $fetchedExact['sighting_time_precision'] === 'exact' &&
           $fetchedExact['sighting_time_uncertainty_minutes'] == 5 &&
           !empty($fetchedExact['gps_captured_at']),
    "2.1 Exact observation stored with 5m uncertainty and hardware GPS timestamp");

// 2.2 Approximate preset observation (Morning)
$postPayloadPreset = [
    'sighting_date' => '2026-11-08',
    'sighting_time_precision' => 'approximate',
    'sighting_time_window' => 'morning',
    'gps_timestamp' => null
];
$normPreset = TemporalService::validateAndNormalizeTemporalInput($postPayloadPreset);
assertTest($normPreset['valid'] === true, "2.2 Approximate Morning preset normalized");

$subPreset = ReportService::submitReport(
    $sectionId, $reporterId, 3, 'Near forest boundary 2.2', 'Low',
    'Morning preset test', null, null, null, null, $normPreset['data']
);
$presetRepId = (int)Database::getInstance()->getConnection()->lastInsertId();
$fetchedPreset = ReportService::getById($presetRepId);
assertTest($fetchedPreset['sighting_date'] === '2026-11-08' &&
           $fetchedPreset['sighting_time'] === '09:00:00' &&
           $fetchedPreset['sighting_time_precision'] === 'approximate' &&
           $fetchedPreset['sighting_time_window'] === 'morning' &&
           $fetchedPreset['sighting_time_uncertainty_minutes'] == 90 &&
           $fetchedPreset['gps_captured_at'] === null,
    "2.2 Morning preset stored: time='09:00:00', window='morning', uncertainty=90m");

// 2.3 Approximate custom observation (14:00 ±60m)
$postPayloadCustom = [
    'sighting_date' => '2026-11-08',
    'sighting_time_precision' => 'approximate',
    'sighting_time_window' => 'custom',
    'sighting_time' => '14:00',
    'sighting_time_uncertainty_minutes' => 60
];
$normCustom = TemporalService::validateAndNormalizeTemporalInput($postPayloadCustom);
assertTest($normCustom['valid'] === true, "2.3 Approximate custom window normalized");

$subCustom = ReportService::submitReport(
    $sectionId, $reporterId, 1, 'Near bridge 2.3', 'High',
    'Custom window test', null, null, null, null, $normCustom['data']
);
$customRepId = (int)Database::getInstance()->getConnection()->lastInsertId();
$fetchedCustom = ReportService::getById($customRepId);
assertTest($fetchedCustom['sighting_date'] === '2026-11-08' &&
           $fetchedCustom['sighting_time'] === '14:00:00' &&
           $fetchedCustom['sighting_time_precision'] === 'approximate' &&
           $fetchedCustom['sighting_time_window'] === 'custom' &&
           $fetchedCustom['sighting_time_uncertainty_minutes'] == 60,
    "2.3 Custom window stored: time='14:00:00', window='custom', uncertainty=60m");

// 2.4 Date known, time unknown (Zero midnight fabrication)
$postPayloadDateKnown = [
    'sighting_date' => '2026-11-08',
    'sighting_time_precision' => 'unknown',
    'sighting_time' => null,
    'sighting_time_window' => null
];
$normDateKnown = TemporalService::validateAndNormalizeTemporalInput($postPayloadDateKnown);
$subDateKnown = ReportService::submitReport(
    $sectionId, $reporterId, 4, 'Village edge 2.4', 'Medium',
    'Date known time unknown', null, null, null, null, $normDateKnown['data']
);
$dateKnownId = (int)Database::getInstance()->getConnection()->lastInsertId();
$fetchedDateKnown = ReportService::getById($dateKnownId);
assertTest($fetchedDateKnown['sighting_date'] === '2026-11-08' &&
           $fetchedDateKnown['sighting_time'] === null &&
           $fetchedDateKnown['sighting_time_precision'] === 'unknown' &&
           $fetchedDateKnown['sighting_time_window'] === null,
    "2.4 Date known, time unknown: sighting_time is strictly NULL (no midnight coercion)");

// 2.5 Date unknown (sighting_date_unknown = 1)
$postPayloadAllUnknown = [
    'sighting_date' => null,
    'sighting_time_precision' => 'unknown',
    'sighting_time' => null,
    'sighting_time_window' => null
];
$normAllUnknown = TemporalService::validateAndNormalizeTemporalInput($postPayloadAllUnknown);
$subAllUnknown = ReportService::submitReport(
    $sectionId, $reporterId, 2, 'Uncertain time 2.5', 'Low',
    'Both date and time unknown', null, null, null, null, $normAllUnknown['data']
);
$allUnknownId = (int)Database::getInstance()->getConnection()->lastInsertId();
$fetchedAllUnknown = ReportService::getById($allUnknownId);
assertTest($fetchedAllUnknown['sighting_date'] === null &&
           $fetchedAllUnknown['sighting_time'] === null &&
           $fetchedAllUnknown['sighting_time_precision'] === 'unknown',
    "2.5 Date unknown: both sighting_date and sighting_time are strictly NULL");

// 2.6 Geolocation cleared / manual coordinates entered
$postPayloadManual = [
    'sighting_date' => '2026-11-08',
    'sighting_time' => '10:00',
    'sighting_time_precision' => 'exact',
    'gps_timestamp' => null // cleared
];
$normManual = TemporalService::validateAndNormalizeTemporalInput($postPayloadManual);
$subManual = ReportService::submitReport(
    $sectionId, $reporterId, 1, 'Manual GPS coords 2.6', 'Low',
    'Coordinates typed manually', null, '8.050000', '80.800000', null, $normManual['data']
);
$manualId = (int)Database::getInstance()->getConnection()->lastInsertId();
$fetchedManual = ReportService::getById($manualId);
assertTest($fetchedManual['latitude'] == '8.050000' &&
           $fetchedManual['longitude'] == '80.800000' &&
           $fetchedManual['gps_captured_at'] === null,
    "2.6 Manual coordinates preserved with gps_captured_at = NULL");

echo "\n";

// -------------------------------------------------------------------------------------------------
// GROUP 3: Wildlife Officer Form Submission & Auto-Elevation Workflow
// -------------------------------------------------------------------------------------------------
echo "-- Group 3: Wildlife Officer Form Submission & Auto-Elevation Workflow --\n";

// Wildlife Officer user ID 4 (or another wildlife_officer)
$wildlifeOfficerId = 4;

$rangerPayload = [
    'sighting_date' => '2026-11-08',
    'sighting_time' => '17:45',
    'sighting_time_precision' => 'exact',
    'gps_timestamp' => null
];
$normRanger = TemporalService::validateAndNormalizeTemporalInput($rangerPayload);

// Simulate wildlife officer form submission
$subRanger = ReportService::submitReport(
    $sectionId, $wildlifeOfficerId, 5, 'Ranger patrol sector B', 'Critical',
    'Patrol confirmed 5 elephants on track', null, '8.082000', '80.820000', '10', $normRanger['data']
);
$rangerRepId = (int)Database::getInstance()->getConnection()->lastInsertId();
assertTest($rangerRepId > 0, "3.1 Official ranger report successfully submitted");

// Immediately elevated to Verified
$elevated = ReportService::updateStatus($rangerRepId, 'Verified', $wildlifeOfficerId);
assertTest($elevated === true, "3.2 Report immediately elevated to 'Verified' by wildlife officer");

$fetchedRanger = ReportService::getById($rangerRepId);
assertTest($fetchedRanger['report_status'] === 'Verified',
    "3.3 Fetched report confirms status is 'Verified'");

assertTest($fetchedRanger['sighting_date'] === '2026-11-08' &&
           $fetchedRanger['sighting_time'] === '17:45:00' &&
           $fetchedRanger['sighting_time_precision'] === 'exact' &&
           $fetchedRanger['sighting_time_uncertainty_minutes'] == 5,
    "3.4 Ranger report retains exact temporal telemetry after verification update");

echo "\n";

// -------------------------------------------------------------------------------------------------
// GROUP 4: Edge Cases & Input Validation Error Rejections
// -------------------------------------------------------------------------------------------------
echo "-- Group 4: Edge Cases & Input Validation Error Rejections --\n";

// 4.1 Future date rejected
$badFutureDate = [
    'sighting_date' => '2030-01-01',
    'sighting_time' => '10:00',
    'sighting_time_precision' => 'exact'
];
$resFuture = TemporalService::validateAndNormalizeTemporalInput($badFutureDate);
assertTest($resFuture['valid'] === false && isset($resFuture['errors']['sighting_date']),
    "4.1 Future date rejected with clean field error");

// 4.2 Future time today rejected
$colomboNow = TemporalService::getNow();
$futureHour = (int)$colomboNow->format('H') + 2;
if ($futureHour < 24) {
    $futureTimeStr = sprintf('%02d:00', $futureHour);
    $badFutureTime = [
        'sighting_date' => $colomboNow->format('Y-m-d'),
        'sighting_time' => $futureTimeStr,
        'sighting_time_precision' => 'exact'
    ];
    $resTime = TemporalService::validateAndNormalizeTemporalInput($badFutureTime);
    assertTest($resTime['valid'] === false && isset($resTime['errors']['sighting_time']),
        "4.2 Future time today ({$futureTimeStr}) rejected with clean error");
} else {
    // If running near midnight, test past date allows that time
    assertTest(true, "4.2 Skipping late-night edge check (hour >= 24)");
}

// 4.3 Exact precision without time rejected
$badExactNoTime = [
    'sighting_date' => '2026-11-08',
    'sighting_time' => '',
    'sighting_time_precision' => 'exact'
];
$resNoTime = TemporalService::validateAndNormalizeTemporalInput($badExactNoTime);
assertTest($resNoTime['valid'] === false && isset($resNoTime['errors']['sighting_time']),
    "4.3 Exact precision without sighting_time rejected");

// 4.4 Approximate custom without uncertainty rejected
$badCustomNoUnc = [
    'sighting_date' => '2026-11-08',
    'sighting_time' => '15:00',
    'sighting_time_precision' => 'approximate',
    'sighting_time_window' => 'custom',
    'sighting_time_uncertainty_minutes' => null
];
$resNoUnc = TemporalService::validateAndNormalizeTemporalInput($badCustomNoUnc);
assertTest($resNoUnc['valid'] === false && isset($resNoUnc['errors']['sighting_time_uncertainty_minutes']),
    "4.4 Approximate custom without uncertainty rejected");

// 4.5 Conflict: exact precision with window specified rejected
$badExactConflict = [
    'sighting_date' => '2026-11-08',
    'sighting_time' => '05:30',
    'sighting_time_precision' => 'exact',
    'sighting_time_window' => 'dawn'
];
$resConflict = TemporalService::validateAndNormalizeTemporalInput($badExactConflict);
assertTest($resConflict['valid'] === false && isset($resConflict['errors']['sighting_time_window']),
    "4.5 Conflict: exact precision with window 'dawn' rejected");

// 4.6 Count reports before & after failed submissions to verify zero writes
$countBefore = (int)Database::getInstance()->getConnection()->query("SELECT COUNT(*) FROM elephant_reports")->fetchColumn();
try {
    ReportService::submitReport(
        $sectionId, $reporterId, 1, 'Location', 'Low', '', null, null, null, null,
        $badFutureDate
    );
} catch (Exception $e) {
    // Expected exception from ReportService pre-validation
}
$countAfter = (int)Database::getInstance()->getConnection()->query("SELECT COUNT(*) FROM elephant_reports")->fetchColumn();
assertTest($countBefore === $countAfter,
    "4.6 Rejected submissions produce zero database writes ({$countBefore} == {$countAfter})");

echo "\n";

// -------------------------------------------------------------------------------------------------
// GROUP 5: Wildlife Officer Review Display & Historical Record Segregation
// -------------------------------------------------------------------------------------------------
echo "-- Group 5: Wildlife Officer Review Display & Historical Record Segregation --\n";

// 5.1 Review panel format matches stored exact data
$repCheckExact = ReportService::getById($exactRepId);
$panelExactSummary = TemporalService::formatSightingSummary($repCheckExact);
$panelExactBadge = TemporalService::renderPrecisionBadge($repCheckExact['sighting_time_precision'], $repCheckExact['sighting_time_window'], $repCheckExact['sighting_time_uncertainty_minutes']);
assertTest(strpos($panelExactSummary, '8 Nov 2026 at 11:15') !== false &&
           strpos($panelExactBadge, 'Exact') !== false &&
           strpos($panelExactBadge, '5m') !== false,
    "5.1 Review panel formats exact sighting with date, clock time, and (±5m) badge");

// 5.2 Review panel segregates observation from operational telemetry
$opSummaryExact = TemporalService::formatOperationalSummary($repCheckExact);
assertTest(!empty($opSummaryExact['filed_at']) &&
           strpos($opSummaryExact['gps_captured_at'], 'Hardware Sensor') !== false,
    "5.2 Operational telemetry clearly separates Date Filed and Hardware GPS acquisition");

// 5.3 Historical unmigrated reports display as unrecorded without crashing
$historicalReport1 = ReportService::getById(1);
$histSummary = TemporalService::formatSightingSummary($historicalReport1);
$histBadge = TemporalService::renderPrecisionBadge($historicalReport1['sighting_time_precision']);
assertTest($histSummary === 'Date and time unrecorded (Historical / Unknown)' &&
           strpos($histBadge, 'Time Unknown') !== false,
    "5.3 Historical Report #1 formats safely as 'Date and time unrecorded' (no midnight coercion)");

// 5.4 Reports list table displays observation time and date filed separately
$allReports = ReportService::getAll();
$foundNew = false;
foreach ($allReports as $r) {
    if ($r['id'] == $exactRepId) {
        $foundNew = true;
        $obsFormatted = TemporalService::formatSightingSummary($r);
        $filedFormatted = format_date($r['created_at']);
        assertTest(!empty($obsFormatted) && !empty($filedFormatted) && $obsFormatted !== $filedFormatted,
            "5.4 Table row segregates Observation Time ('{$obsFormatted}') from Date Filed ('{$filedFormatted}')");
        break;
    }
}
assertTest($foundNew === true, "5.4 Verified newly submitted report found in reports list");

echo "\n";

// -------------------------------------------------------------------------------------------------
// GROUP 6: Operational Database Zero-Write Invariant
// -------------------------------------------------------------------------------------------------
echo "-- Group 6: Operational Database Zero-Write Invariant --\n";

$rawPdo->exec("USE eleguard_rail");
$postOperReports = (int)$rawPdo->query("SELECT COUNT(*) FROM elephant_reports")->fetchColumn();
$postOperLogs = (int)$rawPdo->query("SELECT COUNT(*) FROM system_logs")->fetchColumn();

assertTest($postOperReports === $preOperReports,
    "6.1 Operational elephant_reports count untouched ({$preOperReports} == {$postOperReports})");
assertTest($postOperLogs === $preOperLogs,
    "6.2 Operational system_logs count untouched ({$preOperLogs} == {$postOperLogs})");

// Cleanup test database
echo "\n-- Dropping Isolated Test Database ({$testDbName}) --\n";
$rawPdo->exec("DROP DATABASE IF EXISTS `{$testDbName}`");
echo " [PASS] Cleaned up isolated test database successfully\n\n";

echo "========================================================\n";
echo " Summary: {$passed} Passed, {$failed} Failed\n";
echo "========================================================\n";

if ($failed > 0) {
    exit(1);
}
exit(0);
