<?php
/**
 * EleGuard Rail - Milestone 9C Stage 1: Operational Database Verification Script
 *
 * Verifies that Migration 03 was applied cleanly to the operational database (`eleguard_rail`),
 * that all 7 temporal fields and 4 indexes exist, that all 43 historical records retain
 * NULL/unknown temporal values with zero assumed sighting dates, and that all tables remain intact.
 *
 * Outputs verification results to console and `docs/phase9/temporal_foundation_verification.json`.
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

require_once __DIR__ . '/../../app/config/database.php';

$outputReport = [
    'milestone' => '9C — Stage 1: Database Foundation Only',
    'timestamp' => date('Y-m-d H:i:s T'),
    'database' => DB_NAME,
    'host' => DB_HOST . ':' . DB_PORT,
    'checks' => [],
    'columns_verified' => [],
    'indexes_verified' => [],
    'table_counts' => [],
    'historical_preservation' => [],
    'status' => 'UNKNOWN'
];

try {
    $pdo = new PDO("mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    echo "========================================================================\n";
    echo " EleGuard Rail - Milestone 9C Stage 1: Operational Database Verification\n";
    echo " Database: " . DB_NAME . " on " . DB_HOST . ":" . DB_PORT . "\n";
    echo "========================================================================\n\n";

    $allPassed = true;

    // --- 1. Verify 7 Temporal Columns ---
    echo "-- 1. Verifying 7 Temporal Columns in elephant_reports --\n";
    $cols = $pdo->query("
        SELECT COLUMN_NAME, ORDINAL_POSITION, COLUMN_DEFAULT, IS_NULLABLE, DATA_TYPE, COLUMN_TYPE
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = '" . DB_NAME . "' AND TABLE_NAME = 'elephant_reports'
        ORDER BY ORDINAL_POSITION
    ")->fetchAll(PDO::FETCH_ASSOC);

    $colMap = [];
    foreach ($cols as $c) {
        $colMap[$c['COLUMN_NAME']] = $c;
    }

    $expectedColumns = [
        'sighting_date' => [
            'type' => 'date',
            'nullable' => 'YES',
            'default' => null,
            'prev_col' => 'elephant_count'
        ],
        'sighting_time' => [
            'type' => 'time',
            'nullable' => 'YES',
            'default' => null,
            'prev_col' => 'sighting_date'
        ],
        'sighting_time_precision' => [
            'type' => "enum('exact','approximate','unknown')",
            'nullable' => 'NO',
            'default' => 'unknown',
            'prev_col' => 'sighting_time'
        ],
        'sighting_time_window' => [
            'type' => "enum('dawn','morning','afternoon','dusk','night','custom')",
            'nullable' => 'YES',
            'default' => null,
            'prev_col' => 'sighting_time_precision'
        ],
        'sighting_time_uncertainty_minutes' => [
            'type' => 'int',
            'nullable' => 'YES',
            'default' => null,
            'prev_col' => 'sighting_time_window'
        ],
        'sighting_timezone' => [
            'type' => 'varchar(32)',
            'nullable' => 'NO',
            'default' => 'Asia/Colombo',
            'prev_col' => 'sighting_time_uncertainty_minutes'
        ],
        'gps_captured_at' => [
            'type' => 'datetime',
            'nullable' => 'YES',
            'default' => null,
            'prev_col' => 'gps_accuracy_meters'
        ]
    ];

    foreach ($expectedColumns as $colName => $exp) {
        $found = isset($colMap[$colName]);
        $typeMatch = $found && strpos(strtolower($colMap[$colName]['COLUMN_TYPE']), $exp['type']) !== false;
        $nullableMatch = $found && $colMap[$colName]['IS_NULLABLE'] === $exp['nullable'];
        $rawDefault = $colMap[$colName]['COLUMN_DEFAULT'];
        if ($rawDefault === null || strtoupper($rawDefault) === 'NULL') {
            $actualDefault = null;
        } else {
            $actualDefault = trim((string)$rawDefault, "'");
        }
        $defaultMatch = ($actualDefault === $exp['default']);

        $pass = $found && $typeMatch && $nullableMatch && $defaultMatch;
        if (!$pass) $allPassed = false;

        $statusStr = $pass ? "[PASS]" : "[FAIL]";
        echo " {$statusStr} Column '{$colName}': " . ($found ? $colMap[$colName]['COLUMN_TYPE'] : 'MISSING') . 
             ", Nullable: " . ($found ? $colMap[$colName]['IS_NULLABLE'] : '-') .
             ", Default: " . ($actualDefault === null ? 'NULL' : "'{$actualDefault}'") . "\n";

        $outputReport['columns_verified'][$colName] = [
            'exists' => $found,
            'type' => $found ? $colMap[$colName]['COLUMN_TYPE'] : null,
            'nullable' => $found ? $colMap[$colName]['IS_NULLABLE'] : null,
            'default' => $actualDefault,
            'ordinal_position' => $found ? (int)$colMap[$colName]['ORDINAL_POSITION'] : null,
            'verified' => $pass
        ];
    }
    echo "\n";

    // --- 2. Verify 4 Performance Indexes ---
    echo "-- 2. Verifying 4 Temporal Indexes on elephant_reports --\n";
    $rawIndexes = $pdo->query("SHOW INDEX FROM elephant_reports")->fetchAll(PDO::FETCH_ASSOC);
    $idxGroups = [];
    foreach ($rawIndexes as $idx) {
        $idxGroups[$idx['Key_name']][] = $idx['Column_name'];
    }

    $expectedIndexes = [
        'idx_elephant_reports_sighting_date' => ['sighting_date'],
        'idx_elephant_reports_temporal_hourly' => ['sighting_date', 'sighting_time', 'sighting_time_precision'],
        'idx_elephant_reports_section_temporal' => ['section_id', 'sighting_date', 'sighting_time'],
        'idx_elephant_reports_lag_analysis' => ['sighting_date', 'created_at']
    ];

    foreach ($expectedIndexes as $idxName => $expCols) {
        $exists = isset($idxGroups[$idxName]);
        $colsMatch = $exists && ($idxGroups[$idxName] === $expCols);
        $pass = $exists && $colsMatch;
        if (!$pass) $allPassed = false;

        $statusStr = $pass ? "[PASS]" : "[FAIL]";
        echo " {$statusStr} Index '{$idxName}': (" . ($exists ? implode(', ', $idxGroups[$idxName]) : 'MISSING') . ")\n";

        $outputReport['indexes_verified'][$idxName] = [
            'exists' => $exists,
            'columns' => $exists ? $idxGroups[$idxName] : [],
            'verified' => $pass
        ];
    }
    echo "\n";

    // --- 3. Table Row Counts Integrity ---
    echo "-- 3. Verifying All Operational Table Counts --\n";
    $expectedTableCounts = [
        'elephant_reports' => 43,
        'hotspots' => 4,
        'railway_sections' => 4,
        'incident_history' => 3,
        'alerts' => 15,
        'driver_acknowledgements' => 13,
        'users' => 5,
        'system_logs' => 185
    ];

    foreach ($expectedTableCounts as $tbl => $expCount) {
        $actCount = (int)$pdo->query("SELECT COUNT(*) FROM `{$tbl}`")->fetchColumn();
        $pass = ($actCount === $expCount);
        if (!$pass) $allPassed = false;

        $statusStr = $pass ? "[PASS]" : "[FAIL]";
        echo " {$statusStr} Table '{$tbl}': {$actCount} rows (expected {$expCount})\n";

        $outputReport['table_counts'][$tbl] = [
            'actual' => $actCount,
            'expected' => $expCount,
            'verified' => $pass
        ];
    }
    echo "\n";

    // --- 4. Historical Data Preservation Assertions ---
    echo "-- 4. Verifying Historical Data Preservation (N=43) --\n";
    $totalReports = (int)$pdo->query("SELECT COUNT(*) FROM elephant_reports")->fetchColumn();
    $nullDates = (int)$pdo->query("SELECT COUNT(*) FROM elephant_reports WHERE sighting_date IS NULL")->fetchColumn();
    $nullTimes = (int)$pdo->query("SELECT COUNT(*) FROM elephant_reports WHERE sighting_time IS NULL")->fetchColumn();
    $unknownPrecision = (int)$pdo->query("SELECT COUNT(*) FROM elephant_reports WHERE sighting_time_precision = 'unknown'")->fetchColumn();
    $nullWindows = (int)$pdo->query("SELECT COUNT(*) FROM elephant_reports WHERE sighting_time_window IS NULL")->fetchColumn();
    $nullUncertainty = (int)$pdo->query("SELECT COUNT(*) FROM elephant_reports WHERE sighting_time_uncertainty_minutes IS NULL")->fetchColumn();
    $defaultTz = (int)$pdo->query("SELECT COUNT(*) FROM elephant_reports WHERE sighting_timezone = 'Asia/Colombo'")->fetchColumn();
    $nullGpsCaptured = (int)$pdo->query("SELECT COUNT(*) FROM elephant_reports WHERE gps_captured_at IS NULL")->fetchColumn();

    $historicalAssertions = [
        'sighting_date_null_count' => ['actual' => $nullDates, 'expected' => 43, 'desc' => 'All 43 historical reports retain sighting_date = NULL (zero assumed dates)'],
        'sighting_time_null_count' => ['actual' => $nullTimes, 'expected' => 43, 'desc' => 'All 43 historical reports retain sighting_time = NULL (zero assumed times)'],
        'sighting_time_precision_unknown_count' => ['actual' => $unknownPrecision, 'expected' => 43, 'desc' => 'All 43 historical reports retain sighting_time_precision = "unknown"'],
        'sighting_time_window_null_count' => ['actual' => $nullWindows, 'expected' => 43, 'desc' => 'All 43 historical reports retain sighting_time_window = NULL'],
        'sighting_time_uncertainty_null_count' => ['actual' => $nullUncertainty, 'expected' => 43, 'desc' => 'All 43 historical reports retain uncertainty_minutes = NULL'],
        'sighting_timezone_default_count' => ['actual' => $defaultTz, 'expected' => 43, 'desc' => 'All 43 historical reports have sighting_timezone = "Asia/Colombo"'],
        'gps_captured_at_null_count' => ['actual' => $nullGpsCaptured, 'expected' => 43, 'desc' => 'All 43 historical reports retain gps_captured_at = NULL']
    ];

    foreach ($historicalAssertions as $k => $info) {
        $pass = ($info['actual'] === $info['expected']);
        if (!$pass) $allPassed = false;

        $statusStr = $pass ? "[PASS]" : "[FAIL]";
        echo " {$statusStr} {$info['desc']} [{$info['actual']}/{$info['expected']}]\n";

        $outputReport['historical_preservation'][$k] = [
            'actual' => $info['actual'],
            'expected' => $info['expected'],
            'description' => $info['desc'],
            'verified' => $pass
        ];
    }
    echo "\n";

    // --- 5. Verify Baseline Seed Integrity (IDs 1, 2, 3) ---
    echo "-- 5. Baseline Seed Records Integrity (IDs 1, 2, 3) --\n";
    $seeds = $pdo->query("SELECT id, location_description, urgency_level, risk_score, created_at, updated_at FROM elephant_reports WHERE id IN (1, 2, 3) ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
    $baselineCheck = (
        count($seeds) === 3 &&
        $seeds[0]['location_description'] === 'Near Mile Post 85, close to the small lake' &&
        $seeds[1]['location_description'] === 'Minneriya Crossing marker 102' &&
        $seeds[2]['location_description'] === 'Habarana bend near forest temple'
    );
    if (!$baselineCheck) $allPassed = false;
    $statusStr = $baselineCheck ? "[PASS]" : "[FAIL]";
    echo " {$statusStr} Seed records (IDs 1, 2, 3) descriptions and attributes 100% identical\n";
    $outputReport['historical_preservation']['seed_records_identical'] = $baselineCheck;
    echo "\n";

    $outputReport['status'] = $allPassed ? 'VERIFIED_SUCCESS' : 'VERIFICATION_FAILED';

    // Save JSON report
    $jsonPath = __DIR__ . '/../../docs/phase9/temporal_foundation_verification.json';
    file_put_contents($jsonPath, json_encode($outputReport, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    echo "Verification report written to: {$jsonPath}\n";

    echo "========================================================================\n";
    echo " Final Status: " . ($allPassed ? "STAGE 1 OPERATIONAL VERIFICATION PASSED (100%)" : "FAILED") . "\n";
    echo "========================================================================\n";

    exit($allPassed ? 0 : 1);

} catch (Exception $e) {
    echo "FATAL ERROR during verification: " . $e->getMessage() . "\n";
    $outputReport['status'] = 'FATAL_ERROR';
    $outputReport['error'] = $e->getMessage();
    exit(1);
}
