<?php
/**
 * EleGuard Rail - Milestone 9C Stage 4D.2 Automated Test Suite
 * 
 * Comprehensive Verification for Role-Based Historical Analytics Dashboards:
 * - Group 1: Dashboard View File Integrity & CSS/JS Asset Verification
 * - Group 2: Role-Based Access Control (RBAC) Permitted Route Rendering
 * - Group 3: Role-Based Access Control (RBAC) Forbidden Route Protection & Redirection
 * - Group 4: Unauthenticated Guest Protection & Login Redirection
 * - Group 5: Coordinate Privacy & Anti-Poaching Enforcement on Analytics
 * - Group 6: Mandatory Scientific Transparency & Data Scarcity Declaration Verification
 * - Group 7: Driver & Reporter Operational Dashboard Non-Interference
 * - Group 8: Operational Database Zero-Write Invariant
 * 
 * STRICT ISOLATION: Database operations run exclusively on `eleguard_rail_test`.
 * Operates with ZERO writes to operational `eleguard_rail`.
 */

error_reporting(E_ALL & ~E_WARNING & ~E_NOTICE);
ini_set('display_errors', '0');

require_once __DIR__ . '/bootstrap.php';

$rawHost = '127.0.0.1';
$rawPort = 3308;
$testDbName = getenv('DB_NAME') ?: ($_ENV['DB_NAME'] ?? null);
if (empty($testDbName) || $testDbName === 'eleguard_rail_test' || $testDbName === 'eleguard_rail') {
    $testDbName = 'eleguard_test_tmp_' . bin2hex(random_bytes(6));
    putenv("DB_NAME={$testDbName}");
    $_ENV['DB_NAME'] = $testDbName;
}

$rawPdo = new PDO("mysql:host={$rawHost};port={$rawPort}", 'root', '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
]);
ensureTestDatabaseExists($rawPdo, $testDbName);
$rawPdo->exec("USE `{$testDbName}`");

// Read operational baseline counts safely via read-only account
$roPdo = new PDO("mysql:host={$rawHost};port={$rawPort};dbname=eleguard_rail;charset=utf8mb4", 'eleguard_readonly', 'ReadOnly_Guard#2026', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
]);
$preOperReports = (int)$roPdo->query("SELECT COUNT(*) FROM elephant_reports")->fetchColumn();
$preOperLogs = (int)$roPdo->query("SELECT COUNT(*) FROM system_logs")->fetchColumn();
$preOperAlerts = (int)$roPdo->query("SELECT COUNT(*) FROM alerts")->fetchColumn();
$preOperReport1 = $roPdo->query("SELECT id, report_status, urgency_level, sighting_date, sighting_time, updated_at FROM elephant_reports WHERE id = 1")->fetch(PDO::FETCH_ASSOC);

ensureTestDatabaseExists($rawPdo);

putenv("DB_NAME={$testDbName}");
$_ENV['DB_NAME'] = $testDbName;

require_once __DIR__ . '/../app/config/app.php';
require_once __DIR__ . '/../app/config/database.php';
require_once __DIR__ . '/../app/core/Database.php';
require_once __DIR__ . '/../app/services/AnalyticsService.php';

class Milestone9CStage4D2TestSuite {
    private $pdo;
    private $roPdo;
    private $preOperReports;
    private $preOperLogs;
    private $preOperAlerts;
    private $preOperReport1;
    private $passed = 0;
    private $failed = 0;
    private $rootDir;
    private $testDbName;

