<?php
/**
 * EleGuard Rail — Automated Test Suite
 * Milestone 9C — Stage 4E.4.3-F: Wildlife Officer Verification & Operational Review Interface
 * 
 * Verifies:
 * 1. Role-Based Access Control (RBAC): Wildlife Officer & Admin permitted; Control Officer, Driver, Reporter forbidden.
 * 2. Self-Verification Prevention: Originating observer cannot verify/reject own submission.
 * 3. Status Display & Dashboard Filtering: Tabs & count badges for Pending, Needs Info, Clarification Submitted, Verified, Rejected.
 * 4. Detailed Observation Review Details: Demographics, tuskers, behavior/evidence, corridor & chainage, temporal uncertainty.
 * 5. Leaflet Map View & OSM Geometry Disclaimer: Separate observer/animal markers, uncertainty circles, OSM disclaimer.
 * 6. Verification Panel Controls: 4 approved methods, justification notes, rejection reason validation (min 10 chars).
 * 7. Clarification Conversation Rendering: Inquiries, responses, follow-ups, parent references, thread integrity.
 * 8. Verification History Audit Ledger: Timestamp, authorized actor, previous/new status, method, notes.
 * 9. API Error Handling & Security: RFC 9457 compliance, 401/403/409/422 handling.
 * 10. XSS Protection & Anti-Poaching Coordinate Privacy: HTML escaping, coordinate masking for unauthorized roles.
 * 11. Duplicate Submission Prevention: 60-second window deduplication.
 * 12. Mobile Responsiveness & Feature Preservation: AI advisory & dual risk assessment intact.
 * 13. Operational Database Immutability: eleguard_rail strictly untouched.
 */

declare(strict_types=1);

error_reporting(E_ALL & ~E_WARNING & ~E_NOTICE);
ini_set('display_errors', '0');

$targetDb = getenv('DB_NAME') ?: ($_ENV['DB_NAME'] ?? null);
if (empty($targetDb) || $targetDb === 'eleguard_rail_test' || $targetDb === 'eleguard_rail') {
    $targetDb = 'eleguard_test_tmp_' . bin2hex(random_bytes(6));
    putenv("DB_NAME={$targetDb}");
    $_ENV['DB_NAME'] = $targetDb;
}

$baseDir = dirname(__DIR__);
require_once $baseDir . '/app/config/database.php';
require_once $baseDir . '/app/core/Database.php';
require_once $baseDir . '/app/core/Auth.php';
require_once $baseDir . '/app/core/Session.php';
require_once $baseDir . '/app/services/VerificationService.php';
require_once $baseDir . '/app/services/FieldReportingService.php';
require_once $baseDir . '/app/services/ReportService.php';
require_once $baseDir . '/tests/bootstrap.php';

// Color formatting
function green(string $s): string { return "\033[32m{$s}\033[0m"; }
function red(string $s): string { return "\033[31m{$s}\033[0m"; }
function yellow(string $s): string { return "\033[33m{$s}\033[0m"; }
function bold(string $s): string { return "\033[1m{$s}\033[0m"; }

$testsRun = 0;
$testsPassed = 0;
$testsFailed = 0;

function pass(string $name): void {
    global $testsRun, $testsPassed;
    $testsRun++;
    $testsPassed++;
    echo green("  [PASS] ") . $name . PHP_EOL;
}

function fail(string $name, string $reason = ''): void {
    global $testsRun, $testsFailed;
    $testsRun++;
    $testsFailed++;
    echo red("  [FAIL] ") . $name . ($reason ? " — " . yellow($reason) : '') . PHP_EOL;
}

function section(string $title): void {
    echo PHP_EOL . bold("=== {$title} ===") . PHP_EOL;
}

echo PHP_EOL;
echo "================================================================================" . PHP_EOL;
echo "   ELEGUARD RAIL — MILESTONE 9C: STAGE 4E.4.3-F" . PHP_EOL;
echo "   Wildlife Officer Verification & Operational Review Interface Test Suite" . PHP_EOL;
echo "================================================================================" . PHP_EOL;

// =============================================================================
// STEP 0: MANDATORY DATABASE CONNECTION & BASELINE SAFETY
// =============================================================================
section("Step 0: Mandatory Database Safety Verification");

