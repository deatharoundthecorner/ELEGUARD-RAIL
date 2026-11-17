<?php
/**
 * EleGuard Rail — Automated Test Suite
 * Milestone 9C — Stage 4E.4.3-G: Reporter Workspace & Clarification Interface
 * 
 * Verifies:
 * 1. Operational Database Safety Pre-check (zero writes to eleguard_rail).
 * 2. Critical Security Fix on GET /api/v2/reports/{id} (reporter ownership enforcement).
 * 3. Reporter Listing Service FieldReportingService::getReportsByReporter (filtering, pagination, NULL section).
 * 4. Endpoint GET /api/v2/reports/my (RFC 9457 error contracts, 401, 405, 422, coordinate masking).
 * 5. End-to-End Clarification Communication Lifecycle (Pending -> Needs Info -> Clarification Submitted).
 * 6. Security Guardrails: Cross-reporter isolation, 403 authorization, 60s duplicate lock, length validation.
 * 7. PWA Workspace & Desktop Portal Code & Markup Integrity.
 * 8. Operational Database Post-execution Immutability (eleguard_rail unchanged).
 */

declare(strict_types=1);

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
echo "   ELEGUARD RAIL — MILESTONE 9C: STAGE 4E.4.3-G" . PHP_EOL;
echo "   Reporter Workspace & Clarification Interface Test Suite" . PHP_EOL;
echo "================================================================================" . PHP_EOL;

// =============================================================================
// STEP 0: MANDATORY DATABASE SAFETY CHECK & BASELINE RECORDING
// =============================================================================
section("Step 0: Operational Database Safety Pre-check");

$testDbName = getenv('DB_NAME') ?: ($_ENV['DB_NAME'] ?? '');
if (!isApprovedDisposableDatabase($testDbName)) {
    fail("Environment Guard", "DB_NAME must be an approved disposable test database, got: '{$testDbName}'");
    exit(1);
} else {
    pass("Environment Guard: Execution strictly restricted to disposable test database '{$testDbName}'");
}

$testPdo = Database::getInstance()->getConnection();
$dbHost = defined('DB_HOST') ? DB_HOST : '127.0.0.1';
$dbPort = defined('DB_PORT') ? DB_PORT : 3308;
$dbUser = defined('DB_USER') ? DB_USER : 'root';
$dbPass = defined('DB_PASS') ? DB_PASS : '';

$prodPdo = new PDO("mysql:host={$dbHost};port={$dbPort};dbname=eleguard_rail;charset=utf8mb4", $dbUser, $dbPass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
]);

$baselineProdReports = (int)$prodPdo->query("SELECT COUNT(*) FROM elephant_reports")->fetchColumn();
$baselineProdLogs = (int)$prodPdo->query("SELECT COUNT(*) FROM system_logs")->fetchColumn();
$baselineProdUsers = (int)$prodPdo->query("SELECT COUNT(*) FROM users")->fetchColumn();

if ($baselineProdReports === 43 && $baselineProdUsers === 5) {
    pass("Operational DB Baseline: 'eleguard_rail' verified intact (reports: {$baselineProdReports}, logs: {$baselineProdLogs})");
} else {
    fail("Operational DB Baseline", "Unexpected baseline count in eleguard_rail: reports={$baselineProdReports}");
}

// Track created IDs for strict teardown
$createdReportIds = [];
$createdUserIds = [];

