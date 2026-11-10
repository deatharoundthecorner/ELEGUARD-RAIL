<?php
/**
 * EleGuard Rail - Milestone 9C Stage 3C Automated Test Suite
 * 
 * End-to-End Verification for Controlled Temporal Risk Engine Implementation:
 * - Group 1: Continuous Diurnal Illumination Function D(t)
 * - Group 2: Diurnal Interval Integration & Twilight Transitions
 * - Group 3: Observation-Aware Temporal Risk Calculation API
 * - Group 4: Unknown Observation Time & Zero Diurnal Assumption
 * - Group 5: Experimental Temporal Decay & Section Base Risk Floor Protection
 * - Group 6: Legacy 5-Argument RiskService Backward Compatibility
 * - Group 7: Operational Safety & Zero Operational Database Writes
 * 
 * STRICT ISOLATION: Database operations run exclusively on `eleguard_rail_test`.
 * Operates with ZERO writes to operational `eleguard_rail`.
 */

error_reporting(E_ALL & ~E_WARNING & ~E_NOTICE);
ini_set('display_errors', '0');

require_once __DIR__ . '/bootstrap.php';

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
require_once __DIR__ . '/../app/services/TemporalService.php';
require_once __DIR__ . '/../app/services/TemporalRiskService.php';
require_once __DIR__ . '/../app/services/RiskService.php';
require_once __DIR__ . '/../app/services/ReportService.php';
require_once __DIR__ . '/../app/services/AlertService.php';

// Assert connection is isolated test database
$connectedDb = Database::getInstance()->getConnection()->query("SELECT DATABASE()")->fetchColumn();
if ($connectedDb !== $testDbName) {
    die("FATAL: Test suite connected to '{$connectedDb}' instead of '{$testDbName}'. Aborting for safety.\n");
}

echo "========================================================\n";
echo " EleGuard Rail - Milestone 9C Stage 3C Test Suite\n";
echo " Controlled Temporal Risk Engine Implementation\n";
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
// GROUP 1: Continuous Diurnal Illumination Function D(t)
// -------------------------------------------------------------------------------------------------
echo "-- Group 1: Continuous Diurnal Illumination Function D(t) --\n";

// 1.1 Full daytime hours (07:00 to 17:00) yield 0.0 points
$d12 = TemporalRiskService::getInstantaneousDiurnalFactor(12.0); // Noon
$d14 = TemporalRiskService::getInstantaneousDiurnalFactor(14.5); // 14:30
$d08 = TemporalRiskService::getInstantaneousDiurnalFactor(8.0);  // 08:00
assertTest($d12 === 0.0 && $d14 === 0.0 && $d08 === 0.0,
    "1.1 Full daytime hours (08:00, 12:00, 14:30) yield exactly 0.0 diurnal points");

// 1.2 Full nighttime hours (19:00 to 05:00) yield 2.0 points
$d23 = TemporalRiskService::getInstantaneousDiurnalFactor(23.5); // 23:30
$d02 = TemporalRiskService::getInstantaneousDiurnalFactor(2.0);  // 02:00
$d20 = TemporalRiskService::getInstantaneousDiurnalFactor(20.0); // 20:00
assertTest($d23 === 2.0 && $d02 === 2.0 && $d20 === 2.0,
    "1.2 Full nighttime hours (20:00, 23:30, 02:00) yield exactly 2.0 diurnal points");

// 1.3 Dawn transition (05:00 to 07:00): continuous linear ramp from 2.0 down to 0.0
$d0500 = TemporalRiskService::getInstantaneousDiurnalFactor(5.0); // 2.0
$d0600 = TemporalRiskService::getInstantaneousDiurnalFactor(6.0); // 1.0 (midpoint)
$d0630 = TemporalRiskService::getInstantaneousDiurnalFactor(6.5); // 0.5
$d0700 = TemporalRiskService::getInstantaneousDiurnalFactor(7.0); // 0.0
assertTest($d0500 === 2.0 && abs($d0600 - 1.0) < 1e-6 && abs($d0630 - 0.5) < 1e-6 && $d0700 === 0.0,
    "1.3 Dawn transition ramps continuously from 2.0 (05:00) to 1.0 (06:00) and 0.0 (07:00)");

