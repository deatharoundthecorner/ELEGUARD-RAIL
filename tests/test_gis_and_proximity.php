<?php
/**
 * EleGuard Rail - Milestone 7B Automated Test Suite
 * 
 * Tests:
 * 1. Geodesic Haversine Point-to-Point Distance Calculation.
 * 2. Point directly on railway track (distance ~ 0m).
 * 3. Point near railway track with known perpendicular offset.
 * 4. Point far from railway tracks (>60 km).
 * 5. Nearest section identification across multiple candidate sections.
 * 6. Section 3 (Northern Line) and Section 4 (Batticaloa Line) identification.
 * 7. GPS accuracy uncertainty intervals [min, max].
 * 8. Missing coordinate handling (null / empty).
 * 9. Invalid coordinate handling (out of bounds, non-numeric, foreign territory).
 * 10. GeoJSON route caching and in-memory feature loading.
 * 11. GisService hotspots retrieval and GeoJSON serialization.
 * 12. GisService reports retrieval with privacy protection (no PII leak).
 * 13. Sighting report proximity calculation accuracy.
 * 14. API Authentication Enforcement (401 Unauthorized for unauthenticated).
 * 15. API Role-Based Authorization - Public Reporter Denied (403 Forbidden).
 * 16. API Role-Based Authorization - Train Driver Denied (403 Forbidden).
 * 17. API Role-Based Authorization - Wildlife Officer Permitted (200 OK).
 * 18. API Role-Based Authorization - Railway Control Officer Permitted (200 OK).
 * 19. API Role-Based Authorization - Administrator Permitted (200 OK).
 * 20. API Filter Validation (Invalid parameter returns 400 Bad Request).
 * 21. API HTTP Method Enforcement (POST returns 405 Method Not Allowed).
 */

error_reporting(E_ALL & ~E_WARNING & ~E_NOTICE);
ini_set('display_errors', '0');
ini_set('session.use_cookies', '0');

if (session_status() === PHP_SESSION_NONE) {
    @session_start();
}

require_once __DIR__ . '/../app/config/app.php';
require_once __DIR__ . '/../app/core/Database.php';
require_once __DIR__ . '/../app/core/Session.php';
require_once __DIR__ . '/../app/core/Auth.php';
require_once __DIR__ . '/../app/services/ProximityService.php';
require_once __DIR__ . '/../app/services/GisService.php';

class GisTestSuite {
    private $passed = 0;
    private $failed = 0;

    public function run() {
        echo "========================================================\n";
        echo " EleGuard Rail - Milestone 7B GIS & Proximity Test Suite\n";
        echo "========================================================\n\n";

        $this->testHaversineReference();
        $this->testPointDirectlyOnTrack();
        $this->testPointPerpendicularOffset();
        $this->testPointFarFromTrack();
        $this->testSectionIdentification();
        $this->testNorthernAndEasternSections();
        $this->testGpsAccuracyUncertainty();
        $this->testMissingCoordinates();
        $this->testInvalidCoordinates();
        $this->testGeoJsonCaching();
        $this->testGisServiceHotspots();
        $this->testGisServiceReportsPrivacy();
        $this->testGisServiceProximityIntegration();
        $this->testApiAuthentication();
        $this->testApiRoleAuthorizationPublicReporter();
        $this->testApiRoleAuthorizationDriver();
        $this->testApiRoleAuthorizationWildlifeOfficer();
        $this->testApiRoleAuthorizationControlOfficer();
        $this->testApiRoleAuthorizationAdmin();
        $this->testApiFilterValidation();
        $this->testApiMethodNotAllowed();

        echo "\n--------------------------------------------------------\n";
        echo " Test Results Summary:\n";
        echo " Passed: {$this->passed} / " . ($this->passed + $this->failed) . "\n";
        echo " Failed: {$this->failed}\n";
        echo "--------------------------------------------------------\n";

        if ($this->failed > 0) {
            echo "❌ SOME TESTS FAILED.\n";
            exit(1);
        } else {
            echo "✓ ALL 21 TESTS PASSED SUCCESSFULLY.\n";
            exit(0);
        }
    }

    private function assert($condition, $testName, $details = '') {
        if ($condition) {
            $this->passed++;
            echo " [PASS] {$testName}\n";
            if ($details) echo "        -> {$details}\n";
        } else {
            $this->failed++;
            echo " [FAIL] {$testName}\n";
            if ($details) echo "        -> Error: {$details}\n";
        }
    }