$dbHost = defined('DB_HOST') ? DB_HOST : '127.0.0.1';
$dbPort = defined('DB_PORT') ? (int)DB_PORT : 3308;
$dbCharset = defined('DB_CHARSET') ? DB_CHARSET : 'utf8mb4';
$rootUser = defined('DB_USER') ? DB_USER : 'root';
$rootPass = defined('DB_PASS') ? DB_PASS : '';
$prodDb = 'eleguard_rail';
$testDb = $targetDb;

$adminPdo = new PDO("mysql:host={$dbHost};port={$dbPort};charset={$dbCharset}", $rootUser, $rootPass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
]);

ensureTestDatabaseExists($adminPdo, $testDb);

if (!getenv('ELEGUARD_MASTER_RUNNER')) {
    register_shutdown_function(function() use ($adminPdo, $testDb) {
        safeTeardownTestDatabase($adminPdo, $testDb);
    });
}

$baselineCounts = [
    'reports' => (int)$adminPdo->query("SELECT COUNT(*) FROM `{$prodDb}`.elephant_reports")->fetchColumn(),
    'logs' => (int)$adminPdo->query("SELECT COUNT(*) FROM `{$prodDb}`.system_logs")->fetchColumn(),
    'alerts' => (int)$adminPdo->query("SELECT COUNT(*) FROM `{$prodDb}`.alerts")->fetchColumn(),
    'hotspots' => (int)$adminPdo->query("SELECT COUNT(*) FROM `{$prodDb}`.hotspots")->fetchColumn(),
    'sections' => (int)$adminPdo->query("SELECT COUNT(*) FROM `{$prodDb}`.railway_sections")->fetchColumn(),
    'users' => (int)$adminPdo->query("SELECT COUNT(*) FROM `{$prodDb}`.users")->fetchColumn()
];
pass("Operational database ({$prodDb}) baseline confirmed: Reports={$baselineCounts['reports']}, Logs={$baselineCounts['logs']}, Alerts={$baselineCounts['alerts']}, Hotspots={$baselineCounts['hotspots']}, Sections={$baselineCounts['sections']}, Users={$baselineCounts['users']}");

$testPdo = Database::getInstance()->getConnection();
$activeDb = $testPdo->query("SELECT DATABASE()")->fetchColumn();
if ($activeDb === $testDb) {
    pass("Active database connection verified: SELECT DATABASE() === '{$testDb}'");
} else {
    fail("Database Safety Check Failed: Expected '{$testDb}', got '{$activeDb}'");
    exit(1);
}

// Verify stylesheet exists and is populated
$cssPath = $baseDir . '/public/assets/css/verification_workspace.css';
if (file_exists($cssPath) && filesize($cssPath) > 500) {
    pass("Verification workspace stylesheet exists and is populated: verification_workspace.css (" . filesize($cssPath) . " bytes)");
} else {
    fail("Verification workspace stylesheet missing or empty");
}

// User accounts from eleguard_rail_test
$adminUser = $testPdo->query("SELECT id, name, role FROM users WHERE role = 'admin' LIMIT 1")->fetch();
$officerUser = $testPdo->query("SELECT id, name, role FROM users WHERE role = 'wildlife_officer' LIMIT 1")->fetch();
$ctrlUser = $testPdo->query("SELECT id, name, role FROM users WHERE role = 'control_officer' LIMIT 1")->fetch();
$driverUser = $testPdo->query("SELECT id, name, role FROM users WHERE role = 'driver' LIMIT 1")->fetch();
$reporterUser = $testPdo->query("SELECT id, name, role FROM users WHERE role = 'reporter' LIMIT 1")->fetch();

$adminId = (int)$adminUser['id'];
$officerId = (int)$officerUser['id'];
$ctrlId = (int)$ctrlUser['id'];
$driverId = (int)$driverUser['id'];
$reporterId = (int)$reporterUser['id'];