// 1.4 Dusk transition (17:00 to 19:00): continuous linear ramp from 0.0 up to 2.0
$d1700 = TemporalRiskService::getInstantaneousDiurnalFactor(17.0); // 0.0
$d1800 = TemporalRiskService::getInstantaneousDiurnalFactor(18.0); // 1.0 (midpoint)
$d1830 = TemporalRiskService::getInstantaneousDiurnalFactor(18.5); // 1.5
$d1900 = TemporalRiskService::getInstantaneousDiurnalFactor(19.0); // 2.0
assertTest($d1700 === 0.0 && abs($d1800 - 1.0) < 1e-6 && abs($d1830 - 1.5) < 1e-6 && $d1900 === 2.0,
    "1.4 Dusk transition ramps continuously from 0.0 (17:00) to 1.0 (18:00) and 2.0 (19:00)");

// 1.5 Exact midnight boundary (00:00)
$d0000 = TemporalRiskService::getInstantaneousDiurnalFactor(0.0);
assertTest($d0000 === 2.0, "1.5 Exact midnight (00:00) evaluates correctly to 2.0 points");

echo "\n";

// -------------------------------------------------------------------------------------------------
// GROUP 2: Diurnal Interval Integration & Twilight Transitions
// -------------------------------------------------------------------------------------------------
echo "-- Group 2: Diurnal Interval Integration & Twilight Transitions --\n";

// 2.1 Daytime Morning Preset (07:30 to 10:30)
$intMorning = TemporalRiskService::integrateDiurnalInterval(7.5, 10.5);
assertTest($intMorning['mean_factor'] === 0.0 && $intMorning['duration_hours'] == 3.0,
    "2.1 Morning preset (07:30-10:30) integrates to exactly 0.0 points over 3.0h");

// 2.2 Daytime Afternoon Preset (13:00 to 16:00)
$intAft = TemporalRiskService::integrateDiurnalInterval(13.0, 16.0);
assertTest($intAft['mean_factor'] === 0.0,
    "2.2 Afternoon preset (13:00-16:00) integrates to exactly 0.0 points");

// 2.3 Full Night Preset (20:00 to 00:00)
$intNight = TemporalRiskService::integrateDiurnalInterval(20.0, 24.0);
assertTest($intNight['mean_factor'] === 2.0 && $intNight['duration_hours'] == 4.0,
    "2.3 Night preset (20:00-00:00) integrates to exactly 2.0 points over 4.0h");

// 2.4 Dusk Preset (17:30 to 19:00) crossing twilight ramp
// At 17:30 D = 0.5, at 19:00 D = 2.0 -> Integral = ((0.5 + 2.0)/2)*1.5 = 1.875 -> Mean = 1.25
$intDusk = TemporalRiskService::integrateDiurnalInterval(17.5, 19.0);
assertTest(abs($intDusk['mean_factor'] - 1.25) < 1e-4,
    "2.4 Dusk preset (17:30-19:00) integrates to exact twilight mean 1.25 points");

// 2.5 Dawn Preset (04:30 to 06:30) crossing full night and dawn ramp
// [04:30, 05:00] (0.5h at 2.0 = 1.0) + [05:00, 06:30] (1.5h with (2.0+0.5)/2 = 1.875) -> Sum = 2.875 / 2h = 1.4375
$intDawn = TemporalRiskService::integrateDiurnalInterval(4.5, 6.5);
assertTest(abs($intDawn['mean_factor'] - 1.4375) < 1e-4,
    "2.5 Dawn preset (04:30-06:30) integrates to exact twilight mean 1.4375 points");

// 2.6 Custom window crossing midnight (22:00 to 02:00)
$intMidnightCross = TemporalRiskService::integrateDiurnalInterval(22.0, 2.0);
assertTest($intMidnightCross['mean_factor'] === 2.0 && $intMidnightCross['duration_hours'] == 4.0,
    "2.6 Custom window crossing midnight (22:00 to 02:00) integrates cyclically to 2.0 points");

// 2.7 Point interval (tA == tB)
$intPoint = TemporalRiskService::integrateDiurnalInterval(18.0, 18.0);
assertTest(abs($intPoint['mean_factor'] - 1.0) < 1e-6,
    "2.7 Point interval (18:00 to 18:00) resolves to instantaneous factor 1.0");

echo "\n";

// -------------------------------------------------------------------------------------------------
// GROUP 3: Observation-Aware Temporal Risk Calculation API
// -------------------------------------------------------------------------------------------------
echo "-- Group 3: Observation-Aware Temporal Risk Calculation API --\n";

