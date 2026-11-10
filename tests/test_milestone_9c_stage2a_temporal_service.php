<?php
/**
 * EleGuard Rail - Milestone 9C Stage 2A Automated Test Suite
 * 
 * Comprehensive tests for TemporalService and ReportService temporal integration:
 * - Group 1: Calendar Date Validation & Future Date Rules
 * - Group 2: Clock Time Validation & Formatting
 * - Group 3: Exact Sighting Time Validation & Precision Rules
 * - Group 4: Diurnal Approximate Presets (Dawn, Morning, Afternoon, Dusk, Night)
 * - Group 5: Custom Approximate Window & Uncertainty Limits
 * - Group 6: Unknown Time Handling & Strict NULL Preservation
 * - Group 7: W3C Browser Geolocation GPS Epoch Conversion & Asia/Colombo Mapping
 * - Group 8: Legacy Caller Backward Compatibility in ReportService
 * - Group 9: ReportService End-to-End Temporal Persistence
 * - Group 10: Operational Database Zero-Write Invariant
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
require_once __DIR__ . '/../app/config/database.php';
require_once __DIR__ . '/../app/core/Database.php';
require_once __DIR__ . '/../app/services/TemporalService.php';
require_once __DIR__ . '/../app/services/ReportService.php';

class Milestone9CStage2ATestSuite {
    private $pdo;
    private $rawPdo;
    private $passed = 0;
    private $failed = 0;
    private $testDbName = 'eleguard_rail_test';
    private $preOperReports;
    private $preOperLogs;

    public function __construct($rawPdo, $preOperReports, $preOperLogs) {
        $this->rawPdo = $rawPdo;
        $this->preOperReports = $preOperReports;
        $this->preOperLogs = $preOperLogs;
        $this->pdo = Database::getInstance()->getConnection();
    }

    private function assert($condition, $testName, $details = '') {
        if ($condition) {
            $this->passed++;
            echo " [PASS] {$testName}\n";
        } else {
            $this->failed++;
            echo " [FAIL] {$testName} - Details: {$details}\n";
        }
    }

    public function run() {
        echo "========================================================\n";
        echo " EleGuard Rail - Milestone 9C Stage 2A Test Suite\n";
        echo " Temporal Service Layer & Validation Foundation\n";
        echo " Connected DB: " . DB_NAME . "\n";
        echo "========================================================\n\n";

        // Group 1: Calendar Date Validation
        $this->testCalendarDateValidation();

        // Group 2: Clock Time Validation
        $this->testClockTimeValidation();

        // Group 3: Exact Sighting Time
        $this->testExactSightingValidation();

        // Group 4: Diurnal Approximate Presets
        $this->testApproximatePresets();

        // Group 5: Custom Approximate Window
        $this->testCustomApproximateWindow();

        // Group 6: Unknown Time & NULL Preservation
        $this->testUnknownTimePreservation();

        // Group 7: GPS Timestamp Handling & Conversion
        $this->testGpsTimestampConversion();

        // Group 8: Legacy Caller Backward Compatibility in ReportService
        $this->testLegacyCallerBackwardCompatibility();

        // Group 9: ReportService End-to-End Temporal Persistence
        $this->testReportServiceTemporalPersistence();

        // Group 10: Operational Database Zero-Write Invariant
        $this->verifyOperationalDatabaseUntouched();

        // Cleanup
        $this->cleanupTestDatabase();

        echo "\n========================================================\n";
        echo " Summary: {$this->passed} Passed, {$this->failed} Failed\n";
        echo "========================================================\n";

        return $this->failed === 0;
    }

    private function testCalendarDateValidation() {
        echo "-- Group 1: Calendar Date Validation & Future Date Rules --\n";

        // 1.1 Valid past date
        $r1 = TemporalService::validateDate('2026-10-15');
        $this->assert($r1['valid'] && $r1['date'] === '2026-10-15', "1.1 Valid past date (2026-10-15) accepted");

        // 1.2 Today's date in Asia/Colombo
        $today = TemporalService::getNow()->format('Y-m-d');
        $r2 = TemporalService::validateDate($today);
        $this->assert($r2['valid'] && $r2['date'] === $today, "1.2 Today's date ({$today}) accepted");

        // 1.3 Future date
        $tomorrow = date('Y-m-d', strtotime('+1 day', strtotime($today)));
        $r3 = TemporalService::validateDate($tomorrow);
        $this->assert(!$r3['valid'] && strpos($r3['error'], 'future') !== false, "1.3 Future date ({$tomorrow}) rejected");

        // 1.4 Non-existent calendar date (e.g. Feb 30)
        $r4 = TemporalService::validateDate('2026-02-30');
        $this->assert(!$r4['valid'] && strpos($r4['error'], 'Invalid calendar date') !== false, "1.4 Non-existent date (2026-02-30) rejected");

        // 1.5 Malformed date string
        $r5 = TemporalService::validateDate('15-10-2026');
        $this->assert(!$r5['valid'] && strpos($r5['error'], 'YYYY-MM-DD') !== false, "1.5 Malformed date format rejected");

        // 1.6 Empty/null date
        $r6 = TemporalService::validateDate('');
        $this->assert(!$r6['valid'], "1.6 Empty date string rejected");

        echo "\n";
    }

    private function testClockTimeValidation() {
        echo "-- Group 2: Clock Time Validation & Formatting --\n";

        // 2.1 Standard HH:MM
        $t1 = TemporalService::validateTime('08:30');
        $this->assert($t1['valid'] && $t1['time'] === '08:30:00', "2.1 Time '08:30' normalized to '08:30:00'");

        // 2.2 Standard HH:MM:SS
        $t2 = TemporalService::validateTime('23:59:59');
        $this->assert($t2['valid'] && $t2['time'] === '23:59:59', "2.2 Time '23:59:59' accepted");

        // 2.3 Midnight 00:00
        $t3 = TemporalService::validateTime('00:00');
        $this->assert($t3['valid'] && $t3['time'] === '00:00:00', "2.3 Midnight '00:00' normalized to '00:00:00'");

        // 2.4 Out-of-bounds hour 24:00
        $t4 = TemporalService::validateTime('24:00');
        $this->assert(!$t4['valid'], "2.4 Out-of-bounds hour '24:00' rejected");

        // 2.5 Out-of-bounds minute 14:60
        $t5 = TemporalService::validateTime('14:60');
        $this->assert(!$t5['valid'], "2.5 Out-of-bounds minute '14:60' rejected");

        // 2.6 Non-clock string 'morning'
        $t6 = TemporalService::validateTime('morning');
        $this->assert(!$t6['valid'], "2.6 Non-clock string 'morning' rejected by validateTime");

        echo "\n";
    }

    private function testExactSightingValidation() {
        echo "-- Group 3: Exact Sighting Time Validation & Precision Rules --\n";

        // 3.1 Valid exact sighting (yesterday 14:30)
        $yesterday = date('Y-m-d', strtotime('-1 day'));
        $input = [
            'sighting_date' => $yesterday,
            'sighting_time' => '14:30',
            'sighting_time_precision' => 'exact'
        ];
        $res = TemporalService::validateAndNormalizeTemporalInput($input);
        $this->assert(
            $res['valid'] && 
            $res['data']['sighting_date'] === $yesterday && 
            $res['data']['sighting_time'] === '14:30:00' && 
            $res['data']['sighting_time_precision'] === 'exact' && 
            $res['data']['sighting_time_uncertainty_minutes'] === 5 && 
            $res['data']['sighting_time_window'] === null,
            "3.1 Valid exact sighting accepted with uncertainty=5m and window=NULL"
        );

        // 3.2 Exact precision missing time
        $res2 = TemporalService::validateAndNormalizeTemporalInput([
            'sighting_date' => $yesterday,
            'sighting_time' => '',
            'sighting_time_precision' => 'exact'
        ]);
        $this->assert(!$res2['valid'] && isset($res2['errors']['sighting_time']), "3.2 Exact precision without sighting_time rejected");

        // 3.3 Exact precision missing date
        $res3 = TemporalService::validateAndNormalizeTemporalInput([
            'sighting_date' => '',
            'sighting_time' => '10:00',
            'sighting_time_precision' => 'exact'
        ]);
        $this->assert(!$res3['valid'] && isset($res3['errors']['sighting_date']), "3.3 Exact precision without sighting_date rejected");

        // 3.4 Invalid combination: exact precision with approximate window
        $res4 = TemporalService::validateAndNormalizeTemporalInput([
            'sighting_date' => $yesterday,
            'sighting_time' => '05:30',
            'sighting_time_precision' => 'exact',
            'sighting_time_window' => 'dawn'
        ]);
        $this->assert(!$res4['valid'] && isset($res4['errors']['sighting_time_window']), "3.4 Conflict: exact precision with window='dawn' rejected");

        // 3.5 Exact sighting in future today
        $now = TemporalService::getNow();
        $future = clone $now;
        $future->modify('+2 hours');
        $futureTime = $future->format('H:i:s');
        $res5 = TemporalService::validateAndNormalizeTemporalInput([
            'sighting_date' => $now->format('Y-m-d'),
            'sighting_time' => $futureTime,
            'sighting_time_precision' => 'exact'
        ]);
        $this->assert(!$res5['valid'] && isset($res5['errors']['sighting_time']), "3.5 Exact time in future today rejected");

        echo "\n";
    }

    private function testApproximatePresets() {
        echo "-- Group 4: Diurnal Approximate Presets (Dawn, Morning, Afternoon, Dusk, Night) --\n";

        $pastDate = date('Y-m-d', strtotime('-5 days'));

        // 4.1 Dawn Preset (05:30, ±60 min)
        $rDawn = TemporalService::validateAndNormalizeTemporalInput([
            'sighting_date' => $pastDate,
            'sighting_time_precision' => 'approximate',
            'sighting_time_window' => 'dawn'
        ]);
        $this->assert(
            $rDawn['valid'] && 
            $rDawn['data']['sighting_time'] === '05:30:00' && 
            $rDawn['data']['sighting_time_uncertainty_minutes'] === 60 && 
            $rDawn['data']['sighting_time_window'] === 'dawn',
            "4.1 Dawn preset: time='05:30:00', uncertainty=60m"
        );

        // 4.2 Morning Preset (09:00, ±90 min)
        $rMorn = TemporalService::validateAndNormalizeTemporalInput([
            'sighting_date' => $pastDate,
            'sighting_time_precision' => 'approximate',
            'sighting_time_window' => 'morning'
        ]);
        $this->assert(
            $rMorn['valid'] && 
            $rMorn['data']['sighting_time'] === '09:00:00' && 
            $rMorn['data']['sighting_time_uncertainty_minutes'] === 90,
            "4.2 Morning preset: time='09:00:00', uncertainty=90m"
        );

        // 4.3 Afternoon Preset (14:30, ±90 min)
        $rAft = TemporalService::validateAndNormalizeTemporalInput([
            'sighting_date' => $pastDate,
            'sighting_time_precision' => 'approximate',
            'sighting_time_window' => 'afternoon'
        ]);
        $this->assert(
            $rAft['valid'] && 
            $rAft['data']['sighting_time'] === '14:30:00' && 
            $rAft['data']['sighting_time_uncertainty_minutes'] === 90,
            "4.3 Afternoon preset: time='14:30:00', uncertainty=90m"
        );

        // 4.4 Dusk Preset (18:15, ±45 min)
        $rDusk = TemporalService::validateAndNormalizeTemporalInput([
            'sighting_date' => $pastDate,
            'sighting_time_precision' => 'approximate',
            'sighting_time_window' => 'dusk'
        ]);
        $this->assert(
            $rDusk['valid'] && 
            $rDusk['data']['sighting_time'] === '18:15:00' && 
            $rDusk['data']['sighting_time_uncertainty_minutes'] === 45,
            "4.4 Dusk preset: time='18:15:00', uncertainty=45m"
        );

        // 4.5 Night Preset (22:00, ±120 min)
        $rNight = TemporalService::validateAndNormalizeTemporalInput([
            'sighting_date' => $pastDate,
            'sighting_time_precision' => 'approximate',
            'sighting_time_window' => 'night'
        ]);
        $this->assert(
            $rNight['valid'] && 
            $rNight['data']['sighting_time'] === '22:00:00' && 
            $rNight['data']['sighting_time_uncertainty_minutes'] === 120,
            "4.5 Night preset: time='22:00:00', uncertainty=120m"
        );

        // 4.6 Invalid window name
        $rBadWin = TemporalService::validateAndNormalizeTemporalInput([
            'sighting_date' => $pastDate,
            'sighting_time_precision' => 'approximate',
            'sighting_time_window' => 'twilight'
        ]);
        $this->assert(!$rBadWin['valid'] && isset($rBadWin['errors']['sighting_time_window']), "4.6 Invalid window name 'twilight' rejected");

        echo "\n";
    }

    private function testCustomApproximateWindow() {
        echo "-- Group 5: Custom Approximate Window & Uncertainty Limits --\n";

        $pastDate = date('Y-m-d', strtotime('-2 days'));

        // 5.1 Valid custom window (16:45, ±45 min)
        $rCustom = TemporalService::validateAndNormalizeTemporalInput([
            'sighting_date' => $pastDate,
            'sighting_time_precision' => 'approximate',
            'sighting_time_window' => 'custom',
            'sighting_time' => '16:45',
            'sighting_time_uncertainty_minutes' => 45
        ]);
        $this->assert(
            $rCustom['valid'] && 
            $rCustom['data']['sighting_time'] === '16:45:00' && 
            $rCustom['data']['sighting_time_uncertainty_minutes'] === 45 && 
            $rCustom['data']['sighting_time_window'] === 'custom',
            "5.1 Valid custom window accepted (16:45:00, ±45m)"
        );

        // 5.2 Custom uncertainty below minimum (< 15 min)
        $rLowUnc = TemporalService::validateAndNormalizeTemporalInput([
            'sighting_date' => $pastDate,
            'sighting_time_precision' => 'approximate',
            'sighting_time_window' => 'custom',
            'sighting_time' => '16:45',
            'sighting_time_uncertainty_minutes' => 10
        ]);
        $this->assert(!$rLowUnc['valid'] && isset($rLowUnc['errors']['sighting_time_uncertainty_minutes']), "5.2 Custom uncertainty 10m rejected (< 15m minimum)");

        // 5.3 Custom uncertainty above maximum (> 180 min)
        $rHighUnc = TemporalService::validateAndNormalizeTemporalInput([
            'sighting_date' => $pastDate,
            'sighting_time_precision' => 'approximate',
            'sighting_time_window' => 'custom',
            'sighting_time' => '16:45',
            'sighting_time_uncertainty_minutes' => 240
        ]);
        $this->assert(!$rHighUnc['valid'] && isset($rHighUnc['errors']['sighting_time_uncertainty_minutes']), "5.3 Custom uncertainty 240m rejected (> 180m maximum)");

        // 5.4 Custom window missing reference time
        $rNoTime = TemporalService::validateAndNormalizeTemporalInput([
            'sighting_date' => $pastDate,
            'sighting_time_precision' => 'approximate',
            'sighting_time_window' => 'custom',
            'sighting_time' => '',
            'sighting_time_uncertainty_minutes' => 60
        ]);
        $this->assert(!$rNoTime['valid'] && isset($rNoTime['errors']['sighting_time']), "5.4 Custom window without reference time rejected");

        echo "\n";
    }

    private function testUnknownTimePreservation() {
        echo "-- Group 6: Unknown Time Handling & Strict NULL Preservation --\n";

        // 6.1 Completely empty temporal input defaults to unknown
        $rEmpty = TemporalService::validateAndNormalizeTemporalInput([]);
        $this->assert(
            $rEmpty['valid'] && 
            $rEmpty['data']['sighting_date'] === null && 
            $rEmpty['data']['sighting_time'] === null && 
            $rEmpty['data']['sighting_time_precision'] === 'unknown' && 
            $rEmpty['data']['sighting_time_window'] === null && 
            $rEmpty['data']['sighting_time_uncertainty_minutes'] === null && 
            $rEmpty['data']['sighting_timezone'] === 'Asia/Colombo',
            "6.1 Empty input defaults safely to unknown with all temporal fields NULL"
        );

        // 6.2 Date known, time unknown
        $pastDate = '2026-11-01';
        $rDateOnly = TemporalService::validateAndNormalizeTemporalInput([
            'sighting_date' => $pastDate,
            'sighting_time_precision' => 'unknown'
        ]);
        $this->assert(
            $rDateOnly['valid'] && 
            $rDateOnly['data']['sighting_date'] === $pastDate && 
            $rDateOnly['data']['sighting_time'] === null && 
            $rDateOnly['data']['sighting_time_precision'] === 'unknown',
            "6.2 Date known, time unknown preserved with sighting_time = NULL (not midnight)"
        );

        // 6.3 Conflict: unknown precision specifying clock time
        $rConflictTime = TemporalService::validateAndNormalizeTemporalInput([
            'sighting_date' => $pastDate,
            'sighting_time' => '10:00',
            'sighting_time_precision' => 'unknown'
        ]);
        $this->assert(!$rConflictTime['valid'] && isset($rConflictTime['errors']['sighting_time']), "6.3 Conflict: unknown precision with clock time rejected");

        // 6.4 Conflict: unknown precision specifying window
        $rConflictWin = TemporalService::validateAndNormalizeTemporalInput([
            'sighting_date' => $pastDate,
            'sighting_time_precision' => 'unknown',
            'sighting_time_window' => 'dawn'
        ]);
        $this->assert(!$rConflictWin['valid'] && isset($rConflictWin['errors']['sighting_time_window']), "6.4 Conflict: unknown precision with window rejected");

        // 6.5 Conflict: unknown precision specifying uncertainty
        $rConflictUnc = TemporalService::validateAndNormalizeTemporalInput([
            'sighting_date' => $pastDate,
            'sighting_time_precision' => 'unknown',
            'sighting_time_uncertainty_minutes' => 30
        ]);
        $this->assert(!$rConflictUnc['valid'] && isset($rConflictUnc['errors']['sighting_time_uncertainty_minutes']), "6.5 Conflict: unknown precision with uncertainty rejected");

        echo "\n";
    }

    private function testGpsTimestampConversion() {
        echo "-- Group 7: W3C Browser Geolocation GPS Epoch Conversion & Asia/Colombo Mapping --\n";

        // 7.1 W3C DOMTimeStamp in milliseconds (1762657445000)
        // 1762657445 UTC = 2025-11-09 03:04:05 UTC -> in Asia/Colombo (+05:30) = 2025-11-09 08:34:05
        $ms = 1762657445000;
        $g1 = TemporalService::parseGpsTimestamp($ms);
        $this->assert($g1['valid'] && $g1['datetime'] === '2025-11-09 08:34:05', "7.1 Browser epoch milliseconds correctly converted to Asia/Colombo '2025-11-09 08:34:05'");

        // 7.2 Unix epoch seconds (1762657445)
        $sec = 1762657445;
        $g2 = TemporalService::parseGpsTimestamp($sec);
        $this->assert($g2['valid'] && $g2['datetime'] === '2025-11-09 08:34:05', "7.2 Unix epoch seconds correctly converted to Asia/Colombo '2025-11-09 08:34:05'");

        // 7.3 NULL / empty GPS timestamp
        $g3 = TemporalService::parseGpsTimestamp(null);
        $this->assert($g3['valid'] && $g3['datetime'] === null, "7.3 NULL GPS timestamp returns NULL without errors");

        // 7.4 Implausibly early timestamp (prior to 2020)
        $g4 = TemporalService::parseGpsTimestamp(1000000); // 1970
        $this->assert(!$g4['valid'] && strpos($g4['error'], 'prior to 2020') !== false, "7.4 Implausibly early epoch timestamp rejected");

        // 7.5 Far-future GPS timestamp
        $futureGps = time() + 100000;
        $g5 = TemporalService::parseGpsTimestamp($futureGps);
        $this->assert(!$g5['valid'] && strpos($g5['error'], 'future') !== false, "7.5 Future GPS timestamp rejected");

        echo "\n";
    }

    private function testLegacyCallerBackwardCompatibility() {
        echo "-- Group 8: Legacy Caller Backward Compatibility in ReportService --\n";

        // Submit report using EXACT legacy 10-argument signature (no temporal arguments)
        // ReportService::submitReport($sectionId, $reportedBy, $count, $locationDesc, $urgency, $description, $imagePath, $lat, $lng, $acc)
        $legacySuccess = ReportService::submitReport(
            1, // section_id
            1, // reported_by
            2, // elephant_count
            'Legacy Signature QA Test Marker',
            'Medium',
            'Submission using legacy 10-argument signature without temporal fields.',
            null, // imagePath
            8.0512, // latitude
            80.7611, // longitude
            15.0 // accuracy
        );

        $this->assert($legacySuccess, "8.1 Legacy 10-argument submitReport() succeeded without temporal parameters");

        // Fetch inserted report from test database
        $inserted = $this->pdo->query("SELECT * FROM elephant_reports WHERE location_description = 'Legacy Signature QA Test Marker'")->fetch(PDO::FETCH_ASSOC);

        $this->assert(
            $inserted && 
            $inserted['sighting_date'] === null && 
            $inserted['sighting_time'] === null && 
            $inserted['sighting_time_precision'] === 'unknown' && 
            $inserted['sighting_time_window'] === null && 
            $inserted['sighting_time_uncertainty_minutes'] === null && 
            $inserted['sighting_timezone'] === 'Asia/Colombo' && 
            $inserted['gps_captured_at'] === null,
            "8.2 Legacy report persisted with unknown temporal precision and all temporal fields NULL"
        );

        // Verify risk score was calculated normally
        $this->assert(in_array($inserted['risk_score'], ['Low', 'Medium', 'High', 'Critical']), "8.3 Risk score computed normally for legacy report ({$inserted['risk_score']})");

        echo "\n";
    }

    private function testReportServiceTemporalPersistence() {
        echo "-- Group 9: ReportService End-to-End Temporal Persistence --\n";

        // 9.1 Submit report with exact temporal metadata array
        $pastDate = date('Y-m-d', strtotime('-2 days'));
        $exactSuccess = ReportService::submitReport(
            2, 1, 4, 'Exact Temporal Submission Test', 'High', 'Detailed exact sighting submission.',
            null, 8.0412, 80.8256, 12.0,
            [
                'sighting_date' => $pastDate,
                'sighting_time' => '17:45:00',
                'sighting_time_precision' => 'exact',
                'gps_timestamp' => 1762657445000
            ]
        );
        $this->assert($exactSuccess, "9.1 Exact temporal submission via array succeeded");

        $exactRow = $this->pdo->query("SELECT * FROM elephant_reports WHERE location_description = 'Exact Temporal Submission Test'")->fetch(PDO::FETCH_ASSOC);
        $this->assert(
            $exactRow && 
            $exactRow['sighting_date'] === $pastDate && 
            $exactRow['sighting_time'] === '17:45:00' && 
            $exactRow['sighting_time_precision'] === 'exact' && 
            (int)$exactRow['sighting_time_uncertainty_minutes'] === 5 && 
            $exactRow['gps_captured_at'] === '2025-11-09 08:34:05',
            "9.2 Exact temporal fields and converted GPS timestamp persisted correctly in database"
        );

        // 9.2 Submit report with approximate dusk preset
        $duskSuccess = ReportService::submitReport(
            1, 1, 1, 'Dusk Preset Submission Test', 'Low', 'Dusk approximate observation.',
            null, 8.1256, 80.9124, 20.0,
            [
                'sighting_date' => $pastDate,
                'sighting_time_precision' => 'approximate',
                'sighting_time_window' => 'dusk'
            ]
        );
        $this->assert($duskSuccess, "9.3 Approximate dusk preset submission succeeded");

        $duskRow = $this->pdo->query("SELECT * FROM elephant_reports WHERE location_description = 'Dusk Preset Submission Test'")->fetch(PDO::FETCH_ASSOC);
        $this->assert(
            $duskRow && 
            $duskRow['sighting_time'] === '18:15:00' && 
            (int)$duskRow['sighting_time_uncertainty_minutes'] === 45 && 
            $duskRow['sighting_time_window'] === 'dusk' && 
            $duskRow['sighting_time_precision'] === 'approximate',
            "9.4 Dusk preset reference time (18:15:00) and uncertainty (45m) persisted correctly"
        );

        // 9.3 Rejection of invalid temporal data (e.g. future date)
        $futureDate = date('Y-m-d', strtotime('+3 days'));
        $futureFail = ReportService::submitReport(
            1, 1, 1, 'Invalid Future Report', 'Low', 'Should fail.',
            null, null, null, null,
            [
                'sighting_date' => $futureDate,
                'sighting_time_precision' => 'exact',
                'sighting_time' => '12:00:00'
            ]
        );
        $this->assert(!$futureFail, "9.5 Report submission rejected when temporal validation fails (future date)");

        $futureRow = $this->pdo->query("SELECT * FROM elephant_reports WHERE location_description = 'Invalid Future Report'")->fetch(PDO::FETCH_ASSOC);
        $this->assert(!$futureRow, "9.6 Rejected report was not written to database");

        echo "\n";
    }

    private function verifyOperationalDatabaseUntouched() {
        echo "-- Group 10: Operational Database Zero-Write Invariant --\n";
        $this->rawPdo->exec("USE eleguard_rail");

        $postReports = (int)$this->rawPdo->query("SELECT COUNT(*) FROM elephant_reports")->fetchColumn();
        $postLogs = (int)$this->rawPdo->query("SELECT COUNT(*) FROM system_logs")->fetchColumn();

        $reportsMatch = ($postReports === $this->preOperReports);
        $logsMatch = ($postLogs === $this->preOperLogs);

        $this->assert($reportsMatch, "10.1 Operational elephant_reports count untouched ({$postReports} == {$this->preOperReports})");
        $this->assert($logsMatch, "10.2 Operational system_logs count untouched ({$postLogs} == {$this->preOperLogs})");

        echo "\n";
    }

    private function cleanupTestDatabase() {
        echo "-- Dropping Isolated Test Database ({$this->testDbName}) --\n";
        safeTeardownTestDatabase($this->rawPdo, $this->testDbName);
        $dbs = $this->rawPdo->query("SHOW DATABASES LIKE '{$this->testDbName}'")->fetchAll(PDO::FETCH_COLUMN);
        $this->assert(empty($dbs), "Cleaned up isolated test database successfully");
        echo "\n";
    }
}

// Run test suite
$suite = new Milestone9CStage2ATestSuite($rawPdo, $preOperReports, $preOperLogs);
$success = $suite->run();
exit($success ? 0 : 1);