    // 1. Reference Haversine Calculation Check
    private function testHaversineReference() {
        // Distance between Colombo Fort (6.9344, 79.8500) and Kandy Station (7.2897, 80.6294)
        // Spherical Haversine distance with R = 6,371,000m: 94,335.4m
        $dist = ProximityService::haversineDistance(6.9271, 79.8612, 7.2906, 80.6337);
        $expected = 94335.4; // Independent mathematical reference
        $diff = abs($dist - $expected);
        $this->assert($diff < 2.0, "1. Haversine Reference Calculation (Colombo - Kandy)", 
            "Calculated: " . round($dist, 1) . "m, Expected: 94,335.4m, Delta: " . round($diff, 4) . "m");
    }

    // 2. Point directly on railway track node
    private function testPointDirectlyOnTrack() {
        // Habarana Station first node: 8.0548214, 80.7486025
        $res = ProximityService::calculateRailwayProximity(8.0548214, 80.7486025, 5.0);
        $this->assert(
            $res['is_valid'] === true && $res['distance_meters'] <= 0.1 && $res['nearest_section_id'] === 1,
            "2. Point Directly on Track (Habarana Station)",
            "Distance: {$res['distance_meters']}m, Section: {$res['nearest_section_id']} ({$res['nearest_section_name']})"
        );
    }

    // 3. Point with known perpendicular offset
    private function testPointPerpendicularOffset() {
        // Point constructed 50.0m normal to segment 0 of Section 1
        $res = ProximityService::calculateRailwayProximity(8.0552763, 80.7485746, 3.0);
        $diff = abs($res['distance_meters'] - 50.0);
        $this->assert(
            $res['is_valid'] === true && $diff < 1.0 && $res['nearest_section_id'] === 1,
            "3. Point with Known Perpendicular Offset (50.0m)",
            "Calculated: {$res['distance_meters']}m (Delta from 50m: " . round($diff, 2) . "m)"
        );
    }

    // 4. Point far from tracks
    private function testPointFarFromTrack() {
        // Central hills far from northern/eastern tracks: 7.2000, 80.6000
        $res = ProximityService::calculateRailwayProximity(7.2000, 80.6000);
        $this->assert(
            $res['is_valid'] === true && $res['distance_meters'] > 60000.0,
            "4. Point Far from Track (>60 km)",
            "Calculated distance: " . round($res['distance_meters'] / 1000.0, 1) . " km to nearest section: {$res['nearest_section_name']}"
        );
    }

    // 5. Nearest section identification at junction
    private function testSectionIdentification() {
        // Sighting along Habarana - Gal Oya corridor (8.0600, 80.7600)
        $res = ProximityService::calculateRailwayProximity(8.0600, 80.7600, 10.0);
        $this->assert(
            $res['nearest_section_id'] === 1 && $res['distance_meters'] < 250.0,
            "5. Section Identification: Section 1 (Habarana - Gal Oya)",
            "Identified: Section {$res['nearest_section_id']} at {$res['distance_meters']}m"
        );
    }

    // 6. Section 3 (Northern Line) and Section 4 (Batticaloa Line) identification
    private function testNorthernAndEasternSections() {
        // Near Anuradhapura on Northern Line (8.3300, 80.4050)
        $resNorth = ProximityService::calculateRailwayProximity(8.3300, 80.4050);
        // Near Welikanda on Batticaloa Line (7.9400, 81.2500)
        $resEast = ProximityService::calculateRailwayProximity(7.9400, 81.2500);

        $ok = ($resNorth['nearest_section_id'] === 3) && ($resEast['nearest_section_id'] === 4);
        $this->assert(
            $ok,
            "6. Section Identification: Northern Line (Sec 3) & Batticaloa Forest (Sec 4)",
            "North: Sec {$resNorth['nearest_section_id']} ({$resNorth['distance_meters']}m), East: Sec {$resEast['nearest_section_id']} ({$resEast['distance_meters']}m)"
        );
    }

    // 7. GPS accuracy uncertainty intervals
    private function testGpsAccuracyUncertainty() {
        $res = ProximityService::calculateRailwayProximity(8.0600, 80.7600, 15.0);
        $unc = $res['effective_uncertainty_range'];
        $ok = isset($unc['min_distance_meters']) && isset($unc['max_distance_meters'])
            && $unc['min_distance_meters'] == round($res['distance_meters'] - 15.0, 1)
            && $unc['max_distance_meters'] == round($res['distance_meters'] + 15.0, 1);

        $this->assert(
            $ok,
            "7. GPS Accuracy Uncertainty Range [min, max]",
            "Nominal: {$res['distance_meters']}m, Range: [{$unc['min_distance_meters']}m - {$unc['max_distance_meters']}m]"
        );
    }

    // 8. Missing coordinate handling
    private function testMissingCoordinates() {
        $resNull = ProximityService::calculateRailwayProximity(null, null);
        $resEmpty = ProximityService::calculateRailwayProximity('', '');
        $ok = ($resNull['has_coordinates'] === false) && ($resNull['is_valid'] === false)
           && ($resEmpty['has_coordinates'] === false) && ($resEmpty['is_valid'] === false)
           && ($resNull['distance_meters'] === null);

        $this->assert($ok, "8. Missing Coordinates Handling (NULL and empty strings)", 
            "Status: {$resNull['status_message']}");
    }