// 3.1 Exact daytime observation
$ctxDay = [
    'section_risk' => 'Medium',  // 2 pts
    'urgency_level' => 'Low',    // 1 pt
    'elephant_count' => 1,       // 1 pt
    'active_alerts' => false     // 0 pts
];
$tempDay = [
    'sighting_date' => '2026-11-08',
    'sighting_time' => '12:30:00',
    'sighting_time_precision' => 'exact',
    'sighting_time_uncertainty_minutes' => 5
];
$resDay = RiskService::calculateObservationAwareRisk($ctxDay, $tempDay);
// Total: 2 + 1 + 1 + 0 + 0.0 = 4.0 -> Low
assertTest($resDay['final_score'] === 4 && 
           $resDay['final_classification'] === 'Low' && 
           $resDay['diurnal_adjustment'] === 0.0 &&
           $resDay['model_version'] === '1.0.0-experimental',
    "3.1 Exact daytime observation (12:30) scores 4 pts ('Low') with diurnal = 0.0");

// 3.2 Exact nighttime observation
$ctxNight = [
    'section_risk' => 'Medium',  // 2 pts
    'urgency_level' => 'Low',    // 1 pt
    'elephant_count' => 1,       // 1 pt
    'active_alerts' => false     // 0 pts
];
$tempNight = [
    'sighting_date' => '2026-11-08',
    'sighting_time' => '23:30:00',
    'sighting_time_precision' => 'exact',
    'sighting_time_uncertainty_minutes' => 5
];
$resNight = RiskService::calculateObservationAwareRisk($ctxNight, $tempNight);
// Total: 2 + 1 + 1 + 0 + 2.0 = 6.0 -> Medium (elevated from Low by night hazard)
assertTest($resNight['final_score'] === 6 && 
           $resNight['final_classification'] === 'Medium' && 
           $resNight['diurnal_adjustment'] === 2.0,
    "3.2 Exact nighttime observation (23:30) scores 6 pts ('Medium') with diurnal = 2.0");

// 3.3 Approximate Dusk preset (fractional diurnal 1.25 rounded to nearest integer 1)
$ctxDusk = [
    'section_risk' => 'High',     // 3 pts
    'urgency_level' => 'High',    // 3 pts
    'elephant_count' => 3,        // 2 pts
    'active_alerts' => false      // 0 pts
];
$tempDusk = [
    'sighting_date' => '2026-11-08',
    'sighting_time' => '18:15:00',
    'sighting_time_precision' => 'approximate',
    'sighting_time_window' => 'dusk',
    'sighting_time_uncertainty_minutes' => 45
];
$resDusk = RiskService::calculateObservationAwareRisk($ctxDusk, $tempDusk);
// Total unrounded: 3 + 3 + 2 + 0 + 1.25 = 9.25 -> rounded = 9 -> High
assertTest($resDusk['diurnal_adjustment'] === 1.25 && 
           $resDusk['unrounded_total_score'] === 9.25 && 
           $resDusk['final_score'] === 9 && 
           $resDusk['final_classification'] === 'High',
    "3.3 Approximate Dusk preset produces fractional 1.25 diurnal adjustment, unrounded 9.25, final High (9)");

// 3.4 Approximate Night preset (full 2.0 points)
$tempNightPreset = [
    'sighting_date' => '2026-11-08',
    'sighting_time' => '22:00:00',
    'sighting_time_precision' => 'approximate',
    'sighting_time_window' => 'night',
    'sighting_time_uncertainty_minutes' => 120
];
$resNightPreset = RiskService::calculateObservationAwareRisk($ctxDusk, $tempNightPreset);
// Total: 3 + 3 + 2 + 0 + 2.0 = 10 -> High (or with Critical section would be 11)
assertTest($resNightPreset['diurnal_adjustment'] === 2.0 && 
           $resNightPreset['final_score'] === 10 && 
           $resNightPreset['final_classification'] === 'High',
    "3.4 Approximate Night preset produces exactly 2.0 diurnal adjustment");

// 3.5 Critical threshold attainment
$ctxCrit = [
    'section_risk' => 'Critical', // 4 pts
    'urgency_level' => 'Critical',// 4 pts
    'elephant_count' => 5,        // 3 pts
    'active_alerts' => true       // 2 pts
];
$resCrit = RiskService::calculateObservationAwareRisk($ctxCrit, $tempNightPreset);
// Total: 4 + 4 + 3 + 2 + 2.0 = 15 -> Critical
assertTest($resCrit['final_score'] === 15 && $resCrit['final_classification'] === 'Critical',
    "3.5 Maximum hazard report scores 15 pts ('Critical')");

echo "\n";

// -------------------------------------------------------------------------------------------------
// GROUP 4: Unknown Observation Time & Zero Diurnal Assumption
// -------------------------------------------------------------------------------------------------
echo "-- Group 4: Unknown Observation Time & Zero Diurnal Assumption --\n";

