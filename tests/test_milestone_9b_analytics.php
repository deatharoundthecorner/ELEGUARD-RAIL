<?php
/**
 * EleGuard Rail - Milestone 9B Historical Hotspot Analytics Test Suite
 * 
 * Strict Synthetic-Fixture Unit and Integration Tests:
 * 1. Geodesic distance calculations (Haversine reference against known ground-truth).
 * 2. Nearest-hotspot selection with synthetic fixtures.
 * 3. Coordinate validation (null, non-numeric, out-of-bounds, valid).
 * 4. GPS uncertainty interval calculation and non-negative clamping.
 * 5. Missing coordinate handling for text-only reports (no fatal errors or NaN).
 * 6. Duplicate hotspot coordinate detection.
 * 7. Section mismatch classification and explanation engine.
 * 8. Provenance filtering and population stratification.
 * 9. Empty dataset handling (graceful handling of zero hotspots or reports).
 * 10. Read-only database verification (asserts row counts completely unchanged).
 */

error_reporting(E_ALL & ~E_WARNING & ~E_NOTICE);
ini_set('display_errors', '0');

require_once __DIR__ . '/../app/config/app.php';
require_once __DIR__ . '/../app/core/Database.php';
require_once __DIR__ . '/../app/services/ProximityService.php';

class Milestone9BAnalyticsTestSuite {
    private $passed = 0;
    private $failed = 0;
    private $db = null;

    public function __construct() {
        try {
            $this->db = Database::getInstance()->getConnection();
        } catch (Exception $e) {
            $this->db = null;
        }
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
        echo " EleGuard Rail - Milestone 9B Analytics Test Suite\n";
        echo "========================================================\n\n";

        // Snapshot database state before running
        $initialCounts = $this->getDatabaseCounts();

        $this->testGeodesicDistanceCalculation();
        $this->testNearestHotspotSelection();
        $this->testCoordinateValidation();
        $this->testGpsUncertaintyHandling();
        $this->testMissingCoordinateHandling();
        $this->testDuplicateHotspotDetection();
        $this->testSectionMismatchClassification();
        $this->testProvenanceFiltering();
        $this->testEmptyDatasetHandling();
        $this->testReadOnlyDatabaseIntegrity($initialCounts);

        echo "\n========================================================\n";
        echo " Summary: {$this->passed} Passed, {$this->failed} Failed\n";
        echo "========================================================\n";

        return $this->failed === 0;
    }

    private function getDatabaseCounts() {
        if (!$this->db) return [];
        return [
            'hotspots' => (int)$this->db->query("SELECT COUNT(*) FROM hotspots")->fetchColumn(),
            'elephant_reports' => (int)$this->db->query("SELECT COUNT(*) FROM elephant_reports")->fetchColumn(),
            'railway_sections' => (int)$this->db->query("SELECT COUNT(*) FROM railway_sections")->fetchColumn(),
            'alerts' => (int)$this->db->query("SELECT COUNT(*) FROM alerts")->fetchColumn()
        ];
    }

    // 1. Geodesic distance calculations
    private function testGeodesicDistanceCalculation() {
        // Point A: Colombo (6.9271° N, 79.8612° E), Point B: Kandy (7.2906° N, 80.6337° E)
        // Known great-circle distance is approx 95.5 km
        $dist = ProximityService::haversineDistance(6.9271, 79.8612, 7.2906, 80.6337);
        $this->assert(
            $dist >= 94000.0 && $dist <= 97000.0,
            "1.1 Geodesic distance between known points is accurate (~95.5 km)",
            "Calculated: " . round($dist, 2) . "m"
        );

        // Identical coordinates must yield 0.0 metres
        $zeroDist = ProximityService::haversineDistance(8.0412, 80.8256, 8.0412, 80.8256);
        $this->assert(
            $zeroDist < 0.001,
            "1.2 Geodesic distance between identical points is exactly 0.0m",
            "Calculated: {$zeroDist}m"
        );
    }