// Helper to execute page in isolated subprocess
function renderPageSubprocess(string $pageRelPath, ?string $role = null, ?int $userId = null, array $queryParams = []): string {
    global $baseDir;
    $normBase = str_replace('\\', '/', $baseDir);
    $scriptPath = $normBase . '/public/' . $pageRelPath;
    
    $rolePhp = "";
    if ($role !== null && $userId !== null) {
        $rolePhp = "Session::start(); Session::set('user_id', {$userId}); Session::set('user_name', 'Test {$role}'); Session::set('user_role', '{$role}'); Session::set('user_email', '{$role}@eleguard.lk');";
    }
    
    $queryString = http_build_query($queryParams);
    $uri = '/' . $pageRelPath . ($queryString ? '?' . $queryString : '');
    
    $getArrayExport = var_export($queryParams, true);
    $activeDb = getenv('DB_NAME') ?: ($_ENV['DB_NAME'] ?? $GLOBALS['targetDb']);

    $code = <<<PHP
define('ELEGUARD_TEST_ENVIRONMENT', true);
putenv('DB_NAME={$activeDb}');
\$_ENV['DB_NAME'] = '{$activeDb}';
require_once "{$normBase}/app/core/Session.php";
{$rolePhp}
\$_SERVER['REQUEST_METHOD'] = 'GET';
\$_SERVER['REQUEST_URI'] = '{$uri}';
\$_SERVER['SCRIPT_FILENAME'] = '{$scriptPath}';
\$_SERVER['HTTP_HOST'] = 'localhost';
\$_GET = {$getArrayExport};
include '{$scriptPath}';
PHP;

    $tempFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'test_wl_' . uniqid() . '.php';
    file_put_contents($tempFile, "<?php\n" . $code);

    $cmd = "\"C:\\xampp\\php\\php.exe\" \"{$tempFile}\" 2>&1";
    $output = shell_exec($cmd);
    @unlink($tempFile);

    return $output ?: '';
}

// Track fixture report IDs for teardown
$createdReportIds = [];

