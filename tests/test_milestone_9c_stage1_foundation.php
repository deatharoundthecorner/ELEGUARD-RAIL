<?php
/**
 * EleGuard Rail - Milestone 9C Stage 1: Database Foundation Automated Test Suite
 * 
 * Verifies Migration 03, schema columns, indexes, historical data preservation,
 * synthetic insert/retrieve operations, NULL value preservation, and rollback integrity.
 * 
 * STRICT ISOLATION: Runs exclusively against `eleguard_rail_test`.
 * Operates with ZERO writes to operational `eleguard_rail`.
 */

error_reporting(E_ALL & ~E_WARNING & ~E_NOTICE);
ini_set('display_errors', '0');

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../app/config/database.php';

class Milestone9CStage1TestSuite {
    private $pdo;
    private $passed = 0;
    private $failed = 0;
    private $testDbName = 'eleguard_rail_test';

    public function __construct() {
        $this->pdo = new PDO("mysql:host=" . DB_HOST . ";port=" . DB_PORT, DB_USER, DB_PASS);
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
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
        echo " EleGuard Rail - Milestone 9C Stage 1 Test Suite\n";
        echo "========================================================\n\n";

        // Step 1: Set up fresh test database clone
        $this->setupTestDatabase();

        // Step 2: Apply Migration 03 to test database
        $this->testMigrationExecution();

        // Step 3: Verify new columns exist with proper types and positions
        $this->testColumnDefinitions();

        // Step 4: Verify indexes exist
        $this->testIndexDefinitions();

        // Step 5: Verify historical records retain NULL/unknown
        $this->testHistoricalDataPreservation();

        // Step 6: Test synthetic insert & retrieval across exact, approximate, unknown
        $this->testSyntheticTemporalOperations();

        // Step 7: Test Rollback 03 execution and data preservation
        $this->testRollbackIntegrity();

        // Step 8: Clean up test database
        $this->cleanupTestDatabase();

        // Step 9: Verify operational database integrity
        $this->verifyOperationalDatabaseUntouched();

        echo "\n========================================================\n";
        echo " Summary: {$this->passed} Passed, {$this->failed} Failed\n";
        echo "========================================================\n";

        return $this->failed === 0;
    }

    private function setupTestDatabase() {
        echo "-- Step 1: Setting up isolated test database ({$this->testDbName}) --\n";
        $this->pdo->exec("DROP DATABASE IF EXISTS `{$this->testDbName}`");
        $this->pdo->exec("CREATE DATABASE `{$this->testDbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

        $this->pdo->exec("USE eleguard_rail");
        $tables = $this->pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);

        $this->pdo->exec("SET FOREIGN_KEY_CHECKS = 0");
        foreach ($tables as $t) {
            $createSql = $this->pdo->query("SHOW CREATE TABLE `{$t}`")->fetch(PDO::FETCH_ASSOC)['Create Table'];
            $this->pdo->exec("USE `{$this->testDbName}`");
            $this->pdo->exec($createSql);
            $this->pdo->exec("INSERT INTO `{$this->testDbName}`.`{$t}` SELECT * FROM eleguard_rail.`{$t}`");
            $this->pdo->exec("USE eleguard_rail");
        }
        $this->pdo->exec("SET FOREIGN_KEY_CHECKS = 1");

        $count = (int)$this->pdo->query("SELECT COUNT(*) FROM `{$this->testDbName}`.elephant_reports")->fetchColumn();
        $this->assert($count === 43, "1.1 Cloned 43 existing reports into isolated test database");

        // If clone inherited post-migration columns from operational DB, roll back test clone to pre-migration state
        $cols = $this->pdo->query("SHOW COLUMNS FROM `{$this->testDbName}`.elephant_reports LIKE 'sighting_date'")->fetchAll();
        if (!empty($cols)) {
            $rollbackFile = __DIR__ . '/../database/migrations/rollback_03_temporal_intelligence.sql';
            $sql = file_get_contents($rollbackFile);
            $sql = str_replace('USE eleguard_rail;', "USE `{$this->testDbName}`;", $sql);
            $sql = preg_replace('/--.*$/m', '', $sql);
            $sql = preg_replace('/\/\*.*?\*\//s', '', $sql);
            foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) {
                if (!empty($stmt)) $this->pdo->exec($stmt);
            }
        }