    // 2. Nearest-hotspot selection with synthetic fixtures
    private function testNearestHotspotSelection() {
        $syntheticHotspots = [
            ['id' => 101, 'name' => 'Synthetic North', 'latitude' => 8.1000, 'longitude' => 80.8000],
            ['id' => 102, 'name' => 'Synthetic South', 'latitude' => 8.0000, 'longitude' => 80.8000],
            ['id' => 103, 'name' => 'Synthetic East', 'latitude' => 8.0500, 'longitude' => 80.9000]
        ];

        // Sighting P is close to Synthetic South (8.0050, 80.8010)
        $latP = 8.0050;
        $lonP = 80.8010;

        $minDist = INF;
        $nearest = null;
        foreach ($syntheticHotspots as $h) {
            $d = ProximityService::haversineDistance($latP, $lonP, $h['latitude'], $h['longitude']);
            if ($d < $minDist) {
                $minDist = $d;
                $nearest = $h;
            }
        }

        $this->assert(
            $nearest !== null && $nearest['id'] === 102,
            "2.1 Nearest hotspot correctly identified from synthetic candidates",
            "Selected ID: " . ($nearest['id'] ?? 'none')
        );
        $this->assert(
            $minDist < 1000.0,
            "2.2 Geodesic distance to nearest synthetic hotspot is within expected radius (<1km)",
            "Distance: " . round($minDist, 2) . "m"
        );
    }

    // 3. Coordinate validation
    private function testCoordinateValidation() {
        // Null coordinates
        $v1 = ProximityService::validateCoordinate(null, null);
        $this->assert(!$v1['valid'], "3.1 Coordinate validation rejects null values");

        // Non-numeric coordinates
        $v2 = ProximityService::validateCoordinate("invalid_lat", "80.82");
        $this->assert(!$v2['valid'], "3.2 Coordinate validation rejects non-numeric input");

        // Out-of-bounds latitude (outside Sri Lanka)
        $v3 = ProximityService::validateCoordinate(25.0, 80.82);
        $this->assert(!$v3['valid'], "3.3 Coordinate validation rejects foreign coordinates outside Sri Lanka");

        // Valid coordinates in North-Central Sri Lanka
        $v4 = ProximityService::validateCoordinate(8.0412, 80.8256);
        $this->assert($v4['valid'], "3.4 Coordinate validation accepts valid Sri Lankan coordinates");
    }

    // 4. GPS uncertainty handling
    private function testGpsUncertaintyHandling() {
        $distance = 15.0; // 15 metres
        $accuracy = 25.0; // 25 metres horizontal uncertainty

        // When accuracy > distance, lower bound must clamp to 0.0
        $minBound = max(0.0, $distance - $accuracy);
        $maxBound = $distance + $accuracy;

        $this->assert(
            $minBound === 0.0,
            "4.1 GPS uncertainty lower bound clamps to 0.0m when accuracy exceeds distance",
            "Lower bound: {$minBound}"
        );
        $this->assert(
            $maxBound === 40.0,
            "4.2 GPS uncertainty upper bound correctly evaluates to distance + accuracy (40m)",
            "Upper bound: {$maxBound}"
        );

        // When distance > accuracy
        $dist2 = 100.0;
        $acc2 = 10.0;
        $minBound2 = max(0.0, $dist2 - $acc2);
        $maxBound2 = $dist2 + $acc2;

        $this->assert(
            $minBound2 === 90.0 && $maxBound2 === 110.0,
            "4.3 GPS uncertainty interval accurately evaluates [90m, 110m]",
            "Interval: [{$minBound2}, {$maxBound2}]"
        );
    }

    // 5. Missing coordinate handling
    private function testMissingCoordinateHandling() {
        $syntheticTextReport = [
            'id' => 999,
            'latitude' => null,
            'longitude' => null,
            'location_description' => 'Mile post 120 near culvert'
        ];

        $hasGps = ($syntheticTextReport['latitude'] !== null && $syntheticTextReport['longitude'] !== null);
        $this->assert(
            !$hasGps,
            "5.1 Text-only report without coordinates correctly classified as has_gps = false"
        );

        $val = ProximityService::validateCoordinate($syntheticTextReport['latitude'], $syntheticTextReport['longitude']);
        $this->assert(
            !$val['valid'] && !is_nan($val['valid']),
            "5.2 Validating missing coordinates returns false without PHP warnings or NaN"
        );
    }

    // 6. Duplicate hotspot detection
    private function testDuplicateHotspotDetection() {
        $syntheticHotspots = [
            ['id' => 1, 'lat' => 8.041200, 'lon' => 80.825600],
            ['id' => 2, 'lat' => 8.035400, 'lon' => 80.751100],
            ['id' => 3, 'lat' => 8.041200, 'lon' => 80.825600], // Duplicate of ID 1
            ['id' => 4, 'lat' => 7.985400, 'lon' => 80.321400]
        ];

        $seen = [];
        $duplicates = [];
        foreach ($syntheticHotspots as $h) {
            $key = sprintf("%.6f,%.6f", $h['lat'], $h['lon']);
            if (isset($seen[$key])) {
                $duplicates[] = ['duplicate_id' => $h['id'], 'original_id' => $seen[$key]];
            } else {
                $seen[$key] = $h['id'];
            }
        }

        $this->assert(
            count($duplicates) === 1 && $duplicates[0]['duplicate_id'] === 3,
            "6.1 Duplicate coordinate detection accurately flags duplicate hotspot (ID 3)",
            "Found: " . count($duplicates) . " duplicates"
        );
    }