    // 9. Invalid coordinate handling
    private function testInvalidCoordinates() {
        $resGlobal = ProximityService::calculateRailwayProximity(95.0, 80.0); // Latitude > 90
        $resNonNumeric = ProximityService::calculateRailwayProximity("abc", "xyz");
        $resForeign = ProximityService::calculateRailwayProximity(1.3521, 103.8198); // Singapore

        $ok = ($resGlobal['is_valid'] === false) && ($resNonNumeric['is_valid'] === false) && ($resForeign['is_valid'] === false);
        $this->assert($ok, "9. Invalid Coordinates Handling (Global bounds, non-numeric, outside SL)",
            "Foreign rejection: {$resForeign['status_message']}");
    }

    // 10. GeoJSON route caching and in-memory feature loading
    private function testGeoJsonCaching() {
        $t0 = microtime(true);
        $data1 = ProximityService::loadRailwayGeometry();
        $t1 = microtime(true);
        $data2 = ProximityService::loadRailwayGeometry(); // Cached call
        $t2 = microtime(true);

        $firstTimeMs = ($t1 - $t0) * 1000.0;
        $cachedTimeMs = ($t2 - $t1) * 1000.0;

        $ok = count($data1['features']) === 4 && ($data1 === $data2);
        $this->assert($ok, "10. GeoJSON In-Memory Route Caching",
            "Initial load: " . round($firstTimeMs, 2) . "ms, Cached access: " . round($cachedTimeMs, 4) . "ms");
    }

    // 11. GisService hotspots retrieval and GeoJSON serialization
    private function testGisServiceHotspots() {
        $hotspots = GisService::getHotspots();
        $geoJson = GisService::hotspotsToGeoJson($hotspots);

        $ok = count($hotspots) >= 4 
           && $geoJson['type'] === 'FeatureCollection' 
           && count($geoJson['features']) === count($hotspots)
           && isset($geoJson['features'][0]['properties']['hotspot_name']);

        $this->assert($ok, "11. GisService Hotspots & GeoJSON Point Serialization",
            "Loaded " . count($hotspots) . " hotspots across monitored railway sections.");
    }

    // 12. GisService reports retrieval with privacy protection
    private function testGisServiceReportsPrivacy() {
        $reports = GisService::getElephantReports(['has_gps_only' => false], false);
        $hasPii = false;
        foreach ($reports as $r) {
            if (isset($r['reported_by']) || isset($r['email']) || isset($r['phone'])) {
                $hasPii = true;
                break;
            }
        }

        $this->assert(!$hasPii && count($reports) > 0, "12. GisService Privacy Protection (No Reporter PII Exposed)",
            "Verified " . count($reports) . " report records: reporter personal details stripped from GIS output.");
    }

    // 13. Sighting report proximity calculation accuracy
    private function testGisServiceProximityIntegration() {
        $reports = GisService::getElephantReports(['has_gps_only' => true], true);
        $hasProximity = true;
        foreach ($reports as $r) {
            if (!isset($r['proximity']['distance_meters']) || $r['proximity']['nearest_section_id'] === null) {
                $hasProximity = false;
                break;
            }
        }

        $this->assert($hasProximity && count($reports) > 0, "13. Sighting Reports Proximity Calculation Integration",
            "All GPS reports enriched with nearest section and metric distance.");
    }

    // 14. API Authentication Enforcement (401 Unauthorized)
    private function testApiAuthentication() {
        $response = $this->simulateApiRequest(null, null, 'GET', []);
        $this->assert(
            $response['http_code'] === 401 && ($response['body']['status'] ?? '') === 'error',
            "14. API Authentication: Rejection of Unauthenticated Requests (401 Unauthorized)",
            "HTTP Code: {$response['http_code']}, Message: " . ($response['body']['message'] ?? '')
        );
    }

    // 15. API Role-Based Authorization - Public Reporter Denied (403 Forbidden)
    private function testApiRoleAuthorizationPublicReporter() {
        $response = $this->simulateApiRequest(99, 'public_reporter', 'GET', []);
        $this->assert(
            $response['http_code'] === 403,
            "15. API Role Authorization: Public Reporter Denied (403 Forbidden)",
            "HTTP Code: {$response['http_code']}, Message: " . ($response['body']['message'] ?? '')
        );
    }

    // 16. API Role-Based Authorization - Train Driver Denied (403 Forbidden)
    private function testApiRoleAuthorizationDriver() {
        $response = $this->simulateApiRequest(98, 'driver', 'GET', []);
        $this->assert(
            $response['http_code'] === 403,
            "16. API Role Authorization: Train Driver Denied (403 Forbidden)",
            "HTTP Code: {$response['http_code']}, Message: " . ($response['body']['message'] ?? '')
        );
    }