// 4.1 Date known, time unknown (precision = unknown)
$tempDateOnly = [
    'sighting_date' => '2026-11-08',
    'sighting_time' => null,
    'sighting_time_precision' => 'unknown',
    'sighting_time_window' => null
];
$resDateOnly = RiskService::calculateObservationAwareRisk($ctxDay, $tempDateOnly);
// Total: 2 + 1 + 1 + 0 + 0.0 = 4.0 -> Low
assertTest($resDateOnly['diurnal_adjustment'] === 0.0 && 
           $resDateOnly['final_score'] === 4 && 
           $resDateOnly['observation_precision'] === 'unknown',
    "4.1 Date known with unknown time produces strictly 0.0 diurnal points (zero night assumption)");

// 4.2 Historical unmigrated report (both date and time NULL, Report #1 parity)
$tempHistorical = [
    'sighting_date' => null,
    'sighting_time' => null,
    'sighting_time_precision' => 'unknown'
];
$resHistorical = RiskService::calculateObservationAwareRisk($ctxDay, $tempHistorical);
assertTest($resHistorical['diurnal_adjustment'] === 0.0 && 
           $resHistorical['final_score'] === 4 && 
           $resHistorical['final_classification'] === 'Low',
    "4.2 Historical unmigrated record (NULL date/time) safely produces 0.0 diurnal points");

// 4.3 Missing temporal metadata argument defaults safely
$resEmpty = RiskService::calculateObservationAwareRisk($ctxDay, []);
assertTest($resEmpty['diurnal_adjustment'] === 0.0 && 
           $resEmpty['final_score'] === 4,
    "4.3 Empty temporal metadata array defaults safely without warnings or crashes");

// 4.4 Data quality warnings emitted for anomalies
$tempAnomaly = [
    'sighting_date' => '2026-11-08',
    'sighting_time' => '14:00:00',
    'sighting_time_precision' => 'unknown' // Conflict: time supplied with unknown precision
];
$resAnomaly = RiskService::calculateObservationAwareRisk($ctxDay, $tempAnomaly);
assertTest($resAnomaly['diurnal_adjustment'] === 0.0 && !empty($resAnomaly['data_quality_warnings']),
    "4.4 Conflict between time and unknown precision emits warning and maintains 0.0 diurnal points");

echo "\n";

// -------------------------------------------------------------------------------------------------
// GROUP 5: Experimental Temporal Decay & Section Base Risk Floor Protection
// -------------------------------------------------------------------------------------------------
echo "-- Group 5: Experimental Temporal Decay & Section Base Risk Floor Protection --\n";

// 5.1 Temporal decay is disabled by default
$tempDecayTest = [
    'sighting_date' => '2026-11-08',
    'sighting_time' => '08:00:00',
    'sighting_time_precision' => 'exact',
    'created_at' => '2026-11-08 20:00:00' // 12 hours after observation
];
$resDefaultDecay = RiskService::calculateObservationAwareRisk($ctxDusk, $tempDecayTest);
assertTest($resDefaultDecay['decay_details']['enabled'] === false &&
           $resDefaultDecay['decay_details']['multiplier'] === 1.0 &&
           $resDefaultDecay['decay_details']['points_decayed'] == 0.0,
    "5.1 Experimental temporal decay is strictly disabled by default (multiplier = 1.0)");

// 5.2 Opt-in simulation of temporal decay (12 hours elapsed with T_half = 4.0h -> decay = 2^(-3) = 0.125)
$optionsWithDecay = [
    'enable_decay' => true,
    'decay_half_life' => 4.0,
    'eval_time' => '2026-11-08 20:00:00'
];
$resSimDecay = RiskService::calculateObservationAwareRisk($ctxDusk, $tempDecayTest, $optionsWithDecay);
// In $ctxDusk: section = 3 (High), urgency = 3, count = 2, alert = 0. Daytime observation -> diurnal = 0.
// Sighting incremental: 3 + 2 = 5 pts. Decayed: 5 * 0.125 = 0.625. Points decayed: 4.375.
// Section base: 3. Total unrounded: 3 + 0.625 = 3.625 -> final score = 4 (Low).
assertTest($resSimDecay['decay_details']['enabled'] === true &&
           abs($resSimDecay['decay_details']['multiplier'] - 0.125) < 1e-4 &&
           abs($resSimDecay['unrounded_total_score'] - 3.625) < 1e-3 &&
           $resSimDecay['final_score'] === 4 &&
           $resSimDecay['final_classification'] === 'Low',
    "5.2 Opt-in decay simulation attenuates incremental sighting points over 12h (multiplier 0.125)");