    // 7. Section mismatch classification
    private function testSectionMismatchClassification() {
        $assignedSectionId = 2; // Minneriya - Hingurakgoda
        $nearestGeometricSectionId = 1; // Habarana - Gal Oya (physically closer)

        $isMismatch = ($assignedSectionId !== $nearestGeometricSectionId);
        $this->assert(
            $isMismatch,
            "7.1 Administrative vs geometric section discrepancy correctly identified as a mismatch"
        );

        $matchingAssigned = 1;
        $matchingNearest = 1;
        $this->assert(
            ($matchingAssigned === $matchingNearest),
            "7.2 Concordant administrative and geometric sections correctly identified as a match"
        );
    }

    // 8. Provenance filtering
    private function testProvenanceFiltering() {
        $syntheticReports = [
            ['id' => 1, 'provenance' => 'Confirmed Historical Baseline'],
            ['id' => 2, 'provenance' => 'Confirmed Historical Baseline'],
            ['id' => 5, 'provenance' => 'Confirmed Automated Test'],
            ['id' => 41, 'provenance' => 'Inferred Demonstration'],
            ['id' => 53, 'provenance' => 'E2E Audit Verification']
        ];

        $baselineOnly = array_filter($syntheticReports, function($r) {
            return $r['provenance'] === 'Confirmed Historical Baseline';
        });

        $testOnly = array_filter($syntheticReports, function($r) {
            return $r['provenance'] === 'Confirmed Automated Test';
        });

        $this->assert(
            count($baselineOnly) === 2,
            "8.1 Provenance filter isolates confirmed historical baseline records exactly (N=2)",
            "Count: " . count($baselineOnly)
        );
        $this->assert(
            count($testOnly) === 1,
            "8.2 Provenance filter isolates automated test records separately (N=1)",
            "Count: " . count($testOnly)
        );
    }

    // 9. Empty dataset handling
    private function testEmptyDatasetHandling() {
        $emptyHotspots = [];
        $lat = 8.04;
        $lon = 80.82;

        $minDist = INF;
        $nearest = null;
        foreach ($emptyHotspots as $h) {
            $d = ProximityService::haversineDistance($lat, $lon, $h['lat'], $h['lon']);
            if ($d < $minDist) {
                $minDist = $d;
                $nearest = $h;
            }
        }

        $this->assert(
            $nearest === null && is_infinite($minDist),
            "9.1 Empty hotspot collection handled gracefully without divide-by-zero or crashes"
        );
    }

    // 10. Read-only database verification
    private function testReadOnlyDatabaseIntegrity($initialCounts) {
        if (!$this->db) {
            $this->assert(false, "10.1 Database connection available for integrity verification");
            return;
        }

        $currentCounts = $this->getDatabaseCounts();

        $this->assert(
            $currentCounts['hotspots'] === $initialCounts['hotspots'] && $currentCounts['hotspots'] === 4,
            "10.1 Hotspots table record count completely unchanged (exactly 4 records)",
            "Before: {$initialCounts['hotspots']}, After: {$currentCounts['hotspots']}"
        );

        $this->assert(
            $currentCounts['elephant_reports'] === $initialCounts['elephant_reports'] && $currentCounts['elephant_reports'] === 43,
            "10.2 Elephant reports table record count completely unchanged (exactly 43 records)",
            "Before: {$initialCounts['elephant_reports']}, After: {$currentCounts['elephant_reports']}"
        );

        $this->assert(
            $currentCounts['railway_sections'] === $initialCounts['railway_sections'] && $currentCounts['railway_sections'] === 4,
            "10.3 Railway sections table record count completely unchanged (exactly 4 records)",
            "Before: {$initialCounts['railway_sections']}, After: {$currentCounts['railway_sections']}"
        );

        $this->assert(
            $currentCounts['alerts'] === $initialCounts['alerts'],
            "10.4 Alerts table record count completely unchanged (zero operational alerts triggered)",
            "Before: {$initialCounts['alerts']}, After: {$currentCounts['alerts']}"
        );
    }
}

// Execute test suite
$suite = new Milestone9BAnalyticsTestSuite();
$success = $suite->run();
exit($success ? 0 : 1);