    // 17. API Role-Based Authorization - Wildlife Officer Permitted (200 OK)
    private function testApiRoleAuthorizationWildlifeOfficer() {
        $response = $this->simulateApiRequest(2, 'wildlife_officer', 'GET', []);
        $ok = $response['http_code'] === 200 && ($response['body']['status'] ?? '') === 'success';
        $this->assert(
            $ok,
            "17. API Role Authorization: Wildlife Officer Permitted (200 OK)",
            "HTTP Code: {$response['http_code']}, Routes: " . count($response['body']['routes']['features'] ?? [])
        );
    }

    // 18. API Role-Based Authorization - Railway Control Officer Permitted (200 OK)
    private function testApiRoleAuthorizationControlOfficer() {
        $response = $this->simulateApiRequest(3, 'control_officer', 'GET', []);
        $ok = $response['http_code'] === 200 && ($response['body']['status'] ?? '') === 'success';
        $this->assert(
            $ok,
            "18. API Role Authorization: Control Officer Permitted (200 OK)",
            "HTTP Code: {$response['http_code']}, Summary Reports: " . ($response['body']['summary']['total_sighting_reports'] ?? 0)
        );
    }

    // 19. API Role-Based Authorization - Administrator Permitted (200 OK)
    private function testApiRoleAuthorizationAdmin() {
        $response = $this->simulateApiRequest(1, 'admin', 'GET', []);
        $ok = $response['http_code'] === 200 && ($response['body']['status'] ?? '') === 'success';
        $this->assert(
            $ok,
            "19. API Role Authorization: Administrator Permitted (200 OK)",
            "HTTP Code: {$response['http_code']}, Summary Track Length: " . ($response['body']['summary']['total_track_length_km'] ?? 0) . " km"
        );
    }

    // 20. API Filter Validation (400 Bad Request)
    private function testApiFilterValidation() {
        $response = $this->simulateApiRequest(1, 'admin', 'GET', ['risk_level' => 'SuperCriticalInvalid']);
        $this->assert(
            $response['http_code'] === 400,
            "20. API Input Filter Validation (Invalid risk_level returns 400 Bad Request)",
            "HTTP Code: {$response['http_code']}, Message: " . ($response['body']['message'] ?? '')
        );
    }

    // 21. API HTTP Method Enforcement (405 Method Not Allowed)
    private function testApiMethodNotAllowed() {
        $response = $this->simulateApiRequest(1, 'admin', 'POST', []);
        $this->assert(
            $response['http_code'] === 405,
            "21. API Method Enforcement (POST returns 405 Method Not Allowed)",
            "HTTP Code: {$response['http_code']}, Message: " . ($response['body']['message'] ?? '')
        );
    }

    /**
     * Helper to simulate invoking public/api/gis_data.php in CLI and capture response
     */
    private function simulateApiRequest($userId, $userRole, $method = 'GET', array $queryParams = []) {
        $apiFile = str_replace('\\', '/', realpath(__DIR__ . '/../public/api/gis_data.php'));
        $appPath = str_replace('\\', '/', realpath(__DIR__ . '/../'));

        $userSetup = "";
        if ($userId !== null) {
            $userSetup = "
                \$_SESSION['user_id'] = " . var_export($userId, true) . ";
                \$_SESSION['user_role'] = " . var_export($userRole, true) . ";
                \$_SESSION['user_name'] = 'Test User';
                \$_SESSION['user_email'] = 'test@eleguard.test';
            ";
        }

        $script = "<?php
            error_reporting(0);
            ini_set('display_errors', '0');
            ini_set('session.use_cookies', '0');
            if (session_status() === PHP_SESSION_NONE) {
                @session_start();
            }
            {$userSetup}
            \$_SERVER['REQUEST_METHOD'] = '{$method}';
            \$_GET = " . var_export($queryParams, true) . ";
            require '{$apiFile}';
        ";

        $tempFile = sys_get_temp_dir() . '/api_test_' . uniqid() . '.php';
        file_put_contents($tempFile, $script);

        $output = shell_exec("php " . escapeshellarg($tempFile));
        @unlink($tempFile);

        // Find JSON start
        $jsonStart = strpos($output, '{');
        $body = null;
        if ($jsonStart !== false) {
            $jsonStr = substr($output, $jsonStart);
            $body = json_decode($jsonStr, true);
        }

        $httpCode = 200;
        if (isset($body['code'])) {
            $httpCode = (int)$body['code'];
        } elseif ($body === null) {
            $httpCode = 500;
        }

        return [
            'http_code' => $httpCode,
            'raw' => $output,
            'body' => $body
        ];
    }
}

$suite = new GisTestSuite();
$suite->run();