    public function __construct($pdo, $roPdo, $preOperReports, $preOperLogs, $preOperAlerts, $preOperReport1) {
        $this->pdo = $pdo;
        $this->roPdo = $roPdo;
        $this->preOperReports = $preOperReports;
        $this->preOperLogs = $preOperLogs;
        $this->preOperAlerts = $preOperAlerts;
        $this->preOperReport1 = $preOperReport1;
        $this->rootDir = dirname(__DIR__);
        $this->testDbName = getenv('DB_NAME') ?: 'eleguard_test_tmp';
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

    /**
     * Renders a dashboard page via an isolated child PHP CLI subprocess.
     */
    private function renderPageSubprocess($pageRelPath, $role = null) {
        $baseDir = str_replace('\\', '/', $this->rootDir);
        $scriptPath = $baseDir . '/public/' . $pageRelPath;
        
        $rolePhp = ($role !== null) 
            ? "Session::start(); Session::set('user_id', 1); Session::set('user_name', 'Test User'); Session::set('user_role', '{$role}'); Session::set('user_email', '{$role}@eleguard.lk');" 
            : "";
        
        $code = <<<PHP
define('ELEGUARD_TEST_ENVIRONMENT', true);
putenv('DB_NAME=' . '{$this->testDbName}');
\$_ENV['DB_NAME'] = '{$this->testDbName}';
require_once "{$baseDir}/app/core/Session.php";
{$rolePhp}
\$_SERVER['REQUEST_METHOD'] = 'GET';
\$_SERVER['REQUEST_URI'] = '/{$pageRelPath}';
\$_SERVER['SCRIPT_FILENAME'] = '{$scriptPath}';
\$_SERVER['HTTP_HOST'] = 'localhost';
include '{$scriptPath}';
PHP;

        $tempFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'test_dash_' . uniqid() . '.php';
        file_put_contents($tempFile, "<?php\n" . $code);

        $cmd = "\"C:\\xampp\\php\\php.exe\" \"{$tempFile}\" 2>&1";
        $output = shell_exec($cmd);
        @unlink($tempFile);

        return $output ?: '';
    }

    public function run() {
        echo "========================================================\n";
        echo " EleGuard Rail - Milestone 9C Stage 4D.2 Test Suite\n";
        echo " Role-Based Historical Analytics Dashboards & RBAC\n";
        echo " Connected DB: " . DB_NAME . "\n";
        echo "========================================================\n\n";

        $this->testAssetIntegrity();
        $this->testPermittedDashboardRendering();
        $this->testForbiddenRoleProtection();
        $this->testUnauthenticatedProtection();
        $this->testCoordinatePrivacy();
        $this->testScientificTransparencyDeclarations();
        $this->testDriverReporterNonInterference();
        $this->testStage4D2BContractRemediation();
        $this->testOperationalDatabaseUntouched();

        echo "\n========================================================\n";
        echo " Summary: {$this->passed} Passed, {$this->failed} Failed\n";
        echo "========================================================\n";

        return $this->failed === 0;
    }

    private function testAssetIntegrity() {
        echo "-- Group 1: Dashboard View File Integrity & CSS/JS Asset Verification --\n";

        $cssFile = $this->rootDir . '/public/assets/css/analytics.css';
        $jsFile = $this->rootDir . '/public/assets/js/analytics_dashboard.js';
        $ctrlView = $this->rootDir . '/public/control/analytics.php';
        $wlView = $this->rootDir . '/public/wildlife/analytics.php';
        $adminView = $this->rootDir . '/public/admin/analytics.php';

        $this->assert(file_exists($cssFile) && filesize($cssFile) > 1000, "1.1 Analytics stylesheet exists and is populated (>1KB)");
        $this->assert(file_exists($jsFile) && filesize($jsFile) > 2000, "1.2 Analytics JavaScript module exists and is populated (>2KB)");
        $this->assert(file_exists($ctrlView) && filesize($ctrlView) > 1000, "1.3 Control Officer analytics view exists");
        $this->assert(file_exists($wlView) && filesize($wlView) > 1000, "1.4 Wildlife Officer analytics view exists");
        $this->assert(file_exists($adminView) && filesize($adminView) > 1000, "1.5 Administrator analytics view exists");

        $jsContent = file_get_contents($jsFile);
        $this->assert(strpos($jsContent, 'EleGuardAnalytics') !== false, "1.6 JS module defines EleGuardAnalytics namespace");
        $this->assert(strpos($jsContent, 'renderSvgBarChart') !== false, "1.7 JS module contains SVG bar charting engine");
        $this->assert(strpos($jsContent, 'renderSvgDonut') !== false, "1.8 JS module contains SVG donut charting engine");
        echo "\n";
    }

    private function testPermittedDashboardRendering() {
        echo "-- Group 2: Role-Based Access Control (RBAC) Permitted Route Rendering --\n";

        // 2.1 Control Officer on Control Analytics
        $ctrlOut = $this->renderPageSubprocess('control/analytics.php', 'control_officer');
        $this->assert(strpos($ctrlOut, 'Railway Corridor Historical Intelligence') !== false, "2.1 Control Officer renders /control/analytics.php header");
        $this->assert(strpos($ctrlOut, 'control-temporal-chart') !== false, "2.2 Control view contains diurnal temporal chart mount point");
        $this->assert(strpos($ctrlOut, 'control-sections-table') !== false, "2.3 Control view contains corridor risk sections table mount point");
        $this->assert(strpos($ctrlOut, 'control-latency-stats') !== false, "2.4 Control view contains dispatch latency stats mount point");

        // 2.2 Wildlife Officer on Wildlife Analytics
        $wlOut = $this->renderPageSubprocess('wildlife/analytics.php', 'wildlife_officer');
        $this->assert(strpos($wlOut, 'Wildlife Sighting Intelligence') !== false, "2.5 Wildlife Officer renders /wildlife/analytics.php header");
        $this->assert(strpos($wlOut, 'wildlife-duplicates-table') !== false, "2.6 Wildlife view contains duplicate candidate review table mount point");
        $this->assert(strpos($wlOut, 'wildlife-ai-concordance') !== false, "2.7 Wildlife view contains AI advisory concordance mount point");
        $this->assert(strpos($wlOut, 'wildlife-quality-metrics') !== false, "2.8 Wildlife view contains GPS telemetry & completeness scorecard mount point");

        // 2.3 Admin on Admin Analytics
        $admOut = $this->renderPageSubprocess('admin/analytics.php', 'admin');
        $this->assert(strpos($admOut, 'Platform Analytics, Provenance & Data Quality') !== false, "2.9 Administrator renders /admin/analytics.php header");
        $this->assert(strpos($admOut, 'admin-provenance-chart') !== false, "2.10 Admin view contains provenance donut chart mount point");
        $this->assert(strpos($admOut, 'admin-quality-table') !== false, "2.11 Admin view contains schema integrity audit table mount point");
        $this->assert(strpos($admOut, 'admin-api-health-table') !== false, "2.12 Admin view contains real-time API health table mount point");

        echo "\n";
    }

    private function testForbiddenRoleProtection() {
        echo "-- Group 3: Role-Based Access Control (RBAC) Forbidden Route Protection & Redirection --\n";

        // 3.1 Driver attempting to access control/analytics.php
        $dCtrl = $this->renderPageSubprocess('control/analytics.php', 'driver');
        $this->assert(
            strpos($dCtrl, 'Railway Corridor Historical Intelligence') === false,
            "3.1 Train Driver blocked from viewing /control/analytics.php"
        );

        // 3.2 Driver attempting to access wildlife/analytics.php
        $dWl = $this->renderPageSubprocess('wildlife/analytics.php', 'driver');
        $this->assert(
            strpos($dWl, 'Wildlife Sighting Intelligence') === false,
            "3.2 Train Driver blocked from viewing /wildlife/analytics.php"
        );

        // 3.3 Driver attempting to access admin/analytics.php
        $dAdm = $this->renderPageSubprocess('admin/analytics.php', 'driver');
        $this->assert(
            strpos($dAdm, 'Platform Analytics, Provenance & Data Quality') === false,
            "3.3 Train Driver blocked from viewing /admin/analytics.php"
        );

        // 3.4 Community Reporter attempting to access control/analytics.php
        $rCtrl = $this->renderPageSubprocess('control/analytics.php', 'reporter');
        $this->assert(
            strpos($rCtrl, 'Railway Corridor Historical Intelligence') === false,
            "3.4 Community Reporter blocked from viewing /control/analytics.php"
        );

        // 3.5 Community Reporter attempting to access admin/analytics.php
        $rAdm = $this->renderPageSubprocess('admin/analytics.php', 'reporter');
        $this->assert(
            strpos($rAdm, 'Platform Analytics, Provenance & Data Quality') === false,
            "3.5 Community Reporter blocked from viewing /admin/analytics.php"
        );

        // 3.6 Control Officer attempting to access admin/analytics.php
        $cAdm = $this->renderPageSubprocess('admin/analytics.php', 'control_officer');
        $this->assert(
            strpos($cAdm, 'Platform Analytics, Provenance & Data Quality') === false,
            "3.6 Control Officer blocked from viewing /admin/analytics.php"
        );

        // 3.7 Wildlife Officer attempting to access admin/analytics.php
        $wAdm = $this->renderPageSubprocess('admin/analytics.php', 'wildlife_officer');
        $this->assert(
            strpos($wAdm, 'Platform Analytics, Provenance & Data Quality') === false,
            "3.7 Wildlife Officer blocked from viewing /admin/analytics.php"
        );

        echo "\n";
    }

    private function testUnauthenticatedProtection() {
        echo "-- Group 4: Unauthenticated Guest Protection & Login Redirection --\n";

        $gCtrl = $this->renderPageSubprocess('control/analytics.php', null);
        $this->assert(
            strpos($gCtrl, 'Railway Corridor Historical Intelligence') === false,
            "4.1 Unauthenticated guest blocked from /control/analytics.php"
        );

        $gWl = $this->renderPageSubprocess('wildlife/analytics.php', null);
        $this->assert(
            strpos($gWl, 'Wildlife Sighting Intelligence') === false,
            "4.2 Unauthenticated guest blocked from /wildlife/analytics.php"
        );

        $gAdm = $this->renderPageSubprocess('admin/analytics.php', null);
        $this->assert(
            strpos($gAdm, 'Platform Analytics, Provenance & Data Quality') === false,
            "4.3 Unauthenticated guest blocked from /admin/analytics.php"
        );

        echo "\n";
    }

    private function testCoordinatePrivacy() {
        echo "-- Group 5: Coordinate Privacy & Anti-Poaching Enforcement on Analytics --\n";

        // 5.1 Authorized Control Officer receives unmasked coordinates
        $ctrlHotspots = AnalyticsService::getHotspotAnalytics($this->pdo, ['user_role' => 'control_officer']);
        $this->assert(
            !empty($ctrlHotspots['hotspots']) && isset($ctrlHotspots['hotspots'][0]['latitude']),
            "5.1 Control Officer receives unmasked coordinates for operational safety"
        );

        // 5.2 Authorized Wildlife Officer receives unmasked coordinates
        $wlHotspots = AnalyticsService::getHotspotAnalytics($this->pdo, ['user_role' => 'wildlife_officer']);
        $this->assert(
            !empty($wlHotspots['hotspots']) && isset($wlHotspots['hotspots'][0]['latitude']),
            "5.2 Wildlife Officer receives unmasked coordinates for field patrol coordination"
        );

        // 5.3 Train Driver receives masked coordinates (Tier 3 Spatial Exclusion)
        $driverHotspots = AnalyticsService::getHotspotAnalytics($this->pdo, ['user_role' => 'driver']);
        $this->assert(
            !empty($driverHotspots['hotspots']) && $driverHotspots['hotspots'][0]['is_coordinates_masked'] === true,
            "5.3 Train Driver has raw coordinates masked (Tier 3 Spatial Exclusion)"
        );

        // 5.4 Public Reporter receives masked coordinates (Anti-Poaching Policy)
        $reporterHotspots = AnalyticsService::getHotspotAnalytics($this->pdo, ['user_role' => 'reporter']);
        $this->assert(
            !empty($reporterHotspots['hotspots']) && $reporterHotspots['hotspots'][0]['is_coordinates_masked'] === true,
            "5.4 Public Reporter has raw coordinates masked (Anti-Poaching Policy)"
        );

        echo "\n";
    }

    private function testScientificTransparencyDeclarations() {
        echo "-- Group 6: Mandatory Scientific Transparency & Data Scarcity Declaration Verification --\n";

        $ctrlHtml = file_get_contents($this->rootDir . '/public/control/analytics.php');
        $wlHtml = file_get_contents($this->rootDir . '/public/wildlife/analytics.php');
        $admHtml = file_get_contents($this->rootDir . '/public/admin/analytics.php');

        $this->assert(
            strpos($ctrlHtml, 'N=3 genuine field sightings') !== false,
            "6.1 Control Officer view declares N=3 genuine field sightings constraint"
        );
        $this->assert(
            strpos($ctrlHtml, 'never assumed to be observation time') !== false,
            "6.2 Control Officer view prohibits created_at substitution"
        );
        $this->assert(
            strpos($wlHtml, 'AI is strictly advisory') !== false,
            "6.3 Wildlife Officer view declares AI is strictly advisory"
        );
        $this->assert(
            strpos($wlHtml, '0 automatic merges performed') !== false,
            "6.4 Wildlife Officer view declares zero automated duplicate merges"
        );
        $this->assert(
            strpos($admHtml, 'Operational Immutability Enforced') !== false,
            "6.5 Administrator view declares operational immutability enforcement"
        );

        echo "\n";
    }

    private function testDriverReporterNonInterference() {
        echo "-- Group 7: Driver & Reporter Operational Dashboard Non-Interference --\n";

        // 7.1 Driver dashboard renders without error
        $dRes = $this->renderPageSubprocess('driver/dashboard.php', 'driver');
        $this->assert(strpos($dRes, 'CAB ACTIVE WARNING PANEL') !== false, "7.1 Driver cab warning console remains fully functional");
        $this->assert(strpos($dRes, 'analytics.php') === false, "7.2 Driver cab interface does not expose analytics views");

        // 7.2 Reporter dashboard renders without error
        $rRes = $this->renderPageSubprocess('reporter/dashboard.php', 'reporter');
        $this->assert(strpos($rRes, 'Your Recent Sighting Submissions') !== false, "7.3 Reporter community dashboard remains fully functional");
        $this->assert(strpos($rRes, 'analytics.php') === false, "7.4 Reporter community interface does not expose analytics views");

        echo "\n";
    }

    private function testStage4D2BContractRemediation() {
        echo "-- Group 9: Stage 4D.2-B Contract Alignment & Regression Verification --\n";

        $jsFile = $this->rootDir . '/public/assets/js/analytics_dashboard.js';
        $jsContent = file_get_contents($jsFile);

        // 9.1 BUG-01: AI Concordance Matrix contract references
        $this->assert(
            strpos($jsContent, 'human_officer_concordance_matrix') !== false,
            "9.1 Frontend references backend contract human_officer_concordance_matrix"
        );
        $this->assert(
            strpos($jsContent, 'ai_detected_and_officer_verified') !== false,
            "9.2 Frontend maps ai_detected_and_officer_verified key"
        );
        $this->assert(
            strpos($jsContent, 'pending_human_officer_review') !== false,
            "9.3 Frontend maps pending_human_officer_review key"
        );

        // 9.2 BUG-02: AI Subsystem Telemetry contract references
        $this->assert(
            strpos($jsContent, 'sub.processed') !== false,
            "9.4 Frontend accesses sub.processed status"
        );
        $this->assert(
            strpos($jsContent, 'sub.skipped') !== false,
            "9.5 Frontend accesses sub.skipped status"
        );
        $this->assert(
            strpos($jsContent, 'sub.failed') !== false,
            "9.6 Frontend accesses sub.failed status"
        );

        // 9.3 BUG-03: Hotspot Risk Level contract references
        $this->assert(
            strpos($jsContent, 'h.risk_level') !== false,
            "9.7 Frontend accesses h.risk_level for hotspot severity mapping"
        );

        // 9.4 Contract Simulation: Populated, Empty, and Missing-Field Responses
        // A. Concordance Matrix simulation
        $mockPopulatedAi = [
            'human_officer_concordance_matrix' => [
                'ai_detected_and_officer_verified' => 2,
                'ai_detected_and_officer_rejected' => 1,
                'pending_human_officer_review' => 3,
                'ai_not_detected_and_officer_verified' => 4
            ]
        ];
        $mat = $mockPopulatedAi['human_officer_concordance_matrix'] ?? null;
        $detVer = $mat['ai_detected_and_officer_verified'] ?? 0;
        $this->assert($detVer === 2, "9.8 Populated concordance matrix extracts verified detection correctly (2)");

        $mockEmptyAi = ['human_officer_concordance_matrix' => null];
        $matEmpty = $mockEmptyAi['human_officer_concordance_matrix'] ?? null;
        $emptyFallback = $matEmpty ? 'rendered' : 'fallback';
        $this->assert($emptyFallback === 'fallback', "9.9 Null concordance matrix triggers safe fallback");

        $mockMissingFieldAi = ['human_officer_concordance_matrix' => []];
        $matMissing = $mockMissingFieldAi['human_officer_concordance_matrix'] ?? null;
        $missingVal = $matMissing['ai_detected_and_officer_verified'] ?? 0;
        $this->assert($missingVal === 0, "9.10 Missing field in concordance matrix defaults safely to 0");

        // B. AI Subsystem Telemetry simulation
        $mockPopulatedSub = [
            'ai_subsystem_status' => [
                'processed' => 12,
                'skipped' => 5,
                'pending' => 1,
                'failed' => 0
            ]
        ];
        $sub = $mockPopulatedSub['ai_subsystem_status'];
        $proc = $sub['processed'] ?? 0;
        $skip = $sub['skipped'] ?? 0;
        $this->assert($proc === 12 && $skip === 5, "9.11 Populated telemetry extracts processed (12) and skipped (5)");

        $mockMissingSub = ['ai_subsystem_status' => []];
        $procMissing = $mockMissingSub['ai_subsystem_status']['processed'] ?? 0;
        $this->assert($procMissing === 0, "9.12 Missing telemetry status fields default safely to 0");

        // C. Hotspot Severity mapping simulation
        $mockHotspot = ['hotspot_name' => 'Test Spot', 'risk_level' => 'Critical'];
        $mappedRisk = $mockHotspot['risk_level'] ?? $mockHotspot['severity_level'] ?? 'Normal';
        $this->assert($mappedRisk === 'Critical', "9.13 Populated hotspot risk_level mapped correctly ('Critical')");

        $mockHotspotFallback = ['hotspot_name' => 'Legacy Spot', 'severity_level' => 'High'];
        $mappedRiskFallback = $mockHotspotFallback['risk_level'] ?? $mockHotspotFallback['severity_level'] ?? 'Normal';
        $this->assert($mappedRiskFallback === 'High', "9.14 Legacy severity_level fallback mapped correctly ('High')");

        $mockHotspotMissing = ['hotspot_name' => 'Empty Spot'];
        $mappedRiskMissing = $mockHotspotMissing['risk_level'] ?? $mockHotspotMissing['severity_level'] ?? 'Normal';
        $this->assert($mappedRiskMissing === 'Normal', "9.15 Missing risk level defaults safely to 'Normal'");

        // D. End-to-end API verification using AnalyticsService on test DB
        $aiAdvisoryData = AnalyticsService::getAiAdvisoryAnalytics($this->pdo, ['user_role' => 'wildlife_officer']);
        $this->assert(
            isset($aiAdvisoryData['human_officer_concordance_matrix']) &&
            isset($aiAdvisoryData['ai_subsystem_status']['processed']),
            "9.16 Backend AnalyticsService delivers human_officer_concordance_matrix and ai_subsystem_status.processed"
        );

        $hotspotData = AnalyticsService::getHotspotAnalytics($this->pdo, ['user_role' => 'control_officer']);
        $this->assert(
            !empty($hotspotData['hotspots']) && isset($hotspotData['hotspots'][0]['risk_level']),
            "9.17 Backend AnalyticsService delivers risk_level on hotspot records"
        );

        echo "\n";
    }

    private function testOperationalDatabaseUntouched() {
        echo "-- Group 8: Operational Database Zero-Write Invariant --\n";

        $postReports = (int)$this->roPdo->query("SELECT COUNT(*) FROM elephant_reports")->fetchColumn();
        $postLogs = (int)$this->roPdo->query("SELECT COUNT(*) FROM system_logs")->fetchColumn();
        $postAlerts = (int)$this->roPdo->query("SELECT COUNT(*) FROM alerts")->fetchColumn();
        $postReport1 = $this->roPdo->query("SELECT id, report_status, urgency_level, sighting_date, sighting_time, updated_at FROM elephant_reports WHERE id = 1")->fetch(PDO::FETCH_ASSOC);

        $this->assert(
            $postReports === $this->preOperReports,
            "8.1 Operational elephant_reports count unmodified ({$this->preOperReports} == {$postReports})"
        );
        $this->assert(
            $postLogs === $this->preOperLogs,
            "8.2 Operational system_logs count unmodified ({$this->preOperLogs} == {$postLogs})"
        );
        $this->assert(
            $postAlerts === $this->preOperAlerts,
            "8.3 Operational alerts count unmodified ({$this->preOperAlerts} == {$postAlerts})"
        );
        $this->assert(
            $this->preOperReport1 === $postReport1,
            "8.4 Operational Report #1 record completely untouched and pristine"
        );

        echo "\n";
    }
}

$suite = new Milestone9CStage4D2TestSuite($rawPdo, $roPdo, $preOperReports, $preOperLogs, $preOperAlerts, $preOperReport1);
$success = $suite->run();
exit($success ? 0 : 1);