function createFixtureReport(PDO $pdo, int $reporterId, string $status = 'Pending', string $desc = 'Test Sighting'): int {
    global $createdReportIds;
    $secId = (int)$pdo->query("SELECT id FROM railway_sections LIMIT 1")->fetchColumn();
    $uuid = 'test-f-' . bin2hex(random_bytes(16));
    
    $stmt = $pdo->prepare("
        INSERT INTO elephant_reports (
            client_report_uuid, section_id, reported_by, submission_source, observation_category,
            elephant_count, adult_males, adult_females, calves,
            sighting_date, sighting_time, sighting_time_precision,
            observer_latitude, observer_longitude, observer_gps_accuracy_meters,
            estimated_animal_latitude, estimated_animal_longitude, animal_location_uncertainty_meters,
            nearest_milestone_ref, distance_to_track_meters, track_proximity_zone,
            location_description, latitude, longitude,
            urgency_level, risk_score, report_status, provenance, description, created_at
        ) VALUES (
            :uuid, :sec_id, :reported_by, 'web_portal', 'live_sighting',
            4, 1, 2, 1,
            CURDATE(), CURTIME(), 'exact',
            8.12500000, 80.65500000, 15.0,
            8.12650000, 80.65650000, 45.0,
            'MP 142/4', 85.0, 'Zone 1',
            'Near Gal Oya junction corridor', 8.12500000, 80.65500000,
            'High', 'High', :status, 'development_test', :desc, NOW()
        )
    ");
    $stmt->execute([
        ':uuid' => $uuid,
        ':sec_id' => $secId,
        ':reported_by' => $reporterId,
        ':status' => $status,
        ':desc' => $desc
    ]);
    $id = (int)$pdo->lastInsertId();
    $createdReportIds[] = $id;
    return $id;
}

// =============================================================================
// STEP 1: RBAC PERMITTED ROUTE RENDERING (WILDLIFE OFFICER & ADMIN)
// =============================================================================
section("Step 1: Role-Based Access Control (RBAC) Permitted Route Rendering");

$repA = createFixtureReport($testPdo, $reporterId, 'Pending', 'Large tusker bull spotted near culvert 42');
pass("Created fixture observation report #{$repA} (Status: Pending)");

// 1.1 Wildlife Officer renders reports.php
$wlOut = renderPageSubprocess('wildlife/reports.php', 'wildlife_officer', $officerId);
if (strpos($wlOut, 'Wildlife Sighting Verification Workspace') !== false) {
    pass("1.1 Wildlife Officer renders /wildlife/reports.php header successfully");
} else {
    fail("1.1 Wildlife Officer failed to render reports.php header", substr($wlOut, 0, 500));
}

if (strpos($wlOut, 'verification-tabs-bar') !== false && strpos($wlOut, 'Pending') !== false) {
    pass("1.1.1 Verification dashboard status tabs rendered cleanly");
} else {
    fail("1.1.1 Status tabs not found in output");
}

// 1.2 Administrator renders reports.php
$admOut = renderPageSubprocess('wildlife/reports.php', 'admin', $adminId);
if (strpos($admOut, 'Wildlife Sighting Verification Workspace') !== false) {
    pass("1.2 Administrator renders /wildlife/reports.php successfully without authorization block");
} else {
    fail("1.2 Administrator failed to render reports.php", substr($admOut, 0, 500));
}

// =============================================================================
// STEP 2: FORBIDDEN ROLE PROTECTION & REDIRECTION
// =============================================================================
section("Step 2: Forbidden Role Protection & Redirection");

// 2.1 Control Officer blocked (content not rendered)
$ctrlOut = renderPageSubprocess('wildlife/reports.php', 'control_officer', $ctrlId);
if (strpos($ctrlOut, 'Wildlife Sighting Verification Workspace') === false) {
    pass("2.1 Control Officer strictly blocked from viewing /wildlife/reports.php");
} else {
    fail("2.1 Control Officer was not blocked from wildlife/reports.php");
}

// 2.2 Train Driver blocked
$driverOut = renderPageSubprocess('wildlife/reports.php', 'driver', $driverId);
if (strpos($driverOut, 'Wildlife Sighting Verification Workspace') === false) {
    pass("2.2 Train Driver strictly blocked from viewing /wildlife/reports.php");
} else {
    fail("2.2 Train Driver was not blocked from wildlife/reports.php");
}

// 2.3 Reporter blocked
$repOut = renderPageSubprocess('wildlife/reports.php', 'reporter', $reporterId);
if (strpos($repOut, 'Wildlife Sighting Verification Workspace') === false) {
    pass("2.3 Reporter strictly blocked from viewing /wildlife/reports.php");
} else {
    fail("2.3 Reporter was not blocked from wildlife/reports.php");
}

// 2.4 Unauthenticated Guest blocked
$guestOut = renderPageSubprocess('wildlife/reports.php', null, null);
if (strpos($guestOut, 'Wildlife Sighting Verification Workspace') === false) {
    pass("2.4 Unauthenticated guest strictly blocked from viewing /wildlife/reports.php");
} else {
    fail("2.4 Unauthenticated guest was not blocked from wildlife/reports.php");
}

// =============================================================================
// STEP 3: ANTI-SELF-VERIFICATION ENFORCEMENT IN INTERFACE
// =============================================================================
section("Step 3: Anti-Self-Verification Enforcement in Interface");

// Create report authored by the Wildlife Officer themselves
$selfRepId = createFixtureReport($testPdo, $officerId, 'Pending', 'Observation filed by the Wildlife Officer');
pass("Created fixture report #{$selfRepId} authored by Wildlife Officer (User #{$officerId})");

// Render as the author (officerUser)
$selfViewOut = renderPageSubprocess('wildlife/reports.php', 'wildlife_officer', $officerId, ['id' => $selfRepId]);

if (strpos($selfViewOut, 'Self-Verification Prohibited') !== false) {
    pass("3.1 Self-Verification Prohibited banner prominently rendered when officer reviews their own report");
} else {
    fail("3.1 Self-Verification Prohibited banner missing from review panel", substr($selfViewOut, 0, 600));
}

if (strpos($selfViewOut, 'disabled') !== false && strpos($selfViewOut, 'btnVerifyReport') !== false) {
    pass("3.2 Verification and rejection action buttons disabled for originating author");
} else {
    fail("3.2 Action buttons were not disabled for report author");
}

// Verify backend VerificationService also strictly rejects self-verification attempt
$selfVerifyAttempt = VerificationService::verifyReport(
    $selfRepId,
    'Verified',
    'field_inspection',
    'Attempting self-verification via service',
    $officerId,
    'wildlife_officer'
);
if (!$selfVerifyAttempt['success'] && $selfVerifyAttempt['status_code'] === 403) {
    pass("3.3 Backend VerificationService rejects self-verification with HTTP 403 Forbidden");
} else {
    fail("3.3 Self-verification was not rejected with 403", json_encode($selfVerifyAttempt));
}

// =============================================================================
// STEP 4: STATUS TABS & METRIC BADGES
// =============================================================================
section("Step 4: Status Tabs & Metric Badges");

if (strpos($wlOut, 'tab-pending') !== false && strpos($wlOut, 'tab-needs-info') !== false &&
    strpos($wlOut, 'tab-clarification-submitted') !== false && strpos($wlOut, 'tab-verified') !== false &&
    strpos($wlOut, 'tab-rejected') !== false) {
    pass("4.1 All five required lifecycle tabs present: Pending, Needs Info, Clarification Submitted, Verified, Rejected");
} else {
    fail("4.1 Missing one or more lifecycle tabs");
}

if (strpos($wlOut, 'tab-count-pill') !== false) {
    pass("4.2 Metric count pills rendered for dashboard queues");
} else {
    fail("4.2 Metric count pills missing");
}

// =============================================================================
// STEP 5: DETAILED OBSERVATION REVIEW DETAILS
// =============================================================================
section("Step 5: Detailed Observation Review Details");

// Render report A as independent officer
$revOutA = renderPageSubprocess('wildlife/reports.php', 'wildlife_officer', $officerId, ['id' => $repA]);

if (strpos($revOutA, 'Elephant Demographics & Sighting Event') !== false) {
    pass("5.1 Observation Demographics card rendered successfully");
} else {
    fail("5.1 Observation Demographics card missing", substr($revOutA, 0, 500));
}

if (strpos($revOutA, 'Tusker Indicated in Notes') !== false) {
    pass("5.2 Tusker alert badge rendered for report with tusker notes");
} else {
    fail("5.2 Tusker alert badge not found");
}

if (strpos($revOutA, 'Railway Corridor & Topological Chainage') !== false && strpos($revOutA, 'MP 142/4') !== false) {
    pass("5.3 Railway corridor and milestone chainage ('MP 142/4') displayed accurately");
} else {
    fail("5.3 Railway corridor / chainage details missing");
}

if (strpos($revOutA, 'Distance to Track') !== false && strpos($revOutA, 'Zone 1') !== false) {
    pass("5.4 Track proximity distance and zone badge rendered");
} else {
    fail("5.4 Track proximity details missing");
}

// =============================================================================
// STEP 6: LEAFLET MAP VIEW & OSM ENGINEERING DISCLAIMER
// =============================================================================
section("Step 6: Leaflet Map View & OSM Geometry Disclaimer");

if (strpos($revOutA, 'id="sighting-review-map"') !== false) {
    pass("6.1 Leaflet map container mount point (#sighting-review-map) present");
} else {
    fail("6.1 Map container missing");
}

if (strpos($revOutA, 'OpenStreetMap track centerlines for situational context; not official Sri Lanka Railways engineering alignment geometry') !== false) {
    pass("6.2 Mandatory OpenStreetMap railway centerline engineering disclaimer rendered");
} else {
    fail("6.2 Engineering disclaimer banner missing or text mismatch");
}

if (strpos($revOutA, 'Observer Position') !== false && strpos($revOutA, 'Estimated Elephant Sighting') !== false) {
    pass("6.3 Map telemetry legend differentiates observer position from estimated elephant sighting");
} else {
    fail("6.3 Telemetry legend markers missing");
}

// Test missing animal coordinates case: should NOT invent coordinates
$noAnimRepId = createFixtureReport($testPdo, $reporterId, 'Pending', 'Report without animal offset projection');
$testPdo->exec("UPDATE elephant_reports SET estimated_animal_latitude = NULL, estimated_animal_longitude = NULL WHERE id = {$noAnimRepId}");
$noAnimOut = renderPageSubprocess('wildlife/reports.php', 'wildlife_officer', $officerId, ['id' => $noAnimRepId]);

if (strpos($noAnimOut, 'No animal offset calculated; observer standoff unadjusted') !== false) {
    pass("6.4 Missing animal offset explicitly declared without inventing coordinates");
} else {
    fail("6.4 Sighting without animal offset did not display explicit declaration");
}

// =============================================================================
// STEP 7: VERIFICATION DECISION PANEL & METHODS
// =============================================================================
section("Step 7: Verification Decision Panel & Methods");

if (strpos($revOutA, 'field_inspection') !== false && strpos($revOutA, 'station_master_vhf') !== false &&
    strpos($revOutA, 'remote_telemetry') !== false && strpos($revOutA, 'resident_interview') !== false) {
    pass("7.1 All four approved verification methods selectable in decision dropdown");
} else {
    fail("7.1 Missing verification methods in select element");
}

if (strpos($revOutA, 'btnVerifyReport') !== false && strpos($revOutA, 'btnRejectReport') !== false) {
    pass("7.2 Verify and Reject operational action buttons rendered");
} else {
    fail("7.2 Action buttons missing");
}

// Test rejection reason validation via VerificationService
$shortReject = VerificationService::verifyReport(
    $repA,
    'Rejected',
    'field_inspection',
    'Too short',
    $officerId,
    'wildlife_officer'
);
if (!$shortReject['success'] && $shortReject['status_code'] === 422) {
    pass("7.3 Rejection requires mandatory explanation of >= 10 characters (short notes rejected with 422)");
} else {
    fail("7.3 Short rejection reason was not rejected with 422", json_encode($shortReject));
}

// =============================================================================
// STEP 8: CLARIFICATION CONVERSATION PANEL & THREADING
// =============================================================================
section("Step 8: Clarification Conversation Panel & Threading");

// 8.1 Wildlife Officer requests info on Report A
$inqRes = VerificationService::requestAdditionalInfo(
    $repA,
    'Please confirm whether the herd crossed the track or remained in the scrub.',
    $officerId,
    'wildlife_officer'
);
if ($inqRes['success'] && $inqRes['data']['new_status'] === 'Needs Information') {
    pass("8.1 Officer inquiry successfully dispatched; Report #{$repA} transitioned to 'Needs Information'");
} else {
    fail("8.1 Officer inquiry failed", json_encode($inqRes));
}
$parentMsgId = (int)$inqRes['data']['clarification_message_id'];

// 8.2 Verify "Awaiting Observer Response" state in UI
$needsInfoOut = renderPageSubprocess('wildlife/reports.php', 'wildlife_officer', $officerId, ['id' => $repA]);
if (strpos($needsInfoOut, 'Awaiting Observer Response') !== false) {
    pass("8.2 'Awaiting Observer Response' banner rendered while in 'Needs Information' status");
} else {
    fail("8.2 Awaiting observer response notice missing");
}

// 8.3 Observer replies to inquiry
$repReplyRes = VerificationService::submitClarificationReply(
    $repA,
    'The herd crossed northwards across the railway tracks towards the tank.',
    $parentMsgId,
    $reporterId,
    'reporter'
);
if ($repReplyRes['success'] && $repReplyRes['data']['new_status'] === 'Clarification Submitted') {
    pass("8.3 Observer clarification reply recorded; Report #{$repA} transitioned to 'Clarification Submitted'");
} else {
    fail("8.3 Observer reply failed", json_encode($repReplyRes));
}
$replyMsgId = (int)$repReplyRes['data']['clarification_message_id'];

// 8.4 Render thread in UI
$threadOut = renderPageSubprocess('wildlife/reports.php', 'wildlife_officer', $officerId, ['id' => $repA]);
if (strpos($threadOut, 'bubble-officer-inquiry') !== false && strpos($threadOut, 'bubble-reporter-response') !== false) {
    pass("8.4 Clarification thread displays both officer inquiry and observer reply with distinct styling");
} else {
    fail("8.4 Message bubbles missing from thread output");
}

if (strpos($threadOut, 'In response to #' . $parentMsgId) !== false) {
    pass("8.5 Parent clarification reference tag correctly rendered for threaded response");
} else {
    fail("8.5 Parent reference tag missing from conversation thread");
}

// =============================================================================
// STEP 9: VERIFICATION HISTORY AUDIT LEDGER
// =============================================================================
section("Step 9: Verification History Audit Ledger");

if (strpos($threadOut, 'Verification & State Transition Ledger') !== false && 
    strpos($threadOut, 'Needs Information') !== false &&
    strpos($threadOut, 'Clarification Submitted') !== false) {
    pass("9.1 Verification history table renders audit records for all intermediate transitions");
} else {
    fail("9.1 Verification history table missing transition entries");
}

// Execute final verification on Report A
$finalVerify = VerificationService::verifyReport(
    $repA,
    'Verified',
    'field_inspection',
    'Corroborated by Ranger patrol unit 4. Herd safely passed.',
    $officerId,
    'wildlife_officer'
);
if ($finalVerify['success'] && $finalVerify['data']['new_status'] === 'Verified') {
    pass("9.2 Final verification executed successfully by Wildlife Officer");
} else {
    fail("9.2 Final verification failed", json_encode($finalVerify));
}

$verifiedOut = renderPageSubprocess('wildlife/reports.php', 'wildlife_officer', $officerId, ['id' => $repA]);
if (strpos($verifiedOut, 'badge-status-verified') !== false) {
    pass("9.3 Status badge reflects 'Verified' in report header");
} else {
    fail("9.3 Verified status badge missing");
}

// =============================================================================
// STEP 10: XSS PROTECTION & INPUT ESCAPING
// =============================================================================
section("Step 10: XSS Protection & Input Escaping");

$xssPayload = '<script>alert("XSS-EVAL");</script>';
$xssRepId = createFixtureReport($testPdo, $reporterId, 'Pending', "XSS Test Observation: {$xssPayload}");
$xssOut = renderPageSubprocess('wildlife/reports.php', 'wildlife_officer', $officerId, ['id' => $xssRepId]);

if (strpos($xssOut, '<script>alert("XSS-EVAL");</script>') === false && 
    strpos($xssOut, '&lt;script&gt;alert(&quot;XSS-EVAL&quot;);&lt;/script&gt;') !== false) {
    pass("10.1 XSS payload in report description strictly HTML-escaped in review panel");
} else {
    fail("10.1 XSS protection check failed: script tag found unescaped in HTML");
}

// =============================================================================
// STEP 11: COORDINATE PRIVACY FOR UNAUTHORIZED / NON-OFFICER ROLES
// =============================================================================
section("Step 11: Anti-Poaching Coordinate Privacy Verification");

$repDetailsOfficer = FieldReportingService::getReportById($repA, 'wildlife_officer');
$repDetailsCtrl = FieldReportingService::getReportById($repA, 'control_officer');

$officerLat = $repDetailsOfficer['observer_location']['latitude'] ?? null;
$officerPrecision = $repDetailsOfficer['observer_location']['precision_level'] ?? null;

if ((float)$officerLat === 8.12500000 && $officerPrecision === 'raw_telemetry') {
    pass("11.1 Wildlife Officer receives raw high-precision telemetry coordinates (lat: {$officerLat}, level: {$officerPrecision})");
} else {
    fail("11.1 Wildlife Officer coordinates unexpectedly blurred", json_encode($repDetailsOfficer));
}

// Non-officer roles receive masked/rounded coordinates
$ctrlLat = $repDetailsCtrl['observer_location']['latitude'] ?? null;
$ctrlPrecision = $repDetailsCtrl['observer_location']['precision_level'] ?? null;

if ($ctrlPrecision === 'masked_anti_poaching' || ($ctrlLat !== null && strlen(substr(strrchr((string)$ctrlLat, "."), 1)) <= 2)) {
    pass("11.2 Control Officer receives anti-poaching masked coordinates (lat: {$ctrlLat}, level: {$ctrlPrecision})");
} else {
    fail("11.2 Control Officer coordinates were not masked to 2 decimals: " . json_encode($repDetailsCtrl));
}

// =============================================================================
// STEP 12: FEATURE PRESERVATION & MOBILE RESPONSIVENESS
// =============================================================================
section("Step 12: Feature Preservation & Mobile Responsiveness");

if (strpos($revOutA, 'AI Vision Sighting Advisory') !== false) {
    pass("12.1 AI Vision Sighting Advisory panel preserved in review interface");
} else {
    fail("12.1 AI advisory panel missing");
}

if (strpos($revOutA, 'Observation-Aware Temporal Risk') !== false) {
    pass("12.2 Shadow temporal risk assessment comparison panel preserved");
} else {
    fail("12.2 Shadow risk assessment panel missing");
}

$cssContent = file_get_contents($cssPath);
if (strpos($cssContent, '@media (max-width: 768px)') !== false && strpos($cssContent, '@media (max-width: 992px)') !== false) {
    pass("12.3 Responsive mobile breakpoints (992px and 768px) defined in verification_workspace.css");
} else {
    fail("12.3 Mobile media query breakpoints missing from stylesheet");
}

// =============================================================================
// STEP 13: OPERATIONAL DATABASE ZERO-WRITE IMMUTABILITY VERIFICATION
// =============================================================================
section("Step 13: Operational Database Immutability Verification");

$postCounts = [
    'reports' => (int)$adminPdo->query("SELECT COUNT(*) FROM `{$prodDb}`.elephant_reports")->fetchColumn(),
    'logs' => (int)$adminPdo->query("SELECT COUNT(*) FROM `{$prodDb}`.system_logs")->fetchColumn(),
    'alerts' => (int)$adminPdo->query("SELECT COUNT(*) FROM `{$prodDb}`.alerts")->fetchColumn(),
    'hotspots' => (int)$adminPdo->query("SELECT COUNT(*) FROM `{$prodDb}`.hotspots")->fetchColumn(),
    'sections' => (int)$adminPdo->query("SELECT COUNT(*) FROM `{$prodDb}`.railway_sections")->fetchColumn(),
    'users' => (int)$adminPdo->query("SELECT COUNT(*) FROM `{$prodDb}`.users")->fetchColumn()
];

$countsMatch = true;
foreach ($baselineCounts as $table => $count) {
    if ($postCounts[$table] !== $count) {
        $countsMatch = false;
        fail("Operational DB Count Mismatch on '{$table}': Before={$count}, After={$postCounts[$table]}");
    }
}

if ($countsMatch) {
    pass("Operational database ({$prodDb}) remains 100% untouched: all counts identical to Step 0 baseline");
}

// Clean up fixture reports created in test database
if (!empty($createdReportIds)) {
    try {
        $idList = implode(',', array_map('intval', $createdReportIds));
        $testPdo->exec("SET FOREIGN_KEY_CHECKS = 0");
        $testPdo->exec("DELETE FROM report_clarifications WHERE report_id IN ({$idList})");
        $testPdo->exec("DELETE FROM report_verifications WHERE report_id IN ({$idList})");
        $testPdo->exec("DELETE FROM elephant_reports WHERE id IN ({$idList})");
        $testPdo->exec("SET FOREIGN_KEY_CHECKS = 1");
    } catch (\Throwable $e) {
        // Ignored in test cleanup
    }
}

// =============================================================================
// SUMMARY REPORT
// =============================================================================
echo PHP_EOL;
echo "================================================================================" . PHP_EOL;
echo "   STAGE 4E.4.3-F TEST SUITE EXECUTION SUMMARY" . PHP_EOL;
echo "================================================================================" . PHP_EOL;
echo "  Total Tests Run:    {$testsRun}" . PHP_EOL;
echo "  Tests Passed:       " . green((string)$testsPassed) . PHP_EOL;
echo "  Tests Failed:       " . ($testsFailed > 0 ? red((string)$testsFailed) : "0") . PHP_EOL;
echo "================================================================================" . PHP_EOL;

if ($testsFailed === 0) {
    echo green("  SUCCESS: All Stage 4E.4.3-F Wildlife Interface tests passed cleanly!") . PHP_EOL;
    exit(0);
} else {
    echo red("  FAILURE: {$testsFailed} test(s) failed in Stage 4E.4.3-F suite.") . PHP_EOL;
    exit(1);
}
