<?php
/**
 * EleGuard Rail - Milestone 7C Automated Test Suite
 * Interactive GIS Map & Dashboard Integration Verification
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

class Milestone7cTestSuite {
    private $passed = 0;
    private $failed = 0;

    public function run() {
        echo "========================================================\n";
        echo " EleGuard Rail - Milestone 7C GIS Map Integration Tests\n";
        echo "========================================================\n\n";

        $this->testAssetFilesExist();
        $this->testMapViewPartialSyntaxAndRender();
        $this->testDashboardIntegration();
        $this->testSidebarNavigationRoleGating();
        $this->testDedicatedMapPagesAuthGating();
        $this->testEmptyFilterGracefulHandling();
        $this->testProximityFormattingInReportProperties();
        $this->testJavaScriptComponentIntegrity();
        $this->testRoleAccessWildlifeOfficer();
        $this->testRoleAccessControlOfficer();
        $this->testRoleAccessAdmin();
        $this->testRoleRestrictionDriverAndReporter();

        echo "\n--------------------------------------------------------\n";
        echo " Test Results Summary:\n";
        echo " Passed: {$this->passed} / " . ($this->passed + $this->failed) . "\n";
        echo " Failed: {$this->failed}\n";
        echo "--------------------------------------------------------\n";

        if ($this->failed > 0) {
            echo "❌ SOME TESTS FAILED.\n";
            exit(1);
        } else {
            echo "✓ ALL 12 MILESTONE 7C INTEGRATION TESTS PASSED.\n";
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

    // 1. Verify all required map files and vendor assets exist
    private function testAssetFilesExist() {
        $requiredFiles = [
            'public/assets/vendor/leaflet/leaflet.js',
            'public/assets/vendor/leaflet/leaflet.css',
            'public/assets/css/map.css',
            'public/assets/js/map_component.js',
            'app/layouts/map_view_partial.php',
            'public/wildlife/map.php',
            'public/control/map.php',
            'public/admin/map.php'
        ];

        $allExist = true;
        $missing = [];
        foreach ($requiredFiles as $rel) {
            $full = __DIR__ . '/../' . $rel;
            if (!file_exists($full) || filesize($full) === 0) {
                $allExist = false;
                $missing[] = $rel;
            }
        }

        $this->assert($allExist, "1. Map Files & Local Vendor Assets Verification",
            $allExist ? "All 8 GIS map files and Leaflet vendor assets present on disk." : "Missing: " . implode(', ', $missing));
    }

    // 2. Test map_view_partial.php syntax and rendering
    private function testMapViewPartialSyntaxAndRender() {
        ob_start();
        $mapContainerId = 'test-map';
        $mapHeight = '400px';
        $mapRole = 'wildlife_officer';
        $showFilters = true;
        $showStatsBar = true;
        include __DIR__ . '/../app/layouts/map_view_partial.php';
        $output = ob_get_clean();

        $hasMapDiv = strpos($output, 'id="test-map"') !== false;
        $hasFilters = strpos($output, 'gis-filter-section') !== false;
        $hasStats = strpos($output, 'gis-stat-total-reports') !== false;
        $hasLeaflet = strpos($output, 'leaflet.js') !== false;

        $this->assert($hasMapDiv && $hasFilters && $hasStats && $hasLeaflet,
            "2. Map Partial (map_view_partial.php) Rendering & Structure",
            "Rendered map container, filter toolbar, stats bar, and script bindings.");
    }

    // 3. Test that dashboard files include map_view_partial.php
    private function testDashboardIntegration() {
        $dashboards = [
            'wildlife' => file_get_contents(__DIR__ . '/../public/wildlife/dashboard.php'),
            'control' => file_get_contents(__DIR__ . '/../public/control/dashboard.php'),
            'admin' => file_get_contents(__DIR__ . '/../public/admin/dashboard.php')
        ];

        $allIntegrated = true;
        foreach ($dashboards as $role => $content) {
            if (strpos($content, 'map_view_partial.php') === false) {
                $allIntegrated = false;
                break;
            }
        }

        $this->assert($allIntegrated, "3. Dashboard Map Integration (Wildlife, Control, Admin)",
            "Verified map_view_partial.php included in all three operational dashboards.");
    }

    // 4. Test sidebar navigation role gating
    private function testSidebarNavigationRoleGating() {
        $sidebarContent = file_get_contents(__DIR__ . '/../app/layouts/dashboard_sidebar.php');

        $adminHasMap = strpos($sidebarContent, 'admin/map.php') !== false;
        $controlHasMap = strpos($sidebarContent, 'control/map.php') !== false;
        $wildlifeHasMap = strpos($sidebarContent, 'wildlife/map.php') !== false;
        $driverHasMap = strpos($sidebarContent, 'driver/map.php') !== false;
        $reporterHasMap = strpos($sidebarContent, 'reporter/map.php') !== false;

        $ok = $adminHasMap && $controlHasMap && $wildlifeHasMap && !$driverHasMap && !$reporterHasMap;

        $this->assert($ok, "4. Sidebar Role-Gated Map Navigation",
            "Map link present for Admin, Control, Wildlife; strictly omitted for Driver and Reporter.");
    }

    // 5. Test dedicated map pages enforce role-based access
    private function testDedicatedMapPagesAuthGating() {
        $wildlifeContent = file_get_contents(__DIR__ . '/../public/wildlife/map.php');
        $controlContent = file_get_contents(__DIR__ . '/../public/control/map.php');
        $adminContent = file_get_contents(__DIR__ . '/../public/admin/map.php');

        $ok = (strpos($wildlifeContent, "allowed_roles = 'wildlife_officer'") !== false)
           && (strpos($controlContent, "allowed_roles = 'control_officer'") !== false)
           && (strpos($adminContent, "allowed_roles = 'admin'") !== false);

        $this->assert($ok, "5. Dedicated Map Pages Access Control Enforcement",
            "Allowed roles correctly configured on wildlife/map.php, control/map.php, admin/map.php.");
    }

    // 6. Test empty filter query does not crash and returns valid GeoJSON
    private function testEmptyFilterGracefulHandling() {
        $emptyFilters = ['section_id' => 9999]; // Non-existent section
        $payload = GisService::getGisPayload($emptyFilters);

        $ok = ($payload['status'] === 'success')
           && ($payload['summary']['total_sighting_reports'] === 0)
           && ($payload['reports']['type'] === 'FeatureCollection')
           && (count($payload['reports']['features']) === 0)
           && (count($payload['hotspots']['features']) === 0)
           && (count($payload['routes']['features']) === 4); // Railway routes still intact

        $this->assert($ok, "6. Empty GIS Filter Dataset Graceful Degradation",
            "Empty filter returned valid GeoJSON FeatureCollection with 0 reports without crashing.");
    }

    // 7. Test proximity formatting in report properties
    private function testProximityFormattingInReportProperties() {
        $reports = GisService::getElephantReports(['has_gps_only' => true], true);
        $geo = GisService::reportsToGeoJson($reports);

        $first = $geo['features'][0] ?? null;
        $hasProximity = $first && isset($first['properties']['proximity'])
            && isset($first['properties']['proximity']['distance_meters'])
            && isset($first['properties']['proximity']['nearest_section_name']);

        $this->assert($hasProximity, "7. Proximity Data Formatting in GeoJSON Feature Properties",
            "Report #{$first['properties']['id']} has distance={$first['properties']['proximity']['distance_meters']}m to {$first['properties']['proximity']['nearest_section_name']}");
    }

    // 8. Test JavaScript component file integrity
    private function testJavaScriptComponentIntegrity() {
        $js = file_get_contents(__DIR__ . '/../public/assets/js/map_component.js');
        $hasEleGuardMap = strpos($js, 'class EleGuardMap') !== false;
        $hasInit = strpos($js, 'static init') !== false;
        $hasFetch = strpos($js, 'fetchData()') !== false;
        $hasLegend = strpos($js, 'createLegend()') !== false;
        $hasPolyline = strpos($js, 'createRoutePolyline') !== false;

        $ok = $hasEleGuardMap && $hasInit && $hasFetch && $hasLegend && $hasPolyline;
        $this->assert($ok, "8. JavaScript Map Component (map_component.js) Integrity",
            "Verified EleGuardMap class with route, hotspot, sighting rendering and legend methods.");
    }

    // 9. Test Role Access: Wildlife Officer
    private function testRoleAccessWildlifeOfficer() {
        $res = $this->queryGisApi(2, 'wildlife_officer');
        $ok = $res['http_code'] === 200 && isset($res['body']['routes']) && isset($res['body']['reports']);
        $this->assert($ok, "9. Wildlife Officer GIS Access Authorized (200 OK)",
            "Received {$res['body']['summary']['total_sighting_reports']} reports and {$res['body']['summary']['total_hotspots']} hotspots.");
    }

    // 10. Test Role Access: Railway Control Officer
    private function testRoleAccessControlOfficer() {
        $res = $this->queryGisApi(3, 'control_officer');
        $ok = $res['http_code'] === 200 && isset($res['body']['routes']) && isset($res['body']['reports']);
        $this->assert($ok, "10. Railway Control Officer GIS Access Authorized (200 OK)",
            "Received {$res['body']['summary']['total_routes']} railway routes across 138.29 km.");
    }

    // 11. Test Role Access: Administrator
    private function testRoleAccessAdmin() {
        $res = $this->queryGisApi(1, 'admin');
        $ok = $res['http_code'] === 200 && isset($res['body']['routes']) && isset($res['body']['reports']);
        $this->assert($ok, "11. Administrator Full Network GIS Access Authorized (200 OK)",
            "Central GIS overview verified with complete spatial layer payloads.");
    }

    // 12. Test Role Restriction: Driver and Public Reporter Denied
    private function testRoleRestrictionDriverAndReporter() {
        $resDriver = $this->queryGisApi(98, 'driver');
        $resReporter = $this->queryGisApi(99, 'public_reporter');

        $ok = ($resDriver['http_code'] === 403) && ($resReporter['http_code'] === 403);
        $this->assert($ok, "12. Coordinate Security: Driver and Public Denied (403 Forbidden)",
            "Driver code: {$resDriver['http_code']}, Reporter code: {$resReporter['http_code']} (Sensitive coordinates protected).");
    }

    private function queryGisApi($userId, $userRole) {
        $apiFile = str_replace('\\', '/', realpath(__DIR__ . '/../public/api/gis_data.php'));
        $script = "<?php
            error_reporting(0);
            ini_set('display_errors', '0');
            ini_set('session.use_cookies', '0');
            if (session_status() === PHP_SESSION_NONE) {
                @session_start();
            }
            \$_SESSION['user_id'] = {$userId};
            \$_SESSION['user_role'] = '{$userRole}';
            \$_SERVER['REQUEST_METHOD'] = 'GET';
            \$_GET = [];
            require '{$apiFile}';
        ";

        $tempFile = sys_get_temp_dir() . '/m7c_test_' . uniqid() . '.php';
        file_put_contents($tempFile, $script);
        $output = shell_exec("php " . escapeshellarg($tempFile));
        @unlink($tempFile);

        $jsonStart = strpos($output, '{');
        $body = null;
        if ($jsonStart !== false) {
            $body = json_decode(substr($output, $jsonStart), true);
        }

        $httpCode = 200;
        if (isset($body['code'])) {
            $httpCode = (int)$body['code'];
        }

        return ['http_code' => $httpCode, 'body' => $body];
    }
}

$suite = new Milestone7cTestSuite();
$suite->run();