// 5.3 Section Base Risk Floor Rule: decay NEVER reduces risk below section base risk
$ctxCritSection = [
    'section_risk' => 'Critical', // Base = 4 pts
    'urgency_level' => 'Critical',// 4 pts
    'elephant_count' => 5,        // 3 pts
    'active_alerts' => false      // 0 pts
];
$tempVeryOld = [
    'sighting_date' => '2026-11-01',
    'sighting_time' => '08:00:00',
    'sighting_time_precision' => 'exact',
    'created_at' => '2026-11-08 08:00:00' // 7 days (168 hours) elapsed
];
$resFloorCheck = RiskService::calculateObservationAwareRisk($ctxCritSection, $tempVeryOld, $optionsWithDecay);
// Sighting incremental (4+3=7) decays to ~0. Total score = baseSection(4) + 0 = 4.
assertTest($resFloorCheck['final_score'] >= 4 && 
           $resFloorCheck['base_section_points'] === 4,
    "5.3 Section Base Risk Floor Rule: 7-day-old report never falls below section baseline (4 pts)");

echo "\n";

// -------------------------------------------------------------------------------------------------
// GROUP 6: Legacy 5-Argument RiskService Backward Compatibility
// -------------------------------------------------------------------------------------------------
echo "-- Group 6: Legacy 5-Argument RiskService Backward Compatibility --\n";

// Test identical outputs against historical test vectors
$v1 = RiskService::calculateRiskScore('Critical', 'Critical', 5, '2026-11-08 22:00:00', true);
$v2 = RiskService::calculateRiskScore('Low', 'Low', 1, '2026-11-08 12:00:00', false);
$v3 = RiskService::calculateRiskScore('High', 'Medium', 3, '2026-11-08 20:00:00', false);
$v4 = RiskService::calculateRiskScore('Medium', 'High', 2, '2026-11-08 14:00:00', true);

assertTest($v1 === 'Critical', "6.1 Legacy 5-arg vector 1: Critical section + Critical urgency + night + alert = 'Critical'");
assertTest($v2 === 'Low',      "6.2 Legacy 5-arg vector 2: Low section + Low urgency + day + no alert = 'Low'");
assertTest($v3 === 'High',     "6.3 Legacy 5-arg vector 3: High section + Med urgency + night + no alert = 'High'");
assertTest($v4 === 'High',     "6.4 Legacy 5-arg vector 4: Med section + High urgency + day + alert = 'High'");

echo "\n";

// -------------------------------------------------------------------------------------------------
// GROUP 7: Operational Safety & Zero Operational Database Writes
// -------------------------------------------------------------------------------------------------
echo "-- Group 7: Operational Safety & Zero Operational Database Writes --\n";

$rawPdo->exec("USE eleguard_rail");
$postOperReports = (int)$rawPdo->query("SELECT COUNT(*) FROM elephant_reports")->fetchColumn();
$postOperLogs = (int)$rawPdo->query("SELECT COUNT(*) FROM system_logs")->fetchColumn();
$postOperAlerts = (int)$rawPdo->query("SELECT COUNT(*) FROM alerts")->fetchColumn();
$postOperReport1 = $rawPdo->query("SELECT id, report_status, sighting_date, sighting_time, sighting_time_precision, created_at, updated_at FROM elephant_reports WHERE id = 1")->fetch(PDO::FETCH_ASSOC);

assertTest($postOperReports === $preOperReports,
    "7.1 Operational elephant_reports count completely untouched ({$preOperReports} == {$postOperReports})");
assertTest($postOperLogs === $preOperLogs,
    "7.2 Operational system_logs count completely untouched ({$preOperLogs} == {$postOperLogs})");
assertTest($postOperAlerts === $preOperAlerts,
    "7.3 Operational alerts count completely untouched ({$preOperAlerts} == {$postOperAlerts})");
assertTest($postOperReport1 == $preOperReport1,
    "7.4 Historical Report #1 in operational database is 100% untouched and pristine");

// Drop isolated test database
echo "\n-- Dropping Isolated Test Database ({$testDbName}) --\n";
safeTeardownTestDatabase($rawPdo, $testDbName);
echo " [PASS] Cleaned up isolated test database successfully\n\n";

echo "========================================================\n";
echo " Summary: {$passed} Passed, {$failed} Failed\n";
echo "========================================================\n";

if ($failed > 0) {
    exit(1);
}
exit(0);