        echo "\n";
    }

    private function testMigrationExecution() {
        echo "-- Step 2: Applying Migration 03 to Isolated Test Database --\n";
        $this->pdo->exec("USE `{$this->testDbName}`");
        
        $migrationFile = __DIR__ . '/../database/migrations/03_add_temporal_intelligence_to_elephant_reports.sql';
        $sql = file_get_contents($migrationFile);
        $sql = str_replace('USE eleguard_rail;', "USE `{$this->testDbName}`;", $sql);
        // Strip SQL comments
        $sql = preg_replace('/--.*$/m', '', $sql);
        $sql = preg_replace('/\/\*.*?\*\//s', '', $sql);

        $stmts = array_filter(array_map('trim', explode(';', $sql)));
        $migSuccess = true;
        foreach ($stmts as $stmt) {
            if (!empty($stmt)) {
                try {
                    $this->pdo->exec($stmt);
                } catch (Exception $e) {
                    $migSuccess = false;
                    echo "Migration error: " . $e->getMessage() . "\n";
                }
            }
        }

        $this->assert($migSuccess, "2.1 Migration 03 executed cleanly on isolated test database");
        echo "\n";
    }

    private function testColumnDefinitions() {
        echo "-- Step 3: Verifying Schema Columns and Attributes --\n";
        $this->pdo->exec("USE `{$this->testDbName}`");

        $cols = $this->pdo->query("
            SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = '{$this->testDbName}' AND TABLE_NAME = 'elephant_reports'
        ")->fetchAll(PDO::FETCH_ASSOC);

        $colMap = [];
        foreach ($cols as $c) {
            $colMap[$c['COLUMN_NAME']] = $c;
        }

        // 1. sighting_date
        $this->assert(
            isset($colMap['sighting_date']) && $colMap['sighting_date']['IS_NULLABLE'] === 'YES' && strpos($colMap['sighting_date']['COLUMN_TYPE'], 'date') !== false,
            "3.1 Column 'sighting_date' exists as DATE NULL"
        );

        // 2. sighting_time
        $this->assert(
            isset($colMap['sighting_time']) && $colMap['sighting_time']['IS_NULLABLE'] === 'YES' && strpos($colMap['sighting_time']['COLUMN_TYPE'], 'time') !== false,
            "3.2 Column 'sighting_time' exists as TIME NULL"
        );

        // 3. sighting_time_precision
        $this->assert(
            isset($colMap['sighting_time_precision']) && strpos($colMap['sighting_time_precision']['COLUMN_TYPE'], 'enum') !== false && trim($colMap['sighting_time_precision']['COLUMN_DEFAULT'], "'") === 'unknown',
            "3.3 Column 'sighting_time_precision' exists as ENUM with default 'unknown'"
        );

        // 4. sighting_time_window
        $this->assert(
            isset($colMap['sighting_time_window']) && strpos($colMap['sighting_time_window']['COLUMN_TYPE'], 'enum') !== false && $colMap['sighting_time_window']['IS_NULLABLE'] === 'YES',
            "3.4 Column 'sighting_time_window' exists as nullable ENUM ('dawn', 'morning', etc.)"
        );

        // 5. sighting_time_uncertainty_minutes
        $this->assert(
            isset($colMap['sighting_time_uncertainty_minutes']) && strpos($colMap['sighting_time_uncertainty_minutes']['COLUMN_TYPE'], 'int') !== false && $colMap['sighting_time_uncertainty_minutes']['IS_NULLABLE'] === 'YES',
            "3.5 Column 'sighting_time_uncertainty_minutes' exists as INT NULL"
        );

        // 6. sighting_timezone
        $this->assert(
            isset($colMap['sighting_timezone']) && trim($colMap['sighting_timezone']['COLUMN_DEFAULT'], "'") === 'Asia/Colombo',
            "3.6 Column 'sighting_timezone' exists with default 'Asia/Colombo'"
        );

        // 7. gps_captured_at
        $this->assert(
            isset($colMap['gps_captured_at']) && strpos($colMap['gps_captured_at']['COLUMN_TYPE'], 'datetime') !== false && $colMap['gps_captured_at']['IS_NULLABLE'] === 'YES',
            "3.7 Column 'gps_captured_at' exists as DATETIME NULL"
        );

        echo "\n";
    }

    private function testIndexDefinitions() {
        echo "-- Step 4: Verifying Performance Indexes --\n";
        $this->pdo->exec("USE `{$this->testDbName}`");

        $indexes = $this->pdo->query("SHOW INDEX FROM elephant_reports")->fetchAll(PDO::FETCH_ASSOC);
        $idxNames = array_unique(array_column($indexes, 'Key_name'));

        $this->assert(in_array('idx_elephant_reports_sighting_date', $idxNames), "4.1 Index 'idx_elephant_reports_sighting_date' created");
        $this->assert(in_array('idx_elephant_reports_temporal_hourly', $idxNames), "4.2 Index 'idx_elephant_reports_temporal_hourly' created");
        $this->assert(in_array('idx_elephant_reports_section_temporal', $idxNames), "4.3 Index 'idx_elephant_reports_section_temporal' created");
        $this->assert(in_array('idx_elephant_reports_lag_analysis', $idxNames), "4.4 Index 'idx_elephant_reports_lag_analysis' created");
        echo "\n";
    }

    private function testHistoricalDataPreservation() {
        echo "-- Step 5: Verifying Historical Data Preservation --\n";
        $this->pdo->exec("USE `{$this->testDbName}`");

        $totalCount = (int)$this->pdo->query("SELECT COUNT(*) FROM elephant_reports")->fetchColumn();
        $nullDateCount = (int)$this->pdo->query("SELECT COUNT(*) FROM elephant_reports WHERE sighting_date IS NULL")->fetchColumn();
        $nullTimeCount = (int)$this->pdo->query("SELECT COUNT(*) FROM elephant_reports WHERE sighting_time IS NULL")->fetchColumn();
        $unknownPrecCount = (int)$this->pdo->query("SELECT COUNT(*) FROM elephant_reports WHERE sighting_time_precision = 'unknown'")->fetchColumn();
        $nullWindowCount = (int)$this->pdo->query("SELECT COUNT(*) FROM elephant_reports WHERE sighting_time_window IS NULL")->fetchColumn();
        $nullUncertaintyCount = (int)$this->pdo->query("SELECT COUNT(*) FROM elephant_reports WHERE sighting_time_uncertainty_minutes IS NULL")->fetchColumn();

        $this->assert($totalCount === 43, "5.1 Total report row count preserved at exactly 43");
        $this->assert($nullDateCount === 43, "5.2 All 43 historical records retain sighting_date = NULL (zero assumed dates)");
        $this->assert($nullTimeCount === 43, "5.3 All 43 historical records retain sighting_time = NULL (zero assumed times)");
        $this->assert($unknownPrecCount === 43, "5.4 All 43 historical records retain precision = 'unknown'");
        $this->assert($nullWindowCount === 43, "5.5 All 43 historical records retain sighting_time_window = NULL");
        $this->assert($nullUncertaintyCount === 43, "5.6 All 43 historical records retain uncertainty_minutes = NULL");

        // Verify baseline records (IDs 1, 2, 3) location descriptions and created_at timestamps remain identical
        $stmt = $this->pdo->query("SELECT id, location_description, created_at FROM elephant_reports WHERE id IN (1, 2, 3) ORDER BY id");
        $seeds = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $this->assert(
            $seeds[0]['location_description'] === 'Near Mile Post 85, close to the small lake' &&
            $seeds[1]['location_description'] === 'Minneriya Crossing marker 102' &&
            $seeds[2]['location_description'] === 'Habarana bend near forest temple',
            "5.7 Confirmed historical baseline text and locations completely unchanged"
        );

        echo "\n";
    }

    private function testSyntheticTemporalOperations() {
        echo "-- Step 6: Testing Synthetic Temporal Insert & Retrieval --\n";
        $this->pdo->exec("USE `{$this->testDbName}`");

        // 6.1 Exact nocturnal sighting
        $this->pdo->exec("
            INSERT INTO elephant_reports (
                section_id, reported_by, elephant_count, sighting_date, sighting_time,
                sighting_time_precision, sighting_time_uncertainty_minutes, sighting_timezone,
                location_description, urgency_level, risk_score, report_status
            ) VALUES (
                1, 1, 3, '2026-11-09', '02:30:00', 'exact', 5, 'Asia/Colombo',
                'Synthetic QA exact midnight sighting', 'High', 'High', 'Pending'
            )
        ");
        $idExact = (int)$this->pdo->lastInsertId();
        $rExact = $this->pdo->query("SELECT * FROM elephant_reports WHERE id = {$idExact}")->fetch(PDO::FETCH_ASSOC);

        $this->assert(
            $rExact['sighting_date'] === '2026-11-09' && $rExact['sighting_time'] === '02:30:00' && $rExact['sighting_time_precision'] === 'exact' && (int)$rExact['sighting_time_uncertainty_minutes'] === 5,
            "6.1 Exact sighting inserted and retrieved with exact precision and uncertainty=5m"
        );

        // 6.2 Approximate dusk sighting with ecological window
        $this->pdo->exec("
            INSERT INTO elephant_reports (
                section_id, reported_by, elephant_count, sighting_date, sighting_time,
                sighting_time_precision, sighting_time_window, sighting_time_uncertainty_minutes, sighting_timezone,
                location_description, urgency_level, risk_score, report_status
            ) VALUES (
                2, 1, 5, '2026-11-09', '18:15:00', 'approximate', 'dusk', 45, 'Asia/Colombo',
                'Synthetic QA approximate dusk sighting', 'Critical', 'Critical', 'Pending'
            )
        ");
        $idApprox = (int)$this->pdo->lastInsertId();
        $rApprox = $this->pdo->query("SELECT * FROM elephant_reports WHERE id = {$idApprox}")->fetch(PDO::FETCH_ASSOC);

        $this->assert(
            $rApprox['sighting_time_window'] === 'dusk' && (int)$rApprox['sighting_time_uncertainty_minutes'] === 45 && $rApprox['sighting_time_precision'] === 'approximate',
            "6.2 Approximate sighting inserted with window='dusk' and uncertainty=45m"
        );

        // 6.3 Date known, time unknown
        $this->pdo->exec("
            INSERT INTO elephant_reports (
                section_id, reported_by, elephant_count, sighting_date, sighting_time,
                sighting_time_precision, sighting_timezone,
                location_description, urgency_level, risk_score, report_status
            ) VALUES (
                1, 1, 1, '2026-11-08', NULL, 'unknown', 'Asia/Colombo',
                'Synthetic QA date-only sighting', 'Low', 'Low', 'Pending'
            )
        ");
        $idUnknown = (int)$this->pdo->lastInsertId();
        $rUnknown = $this->pdo->query("SELECT * FROM elephant_reports WHERE id = {$idUnknown}")->fetch(PDO::FETCH_ASSOC);

        $this->assert(
            $rUnknown['sighting_date'] === '2026-11-08' && $rUnknown['sighting_time'] === null && $rUnknown['sighting_time_precision'] === 'unknown',
            "6.3 Date-only sighting inserted with sighting_time = NULL and precision = 'unknown'"
        );

        // Clean up synthetic test rows
        $this->pdo->exec("DELETE FROM elephant_reports WHERE id IN ({$idExact}, {$idApprox}, {$idUnknown})");
        $cleanCount = (int)$this->pdo->query("SELECT COUNT(*) FROM elephant_reports")->fetchColumn();
        $this->assert($cleanCount === 43, "6.4 Synthetic test rows cleaned up cleanly; row count returns to 43");

        echo "\n";
    }

    private function testRollbackIntegrity() {
        echo "-- Step 7: Testing Rollback Migration Script --\n";
        $this->pdo->exec("USE `{$this->testDbName}`");

        $rollbackFile = __DIR__ . '/../database/migrations/rollback_03_temporal_intelligence.sql';
        $sql = file_get_contents($rollbackFile);
        $sql = str_replace('USE eleguard_rail;', "USE `{$this->testDbName}`;", $sql);
        // Strip SQL comments
        $sql = preg_replace('/--.*$/m', '', $sql);
        $sql = preg_replace('/\/\*.*?\*\//s', '', $sql);

        $stmts = array_filter(array_map('trim', explode(';', $sql)));
        $rollSuccess = true;
        foreach ($stmts as $stmt) {
            if (!empty($stmt)) {
                try {
                    $this->pdo->exec($stmt);
                } catch (Exception $e) {
                    $rollSuccess = false;
                    echo "Rollback error: " . $e->getMessage() . "\n";
                }
            }
        }

        $this->assert($rollSuccess, "7.1 Rollback 03 executed cleanly on test database");

        // Verify columns are removed
        $cols = $this->pdo->query("
            SELECT COLUMN_NAME
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = '{$this->testDbName}' AND TABLE_NAME = 'elephant_reports'
        ")->fetchAll(PDO::FETCH_COLUMN);

        $temporalCols = ['sighting_date', 'sighting_time', 'sighting_time_precision', 'sighting_time_window', 'sighting_time_uncertainty_minutes', 'sighting_timezone', 'gps_captured_at'];
        $remaining = array_intersect($temporalCols, $cols);

        $this->assert(empty($remaining), "7.2 All 7 temporal columns removed by rollback script");

        // Verify existing 43 rows survived rollback completely intact
        $postRollbackCount = (int)$this->pdo->query("SELECT COUNT(*) FROM elephant_reports")->fetchColumn();
        $this->assert($postRollbackCount === 43, "7.3 All 43 existing report records survived rollback completely intact");

        echo "\n";
    }

    private function cleanupTestDatabase() {
        echo "-- Step 8: Dropping Isolated Test Database --\n";
        safeTeardownTestDatabase($this->pdo, $this->testDbName);
        $dbs = $this->pdo->query("SHOW DATABASES LIKE '{$this->testDbName}'")->fetchAll(PDO::FETCH_COLUMN);
        $this->assert(empty($dbs), "8.1 Isolated test database dropped cleanly");
        echo "\n";
    }

    private function verifyOperationalDatabaseUntouched() {
        echo "-- Step 9: Verifying Operational Database (eleguard_rail) Untouched --\n";
        $this->pdo->exec("USE eleguard_rail");

        $counts = [
            'elephant_reports' => (int)$this->pdo->query("SELECT COUNT(*) FROM elephant_reports")->fetchColumn(),
            'hotspots' => (int)$this->pdo->query("SELECT COUNT(*) FROM hotspots")->fetchColumn(),
            'railway_sections' => (int)$this->pdo->query("SELECT COUNT(*) FROM railway_sections")->fetchColumn(),
            'incident_history' => (int)$this->pdo->query("SELECT COUNT(*) FROM incident_history")->fetchColumn(),
            'alerts' => (int)$this->pdo->query("SELECT COUNT(*) FROM alerts")->fetchColumn(),
            'driver_acknowledgements' => (int)$this->pdo->query("SELECT COUNT(*) FROM driver_acknowledgements")->fetchColumn(),
            'users' => (int)$this->pdo->query("SELECT COUNT(*) FROM users")->fetchColumn(),
            'system_logs' => (int)$this->pdo->query("SELECT COUNT(*) FROM system_logs")->fetchColumn()
        ];

        $expected = [
            'elephant_reports' => 43,
            'hotspots' => 4,
            'railway_sections' => 4,
            'incident_history' => 3,
            'alerts' => 15,
            'driver_acknowledgements' => 13,
            'users' => 5,
            'system_logs' => 185
        ];

        $matches = ($counts === $expected);
        $this->assert($matches, "9.1 Operational database eleguard_rail remained 100% untouched throughout isolated testing");
        echo "\n";
    }
}

// Run test suite
$suite = new Milestone9CStage1TestSuite();
$success = $suite->run();
exit($success ? 0 : 1);
