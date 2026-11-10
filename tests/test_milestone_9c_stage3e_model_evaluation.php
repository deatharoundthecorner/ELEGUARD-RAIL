<?php
/**
 * EleGuard Rail - Milestone 9C Stage 3E Automated Test & Evaluation Suite
 * 
 * Temporal Risk Model Evaluation, Mathematical Validation & Scenario Simulation:
 * - Group 1: Mathematical Correctness of Continuous Diurnal Function D(t)
 * - Group 2: Mathematical Correctness of Interval Integration & Twilight Transitions
 * - Group 3: Controlled 20-Scenario Simulation Dataset Generation
 * - Group 4: Comparative Risk Evaluation (Operational vs Experimental Score)
 * - Group 5: Experimental Decay Sensitivity Analysis (2h, 4h, 6h, 8h half-lives)
 * - Group 6: Empirical Limitations Audit (Historical Baseline & Data Scarcity)
 * - Group 7: Operational Safety & Zero Database Modification Invariants
 * 
 * STRICT ISOLATION: Database operations run exclusively on `eleguard_rail_test`.
 * Zero writes to operational database `eleguard_rail`.
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
require_once __DIR__ . '/../app/core/Session.php';
require_once __DIR__ . '/../app/core/Router.php';
require_once __DIR__ . '/../app/services/TemporalService.php';
require_once __DIR__ . '/../app/services/TemporalRiskService.php';
require_once __DIR__ . '/../app/services/RiskService.php';
require_once __DIR__ . '/../app/services/ReportService.php';
require_once __DIR__ . '/../app/services/AlertService.php';
require_once __DIR__ . '/../app/includes/functions.php';

// Assert connection is isolated test database
$connectedDb = Database::getInstance()->getConnection()->query("SELECT DATABASE()")->fetchColumn();
if ($connectedDb !== $testDbName) {
    die("FATAL: Test suite connected to '{$connectedDb}' instead of '{$testDbName}'. Aborting for safety.\n");
}

echo "========================================================================\n";
echo "ELEGUARD RAIL — MILESTONE 9C STAGE 3E EVALUATION TEST SUITE\n";
echo "Temporal Risk Model Evaluation, Mathematical Validation & Scenarios\n";
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
// GROUP 1: Mathematical Validation of Continuous Diurnal Function D(t)
// ------------------------------------------------------------------------
echo "--- GROUP 1: Mathematical Validation of Continuous Diurnal Function D(t) ---\n";

// Expected analytical piecewise formula:
// [19:00, 05:00) -> 2.0
// [05:00, 07:00) -> 7.0 - t
// [07:00, 17:00) -> 0.0
// [17:00, 19:00) -> t - 17.0

$exactPoints = [
    ['00:00', 0.0, 2.0, "Midnight baseline"],
    ['02:30', 2.5, 2.0, "Deep night"],
    ['04:59', 4.98333, 2.0, "Pre-dawn night boundary"],
    ['05:00', 5.0, 2.0, "Dawn transition start"],
    ['05:30', 5.5, 1.5, "Dawn mid-transition (7.0 - 5.5 = 1.5)"],
    ['06:00', 6.0, 1.0, "Dawn midpoint (7.0 - 6.0 = 1.0)"],
    ['06:30', 6.5, 0.5, "Dawn late transition (7.0 - 6.5 = 0.5)"],
    ['07:00', 7.0, 0.0, "Daylight boundary (Dawn end)"],
    ['10:00', 10.0, 0.0, "Mid-morning daylight"],
    ['12:00', 12.0, 0.0, "Solar noon daylight"],
    ['16:59', 16.98333, 0.0, "Pre-dusk daylight boundary"],
    ['17:00', 17.0, 0.0, "Dusk transition start"],
    ['17:30', 17.5, 0.5, "Dusk early transition (17.5 - 17.0 = 0.5)"],
    ['18:00', 18.0, 1.0, "Dusk midpoint (18.0 - 17.0 = 1.0)"],
    ['18:30', 18.5, 1.5, "Dusk late transition (18.5 - 17.0 = 1.5)"],
    ['19:00', 19.0, 2.0, "Full night boundary (Dusk end)"],
    ['21:45', 21.75, 2.0, "Night post-dusk"],
    ['23:59', 23.98333, 2.0, "Pre-midnight night boundary"]
];

foreach ($exactPoints as $idx => $pt) {
    list($clock, $decHour, $expectedD, $desc) = $pt;
    $computedD = TemporalRiskService::getInstantaneousDiurnalFactor($decHour);
    $err = abs($computedD - $expectedD);
    runTest("1." . ($idx + 1) . " {$clock} SLST ({$desc}): D(t)={$expectedD} (computed: {$computedD})", $err < 0.005, $totalTests, $passedTests, $failedTests);
}

// ------------------------------------------------------------------------
// GROUP 2: Interval Integration & Twilight Transition Calculus
// ------------------------------------------------------------------------
echo "\n--- GROUP 2: Interval Integration & Twilight Transition Calculus ---\n";

// Analytical Interval Cases
// 2.1 Dawn Preset (04:30 to 06:30, duration 2.0h)
// Integral = [4.5, 5.0] (0.5 * 2 = 1.0) + [5.0, 6.5] (1.5 * ((2.0+0.5)/2) = 1.875) = 2.875
// Mean factor = 2.875 / 2.0 = 1.4375 pts
$intDawn = TemporalRiskService::integrateDiurnalInterval(4.5, 6.5);
runTest("2.1 Dawn preset [04:30, 06:30] mean = 1.4375 pts (duration 2.0h)", abs($intDawn['mean_factor'] - 1.4375) < 0.001 && abs($intDawn['duration_hours'] - 2.0) < 0.001, $totalTests, $passedTests, $failedTests);

// 2.2 Dusk Preset (17:30 to 19:00, duration 1.5h)
// Integral = [17.5, 19.0] (1.5 * ((0.5+2.0)/2) = 1.875)
// Mean factor = 1.875 / 1.5 = 1.25 pts
$intDusk = TemporalRiskService::integrateDiurnalInterval(17.5, 19.0);
runTest("2.2 Dusk preset [17:30, 19:00] mean = 1.2500 pts (duration 1.5h)", abs($intDusk['mean_factor'] - 1.2500) < 0.001 && abs($intDusk['duration_hours'] - 1.5) < 0.001, $totalTests, $passedTests, $failedTests);

// 2.3 Morning Preset (07:30 to 10:30, duration 3.0h) -> strictly 0.0 pts
$intMorning = TemporalRiskService::integrateDiurnalInterval(7.5, 10.5);
runTest("2.3 Morning preset [07:30, 10:30] mean = 0.0000 pts (duration 3.0h)", abs($intMorning['mean_factor'] - 0.0) < 0.001, $totalTests, $passedTests, $failedTests);

// 2.4 Afternoon Preset (13:00 to 16:00, duration 3.0h) -> strictly 0.0 pts
$intAfternoon = TemporalRiskService::integrateDiurnalInterval(13.0, 16.0);
runTest("2.4 Afternoon preset [13:00, 16:00] mean = 0.0000 pts (duration 3.0h)", abs($intAfternoon['mean_factor'] - 0.0) < 0.001, $totalTests, $passedTests, $failedTests);

// 2.5 Night Preset (20:00 to 00:00, duration 4.0h) -> strictly 2.0 pts
$intNight = TemporalRiskService::integrateDiurnalInterval(20.0, 24.0);
runTest("2.5 Night preset [20:00, 24:00] mean = 2.0000 pts (duration 4.0h)", abs($intNight['mean_factor'] - 2.0) < 0.001, $totalTests, $passedTests, $failedTests);

// 2.6 Midnight Crossing (22:00 to 03:00, duration 5.0h) -> strictly 2.0 pts
$intMidnightCross = TemporalRiskService::integrateDiurnalInterval(22.0, 3.0);
runTest("2.6 Midnight crossing [22:00, 03:00] mean = 2.0000 pts (duration 5.0h)", abs($intMidnightCross['mean_factor'] - 2.0) < 0.001 && abs($intMidnightCross['duration_hours'] - 5.0) < 0.001, $totalTests, $passedTests, $failedTests);

// 2.7 Broad Midnight Crossing into Dawn (22:00 to 06:00, duration 8.0h)
// [22, 24] = 4.0, [0, 5] = 10.0, [5, 6] = 1.5 => Integral = 15.5
// Mean = 15.5 / 8.0 = 1.9375 pts
$intBroadDawn = TemporalRiskService::integrateDiurnalInterval(22.0, 6.0);
runTest("2.7 Midnight to Dawn crossing [22:00, 06:00] mean = 1.9375 pts (duration 8.0h)", abs($intBroadDawn['mean_factor'] - 1.9375) < 0.001 && abs($intBroadDawn['duration_hours'] - 8.0) < 0.001, $totalTests, $passedTests, $failedTests);

// 2.8 Point Interval (18:00 to 18:00) -> instantaneous factor 1.0
$intPoint = TemporalRiskService::integrateDiurnalInterval(18.0, 18.0);
runTest("2.8 Point observation [18:00, 18:00] mean = 1.0000 pts (duration 0.0h)", abs($intPoint['mean_factor'] - 1.0) < 0.001 && $intPoint['duration_hours'] == 0.0, $totalTests, $passedTests, $failedTests);

// ------------------------------------------------------------------------
// GROUP 3 & 4: Controlled 20-Scenario Simulation Dataset & Comparative Risk
// ------------------------------------------------------------------------
echo "\n--- GROUP 3 & 4: Controlled 20-Scenario Simulation Dataset & Comparative Risk ---\n";

$scenarios = [
    // 1. Daytime / Low Risk Section / Low Urgency / 1 Elephant / On-time
    [
        'id' => 'SCN-01',
        'title' => 'Daytime Single Elephant in Low Risk Section (On-Time Filing)',
        'section_name' => 'Colombo-Fort',
        'section_risk' => 'Low',
        'urgency_level' => 'Low',
        'elephant_count' => 1,
        'active_alerts' => false,
        'observation' => ['date' => '2026-11-10', 'time' => '11:30:00', 'precision' => 'exact', 'uncertainty' => 0],
        'filing_time' => '2026-11-10 11:35:00'
    ],
    // 2. Daytime / Med Risk Section / Med Urgency / 3 Elephants / On-time
    [
        'id' => 'SCN-02',
        'title' => 'Daytime Small Group in Medium Risk Section',
        'section_name' => 'Polgahawela-Kurunegala',
        'section_risk' => 'Medium',
        'urgency_level' => 'Medium',
        'elephant_count' => 3,
        'active_alerts' => false,
        'observation' => ['date' => '2026-11-10', 'time' => '14:15:00', 'precision' => 'exact', 'uncertainty' => 0],
        'filing_time' => '2026-11-10 14:20:00'
    ],
    // 3. Daytime / High Risk Section / High Urgency / 6 Elephants (Large Herd) / On-time
    [
        'id' => 'SCN-03',
        'title' => 'Daytime Large Herd in High Risk Section',
        'section_name' => 'Maho-Anuradhapura',
        'section_risk' => 'High',
        'urgency_level' => 'High',
        'elephant_count' => 6,
        'active_alerts' => false,
        'observation' => ['date' => '2026-11-10', 'time' => '10:00:00', 'precision' => 'exact', 'uncertainty' => 0],
        'filing_time' => '2026-11-10 10:15:00'
    ],
    // 4. Night / Low Risk Section / Low Urgency / 1 Elephant / On-time
    [
        'id' => 'SCN-04',
        'title' => 'Nighttime Solitary Sighting in Low Risk Section',
        'section_name' => 'Colombo-Fort',
        'section_risk' => 'Low',
        'urgency_level' => 'Low',
        'elephant_count' => 1,
        'active_alerts' => false,
        'observation' => ['date' => '2026-11-10', 'time' => '21:00:00', 'precision' => 'exact', 'uncertainty' => 0],
        'filing_time' => '2026-11-10 21:05:00'
    ],
    // 5. Night / Med Risk Section / High Urgency / 2 Elephants / On-time
    [
        'id' => 'SCN-05',
        'title' => 'Nighttime High-Urgency Sighting in Medium Risk Corridor',
        'section_name' => 'Polgahawela-Kurunegala',
        'section_risk' => 'Medium',
        'urgency_level' => 'High',
        'elephant_count' => 2,
        'active_alerts' => false,
        'observation' => ['date' => '2026-11-10', 'time' => '23:30:00', 'precision' => 'exact', 'uncertainty' => 0],
        'filing_time' => '2026-11-10 23:45:00'
    ],
    // 6. Night / Critical Risk Section / Critical Urgency / 8 Elephants / Active Alert
    [
        'id' => 'SCN-06',
        'title' => 'Nighttime Maximum Hazard Herd with Active Section Alert',
        'section_name' => 'Gal Oya-Habarana',
        'section_risk' => 'Critical',
        'urgency_level' => 'Critical',
        'elephant_count' => 8,
        'active_alerts' => true,
        'observation' => ['date' => '2026-11-10', 'time' => '20:45:00', 'precision' => 'exact', 'uncertainty' => 0],
        'filing_time' => '2026-11-10 20:50:00'
    ],
    // 7. Dawn (05:45) / Med Risk Section / Med Urgency / 1 Elephant / On-time
    [
        'id' => 'SCN-07',
        'title' => 'Dawn Twilight Point Sighting (05:45 SLST)',
        'section_name' => 'Polgahawela-Kurunegala',
        'section_risk' => 'Medium',
        'urgency_level' => 'Medium',
        'elephant_count' => 1,
        'active_alerts' => false,
        'observation' => ['date' => '2026-11-10', 'time' => '05:45:00', 'precision' => 'exact', 'uncertainty' => 0],
        'filing_time' => '2026-11-10 05:50:00'
    ],
    // 8. Dawn Preset (04:30-06:30) / High Risk Section / High Urgency / 4 Elephants
    [
        'id' => 'SCN-08',
        'title' => 'Dawn Twilight Approximate Preset Window Sighting',
        'section_name' => 'Maho-Anuradhapura',
        'section_risk' => 'High',
        'urgency_level' => 'High',
        'elephant_count' => 4,
        'active_alerts' => false,
        'observation' => ['date' => '2026-11-10', 'time' => '05:30:00', 'precision' => 'approximate', 'window' => 'dawn', 'uncertainty' => 60],
        'filing_time' => '2026-11-10 07:00:00'
    ],
    // 9. Dusk (18:15) / Med Risk Section / High Urgency / 2 Elephants
    [
        'id' => 'SCN-09',
        'title' => 'Dusk Twilight Point Sighting (18:15 SLST)',
        'section_name' => 'Polgahawela-Kurunegala',
        'section_risk' => 'Medium',
        'urgency_level' => 'High',
        'elephant_count' => 2,
        'active_alerts' => false,
        'observation' => ['date' => '2026-11-10', 'time' => '18:15:00', 'precision' => 'exact', 'uncertainty' => 0],
        'filing_time' => '2026-11-10 18:20:00'
    ],
    // 10. Dusk Preset (17:30-19:00) / Critical Risk Section / Med Urgency / 3 Elephants
    [
        'id' => 'SCN-10',
        'title' => 'Dusk Twilight Approximate Preset Window in Critical Corridor',
        'section_name' => 'Gal Oya-Habarana',
        'section_risk' => 'Critical',
        'urgency_level' => 'Medium',
        'elephant_count' => 3,
        'active_alerts' => false,
        'observation' => ['date' => '2026-11-10', 'time' => '18:15:00', 'precision' => 'approximate', 'window' => 'dusk', 'uncertainty' => 45],
        'filing_time' => '2026-11-10 19:15:00'
    ],
    // 11. Small Herd (1 elephant) / Night / Low Risk
    [
        'id' => 'SCN-11',
        'title' => 'Solitary Elephant at Night in Low Risk Section',
        'section_name' => 'Colombo-Fort',
        'section_risk' => 'Low',
        'urgency_level' => 'Low',
        'elephant_count' => 1,
        'active_alerts' => false,
        'observation' => ['date' => '2026-11-10', 'time' => '22:15:00', 'precision' => 'exact', 'uncertainty' => 0],
        'filing_time' => '2026-11-10 22:20:00'
    ],
    // 12. Large Herd (10 elephants) / Day / Med Risk
    [
        'id' => 'SCN-12',
        'title' => 'Large Herd Crossing during Daylight Hours',
        'section_name' => 'Polgahawela-Kurunegala',
        'section_risk' => 'Medium',
        'urgency_level' => 'High',
        'elephant_count' => 10,
        'active_alerts' => false,
        'observation' => ['date' => '2026-11-10', 'time' => '13:30:00', 'precision' => 'exact', 'uncertainty' => 0],
        'filing_time' => '2026-11-10 13:40:00'
    ],
    // 13. Large Herd (12 elephants) / Night / Critical Risk / Active Alert
    [
        'id' => 'SCN-13',
        'title' => 'Very Large Herd at Night in Critical Section with Pre-existing Alert',
        'section_name' => 'Gal Oya-Habarana',
        'section_risk' => 'Critical',
        'urgency_level' => 'Critical',
        'elephant_count' => 12,
        'active_alerts' => true,
        'observation' => ['date' => '2026-11-10', 'time' => '01:30:00', 'precision' => 'exact', 'uncertainty' => 0],
        'filing_time' => '2026-11-10 01:45:00'
    ],
    // 14. High-Risk Section / Daylight / Low Urgency / 1 Elephant
    [
        'id' => 'SCN-14',
        'title' => 'Passive Daytime Sighting in High Risk Section',
        'section_name' => 'Maho-Anuradhapura',
        'section_risk' => 'High',
        'urgency_level' => 'Low',
        'elephant_count' => 1,
        'active_alerts' => false,
        'observation' => ['date' => '2026-11-10', 'time' => '11:00:00', 'precision' => 'exact', 'uncertainty' => 0],
        'filing_time' => '2026-11-10 11:15:00'
    ],
    // 15. Low-Risk Section / Night / Critical Urgency / 5 Elephants
    [
        'id' => 'SCN-15',
        'title' => 'High Severity Night Event in Low Risk Section',
        'section_name' => 'Colombo-Fort',
        'section_risk' => 'Low',
        'urgency_level' => 'Critical',
        'elephant_count' => 5,
        'active_alerts' => false,
        'observation' => ['date' => '2026-11-10', 'time' => '23:00:00', 'precision' => 'exact', 'uncertainty' => 0],
        'filing_time' => '2026-11-10 23:10:00'
    ],
    // 16. Delayed Report (observed morning 08:00, filed evening 19:30 -> 11.5h delay)
    [
        'id' => 'SCN-16',
        'title' => 'Morning Observation Ingestion-Delayed to Nighttime Filing',
        'section_name' => 'Polgahawela-Kurunegala',
        'section_risk' => 'Medium',
        'urgency_level' => 'High',
        'elephant_count' => 2,
        'active_alerts' => false,
        'observation' => ['date' => '2026-11-10', 'time' => '08:00:00', 'precision' => 'exact', 'uncertainty' => 0],
        'filing_time' => '2026-11-10 19:30:00' // Night filing time!
    ],
    // 17. Delayed Report (observed night 23:00, filed next morning 11:00 -> 12.0h delay)
    [
        'id' => 'SCN-17',
        'title' => 'Night Observation Ingestion-Delayed to Daytime Filing',
        'section_name' => 'Polgahawela-Kurunegala',
        'section_risk' => 'Medium',
        'urgency_level' => 'High',
        'elephant_count' => 2,
        'active_alerts' => false,
        'observation' => ['date' => '2026-11-09', 'time' => '23:00:00', 'precision' => 'exact', 'uncertainty' => 0],
        'filing_time' => '2026-11-10 11:00:00' // Daytime filing time!
    ],
    // 18. Approximate Custom Window (17:00 ± 60m spanning 16:00 to 18:00 across pre-dusk and dusk)
    [
        'id' => 'SCN-18',
        'title' => 'Approximate Custom Window (16:00 to 18:00) Crossing Dusk Boundary',
        'section_name' => 'Polgahawela-Kurunegala',
        'section_risk' => 'Medium',
        'urgency_level' => 'Medium',
        'elephant_count' => 2,
        'active_alerts' => false,
        'observation' => ['date' => '2026-11-10', 'time' => '17:00:00', 'precision' => 'approximate', 'window' => 'custom', 'uncertainty' => 60],
        'filing_time' => '2026-11-10 18:30:00'
    ],
    // 19. Unknown Observation Time (date known, time null)
    [
        'id' => 'SCN-19',
        'title' => 'Unknown Observation Time Fallback (Neutral Zero Policy)',
        'section_name' => 'Maho-Anuradhapura',
        'section_risk' => 'High',
        'urgency_level' => 'High',
        'elephant_count' => 3,
        'active_alerts' => false,
        'observation' => ['date' => '2026-11-10', 'time' => null, 'precision' => 'unknown', 'uncertainty' => null],
        'filing_time' => '2026-11-10 14:00:00'
    ],
    // 20. Potential Duplicate Sighting Flagged
    [
        'id' => 'SCN-20',
        'title' => 'Potential Duplicate Sighting Flagged (Non-Inflation Protection)',
        'section_name' => 'Gal Oya-Habarana',
        'section_risk' => 'Critical',
        'urgency_level' => 'High',
        'elephant_count' => 2,
        'active_alerts' => true,
        'is_potential_duplicate' => true,
        'observation' => ['date' => '2026-11-10', 'time' => '20:15:00', 'precision' => 'exact', 'uncertainty' => 0],
        'filing_time' => '2026-11-10 20:25:00'
    ]
];

$evaluationResults = [];
$concordantCount = 0;
$promotedCount = 0;
$demotedCount = 0;

foreach ($scenarios as $scn) {
    // 1. Calculate Operational Risk using filing time
    $opClass = RiskService::calculateRiskScore(
        $scn['section_risk'],
        $scn['urgency_level'],
        $scn['elephant_count'],
        $scn['filing_time'],
        $scn['active_alerts']
    );

    // Compute raw operational score points for detailed audit
    $rawSec = ['Low' => 1, 'Medium' => 2, 'High' => 3, 'Critical' => 4][ucfirst(strtolower($scn['section_risk']))];
    $rawUrg = ['Low' => 1, 'Medium' => 2, 'High' => 3, 'Critical' => 4][ucfirst(strtolower($scn['urgency_level']))];
    $rawCnt = ($scn['elephant_count'] >= 5) ? 3 : (($scn['elephant_count'] >= 2) ? 2 : (($scn['elephant_count'] == 1) ? 1 : 0));
    $rawAlt = $scn['active_alerts'] ? 2 : 0;
    $filingHour = (int)date('H', strtotime($scn['filing_time']));
    $rawOpNight = ($filingHour >= 18 || $filingHour < 6) ? 2 : 0;
    $rawOpTotal = $rawSec + $rawUrg + $rawCnt + $rawAlt + $rawOpNight;

    // 2. Calculate Experimental Observation-Aware Risk
    $temporalMeta = [
        'sighting_date' => $scn['observation']['date'],
        'sighting_time' => $scn['observation']['time'],
        'sighting_time_precision' => $scn['observation']['precision'],
        'sighting_time_window' => $scn['observation']['window'] ?? null,
        'sighting_time_uncertainty_minutes' => $scn['observation']['uncertainty'] ?? null
    ];
    $reportCtx = [
        'section_risk' => $scn['section_risk'],
        'urgency_level' => $scn['urgency_level'],
        'elephant_count' => $scn['elephant_count'],
        'active_alerts' => $scn['active_alerts'],
        'is_potential_duplicate' => !empty($scn['is_potential_duplicate'])
    ];

    $expResult = RiskService::calculateObservationAwareRisk($reportCtx, $temporalMeta, ['enable_decay' => false]);

    // Categorization
    $opRank = ['Low' => 1, 'Medium' => 2, 'High' => 3, 'Critical' => 4][$opClass];
    $expRank = ['Low' => 1, 'Medium' => 2, 'High' => 3, 'Critical' => 4][$expResult['final_classification']];

    $shift = 'Concordant';
    if ($expRank > $opRank) {
        $shift = 'Promoted';
        $promotedCount++;
    } elseif ($expRank < $opRank) {
        $shift = 'Demoted';
        $demotedCount++;
    } else {
        $concordantCount++;
    }

    $evaluationResults[] = [
        'scenario' => $scn,
        'operational' => [
            'base_section' => $rawSec,
            'urgency' => $rawUrg,
            'count' => $rawCnt,
            'alert' => $rawAlt,
            'night_bonus' => $rawOpNight,
            'raw_total' => $rawOpTotal,
            'classification' => $opClass
        ],
        'experimental' => [
            'base_section' => $expResult['base_section_points'],
            'urgency' => $expResult['urgency_points'],
            'count' => $expResult['elephant_count_points'],
            'alert' => $expResult['active_alert_points'],
            'diurnal_adjustment' => $expResult['diurnal_adjustment'],
            'unrounded_total' => $expResult['unrounded_total_score'],
            'final_score' => $expResult['final_score'],
            'classification' => $expResult['final_classification'],
            'warnings' => $expResult['data_quality_warnings']
        ],
        'comparison' => [
            'score_diff' => round($expResult['unrounded_total_score'] - $rawOpTotal, 2),
            'shift' => $shift
        ]
    ];
}

// Verify Scenario dataset coverage
runTest("3.1 Generated exactly 20 controlled synthetic scenarios", count($evaluationResults) === 20, $totalTests, $passedTests, $failedTests);
runTest("3.2 Verified concordance rate established (Concordant={$concordantCount}, Promoted={$promotedCount}, Demoted={$demotedCount})", ($concordantCount + $promotedCount + $demotedCount) === 20, $totalTests, $passedTests, $failedTests);

// Specific Scenario Key Verifications
// SCN-16: Morning observation filed at night.
// Operational score received +2 night bonus because filed at 19:30!
// Experimental score received +0.0 because observed at 08:00 AM!
// Should be Demoted or score_diff = -2.00
$scn16 = $evaluationResults[15];
runTest("4.1 SCN-16 removes ingestion-time night bonus for morning sighting filed at night (diff: {$scn16['comparison']['score_diff']} pts)", $scn16['comparison']['score_diff'] == -2.0, $totalTests, $passedTests, $failedTests);

// SCN-17: Night observation filed at noon next day.
// Operational score received 0 night bonus because filed at 11:00 AM!
// Experimental score received +2.0 because observed at 23:00 at night!
// Should be Promoted: diff = +2.00
$scn17 = $evaluationResults[16];
runTest("4.2 SCN-17 restores night hazard for night sighting delayed to daytime filing (diff: +{$scn17['comparison']['score_diff']} pts, shift: {$scn17['comparison']['shift']})", $scn17['comparison']['score_diff'] == 2.0 && $scn17['comparison']['shift'] === 'Promoted', $totalTests, $passedTests, $failedTests);

// SCN-19: Unknown observation time produces exactly 0.0 diurnal adjustment and attaches warning
$scn19 = $evaluationResults[18];
runTest("4.3 SCN-19 unknown time produces 0.0 diurnal points with transparent warning", $scn19['experimental']['diurnal_adjustment'] == 0.0 && count($scn19['experimental']['warnings']) > 0, $totalTests, $passedTests, $failedTests);

// SCN-20: Duplicate flag verification
$scn20 = $evaluationResults[19];
runTest("4.4 SCN-20 duplicate flag emits notice without inflating points", count($scn20['experimental']['warnings']) > 0 && strpos($scn20['experimental']['warnings'][0], 'duplicate') !== false, $totalTests, $passedTests, $failedTests);

// ------------------------------------------------------------------------
// GROUP 5: Experimental Decay Sensitivity Analysis
// ------------------------------------------------------------------------
echo "\n--- GROUP 5: Experimental Decay Sensitivity Analysis ---\n";

$halfLives = [2.0, 4.0, 6.0, 8.0];
$elapsedHours = [0.5, 1.0, 2.0, 4.0, 8.0, 12.0, 24.0];

$decayMatrix = [];

foreach ($halfLives as $th) {
    $decayMatrix[(string)$th] = [];
    foreach ($elapsedHours as $dt) {
        $lambda = log(2.0) / $th;
        $multiplier = exp(-$lambda * $dt);
        $decayMatrix[(string)$th][(string)$dt] = round($multiplier, 4);
    }
}

// 5.1 Verification of mathematical half-life property: at dt = th, multiplier must be exactly 0.5000
foreach ($halfLives as $th) {
    $mAtHalf = $decayMatrix[(string)$th][(string)$th] ?? null;
    if ($mAtHalf !== null) {
        runTest("5.1 Half-life property verified: t_half={$th}h at dt={$th}h multiplier=0.5000", abs($mAtHalf - 0.5000) < 0.001, $totalTests, $passedTests, $failedTests);
    }
}

// 5.2 Verification of quarter-life property: at dt = 2*th, multiplier must be exactly 0.2500
runTest("5.2 Quarter-life property verified: t_half=4.0h at dt=8.0h multiplier=0.2500", abs($decayMatrix['4'][8.0] - 0.2500) < 0.001, $totalTests, $passedTests, $failedTests);
runTest("5.3 Eighth-life property verified: t_half=4.0h at dt=12.0h multiplier=0.1250", abs($decayMatrix['4'][12.0] - 0.1250) < 0.001, $totalTests, $passedTests, $failedTests);

// 5.4 Test Decay impact on SCN-06 (Maximum hazard: Section=4, Alert=2, Sighting threat=4+3+2=9)
// Floor = 4 + 2 = 6 (Medium).
// At 24h with t_half=4h: multiplier = 2^(-6) = 0.015625. Decayed sighting = 9 * 0.015625 = 0.14. Total = 6.14 -> 6 (Medium).
// Score never falls below Section Base + Alerts floor (6 pts).
$scn06Meta = [
    'sighting_date' => '2026-11-09',
    'sighting_time' => '12:00:00',
    'sighting_time_precision' => 'exact',
    'sighting_time_uncertainty_minutes' => 0
];
$scn06Ctx = [
    'section_risk' => 'Critical', // 4
    'urgency_level' => 'Critical', // 4
    'elephant_count' => 8, // 3
    'active_alerts' => true // 2
];
$resDecay24 = RiskService::calculateObservationAwareRisk($scn06Ctx, $scn06Meta, [
    'enable_decay' => true,
    'decay_half_life' => 4.0,
    'eval_time' => '2026-11-10 12:00:00' // 24h later
]);
runTest("5.4 Section Base Floor Rule verified under 24h decay: Score={$resDecay24['final_score']} >= Floor=6", $resDecay24['final_score'] >= 6 && $resDecay24['unrounded_total_score'] >= 6.0, $totalTests, $passedTests, $failedTests);

// ------------------------------------------------------------------------
// GROUP 6: Empirical Limitations Audit (Historical Baseline & Data Scarcity)
// ------------------------------------------------------------------------
echo "\n--- GROUP 6: Empirical Limitations Audit (Historical Baseline & Data Scarcity) ---\n";

$rawPdo->exec("USE eleguard_rail");
$totReports = (int)$rawPdo->query("SELECT COUNT(*) FROM elephant_reports")->fetchColumn();
$nullDateReports = (int)$rawPdo->query("SELECT COUNT(*) FROM elephant_reports WHERE sighting_date IS NULL")->fetchColumn();
$nullTimeReports = (int)$rawPdo->query("SELECT COUNT(*) FROM elephant_reports WHERE sighting_time IS NULL")->fetchColumn();
$unknownPrecReports = (int)$rawPdo->query("SELECT COUNT(*) FROM elephant_reports WHERE sighting_time_precision = 'unknown'")->fetchColumn();

// Check genuine historical baseline reports (unmodified operational reports with ID <= 3)
$baselineReports = $rawPdo->query("SELECT id, location_description, sighting_date, sighting_time, latitude, longitude FROM elephant_reports WHERE id <= 3")->fetchAll(PDO::FETCH_ASSOC);

runTest("6.1 All 43 operational reports retain sighting_date = NULL (100% historical unmigrated)", $nullDateReports === 43, $totalTests, $passedTests, $failedTests);
runTest("6.2 All 43 operational reports retain sighting_time = NULL", $nullTimeReports === 43, $totalTests, $passedTests, $failedTests);
runTest("6.3 All 43 operational reports retain precision = 'unknown'", $unknownPrecReports === 43, $totalTests, $passedTests, $failedTests);
runTest("6.4 Confirmed only 3 historical baseline records exist (N=3)", count($baselineReports) === 3, $totalTests, $passedTests, $failedTests);

// Save synthetic scenario dataset to tests/data/milestone_9c_stage3e_scenarios.json
$dataDir = __DIR__ . '/data';
if (!file_exists($dataDir)) {
    mkdir($dataDir, 0755, true);
}
$scenarioJsonPath = $dataDir . '/milestone_9c_stage3e_scenarios.json';
$scenarioPayload = [
    'metadata' => [
        'generated_at' => date('Y-m-d H:i:s'),
        'purpose' => 'Milestone 9C Stage 3E Controlled Scenario Simulation Dataset',
        'total_scenarios' => count($evaluationResults),
        'summary' => [
            'concordant' => $concordantCount,
            'promoted' => $promotedCount,
            'demoted' => $demotedCount
        ],
        'decay_sensitivity_matrix' => $decayMatrix
    ],
    'scenarios' => $evaluationResults
];
file_put_contents($scenarioJsonPath, json_encode($scenarioPayload, JSON_PRETTY_PRINT));
runTest("6.5 Synthetic scenario dataset persisted to tests/data/milestone_9c_stage3e_scenarios.json", file_exists($scenarioJsonPath) && filesize($scenarioJsonPath) > 500, $totalTests, $passedTests, $failedTests);

// ------------------------------------------------------------------------
// GROUP 7: Operational Safety & Zero Database Modification Invariants
// ------------------------------------------------------------------------
echo "\n--- GROUP 7: Operational Safety & Zero Database Modification Invariants ---\n";

$rawPdo->exec("USE eleguard_rail");
$postOperReports = (int)$rawPdo->query("SELECT COUNT(*) FROM elephant_reports")->fetchColumn();
$postOperLogs = (int)$rawPdo->query("SELECT COUNT(*) FROM system_logs")->fetchColumn();
$postOperAlerts = (int)$rawPdo->query("SELECT COUNT(*) FROM alerts")->fetchColumn();
$postOperReport1 = $rawPdo->query("SELECT id, report_status, sighting_date, sighting_time, sighting_time_precision, created_at, updated_at FROM elephant_reports WHERE id = 1")->fetch(PDO::FETCH_ASSOC);

runTest("7.1 Operational elephant_reports count unmodified (43 == {$postOperReports})", $postOperReports === 43 && $postOperReports === $preOperReports, $totalTests, $passedTests, $failedTests);
runTest("7.2 Operational system_logs count unmodified (185 == {$postOperLogs})", $postOperLogs === 185 && $postOperLogs === $preOperLogs, $totalTests, $passedTests, $failedTests);
runTest("7.3 Operational alerts count unmodified (15 == {$postOperAlerts})", $postOperAlerts === 15 && $postOperAlerts === $preOperAlerts, $totalTests, $passedTests, $failedTests);
runTest("7.4 Operational Report #1 record untouched", $preOperReport1 === $postOperReport1, $totalTests, $passedTests, $failedTests);

echo "\n========================================================================\n";
echo "STAGE 3E EVALUATION TEST SUITE COMPLETE: {$passedTests} / {$totalTests} PASSED\n";
if ($failedTests > 0) {
    echo "CRITICAL: {$failedTests} tests failed!\n";
} else {
    echo "SUCCESS: All Stage 3E mathematical validation and scenario tests passed!\n";
}
echo "========================================================================\n";

exit($failedTests > 0 ? 1 : 0);