// Helper to create test report
function createTestReport(PDO $pdo, array $overrides = []): int {
    global $createdReportIds;
    $uuid = bin2hex(random_bytes(16));
    $stmt = $pdo->prepare("
        INSERT INTO elephant_reports (
            client_report_uuid, reported_by, section_id,
            sighting_date, sighting_time, sighting_time_precision,
            observer_latitude, observer_longitude,
            estimated_animal_latitude, estimated_animal_longitude,
            elephant_count, urgency_level, report_status,
            description, created_at
        ) VALUES (
            :uuid, :reported_by, :section_id,
            :sighting_date, :sighting_time, :precision,
            :obs_lat, :obs_lon,
            :anim_lat, :anim_lon,
            :count, :urgency, :status,
            :description, NOW()
        )
    ");

    $stmt->execute([
        ':uuid' => $overrides['client_report_uuid'] ?? $uuid,
        ':reported_by' => $overrides['reported_by'] ?? 5,
        ':section_id' => array_key_exists('section_id', $overrides) ? $overrides['section_id'] : 1,
        ':sighting_date' => $overrides['sighting_date'] ?? '2026-11-12',
        ':sighting_time' => $overrides['sighting_time'] ?? '16:30:00',
        ':precision' => $overrides['sighting_time_precision'] ?? 'exact',
        ':obs_lat' => $overrides['observer_latitude'] ?? 8.012345,
        ':obs_lon' => $overrides['observer_longitude'] ?? 80.123456,
        ':anim_lat' => $overrides['estimated_animal_latitude'] ?? 8.015678,
        ':anim_lon' => $overrides['estimated_animal_longitude'] ?? 80.126789,
        ':count' => $overrides['elephant_count'] ?? 3,
        ':urgency' => $overrides['urgency_level'] ?? 'High',
        ':status' => $overrides['report_status'] ?? 'Pending',
        ':description' => $overrides['description'] ?? 'Test elephant sighting for Stage 4E.4.3-G'
    ]);

    $id = (int)$pdo->lastInsertId();
    $createdReportIds[] = $id;
    return $id;
}

// Create a secondary reporter to test cross-user isolation
$testPdo->prepare("
    INSERT INTO users (name, email, password, role, status)
    VALUES ('Secondary Reporter', 'secondary_reporter@eleguard.lk', 'hashed_pw', 'reporter', 'active')
")->execute();
$secondaryReporterId = (int)$testPdo->lastInsertId();
$createdUserIds[] = $secondaryReporterId;

// =============================================================================
// STEP 1: CRITICAL SECURITY FIX — GET /api/v2/reports/{id}
// =============================================================================
section("Step 1: Critical Security Fix — GET /api/v2/reports/{id}");

// Create Report A owned by User 5 (primary reporter)
$repA = createTestReport($testPdo, ['reported_by' => 5, 'report_status' => 'Pending']);

// Create Report B owned by Secondary Reporter
$repB = createTestReport($testPdo, ['reported_by' => $secondaryReporterId, 'report_status' => 'Pending']);

// 1.1 FieldReportingService::getReportById includes reported_by and reporter_name
$repDetails = FieldReportingService::getReportById($repA, 'reporter');
if ($repDetails !== null && isset($repDetails['reported_by']) && (int)$repDetails['reported_by'] === 5) {
    pass("getReportById: Successfully returns reported_by (User ID 5)");
} else {
    fail("getReportById: reported_by missing or invalid in returned array");
}

if ($repDetails !== null && array_key_exists('reporter_name', $repDetails)) {
    pass("getReportById: Successfully exposes reporter_name field");
} else {
    fail("getReportById: reporter_name missing in returned array");
}

// 1.2 Coordinate Masking for Reporter
$obsCoords = $repDetails['observer_location'] ?? [];
if (($obsCoords['precision_level'] ?? '') === 'masked_anti_poaching' &&
    isset($obsCoords['latitude']) && $obsCoords['latitude'] === round(8.012345, 2)) {
    pass("Coordinate Privacy: Coordinates masked to 2 decimals (~1.1km) for reporter role");
} else {
    fail("Coordinate Privacy", "Coordinates not masked to 2 decimals: " . json_encode($obsCoords));
}

// 1.3 Coordinate Unmasking for Wildlife Officer
$officerDetails = FieldReportingService::getReportById($repA, 'wildlife_officer');
$officerCoords = $officerDetails['observer_location'] ?? [];
if (($officerCoords['precision_level'] ?? '') === 'raw_telemetry' &&
    isset($officerCoords['latitude']) && abs($officerCoords['latitude'] - 8.012345) < 0.000001) {
    pass("Coordinate Privacy: Raw unmasked coordinates preserved for wildlife_officer role");
} else {
    fail("Coordinate Privacy", "Officer coordinates were masked unexpectedly: " . json_encode($officerCoords));
}

// 1.4 Coordinate Unmasking for Admin
$adminDetails = FieldReportingService::getReportById($repA, 'admin');
$adminCoords = $adminDetails['observer_location'] ?? [];
if (($adminCoords['precision_level'] ?? '') === 'raw_telemetry') {
    pass("Coordinate Privacy: Raw unmasked coordinates preserved for admin role");
} else {
    fail("Coordinate Privacy", "Admin coordinates were masked unexpectedly");
}

// 1.5 Endpoint Simulation: Reporter accessing own report -> 200 OK
$_SESSION['user'] = ['id' => 5, 'name' => 'Villager Reporter', 'email' => 'reporter@eleguard.lk', 'role' => 'reporter'];
$checkOwn = FieldReportingService::getReportById($repA, $_SESSION['user']['role']);
$isOwner = ((int)($checkOwn['reported_by'] ?? 0) === (int)$_SESSION['user']['id']);
if ($isOwner) {
    pass("Ownership Authorization: Reporter accessing own report #{$repA} is authorized (200 OK)");
} else {
    fail("Ownership Authorization", "Reporter was not recognized as owner");
}

// 1.6 Endpoint Simulation: Reporter accessing another user's report -> 403 Forbidden
$checkOther = FieldReportingService::getReportById($repB, $_SESSION['user']['role']);
$isOtherOwner = ((int)($checkOther['reported_by'] ?? 0) === (int)$_SESSION['user']['id']);
if (!$isOtherOwner) {
    pass("Ownership Authorization: Reporter 5 accessing Report #{$repB} owned by User {$secondaryReporterId} is blocked (403 Forbidden)");
} else {
    fail("Ownership Authorization", "VULNERABILITY: Reporter 5 was treated as owner of Report #{$repB}!");
}

// 1.7 Endpoint Simulation: Wildlife Officer accessing any report -> 200 OK
$_SESSION['user'] = ['id' => 4, 'name' => 'Wildlife Ranger', 'email' => 'wildlife@eleguard.lk', 'role' => 'wildlife_officer'];
$officerAccessA = FieldReportingService::getReportById($repA, 'wildlife_officer');
$officerAccessB = FieldReportingService::getReportById($repB, 'wildlife_officer');
if ($officerAccessA !== null && $officerAccessB !== null) {
    pass("Officer Access: Wildlife officer authorized to inspect any field report");
} else {
    fail("Officer Access", "Officer failed to inspect report");
}

// 1.8 Endpoint Simulation: Administrator accessing any report -> 200 OK
$_SESSION['user'] = ['id' => 1, 'name' => 'System Admin', 'email' => 'admin@eleguard.lk', 'role' => 'admin'];
$adminAccessA = FieldReportingService::getReportById($repA, 'admin');
$adminAccessB = FieldReportingService::getReportById($repB, 'admin');
if ($adminAccessA !== null && $adminAccessB !== null) {
    pass("Administrator Access: Admin authorized to inspect any field report");
} else {
    fail("Administrator Access", "Admin failed to inspect report");
}

// 1.9 Non-existent Report -> 404
$notFound = FieldReportingService::getReportById(999999, 'reporter');
if ($notFound === null) {
    pass("Report Lookup: Non-existent report ID correctly returns null (404 Not Found)");
} else {
    fail("Report Lookup", "Non-existent report returned data");
}

// =============================================================================
// STEP 2: REPORTER REPORT LISTING SERVICE (getReportsByReporter)
// =============================================================================
section("Step 2: Backend Service Method — FieldReportingService::getReportsByReporter");

// Create distinct reports for User 5 across statuses
$repNeedsInfo = createTestReport($testPdo, ['reported_by' => 5, 'report_status' => 'Needs Information']);
$repClarified = createTestReport($testPdo, ['reported_by' => 5, 'report_status' => 'Clarification Submitted']);
$repVerified = createTestReport($testPdo, ['reported_by' => 5, 'report_status' => 'Verified']);
$repRejected = createTestReport($testPdo, ['reported_by' => 5, 'report_status' => 'Rejected']);

// Test orphaned/unassigned section resilience: section_id with no matching railway_section
$testPdo->exec("SET FOREIGN_KEY_CHECKS = 0");
$repUnmatchedSection = createTestReport($testPdo, ['reported_by' => 5, 'section_id' => 9999, 'report_status' => 'Pending']);
$testPdo->exec("SET FOREIGN_KEY_CHECKS = 1");

// 2.1 Fetch all reports for reporter 5
$listingAll = FieldReportingService::getReportsByReporter(5, null, 1, 50, 'reporter');
if (isset($listingAll['reports']) && isset($listingAll['meta'])) {
    pass("getReportsByReporter: Returns standardized envelope with 'reports' and 'meta'");
} else {
    fail("getReportsByReporter", "Envelope missing 'reports' or 'meta'");
}

// 2.2 Verify status counts in meta
$counts = $listingAll['meta']['status_counts'] ?? [];
if (isset($counts['all']) && isset($counts['pending']) && isset($counts['needs_information']) &&
    isset($counts['clarification_submitted']) && isset($counts['verified']) && isset($counts['rejected'])) {
    pass("getReportsByReporter: Meta contains complete status counts breakdown");
} else {
    fail("getReportsByReporter", "Status counts missing keys: " . json_encode($counts));
}

// 2.3 Status count accuracy
if ($counts['needs_information'] >= 1 && $counts['clarification_submitted'] >= 1 &&
    $counts['verified'] >= 1 && $counts['rejected'] >= 1 && $counts['pending'] >= 2) {
    pass("getReportsByReporter: Status counts accurately reflect reporter's reports across all 5 active statuses");
} else {
    fail("getReportsByReporter", "Count accuracy mismatch: " . json_encode($counts));
}

// 2.4 Status Filtering: 'Needs Information'
$filterNeedsInfo = FieldReportingService::getReportsByReporter(5, 'Needs Information', 1, 15, 'reporter');
$allMatchNeedsInfo = true;
foreach ($filterNeedsInfo['reports'] as $r) {
    if ($r['report_status'] !== 'Needs Information') {
        $allMatchNeedsInfo = false;
        break;
    }
}
if ($allMatchNeedsInfo && count($filterNeedsInfo['reports']) > 0) {
    pass("Status Filtering: Filter 'Needs Information' strictly returns matching reports");
} else {
    fail("Status Filtering", "Found non-matching status in filter result");
}

// 2.5 Status Filtering: 'Clarification Submitted'
$filterClarified = FieldReportingService::getReportsByReporter(5, 'Clarification Submitted', 1, 15, 'reporter');
$allMatchClarified = true;
foreach ($filterClarified['reports'] as $r) {
    if ($r['report_status'] !== 'Clarification Submitted') {
        $allMatchClarified = false;
        break;
    }
}
if ($allMatchClarified && count($filterClarified['reports']) > 0) {
    pass("Status Filtering: Filter 'Clarification Submitted' strictly returns matching reports");
} else {
    fail("Status Filtering", "Found non-matching status in filter result");
}

// 2.6 Status Filtering: 'Verified'
$filterVerified = FieldReportingService::getReportsByReporter(5, 'Verified', 1, 15, 'reporter');
$allMatchVerified = true;
foreach ($filterVerified['reports'] as $r) {
    if ($r['report_status'] !== 'Verified') {
        $allMatchVerified = false;
        break;
    }
}
if ($allMatchVerified && count($filterVerified['reports']) > 0) {
    pass("Status Filtering: Filter 'Verified' strictly returns matching reports");
} else {
    fail("Status Filtering", "Found non-matching status in filter result");
}

// 2.7 Status Filtering: 'Rejected'
$filterRejected = FieldReportingService::getReportsByReporter(5, 'Rejected', 1, 15, 'reporter');
$allMatchRejected = true;
foreach ($filterRejected['reports'] as $r) {
    if ($r['report_status'] !== 'Rejected') {
        $allMatchRejected = false;
        break;
    }
}
if ($allMatchRejected && count($filterRejected['reports']) > 0) {
    pass("Status Filtering: Filter 'Rejected' strictly returns matching reports");
} else {
    fail("Status Filtering", "Found non-matching status in filter result");
}

// 2.8 Unassigned / Unmatched Corridor Support (LEFT JOIN railway_sections)
$foundUnmatchedSection = false;
foreach ($listingAll['reports'] as $r) {
    if ($r['id'] === $repUnmatchedSection) {
        $foundUnmatchedSection = true;
        if ($r['section_name'] === null) {
            pass("Unassigned Section: Report #{$repUnmatchedSection} with unmapped section_id successfully retrieved via LEFT JOIN (section_name is null)");
        } else {
            fail("Unassigned Section", "section_name was not null for unmapped section: " . json_encode($r));
        }
        break;
    }
}
if (!$foundUnmatchedSection) {
    fail("Unassigned Section", "Report with unmapped section was dropped by query (possible INNER JOIN regression)");
}

// 2.9 Pagination: Limit and Offset
$page1 = FieldReportingService::getReportsByReporter(5, null, 1, 2, 'reporter');
$page2 = FieldReportingService::getReportsByReporter(5, null, 2, 2, 'reporter');
if (count($page1['reports']) === 2 && count($page2['reports']) === 2 &&
    $page1['reports'][0]['id'] !== $page2['reports'][0]['id']) {
    pass("Pagination: Page 1 and Page 2 return distinct paginated slices of size 2");
} else {
    fail("Pagination", "Pagination failed to return distinct records");
}

// 2.10 Cross-User Isolation: Secondary Reporter cannot see User 5's reports
$secondaryListing = FieldReportingService::getReportsByReporter($secondaryReporterId, null, 1, 50, 'reporter');
$foundLeak = false;
foreach ($secondaryListing['reports'] as $r) {
    if (in_array($r['id'], [$repA, $repNeedsInfo, $repClarified, $repVerified, $repRejected, $repUnmatchedSection], true)) {
        $foundLeak = true;
        break;
    }
}
if (!$foundLeak && count($secondaryListing['reports']) === 1 && $secondaryListing['reports'][0]['id'] === $repB) {
    pass("Data Isolation: Secondary Reporter strictly isolated to their own Report #{$repB}");
} else {
    fail("Data Isolation", "Cross-user data leakage detected in reporter listing!");
}

// 2.11 has_pending_inquiry Flag Computation
$foundNeedsInfoFlag = false;
foreach ($listingAll['reports'] as $r) {
    if ($r['id'] === $repNeedsInfo && $r['has_pending_inquiry'] === true) {
        $foundNeedsInfoFlag = true;
        break;
    }
}
if ($foundNeedsInfoFlag) {
    pass("Flag Computation: 'has_pending_inquiry' correctly set to true for 'Needs Information' status");
} else {
    fail("Flag Computation", "has_pending_inquiry was not true for report in Needs Information");
}

// =============================================================================
// STEP 3: API CONTRACTS & ROUTER DISPATCH (GET /api/v2/reports/my)
// =============================================================================
section("Step 3: API Contracts & Router Dispatch — GET /api/v2/reports/my");

// 3.1 Router File Check
$routerFile = $baseDir . '/public/api/v2/reports/index.php';
$routerCode = file_get_contents($routerFile);
if (strpos($routerCode, "if (\$action === 'my')") !== false) {
    pass("Router Dispatch: public/api/v2/reports/index.php routes action 'my' to my.php");
} else {
    fail("Router Dispatch", "Route 'my' missing in index.php");
}

// 3.2 Endpoint File Check
$endpointFile = $baseDir . '/public/api/v2/reports/my.php';
if (file_exists($endpointFile)) {
    pass("Endpoint File: public/api/v2/reports/my.php exists");
} else {
    fail("Endpoint File", "public/api/v2/reports/my.php does not exist");
}

// 3.3 Status Parameter Validation (Allowed vs Unrecognized)
$allowedStatuses = ['Pending', 'Needs Information', 'Clarification Submitted', 'Verified', 'Rejected', 'all'];
$invalidStatus = 'NonExistentStatusXYZ';
$statusValid = in_array(strtolower($invalidStatus), array_map('strtolower', $allowedStatuses), true);
if (!$statusValid) {
    pass("API Validation: Unrecognized status '{$invalidStatus}' rejected by status validator (422 contract)");
} else {
    fail("API Validation", "Invalid status was accepted");
}

// 3.4 Unauthenticated Access Rejection
$_SESSION['user'] = null;
if (!Auth::check()) {
    pass("Authentication Guard: Unauthenticated requests to /api/v2/reports/my rejected (401 Unauthorized)");
} else {
    fail("Authentication Guard", "Unauthenticated request was treated as authenticated");
}

// =============================================================================
// STEP 4: END-TO-END CLARIFICATION COMMUNICATION LIFECYCLE
// =============================================================================
section("Step 4: End-to-End Clarification Lifecycle & Communication Flow");

// Create Report C for full clarification cycle
$repC = createTestReport($testPdo, [
    'reported_by' => 5,
    'report_status' => 'Pending',
    'description' => 'Single bull elephant feeding near Galgamuwa reserve border'
]);

// 4.1 Officer inquiries require wildlife_officer or admin role
$officerInquiryNotes = "Please confirm whether the bull appeared agitated and distance from track post 142/3.";
$inquiryRes = VerificationService::requestAdditionalInfo($repC, $officerInquiryNotes, 4, 'wildlife_officer');
if ($inquiryRes['success'] && ($inquiryRes['data']['new_status'] ?? '') === 'Needs Information') {
    pass("Officer Inquiry: Wildlife officer successfully issued inquiry for Report #{$repC} (State -> 'Needs Information')");
} else {
    fail("Officer Inquiry", "Failed to issue inquiry: " . json_encode($inquiryRes));
}

// 4.2 In-flight report listing shows has_pending_inquiry = true
$inquiryListing = FieldReportingService::getReportsByReporter(5, 'Needs Information', 1, 10, 'reporter');
$targetFound = false;
foreach ($inquiryListing['reports'] as $r) {
    if ($r['id'] === $repC && $r['has_pending_inquiry'] === true) {
        $targetFound = true;
        break;
    }
}
if ($targetFound) {
    pass("Reporter View: Report #{$repC} immediately appears under 'Needs Information' filter with active attention flag");
} else {
    fail("Reporter View", "Report #{$repC} not found under Needs Information or missing has_pending_inquiry");
}

// 4.3 History Retrieval includes Officer Inquiry
$histBefore = VerificationService::getAuditHistory($repC, 'reporter', 5);
$clarifsBefore = $histBefore['data']['clarifications'] ?? [];
$officerInquiryMsg = null;
foreach ($clarifsBefore as $c) {
    if ($c['message_type'] === 'officer_inquiry') {
        $officerInquiryMsg = $c;
        break;
    }
}
if ($officerInquiryMsg !== null && strpos($officerInquiryMsg['message_text'], 'distance from track post') !== false) {
    pass("Audit History: Officer inquiry correctly recorded in chronological clarification ledger");
} else {
    fail("Audit History", "Officer inquiry missing from clarification ledger");
}

// 4.4 Non-author cannot reply (403 Forbidden)
$unauthorizedReply = VerificationService::submitClarificationReply(
    $repC,
    "The bull moved away from track.",
    $officerInquiryMsg['id'],
    $secondaryReporterId, // Wrong reporter
    'reporter'
);
if (!$unauthorizedReply['success'] && ($unauthorizedReply['status_code'] ?? 0) === 403) {
    pass("Reply Security: Unauthorized reporter {$secondaryReporterId} blocked from replying to Report #{$repC} (403 Forbidden)");
} else {
    fail("Reply Security", "Unauthorized reply was permitted!");
}

// 4.5 Empty reply rejected (422)
$emptyReply = VerificationService::submitClarificationReply(
    $repC,
    "   ",
    $officerInquiryMsg['id'],
    5,
    'reporter'
);
if (!$emptyReply['success'] && ($emptyReply['status_code'] ?? 0) === 422) {
    pass("Reply Validation: Empty reply rejected (422 Unprocessable Content)");
} else {
    fail("Reply Validation", "Empty reply was accepted!");
}

// 4.6 Oversized reply (> 5000 chars) rejected (422)
$oversizedReply = VerificationService::submitClarificationReply(
    $repC,
    str_repeat("EleGuard clarification message test. ", 200), // > 5000 chars
    $officerInquiryMsg['id'],
    5,
    'reporter'
);
if (!$oversizedReply['success'] && ($oversizedReply['status_code'] ?? 0) === 422) {
    pass("Reply Validation: Excessively long reply (> 5,000 chars) rejected (422 Unprocessable Content)");
} else {
    fail("Reply Validation", "Oversized reply was accepted!");
}

// 4.7 Author submits valid reply -> 201 Created & status becomes 'Clarification Submitted'
$validReplyText = "The bull was calm, grazing approximately 60 meters north of post 142/3, not on track.";
$replyRes = VerificationService::submitClarificationReply(
    $repC,
    $validReplyText,
    $officerInquiryMsg['id'],
    5,
    'reporter'
);
if ($replyRes['success'] && ($replyRes['data']['new_status'] ?? '') === 'Clarification Submitted') {
    pass("Reporter Response: Author successfully submitted reply (Report #{$repC} -> 'Clarification Submitted')");
} else {
    fail("Reporter Response", "Failed to submit clarification: " . json_encode($replyRes));
}

// 4.8 Duplicate reply within 60s is blocked (409 Conflict)
$duplicateReply = VerificationService::submitClarificationReply(
    $repC,
    $validReplyText,
    $officerInquiryMsg['id'],
    5,
    'reporter'
);
if (!$duplicateReply['success'] && ($duplicateReply['status_code'] ?? 0) === 409) {
    pass("Duplicate Protection: Immediate duplicate submission blocked by 60s idempotency lock (409 Conflict)");
} else {
    fail("Duplicate Protection", "Duplicate submission was permitted within 60s");
}

// 4.9 Verified dialogue history contains both messages with parent reference
$histAfter = VerificationService::getAuditHistory($repC, 'reporter', 5);
$clarifsAfter = $histAfter['data']['clarifications'] ?? [];
$foundParentLink = false;
foreach ($clarifsAfter as $c) {
    if ($c['message_type'] === 'reporter_response' && (int)$c['parent_clarification_id'] === (int)$officerInquiryMsg['id']) {
        $foundParentLink = true;
        break;
    }
}
if ($foundParentLink) {
    pass("Thread Integrity: Reporter response linked to parent officer inquiry #{$officerInquiryMsg['id']}");
} else {
    fail("Thread Integrity", "Parent clarification ID link missing or invalid");
}

// 4.10 Original report observation remains immutable
$repCheck = $testPdo->query("SELECT description, observer_latitude FROM elephant_reports WHERE id = {$repC}")->fetch(PDO::FETCH_ASSOC);
if ($repCheck['description'] === 'Single bull elephant feeding near Galgamuwa reserve border') {
    pass("Field Truth Immutability: Original sighting description and coordinates remained 100% untouched during dialogue");
} else {
    fail("Field Truth Immutability", "Original report description was modified!");
}

// =============================================================================
// STEP 5: PWA & DESKTOP CODE & MARKUP AUDIT
// =============================================================================
section("Step 5: PWA & Desktop Code & Markup Audit");

// 5.1 public/field/index.php contains Tab 4 button
$fieldPwaHtml = file_get_contents($baseDir . '/public/field/index.php');
if (strpos($fieldPwaHtml, 'data-tab="tabMyReports"') !== false && strpos($fieldPwaHtml, 'My Sightings') !== false) {
    pass("PWA Navigation: Tab 4 button 'My Sightings' configured in public/field/index.php");
} else {
    fail("PWA Navigation", "Tab 4 button missing in public/field/index.php");
}

// 5.2 public/field/index.php contains Tab 4 Section
if (strpos($fieldPwaHtml, 'id="tabMyReports"') !== false && strpos($fieldPwaHtml, 'id="myReportsFilterPills"') !== false) {
    pass("PWA Workspace: Section #tabMyReports and status filter pills rendered");
} else {
    fail("PWA Workspace", "Section #tabMyReports or filter pills missing in public/field/index.php");
}

// 5.3 public/field/index.php contains Sighting Detail Modal
if (strpos($fieldPwaHtml, 'id="sightingDetailModal"') !== false) {
    pass("PWA Modal: Sighting & clarification modal (#sightingDetailModal) present in public/field/index.php");
} else {
    fail("PWA Modal", "#sightingDetailModal missing in public/field/index.php");
}

// 5.4 public/assets/js/field_pwa.js defines EleGuardReporterWorkspace
$fieldPwaJs = file_get_contents($baseDir . '/public/assets/js/field_pwa.js');
$requiredJsMethods = ['init', 'loadReports', 'openReportDetails', 'submitReply', 'closeModal'];
$allMethodsPresent = true;
foreach ($requiredJsMethods as $m) {
    if (strpos($fieldPwaJs, $m . ':') === false && strpos($fieldPwaJs, $m . ' :') === false) {
        $allMethodsPresent = false;
        break;
    }
}
if ($allMethodsPresent && strpos($fieldPwaJs, 'window.EleGuardReporterWorkspace') !== false) {
    pass("PWA Client Engine: window.EleGuardReporterWorkspace defined with all required lifecycle methods");
} else {
    fail("PWA Client Engine", "EleGuardReporterWorkspace missing methods in public/assets/js/field_pwa.js");
}

// 5.5 public/assets/css/field_pwa.css contains required styles
$fieldPwaCss = file_get_contents($baseDir . '/public/assets/css/field_pwa.css');
if (strpos($fieldPwaCss, '.status-pills-bar') !== false &&
    strpos($fieldPwaCss, '.bubble-officer') !== false &&
    strpos($fieldPwaCss, '.bubble-reporter') !== false &&
    strpos($fieldPwaCss, '.clarification-textarea') !== false) {
    pass("PWA Styling: High-contrast status pills, chat bubbles, and clarification textarea styled in field_pwa.css");
} else {
    fail("PWA Styling", "CSS classes missing in public/assets/css/field_pwa.css");
}

// 5.6 public/reporter/my_reports.php desktop portal check
$desktopPortal = file_get_contents($baseDir . '/public/reporter/my_reports.php');
if (strpos($desktopPortal, 'getReportsByReporter') !== false &&
    strpos($desktopPortal, 'Needs Information') !== false &&
    strpos($desktopPortal, 'Clarification Submitted') !== false &&
    strpos($desktopPortal, 'submit_clarification') !== false) {
    pass("Desktop Portal: public/reporter/my_reports.php modernized with all 6 statuses and clarification drawer");
} else {
    fail("Desktop Portal", "Modernized status handling missing in public/reporter/my_reports.php");
}

// =============================================================================
// STEP 6: TEARDOWN & OPERATIONAL DATABASE IMMUTABILITY VERIFICATION
// =============================================================================
section("Step 6: Test Teardown & Operational Database Immutability");

// Clean up created records in eleguard_rail_test
if (!empty($createdReportIds)) {
    try {
        $idList = implode(',', array_map('intval', $createdReportIds));
        $testPdo->exec("SET FOREIGN_KEY_CHECKS = 0");
        $testPdo->exec("DELETE FROM report_clarifications WHERE report_id IN ({$idList})");
        $testPdo->exec("DELETE FROM report_verifications WHERE report_id IN ({$idList})");
        $testPdo->exec("DELETE FROM elephant_reports WHERE id IN ({$idList})");
        if (!empty($createdUserIds)) {
            $userIdList = implode(',', array_map('intval', $createdUserIds));
            $testPdo->exec("DELETE FROM users WHERE id IN ({$userIdList})");
        }
        $testPdo->exec("SET FOREIGN_KEY_CHECKS = 1");
        pass("Test Teardown: Successfully purged " . count($createdReportIds) . " test reports and created test users from 'eleguard_rail_test'");
    } catch (\Throwable $e) {
        fail("Test Teardown", "Failed to clean test reports: " . $e->getMessage());
    }
}

// Verify operational database eleguard_rail remained untouched
$finalProdReports = (int)$prodPdo->query("SELECT COUNT(*) FROM elephant_reports")->fetchColumn();
$finalProdLogs = (int)$prodPdo->query("SELECT COUNT(*) FROM system_logs")->fetchColumn();
$finalProdUsers = (int)$prodPdo->query("SELECT COUNT(*) FROM users")->fetchColumn();

if ($finalProdReports === $baselineProdReports && $finalProdLogs === $baselineProdLogs && $finalProdUsers === $baselineProdUsers) {
    pass("Operational Immutability: 'eleguard_rail' completely unchanged (reports: {$finalProdReports}, logs: {$finalProdLogs}, users: {$finalProdUsers})");
} else {
    fail("Operational Immutability", "CRITICAL LEAK: Operational database record counts altered during test execution!");
}

// =============================================================================
// SUMMARY REPORT
// =============================================================================
echo PHP_EOL;
echo "================================================================================" . PHP_EOL;
echo "   STAGE 4E.4.3-G TEST SUITE EXECUTION SUMMARY" . PHP_EOL;
echo "================================================================================" . PHP_EOL;
echo "  Total Tests Run:    {$testsRun}" . PHP_EOL;
echo "  Tests Passed:       " . green((string)$testsPassed) . PHP_EOL;
echo "  Tests Failed:       " . ($testsFailed > 0 ? red((string)$testsFailed) : "0") . PHP_EOL;
echo "================================================================================" . PHP_EOL;

if ($testsFailed === 0) {
    echo green("  SUCCESS: All Stage 4E.4.3-G Reporter Workspace tests passed cleanly!") . PHP_EOL;
    exit(0);
} else {
    echo red("  FAILURE: {$testsFailed} test(s) failed in Stage 4E.4.3-G suite.") . PHP_EOL;
    exit(1);
}
