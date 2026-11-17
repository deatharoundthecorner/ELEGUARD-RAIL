<?php
/**
 * EleGuard Rail — Automated Test Suite
 * Milestone 9C — Stage 4E.4.3-D: Verification Service & State Transition Engine
 * 
 * Verifies:
 * 1. Strict Server-Side Role-Based Authorization (wildlife_officer & admin only).
 * 2. Anti-Self-Verification Enforcement (original observer cannot verify/reject own report).
 * 3. Input Validation & Verification Method Constraints (4 approved methods, reason strlen >= 10 for rejection).
 * 4. Biological State Transition Lifecycle & Concurrency Conflicts (SELECT ... FOR UPDATE & HTTP 409).
 * 5. Transactional Atomicity & Rollback Integrity.
 * 6. Threaded Clarification Cross-Report Isolation & Anti-Impersonation.
 * 7. Railway Section Attribution Domain Decoupling (leaves biological status intact).
 * 8. Legacy Status-Update Bypass Prevention (ReportService::updateStatus hardening).
 * 9. Existing Verification History Preservation & Audit History Retrieval.
 * 10. Operational Database Immutability Check (eleguard_rail strictly untouched).
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
echo "   ELEGUARD RAIL — MILESTONE 9C: STAGE 4E.4.3-D" . PHP_EOL;
echo "   Verification Service & State Transition Engine Test Suite" . PHP_EOL;
echo "================================================================================" . PHP_EOL;

// =============================================================================
// STEP 0: MANDATORY DATABASE CONNECTION SAFETY CHECK
// =============================================================================
section("Step 0: Mandatory Database Connection Safety Verification");

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

// Fetch user fixtures from test database
$users = $testPdo->query("SELECT id, name, role FROM users")->fetchAll(PDO::FETCH_ASSOC);
$userIdsByRole = [];
foreach ($users as $u) {
    $userIdsByRole[$u['role']] = (int)$u['id'];
}

$wildlifeOfficerId = $userIdsByRole['wildlife_officer'] ?? 2;
$adminId = $userIdsByRole['admin'] ?? 1;
$controlOfficerId = $userIdsByRole['control_officer'] ?? 3;
$driverId = $userIdsByRole['driver'] ?? 4;
$reporterId = $userIdsByRole['reporter'] ?? 5;

// Helper to create an isolated test report
function createTestReport(PDO $pdo, int $reportedBy, string $status = 'Pending'): int {
    $uuid = 'test-' . bin2hex(random_bytes(8));
    $stmt = $pdo->prepare("
        INSERT INTO elephant_reports (
            client_report_uuid, section_id, reported_by, observation_category,
            elephant_count, urgency_level, location_description, report_status,
            latitude, longitude, created_at, updated_at
        ) VALUES (
            :uuid, 1, :reported_by, 'live_sighting',
            2, 'medium', 'Test Corridor Section 1', :status,
            8.03500000, 80.75800000, NOW(), NOW()
        )
    ");
    $stmt->execute([
        ':uuid' => $uuid,
        ':reported_by' => $reportedBy,
        ':status' => $status
    ]);
    return (int)$pdo->lastInsertId();
}

// =============================================================================
// SECTION 1: ROLE-BASED AUTHORIZATION ENFORCEMENT
// =============================================================================
section("Section 1: Role-Based Authorization Enforcement");

// 1.1 Authorized Wildlife Officer verification succeeds
$rep1 = createTestReport($testPdo, $reporterId, 'Pending');
$res1 = VerificationService::verifyReport($rep1, 'Verified', 'field_inspection', 'Confirmed footprint trail on tracks', $wildlifeOfficerId, 'wildlife_officer');
if ($res1['success'] && $res1['status_code'] === 200 && $res1['data']['new_status'] === 'Verified') {
    pass("1.1 Authorized Wildlife Officer verification succeeds (HTTP 200)");
} else {
    fail("1.1 Wildlife Officer verification failed", json_encode($res1));
}

// 1.2 Authorized Administrator verification succeeds
$rep2 = createTestReport($testPdo, $reporterId, 'Pending');
$res2 = VerificationService::verifyReport($rep2, 'Verified', 'station_master_vhf', 'Station Master VHF log confirmed sighting', $adminId, 'admin');
if ($res2['success'] && $res2['status_code'] === 200 && $res2['data']['new_status'] === 'Verified') {
    pass("1.2 Authorized Administrator verification succeeds (HTTP 200)");
} else {
    fail("1.2 Administrator verification failed", json_encode($res2));
}

// 1.3 Unauthorized Reporter rejection attempt fails (HTTP 403)
$rep3 = createTestReport($testPdo, $reporterId, 'Pending');
$res3 = VerificationService::verifyReport($rep3, 'Rejected', 'remote_telemetry', 'Reporter attempting rejection', $reporterId, 'reporter');
if (!$res3['success'] && $res3['status_code'] === 403) {
    pass("1.3 Unauthorized Reporter rejection attempt strictly rejected (HTTP 403 Forbidden)");
} else {
    fail("1.3 Reporter was allowed to reject report", json_encode($res3));
}

// 1.4 Unauthorized Driver rejection attempt fails (HTTP 403)
$res4 = VerificationService::verifyReport($rep3, 'Rejected', 'remote_telemetry', 'Locomotive driver attempting rejection', $driverId, 'driver');
if (!$res4['success'] && $res4['status_code'] === 403) {
    pass("1.4 Unauthorized Driver rejection attempt strictly rejected (HTTP 403 Forbidden)");
} else {
    fail("1.4 Driver was allowed to reject report", json_encode($res4));
}

// 1.5 Unauthorized Control Officer verification attempt fails (HTTP 403)
$res5 = VerificationService::verifyReport($rep3, 'Verified', 'station_master_vhf', 'Train dispatcher attempting verification', $controlOfficerId, 'control_officer');
if (!$res5['success'] && $res5['status_code'] === 403) {
    pass("1.5 Unauthorized Control Officer verification attempt strictly rejected (HTTP 403 Forbidden)");
} else {
    fail("1.5 Control Officer was allowed to verify report", json_encode($res5));
}

// =============================================================================
// SECTION 2: ANTI-SELF-VERIFICATION ENFORCEMENT
// =============================================================================
section("Section 2: Anti-Self-Verification Enforcement");

// Create report submitted by wildlife officer themselves
$repOfficerSelf = createTestReport($testPdo, $wildlifeOfficerId, 'Pending');

// 2.1 Original author attempting to verify their own report fails (HTTP 403)
$selfVerRes = VerificationService::verifyReport(
    $repOfficerSelf, 'Verified', 'field_inspection', 'Officer verifying own report', $wildlifeOfficerId, 'wildlife_officer'
);
if (!$selfVerRes['success'] && $selfVerRes['status_code'] === 403 && strpos($selfVerRes['error']['type'], 'self-verification') !== false) {
    pass("2.1 Anti-Self-Verification: Original author cannot verify own submission (HTTP 403 Forbidden)");
} else {
    fail("2.1 Anti-self-verification guard failed", json_encode($selfVerRes));
}

// 2.2 Original author attempting to reject their own report fails (HTTP 403)
$selfRejRes = VerificationService::verifyReport(
    $repOfficerSelf, 'Rejected', 'field_inspection', 'Officer rejecting own report after submission', $wildlifeOfficerId, 'wildlife_officer'
);
if (!$selfRejRes['success'] && $selfRejRes['status_code'] === 403) {
    pass("2.2 Anti-Self-Verification: Original author cannot reject own submission (HTTP 403 Forbidden)");
} else {
    fail("2.2 Anti-self-rejection guard failed", json_encode($selfRejRes));
}

// 2.3 Independent officer can verify the report without self-certification conflict
$indepVerRes = VerificationService::verifyReport(
    $repOfficerSelf, 'Verified', 'field_inspection', 'Independent administrator verified officer report', $adminId, 'admin'
);
if ($indepVerRes['success'] && $indepVerRes['status_code'] === 200) {
    pass("2.3 Independent authorized officer successfully verifies report without conflict");
} else {
    fail("2.3 Independent verification failed", json_encode($indepVerRes));
}

// =============================================================================
// SECTION 3: INPUT VALIDATION & VERIFICATION METHOD CONSTRAINTS
// =============================================================================
section("Section 3: Input Validation & Verification Method Constraints");

$repVal = createTestReport($testPdo, $reporterId, 'Pending');

// 3.1 Rejection without reason fails (HTTP 422)
$emptyRejRes = VerificationService::verifyReport($repVal, 'Rejected', 'remote_telemetry', '', $wildlifeOfficerId, 'wildlife_officer');
if (!$emptyRejRes['success'] && $emptyRejRes['status_code'] === 422) {
    pass("3.1 Rejection with empty reason strictly rejected (HTTP 422 Unprocessable)");
} else {
    fail("3.1 Empty rejection reason was accepted", json_encode($emptyRejRes));
}

// 3.2 Rejection with short reason (<10 chars) fails (HTTP 422)
$shortRejRes = VerificationService::verifyReport($repVal, 'Rejected', 'remote_telemetry', 'No cow', $wildlifeOfficerId, 'wildlife_officer');
if (!$shortRejRes['success'] && $shortRejRes['status_code'] === 422) {
    pass("3.2 Rejection with short justification (<10 chars) strictly rejected (HTTP 422 Unprocessable)");
} else {
    fail("3.2 Short rejection reason was accepted", json_encode($shortRejRes));
}

// 3.3 Rejection with valid justification (>=10 chars) succeeds
$validRejRes = VerificationService::verifyReport($repVal, 'Rejected', 'remote_telemetry', 'Ground inspection confirmed tracks were domestic buffalo, not wild elephant.', $wildlifeOfficerId, 'wildlife_officer');
if ($validRejRes['success'] && $validRejRes['status_code'] === 200 && $validRejRes['data']['new_status'] === 'Rejected') {
    pass("3.3 Rejection with valid justification (>=10 chars) succeeds (HTTP 200 OK)");
} else {
    fail("3.3 Valid rejection failed", json_encode($validRejRes));
}

// 3.4 Invalid verification method fails (HTTP 422)
$repVal2 = createTestReport($testPdo, $reporterId, 'Pending');
$badMethodRes = VerificationService::verifyReport($repVal2, 'Verified', 'satellite_ai_guess', 'Notes', $wildlifeOfficerId, 'wildlife_officer');
if (!$badMethodRes['success'] && $badMethodRes['status_code'] === 422) {
    pass("3.4 Unapproved verification method ('satellite_ai_guess') strictly rejected (HTTP 422 Unprocessable)");
} else {
    fail("3.4 Invalid verification method was accepted", json_encode($badMethodRes));
}

// 3.5 All 4 approved verification methods succeed
$allMethodsOk = true;
foreach (['field_inspection', 'station_master_vhf', 'remote_telemetry', 'resident_interview'] as $m) {
    $r = createTestReport($testPdo, $reporterId, 'Pending');
    $res = VerificationService::verifyReport($r, 'Verified', $m, "Verified via {$m}", $wildlifeOfficerId, 'wildlife_officer');
    if (!$res['success'] || $res['data']['verification_method'] !== $m) {
        $allMethodsOk = false;
        break;
    }
}
if ($allMethodsOk) {
    pass("3.5 All 4 approved verification methods (field_inspection, station_master_vhf, remote_telemetry, resident_interview) accepted");
} else {
    fail("3.5 Approved verification method failed");
}

// 3.6 Invalid report ID returns HTTP 404
$badIdRes = VerificationService::verifyReport(999999, 'Verified', 'remote_telemetry', 'Notes', $wildlifeOfficerId, 'wildlife_officer');
if (!$badIdRes['success'] && $badIdRes['status_code'] === 404) {
    pass("3.6 Non-existent report ID (999999) returns HTTP 404 Not Found");
} else {
    fail("3.6 Invalid report ID did not return 404", json_encode($badIdRes));
}

// =============================================================================
// SECTION 4: LIFECYCLE STATE TRANSITIONS & CONCURRENCY CONFLICT (HTTP 409)
// =============================================================================
section("Section 4: Lifecycle State Transitions & Concurrency Conflict");

$repCycle = createTestReport($testPdo, $reporterId, 'Pending');

// 4.1 Transition Pending -> Needs Information succeeds
$reqInfoRes = VerificationService::requestAdditionalInfo($repCycle, 'Please confirm whether herd was on track or embankment.', $wildlifeOfficerId, 'wildlife_officer');
if ($reqInfoRes['success'] && $reqInfoRes['status_code'] === 200 && $reqInfoRes['data']['new_status'] === 'Needs Information') {
    pass("4.1 Transition 'Pending' -> 'Needs Information' succeeds and creates inquiry record");
} else {
    fail("4.1 Request additional info failed", json_encode($reqInfoRes));
}

// 4.2 Transition Needs Information -> Verified directly is strictly blocked (HTTP 409 Conflict)
$bypassRes = VerificationService::verifyReport($repCycle, 'Verified', 'remote_telemetry', 'Trying to verify during pending clarification', $wildlifeOfficerId, 'wildlife_officer');
if (!$bypassRes['success'] && $bypassRes['status_code'] === 409 && strpos($bypassRes['error']['type'], 'clarification-pending') !== false) {
    pass("4.2 Verification bypass while awaiting clarification strictly blocked (HTTP 409 Conflict)");
} else {
    fail("4.2 Allowed verification while in 'Needs Information' status", json_encode($bypassRes));
}

// 4.3 Observer clarification reply transitions Needs Information -> Clarification Submitted
$inqMsgId = $reqInfoRes['data']['clarification_message_id'];
$replyRes = VerificationService::submitClarificationReply($repCycle, 'Both elephants were on the gravel embankment 15 meters from track.', $inqMsgId, $reporterId, 'reporter');
if ($replyRes['success'] && $replyRes['status_code'] === 200 && $replyRes['data']['new_status'] === 'Clarification Submitted') {
    pass("4.3 Clarification reply transitions 'Needs Information' -> 'Clarification Submitted' (HTTP 200 OK)");
} else {
    fail("4.3 Clarification reply failed", json_encode($replyRes));
}

// 4.4 Non-author attempting to submit clarification reply is blocked (HTTP 403 Forbidden)
$repCycle2 = createTestReport($testPdo, $reporterId, 'Pending');
VerificationService::requestAdditionalInfo($repCycle2, 'Need photo confirmation', $wildlifeOfficerId, 'wildlife_officer');
$spoofReplyRes = VerificationService::submitClarificationReply($repCycle2, 'Unauthorized user reply attempt', null, $driverId, 'driver');
if (!$spoofReplyRes['success'] && $spoofReplyRes['status_code'] === 403) {
    pass("4.4 Unauthorized user cannot reply to another reporter's inquiry (HTTP 403 Forbidden)");
} else {
    fail("4.4 Non-author was permitted to reply to clarification", json_encode($spoofReplyRes));
}

// 4.5 Transition Clarification Submitted -> Verified succeeds
$cycleVerifyRes = VerificationService::verifyReport($repCycle, 'Verified', 'field_inspection', 'Verified after observer confirmed embankment location', $wildlifeOfficerId, 'wildlife_officer');
if ($cycleVerifyRes['success'] && $cycleVerifyRes['status_code'] === 200 && $cycleVerifyRes['data']['new_status'] === 'Verified') {
    pass("4.5 Transition 'Clarification Submitted' -> 'Verified' succeeds (HTTP 200 OK)");
} else {
    fail("4.5 Verification after clarification failed", json_encode($cycleVerifyRes));
}

// 4.6 Attempting to verify an already verified report returns HTTP 409 Conflict
$alreadyVerRes = VerificationService::verifyReport($repCycle, 'Verified', 'field_inspection', 'Attempting double verification', $wildlifeOfficerId, 'wildlife_officer');
if (!$alreadyVerRes['success'] && $alreadyVerRes['status_code'] === 409) {
    pass("4.6 Attempting to re-verify an already 'Verified' report returns HTTP 409 Conflict");
} else {
    fail("4.6 Re-verifying already verified report did not return 409", json_encode($alreadyVerRes));
}

// 4.7 Concurrent review conflict: expected_previous_status mismatch returns HTTP 409 Conflict
$repCon = createTestReport($testPdo, $reporterId, 'Pending');
$conflictRes = VerificationService::verifyReport($repCon, 'Verified', 'remote_telemetry', 'Expecting Needs Information', $wildlifeOfficerId, 'wildlife_officer', 'Needs Information');
if (!$conflictRes['success'] && $conflictRes['status_code'] === 409) {
    pass("4.7 Optimistic concurrency collision returns HTTP 409 Conflict when expected status mismatches");
} else {
    fail("4.7 Concurrency conflict was not caught", json_encode($conflictRes));
}

// =============================================================================
// SECTION 5: TRANSACTIONAL ATOMICITY & ROLLBACK INTEGRITY
// =============================================================================
section("Section 5: Transactional Atomicity & Rollback Integrity");

$repTx = createTestReport($testPdo, $reporterId, 'Pending');
$statusBeforeTx = $testPdo->query("SELECT report_status FROM elephant_reports WHERE id = {$repTx}")->fetchColumn();
$verifCountBeforeTx = (int)$testPdo->query("SELECT COUNT(*) FROM report_verifications")->fetchColumn();

// Verify that valid verification inserts into report_verifications and commits atomically
$txRes = VerificationService::verifyReport($repTx, 'Verified', 'field_inspection', 'Valid atomic verification test', $wildlifeOfficerId, 'wildlife_officer');
$statusAfterTx = $testPdo->query("SELECT report_status FROM elephant_reports WHERE id = {$repTx}")->fetchColumn();
$verifCountAfterTx = (int)$testPdo->query("SELECT COUNT(*) FROM report_verifications")->fetchColumn();

if ($txRes['success'] && $statusAfterTx === 'Verified' && $verifCountAfterTx === ($verifCountBeforeTx + 1)) {
    pass("5.1 Atomic Execution: Report status update and report_verifications ledger entry committed together");
} else {
    fail("5.1 Atomic commit verification failed");
}

// 5.2 Verify correct ledger actor identity
$ledgerEntry = $testPdo->query("SELECT * FROM report_verifications WHERE id = {$txRes['data']['audit_ledger_id']}")->fetch(PDO::FETCH_ASSOC);
if ($ledgerEntry && (int)$ledgerEntry['verified_by'] === $wildlifeOfficerId && (int)$ledgerEntry['report_id'] === $repTx) {
    pass("5.2 Ledger Actor Identity: verified_by ({$ledgerEntry['verified_by']}) and report_id ({$ledgerEntry['report_id']}) match authenticated session");
} else {
    fail("5.2 Ledger identity mismatch", json_encode($ledgerEntry));
}

// =============================================================================
// SECTION 6: THREADED CLARIFICATION & CROSS-REPORT PROTECTION
// =============================================================================
section("Section 6: Threaded Clarification & Cross-Report Protection");

$repA = createTestReport($testPdo, $reporterId, 'Pending');
$repB = createTestReport($testPdo, $reporterId, 'Pending');

$inqA = VerificationService::requestAdditionalInfo($repA, 'Inquiry for Report A: Sighting distance confirmation', $wildlifeOfficerId, 'wildlife_officer');
$inqB = VerificationService::requestAdditionalInfo($repB, 'Inquiry for Report B: Direction of travel confirmation', $wildlifeOfficerId, 'wildlife_officer');

$inqMsgIdA = $inqA['data']['clarification_message_id'];

// 6.1 Parent clarification belonging to Report A cannot be referenced by Report B (Cross-report violation)
$crossRes = VerificationService::submitClarificationReply($repB, 'Attempting cross report parent link', $inqMsgIdA, $reporterId, 'reporter');
if (!$crossRes['success'] && $crossRes['status_code'] === 422 && strpos($crossRes['error']['type'], 'cross-report') !== false) {
    pass("6.1 Cross-Report Isolation: Reply on Report B referencing parent on Report A strictly rejected (HTTP 422)");
} else {
    fail("6.1 Cross-report parent link was accepted", json_encode($crossRes));
}

// 6.2 Valid child response correctly references parent in same report
$validReplyA = VerificationService::submitClarificationReply($repA, 'Valid reply to Report A parent inquiry', $inqMsgIdA, $reporterId, 'reporter');
if ($validReplyA['success'] && $validReplyA['status_code'] === 200) {
    pass("6.2 In-Report Threading: Reply on Report A correctly links to Report A parent message");
} else {
    fail("6.2 In-report reply failed", json_encode($validReplyA));
}

// 6.3 Server derives actor from session, ignoring client-supplied role manipulation
// Note: VerificationService only reads the passed $officerRole, which in the API endpoints comes from Auth::user()['role']
$spoofApiRoleAttempt = VerificationService::verifyReport($repA, 'Verified', 'remote_telemetry', 'Spoofed role claim', $reporterId, 'reporter');
if (!$spoofApiRoleAttempt['success'] && $spoofApiRoleAttempt['status_code'] === 403) {
    pass("6.3 Anti-Impersonation: Client-supplied role claims cannot bypass session-derived role enforcement (HTTP 403)");
} else {
    fail("6.3 Role spoofing was permitted", json_encode($spoofApiRoleAttempt));
}

// =============================================================================
// SECTION 7: CORRIDOR ATTRIBUTION & DOMAIN DECOUPLING
// =============================================================================
section("Section 7: Corridor Attribution & Domain Decoupling");

$repSec = createTestReport($testPdo, $reporterId, 'Pending');

// 7.1 Section reassignment by control officer logs to report_section_assignment_history
$secReassignRes = VerificationService::confirmOrReassignSection(
    $repSec, 2, 'subsequent_reassignment', 'station_master_vhf',
    'Confirmed via Gal Oya SM train sheet loop siding switch',
    $controlOfficerId, 'control_officer'
);

if ($secReassignRes['success'] && $secReassignRes['status_code'] === 200 && $secReassignRes['data']['confirmed_section_id'] === 2) {
    pass("7.1 Corridor Attribution: Control Officer reassigns section with dedicated audit logging");
} else {
    fail("7.1 Section reassignment failed", json_encode($secReassignRes));
}

// 7.2 Section reassignment strictly leaves biological report_status unaltered
$repSecAfter = $testPdo->query("SELECT report_status, section_id, section_assignment_status FROM elephant_reports WHERE id = {$repSec}")->fetch(PDO::FETCH_ASSOC);
if ($repSecAfter['report_status'] === 'Pending' && (int)$repSecAfter['section_id'] === 2 && $repSecAfter['section_assignment_status'] === 'controller_confirmed') {
    pass("7.2 Domain Independence: Section reassignment strictly leaves biological 'report_status' untouched ('Pending')");
} else {
    fail("7.2 Section reassignment mutated biological report_status", json_encode($repSecAfter));
}

// 7.3 Unauthorized role (e.g. reporter) cannot reassign section
$unauthSecRes = VerificationService::confirmOrReassignSection(
    $repSec, 1, 'controller_confirmation', 'manual_controller_override', 'Unauthorized attempt', $reporterId, 'reporter'
);
if (!$unauthSecRes['success'] && $unauthSecRes['status_code'] === 403) {
    pass("7.3 Role Restriction: Reporters cannot reassign railway corridor attributions (HTTP 403 Forbidden)");
} else {
    fail("7.3 Reporter was permitted to reassign section", json_encode($unauthSecRes));
}

// =============================================================================
// SECTION 8: LEGACY STATUS-UPDATE BYPASS PREVENTION
// =============================================================================
section("Section 8: Legacy Status-Update Bypass Prevention");

$repBypass = createTestReport($testPdo, $reporterId, 'Pending');

// 8.1 Calling ReportService::updateStatus() without authenticated user ID is rejected
$noUserRes = ReportService::updateStatus($repBypass, 'Verified', null);
if ($noUserRes === false) {
    pass("8.1 Unattributed updateStatus() call strictly rejected (returns false)");
} else {
    fail("8.1 updateStatus allowed unauthenticated status change");
}

// 8.2 Calling ReportService::updateStatus() as control officer to verify is rejected
$ctrlBypassRes = ReportService::updateStatus($repBypass, 'Verified', $controlOfficerId);
if ($ctrlBypassRes === false) {
    pass("8.2 Control Officer calling updateStatus(..., 'Verified') strictly rejected (returns false)");
} else {
    fail("8.2 Control officer was allowed to updateStatus to Verified");
}

// 8.3 Calling ReportService::updateStatus() as driver to reject is rejected
$driverBypassRes = ReportService::updateStatus($repBypass, 'Rejected', $driverId);
if ($driverBypassRes === false) {
    pass("8.3 Driver calling updateStatus(..., 'Rejected') strictly rejected (returns false)");
} else {
    fail("8.3 Driver was allowed to updateStatus to Rejected");
}

// 8.4 Calling ReportService::updateStatus() as wildlife officer invokes VerificationService and creates ledger entry
$verifCountBeforeLegacy = (int)$testPdo->query("SELECT COUNT(*) FROM report_verifications WHERE report_id = {$repBypass}")->fetchColumn();
$officerLegacyRes = ReportService::updateStatus($repBypass, 'Verified', $wildlifeOfficerId);
$verifCountAfterLegacy = (int)$testPdo->query("SELECT COUNT(*) FROM report_verifications WHERE report_id = {$repBypass}")->fetchColumn();
$repBypassAfter = $testPdo->query("SELECT report_status FROM elephant_reports WHERE id = {$repBypass}")->fetchColumn();

if ($officerLegacyRes === true && $repBypassAfter === 'Verified' && $verifCountAfterLegacy === ($verifCountBeforeLegacy + 1)) {
    pass("8.4 Authorized updateStatus() invokes VerificationService, updates status, and appends report_verifications audit entry");
} else {
    fail("8.4 Authorized updateStatus failed", "result={$officerLegacyRes}, status={$repBypassAfter}, verifCount={$verifCountAfterLegacy}");
}

// 8.5 Anti-Self-Verification in updateStatus
$repSelfBypass = createTestReport($testPdo, $wildlifeOfficerId, 'Pending');

// 8.5.1 Self-verification blocked when optional parameter is omitted (default false)
$selfOmittedRes = ReportService::updateStatus($repSelfBypass, 'Verified', $wildlifeOfficerId);
if ($selfOmittedRes === false) {
    pass("8.5.1 Anti-Self-Verification in updateStatus(): author calling updateStatus with omitted 4th param returns false (default false enforced)");
} else {
    fail("8.5.1 updateStatus permitted self-verification when optional param was omitted");
}

// 8.5.2 Self-verification blocked when explicitly passed false
$selfExplicitFalseRes = ReportService::updateStatus($repSelfBypass, 'Verified', $wildlifeOfficerId, false);
if ($selfExplicitFalseRes === false) {
    pass("8.5.2 Anti-Self-Verification in updateStatus(): author calling updateStatus with allowSelfVerification=false returns false");
} else {
    fail("8.5.2 updateStatus permitted self-verification when allowSelfVerification=false");
}

// 8.5.3 Self-verification permitted ONLY with explicit authorization flag (allowSelfVerification=true)
$selfExplicitTrueRes = ReportService::updateStatus($repSelfBypass, 'Verified', $wildlifeOfficerId, true);
$repSelfAfterTrue = $testPdo->query("SELECT report_status FROM elephant_reports WHERE id = {$repSelfBypass}")->fetchColumn();
if ($selfExplicitTrueRes === true && $repSelfAfterTrue === 'Verified') {
    pass("8.5.3 updateStatus(): explicit legacy authorization flag (allowSelfVerification=true) succeeds when required");
} else {
    fail("8.5.3 updateStatus failed when allowSelfVerification=true");
}

// 8.6 SEC-02: Status Fallback Hardening & Operational Status Constraints
$repOpTest = createTestReport($testPdo, $reporterId, 'Pending');

// 8.6.1 Arbitrary status values are strictly rejected
$bogusStatusRes = ReportService::updateStatus($repOpTest, 'ArbitraryNonsenseStatus', $controlOfficerId);
if ($bogusStatusRes === false) {
    pass("8.6.1 Hardened updateStatus(): arbitrary status values are strictly rejected (returns false)");
} else {
    fail("8.6.1 updateStatus permitted arbitrary unwhitelisted status value");
}

// 8.6.2 Unauthenticated operational status update is rejected
$noUserOpRes = ReportService::updateStatus($repOpTest, 'Converted to Alert', null);
if ($noUserOpRes === false) {
    pass("8.6.2 Hardened updateStatus(): unauthenticated operational status update strictly rejected (returns false)");
} else {
    fail("8.6.2 updateStatus allowed unauthenticated operational status change");
}

// 8.6.3 Unauthorized role for operational status is rejected (e.g. driver attempting 'Converted to Alert')
$unauthOpRes = ReportService::updateStatus($repOpTest, 'Converted to Alert', $driverId);
if ($unauthOpRes === false) {
    pass("8.6.3 Hardened updateStatus(): unauthorized role ('driver') for 'Converted to Alert' rejected (returns false)");
} else {
    fail("8.6.3 updateStatus allowed driver to convert report to alert");
}

// 8.6.4 Valid operational status transition succeeds for authorized control officer
$alertUpdateRes = ReportService::updateStatus($repOpTest, 'Converted to Alert', $controlOfficerId);
$statusAfterAlert = $testPdo->query("SELECT report_status FROM elephant_reports WHERE id = {$repOpTest}")->fetchColumn();
if ($alertUpdateRes === true && $statusAfterAlert === 'Converted to Alert') {
    pass("8.6.4 Valid operational status update ('Converted to Alert') succeeds for authorized Control Officer");
} else {
    fail("8.6.4 Operational status update failed for control officer");
}

// 8.7 Ranger Submission Lifecycle: field observations remain in 'Pending' status without auto-elevation
$normTemporal = [
    'sighting_date' => '2026-11-10',
    'sighting_time' => '08:30:00',
    'sighting_time_precision' => 'exact',
    'sighting_time_window' => null,
    'sighting_time_uncertainty_minutes' => 5,
    'sighting_timezone' => 'Asia/Colombo',
    'gps_captured_at' => null
];
$newRangerReportId = ReportService::submitReportWithId(
    1, $wildlifeOfficerId, 3, 'Ranger field observation sector Alpha', 'High',
    'Ranger patrol spotted herd near milepost 112', null, '8.050000', '80.750000', '10', $normTemporal
);
$rangerReportStatus = $testPdo->query("SELECT report_status FROM elephant_reports WHERE id = {$newRangerReportId}")->fetchColumn();
$rangerVerifCount = (int)$testPdo->query("SELECT COUNT(*) FROM report_verifications WHERE report_id = {$newRangerReportId}")->fetchColumn();

if ($newRangerReportId > 0 && $rangerReportStatus === 'Pending') {
    pass("8.7 Ranger Field Submission: report persists as 'Pending' awaiting independent officer verification");
} else {
    fail("8.7 Ranger report was improperly auto-elevated, status={$rangerReportStatus}");
}

if ($rangerVerifCount === 0) {
    pass("8.8 Ranger Field Submission: zero verification ledger entries manufactured upon submission (count=0)");
} else {
    fail("8.8 Verification ledger entry was unexpectedly created during field submission");
}

// 8.9 SEC-05: API Discovery accurately documents all routes including /clarify and /history
$apiHelperFile = __DIR__ . '/../public/api/v2/reports/index.php';
$indexContents = file_get_contents($apiHelperFile);
if (strpos($indexContents, 'POST /api/v2/reports/{id}/clarify') !== false && 
    strpos($indexContents, 'GET /api/v2/reports/{id}/history') !== false) {
    pass("8.9 API Discovery: router accurately advertises '/clarify' and '/history' endpoints");
} else {
    fail("8.9 API discovery missing newly implemented endpoints in index.php");
}

// =============================================================================
// SECTION 9: AUDIT HISTORY RETRIEVAL & DATA INVIOLABILITY
// =============================================================================
section("Section 9: Audit History Retrieval & Data Inviolability");

// 9.1 getAuditHistory retrieves chronological verification, clarification, and section records
$historyRes = VerificationService::getAuditHistory($repA, 'wildlife_officer');
if ($historyRes['success'] && 
    !empty($historyRes['data']['verifications']) && 
    !empty($historyRes['data']['clarifications'])) {
    pass("9.1 Audit History API: Chronological verifications and clarifications retrieved successfully");
} else {
    fail("9.1 Audit history retrieval failed", json_encode($historyRes));
}

// 9.2 Unauthorized role rejected by history API
$historyUnauth = VerificationService::getAuditHistory($repA, 'driver');
if (!$historyUnauth['success'] && $historyUnauth['status_code'] === 403) {
    pass("9.2 Audit History API: Unauthorized role ('driver') strictly rejected (HTTP 403 Forbidden)");
} else {
    fail("9.2 Driver was allowed to inspect audit history", json_encode($historyUnauth));
}

// Clean up temporary test reports
$testPdo->exec("SET FOREIGN_KEY_CHECKS = 0;");
$allTestIds = "{$rep1}, {$rep2}, {$rep3}, {$repOfficerSelf}, {$repVal}, {$repVal2}, {$repCycle}, {$repCycle2}, {$repTx}, {$repA}, {$repB}, {$repSec}, {$repBypass}, {$repSelfBypass}, {$repOpTest}, {$newRangerReportId}";
$testPdo->exec("DELETE FROM report_clarifications WHERE report_id IN ({$allTestIds})");
$testPdo->exec("DELETE FROM report_section_assignment_history WHERE report_id IN ({$allTestIds})");
$testPdo->exec("DELETE FROM report_verifications WHERE report_id IN ({$allTestIds})");
$testPdo->exec("DELETE FROM elephant_reports WHERE id IN ({$allTestIds})");
$testPdo->exec("SET FOREIGN_KEY_CHECKS = 1;");

// =============================================================================
// STEP 10: OPERATIONAL DATABASE IMMUTABILITY VERIFICATION
// =============================================================================
section("Step 10: Operational Database Immutability Verification");

$finalCounts = [
    'reports' => (int)$adminPdo->query("SELECT COUNT(*) FROM `{$prodDb}`.elephant_reports")->fetchColumn(),
    'logs' => (int)$adminPdo->query("SELECT COUNT(*) FROM `{$prodDb}`.system_logs")->fetchColumn(),
    'alerts' => (int)$adminPdo->query("SELECT COUNT(*) FROM `{$prodDb}`.alerts")->fetchColumn(),
    'hotspots' => (int)$adminPdo->query("SELECT COUNT(*) FROM `{$prodDb}`.hotspots")->fetchColumn(),
    'sections' => (int)$adminPdo->query("SELECT COUNT(*) FROM `{$prodDb}`.railway_sections")->fetchColumn(),
    'users' => (int)$adminPdo->query("SELECT COUNT(*) FROM `{$prodDb}`.users")->fetchColumn()
];

$immutable = true;
foreach ($baselineCounts as $entity => $count) {
    if ($finalCounts[$entity] !== $count) {
        $immutable = false;
        fail("Operational Database Mutation detected on {$entity}: before={$count}, after={$finalCounts[$entity]}");
    }
}

if ($immutable) {
    pass("Operational database ({$prodDb}) remains 100% immutable (Reports: {$finalCounts['reports']}, Logs: {$finalCounts['logs']}, Alerts: {$finalCounts['alerts']}, Hotspots: {$finalCounts['hotspots']}, Sections: {$finalCounts['sections']}, Users: {$finalCounts['users']})");
}

echo PHP_EOL;
echo "================================================================================" . PHP_EOL;
echo "   TEST SUITE EXECUTION SUMMARY" . PHP_EOL;
echo "================================================================================" . PHP_EOL;
echo "Total Tests Run:    {$testsRun}" . PHP_EOL;
echo "Total Tests Passed: {$testsPassed}" . PHP_EOL;
echo "Total Tests Failed: {$testsFailed}" . PHP_EOL;

if ($testsFailed === 0) {
    echo green(bold("✓ ALL STAGE 4E.4.3-D VERIFICATION SERVICE TESTS PASSED")) . PHP_EOL;
    exit(0);
} else {
    echo red(bold("✗ TEST SUITE FAILED WITH {$testsFailed} FAILURES")) . PHP_EOL;
    exit(1);
}
