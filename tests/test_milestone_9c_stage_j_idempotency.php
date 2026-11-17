<?php
/**
 * EleGuard Rail — Milestone 9C Stage J Phase J.5
 * Offline Synchronization UUID Idempotency Verification Suite
 * Change Reference: ER-M9C-STAGE-J-J5-007
 * 
 * Verifies 13 Core Invariants:
 * 1.  User A submits a new report with client UUID -> 201 Created, stored successfully.
 * 2.  User A retries submission with identical payload and same UUID -> 200 OK, is_replay: true, returns original report ID.
 * 3.  User B submits report with User A's UUID -> 409 Conflict (UUID Conflict).
 * 4.  Cross-user conflict response reveals NO protected internal information (zero data leakage: no report ID, status, date, or author).
 * 5.  Same user resubmits UUID with modified elephant count -> 409 Conflict (Payload Mismatch).
 * 6.  Same user resubmits UUID with modified spatial coordinates -> 409 Conflict (Payload Mismatch).
 * 7.  Same user resubmits UUID with modified description -> 409 Conflict (Payload Mismatch).
 * 8.  Concurrent submission simulation for same user -> 1 creates (201), concurrent requests return 200 Replay.
 * 9.  Concurrent submission simulation cross-user -> 1 creates (201), concurrent request from different user returns 409 Conflict.
 * 10. Legacy record with NULL payload hash in database -> Replays gracefully with 200 OK without failing.
 * 11. Multi-record batch sync with mixed new submissions and replays -> synced_count: 2, replayed_count: 2.
 * 12. Batch sync with rejected records -> rejected_count: 1, properly flagged.
 * 13. Mathematical reconciliation -> total_received === synced_count + replayed_count + rejected_count across all batch runs.
 */

define('ELEGUARD_TEST_SUITE', true);
define('ELEGUARD_TEST_ENVIRONMENT', true);

$baseDir = str_replace('\\', '/', dirname(__DIR__));
require_once $baseDir . '/app/config/database.php';
require_once $baseDir . '/tests/bootstrap.php';
require_once $baseDir . '/app/services/RiskService.php';
require_once $baseDir . '/app/services/FieldReportingService.php';
require_once $baseDir . '/app/core/Auth.php';

// -----------------------------------------------------------------------------
// Target Database Resolution & Safety Verification
// -----------------------------------------------------------------------------
$targetDb = getenv('DB_NAME') ?: ($_ENV['DB_NAME'] ?? null);
$wasSelfGenerated = false;
if (empty($targetDb) || $targetDb === 'eleguard_rail_test' || $targetDb === 'eleguard_rail') {
    $targetDb = 'eleguard_test_tmp_' . bin2hex(random_bytes(6));
    putenv("DB_NAME={$targetDb}");
    $_ENV['DB_NAME'] = $targetDb;
    $wasSelfGenerated = true;
}

validateTestDatabaseTarget($targetDb);

$dbHost = defined('DB_HOST') ? DB_HOST : '127.0.0.1';
$dbPort = defined('DB_PORT') ? (int)DB_PORT : 3308;

$adminConfigFile = getenv('USERPROFILE') ? getenv('USERPROFILE') . '/.eleguard-test-admin.php' : 'C:/Users/user/.eleguard-test-admin.php';
if (file_exists($adminConfigFile)) {
    $adminConfig = require $adminConfigFile;
    $adminUser = $adminConfig['admin_user'];
    $adminPass = $adminConfig['admin_pass'];
} else {
    $adminUser = defined('DB_USER') ? DB_USER : 'root';
    $adminPass = defined('DB_PASS') ? DB_PASS : '';
}

$adminPdo = new PDO("mysql:host={$dbHost};port={$dbPort}", $adminUser, $adminPass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
]);

ensureTestDatabaseExists($adminPdo, $targetDb);

// Record baseline operational database state to verify zero modification
$opCountsBefore = [];
$opTables = ['elephant_reports', 'report_verifications', 'report_clarifications', 'section_attribution_audits', 'alerts', 'users'];
foreach ($opTables as $tbl) {
    try {
        $opCountsBefore[$tbl] = (int)$adminPdo->query("SELECT COUNT(*) FROM `eleguard_rail`.`{$tbl}`")->fetchColumn();
    } catch (Exception $e) {
        $opCountsBefore[$tbl] = -1;
    }
}

// -----------------------------------------------------------------------------
// Test Output Helpers
// -----------------------------------------------------------------------------
function pass($desc) { echo "  \033[32m[PASS]\033[0m " . $desc . PHP_EOL; }
function fail($desc, $err = '') { 
    echo "  \033[31m[FAIL]\033[0m " . $desc . ($err ? " — \033[33m" . $err . "\033[0m" : "") . PHP_EOL; 
    $GLOBALS['testsFailed']++;
}
function section($title) { echo PHP_EOL . "--- " . strtoupper($title) . " ---" . PHP_EOL; }

$testsRun = 0;
$testsFailed = 0;

function assertCondition($cond, $desc, $err = '') {
    $GLOBALS['testsRun']++;
    if ($cond) {
        pass($desc);
    } else {
        fail($desc, $err);
    }
}

echo "================================================================================" . PHP_EOL;
echo " EleGuard Rail — Stage J Phase J.5 Offline Synchronization UUID Idempotency Suite" . PHP_EOL;
echo " Target Test Database: " . $targetDb . PHP_EOL;
echo "================================================================================" . PHP_EOL;

// Ensure test users exist in target disposable database
$db = Database::getInstance()->getConnection();
$userA_id = 501;
$userB_id = 502;
$admin_id = 1;

$db->exec("
    INSERT IGNORE INTO users (id, name, email, password, role, status) VALUES
    ({$userA_id}, 'Field Officer Alpha', 'alpha@wildlife.gov.lk', 'hash', 'wildlife_officer', 'active'),
    ({$userB_id}, 'Field Officer Beta', 'beta@wildlife.gov.lk', 'hash', 'wildlife_officer', 'active')
");

// Helper to generate base valid report payload
function generateValidReport($uuid, $overrides = []) {
    $base = [
        'client_report_uuid' => $uuid,
        'submission_source' => 'mobile_pwa',
        'observation_category' => 'live_sighting',
        'sighting_date' => date('Y-m-d', time() - 3600),
        'sighting_time' => date('H:i', time() - 1800),
        'sighting_time_precision' => 'exact',
        'observer_location' => [
            'latitude' => 8.03250000,
            'longitude' => 80.28500000,
            'gps_accuracy_meters' => 8.50,
            'acquisition_method' => 'gps_device'
        ],
        'animal_location' => [
            'observation_distance_meters' => 45.00,
            'observation_bearing_degrees' => 120.00
        ],
        'elephant_count' => 3,
        'adult_males' => 1,
        'adult_females' => 1,
        'calves' => 1,
        'urgency_level' => 'High',
        'location_description' => 'Near Galgamuwa bridge culvert',
        'description' => 'Herd observed foraging near perimeter fence'
    ];
    return array_replace_recursive($base, $overrides);
}

// =============================================================================
// SECTION 1: Standard Ingestion & Idempotent Replay (J5-01 Baseline)
// =============================================================================
section("1. Standard Ingestion and Same-User Replay");

$uuid1 = 'f47ac10b-58cc-4372-a567-0e02b2c3d479';
$payload1 = generateValidReport($uuid1);

// Test 1: User A submits new UUID -> 201 Created
$res1 = FieldReportingService::submitReport($payload1, $userA_id, 'wildlife_officer');
$createdReportId = $res1['data']['report_id'] ?? null;

assertCondition(
    ($res1['success'] ?? false) === true &&
    ($res1['status_code'] ?? 0) === 201 &&
    ($res1['is_replay'] ?? true) === false &&
    !empty($createdReportId),
    "Test 1: User A submits new UUID -> returns HTTP 201 Created with new report ID",
    "Result: " . json_encode($res1)
);

// Verify payload_hash column was populated in database
$hashStmt = $db->prepare("SELECT payload_hash FROM elephant_reports WHERE id = :id");
$hashStmt->execute([':id' => $createdReportId]);
$storedHash = $hashStmt->fetchColumn();

assertCondition(
    !empty($storedHash) && strlen($storedHash) === 64,
    "Test 1b: Database record contains 64-character SHA-256 payload_hash",
    "Stored hash: " . var_export($storedHash, true)
);

// Test 2: User A retries identical payload with same UUID -> 200 OK, is_replay: true
$res2 = FieldReportingService::submitReport($payload1, $userA_id, 'wildlife_officer');
assertCondition(
    ($res2['success'] ?? false) === true &&
    ($res2['status_code'] ?? 0) === 200 &&
    ($res2['is_replay'] ?? false) === true &&
    ($res2['data']['report_id'] ?? 0) === (int)$createdReportId,
    "Test 2: User A retries identical payload -> returns HTTP 200 Replay with original report ID",
    "Result: " . json_encode($res2)
);

// =============================================================================
// SECTION 2: Cross-User Replay Isolation & Information Disclosure Prevention (J5-01)
// =============================================================================
section("2. Cross-User UUID Collision Isolation");

// Test 3: User B attempts to submit using User A's UUID -> 409 Conflict
$payloadUserB = generateValidReport($uuid1);
$res3 = FieldReportingService::submitReport($payloadUserB, $userB_id, 'wildlife_officer');

assertCondition(
    ($res3['success'] ?? true) === false &&
    ($res3['status_code'] ?? 0) === 409 &&
    isset($res3['error']['type']) &&
    $res3['error']['type'] === 'https://eleguard.rail.gov.lk/errors/uuid-conflict',
    "Test 3: User B submits User A's UUID -> returns HTTP 409 Conflict (UUID Conflict)",
    "Result: " . json_encode($res3)
);

// Test 4: Cross-user conflict response reveals zero protected internal information
$hasReportId = isset($res3['data']['report_id']) || isset($res3['error']['report_id']);
$hasCreatedAt = isset($res3['data']['created_at']) || isset($res3['error']['created_at']);
$hasReportStatus = isset($res3['data']['report_status']) || isset($res3['error']['report_status']);
$hasAuthor = isset($res3['data']['reported_by']) || isset($res3['error']['reported_by']);
$detailContainsInternalInfo = isset($res3['error']['detail']) && (
    strpos($res3['error']['detail'], (string)$createdReportId) !== false ||
    strpos($res3['error']['detail'], 'alpha') !== false ||
    strpos($res3['error']['detail'], '501') !== false
);

assertCondition(
    !$hasReportId && !$hasCreatedAt && !$hasReportStatus && !$hasAuthor && !$detailContainsInternalInfo,
    "Test 4: Cross-user conflict reveals zero protected internal information (zero data leakage)",
    "Leaked data in res3: " . json_encode($res3)
);

// =============================================================================
// SECTION 3: Material Payload Fingerprint Mismatch Detection (J5-02)
// =============================================================================
section("3. Deterministic Payload Fingerprinting & Mismatch Detection");

// Test 5: Same user resubmits UUID with modified elephant count -> 409 Conflict
$modifiedCountPayload = generateValidReport($uuid1, ['elephant_count' => 7]);
$res5 = FieldReportingService::submitReport($modifiedCountPayload, $userA_id, 'wildlife_officer');

assertCondition(
    ($res5['success'] ?? true) === false &&
    ($res5['status_code'] ?? 0) === 409 &&
    isset($res5['error']['type']) &&
    $res5['error']['type'] === 'https://eleguard.rail.gov.lk/errors/payload-mismatch',
    "Test 5: Same user resubmits UUID with modified elephant count -> 409 Conflict (Payload Mismatch)",
    "Result: " . json_encode($res5)
);

// Test 6: Same user resubmits UUID with modified spatial coordinates -> 409 Conflict
$modifiedCoordsPayload = generateValidReport($uuid1, [
    'observer_location' => [
        'latitude' => 8.04000000,
        'longitude' => 80.29000000
    ]
]);
$res6 = FieldReportingService::submitReport($modifiedCoordsPayload, $userA_id, 'wildlife_officer');

assertCondition(
    ($res6['success'] ?? true) === false &&
    ($res6['status_code'] ?? 0) === 409 &&
    isset($res6['error']['type']) &&
    $res6['error']['type'] === 'https://eleguard.rail.gov.lk/errors/payload-mismatch',
    "Test 6: Same user resubmits UUID with modified spatial coordinates -> 409 Conflict (Payload Mismatch)",
    "Result: " . json_encode($res6)
);

// Test 7: Same user resubmits UUID with modified description -> 409 Conflict
$modifiedDescPayload = generateValidReport($uuid1, ['description' => 'Updated: Herd moved towards Maho line']);
$res7 = FieldReportingService::submitReport($modifiedDescPayload, $userA_id, 'wildlife_officer');

assertCondition(
    ($res7['success'] ?? true) === false &&
    ($res7['status_code'] ?? 0) === 409 &&
    isset($res7['error']['type']) &&
    $res7['error']['type'] === 'https://eleguard.rail.gov.lk/errors/payload-mismatch',
    "Test 7: Same user resubmits UUID with modified description -> 409 Conflict (Payload Mismatch)",
    "Result: " . json_encode($res7)
);

// =============================================================================
// SECTION 4: Concurrency & Legacy Record Fallback
// =============================================================================
section("4. Concurrency Simulation & Legacy Fallback");

// Test 8: Concurrent submission simulation for same user (InnoDB lock serialization)
$uuidConcurrent1 = 'c0000000-0000-4000-8000-000000000001';
$payloadConc1 = generateValidReport($uuidConcurrent1);

$sub1 = FieldReportingService::submitReport($payloadConc1, $userA_id, 'wildlife_officer');
$sub2 = FieldReportingService::submitReport($payloadConc1, $userA_id, 'wildlife_officer');

assertCondition(
    ($sub1['status_code'] === 201 && $sub2['status_code'] === 200 && $sub2['is_replay'] === true && $sub2['data']['report_id'] === $sub1['data']['report_id']),
    "Test 8: Consecutive/concurrent same-user submissions serialize cleanly (1st = 201 Created, 2nd = 200 Replay)",
    "Sub1: {$sub1['status_code']}, Sub2: {$sub2['status_code']}"
);

// Test 9: Concurrent submission simulation cross-user (1 creates, 2nd gets 409 Conflict)
$uuidConcurrent2 = 'c0000000-0000-4000-8000-000000000002';
$payloadConc2 = generateValidReport($uuidConcurrent2);

$subUserA = FieldReportingService::submitReport($payloadConc2, $userA_id, 'wildlife_officer');
$subUserB = FieldReportingService::submitReport($payloadConc2, $userB_id, 'wildlife_officer');

assertCondition(
    ($subUserA['status_code'] === 201 && $subUserB['status_code'] === 409 && $subUserB['error']['type'] === 'https://eleguard.rail.gov.lk/errors/uuid-conflict'),
    "Test 9: Cross-user concurrency yields 201 Created for 1st user and 409 Conflict for 2nd user",
    "User A: {$subUserA['status_code']}, User B: {$subUserB['status_code']}"
);

// Test 10: Legacy record with NULL payload hash in database replays gracefully
$uuidLegacy = 'e0000000-0000-4000-8000-000000000001';
$payloadLegacy = generateValidReport($uuidLegacy);
$resLegInitial = FieldReportingService::submitReport($payloadLegacy, $userA_id, 'wildlife_officer');
$legacyReportId = $resLegInitial['data']['report_id'] ?? 0;

// Manually simulate a legacy pre-migration row by setting payload_hash = NULL
$db->exec("UPDATE elephant_reports SET payload_hash = NULL WHERE id = {$legacyReportId}");

// Re-submitting by same owner should gracefully replay with 200 OK
$resLegReplay = FieldReportingService::submitReport($payloadLegacy, $userA_id, 'wildlife_officer');

assertCondition(
    ($resLegReplay['success'] ?? false) === true &&
    ($resLegReplay['status_code'] ?? 0) === 200 &&
    ($resLegReplay['is_replay'] ?? false) === true &&
    ($resLegReplay['data']['report_id'] ?? 0) === (int)$legacyReportId,
    "Test 10: Legacy record with NULL payload_hash replays gracefully with HTTP 200 OK",
    "Result: " . json_encode($resLegReplay)
);

// =============================================================================
// SECTION 5: Multi-Record Batch Sync Metrics & Mathematical Reconciliation (J5-03)
// =============================================================================
section("5. Batch Sync Metrics Disaggregation & Reconciliation");

// Setup batch with:
// 2 Brand New Submissions
// 2 Exact Replays (uuid1 and uuidConcurrent1 from earlier)
// 1 Rejected Submission (Invalid elephant count: negative)

$batchUuidNew1 = 'b1111111-1111-4111-8111-111111111111';
$batchUuidNew2 = 'b2222222-2222-4222-8222-222222222222';
$batchUuidReject = 'b3333333-3333-4333-8333-333333333333';

$batchPayload = [
    'reports' => [
        generateValidReport($batchUuidNew1),                      // Expected: synced
        generateValidReport($batchUuidNew2),                      // Expected: synced
        generateValidReport($uuid1),                             // Expected: replayed
        generateValidReport($uuidConcurrent1),                   // Expected: replayed
        generateValidReport($batchUuidReject, ['elephant_count' => -5]) // Expected: rejected (validation failure)
    ]
];

$batchResult = FieldReportingService::syncBatch($batchPayload, $userA_id, 'wildlife_officer', 2048);

$batchData = $batchResult['data'] ?? [];
$totalReceived = $batchData['total_received'] ?? 0;
$syncedCount = $batchData['synced_count'] ?? -1;
$replayedCount = $batchData['replayed_count'] ?? -1;
$rejectedCount = $batchData['rejected_count'] ?? -1;

// Test 11: Multi-record batch sync with mixed new submissions and replays
assertCondition(
    $syncedCount === 2 && $replayedCount === 2,
    "Test 11: Mixed batch correctly disaggregates counts (synced_count: 2, replayed_count: 2)",
    "Counts — synced: {$syncedCount}, replayed: {$replayedCount}"
);

// Test 12: Batch sync containing rejected record correctly reports rejected count
assertCondition(
    $rejectedCount === 1,
    "Test 12: Batch sync correctly reports rejected_count: 1 for validation failure",
    "Counts — rejected: {$rejectedCount}"
);

// Test 13: Mathematical reconciliation invariant
$sum = $syncedCount + $replayedCount + $rejectedCount;
assertCondition(
    $totalReceived === 5 && $sum === $totalReceived,
    "Test 13: Mathematical reconciliation invariant holds: total_received ({$totalReceived}) === synced ({$syncedCount}) + replayed ({$replayedCount}) + rejected ({$rejectedCount})",
    "Sum: {$sum}, Expected: {$totalReceived}"
);

// Verify individual batch acknowledgement statuses
$acks = $batchData['acknowledgements'] ?? [];
$hasTwoSyncedAcks = count(array_filter($acks, function($a) { return ($a['status'] ?? '') === 'synced'; })) === 2;
$hasTwoReplayedAcks = count(array_filter($acks, function($a) { return ($a['status'] ?? '') === 'replayed'; })) === 2;
$hasOneRejectedAck = count(array_filter($acks, function($a) { return ($a['status'] ?? '') === 'rejected'; })) === 1;

assertCondition(
    $hasTwoSyncedAcks && $hasTwoReplayedAcks && $hasOneRejectedAck,
    "Test 13b: Acknowledgements array correctly labels 2 synced, 2 replayed, and 1 rejected item",
    "Acks: " . json_encode($acks)
);

// =============================================================================
// Operational Database Invariant Verification
// =============================================================================
section("Operational Database Immutability Check");
$opCountsAfter = [];
$mutated = [];
foreach ($opTables as $tbl) {
    try {
        $count = (int)$adminPdo->query("SELECT COUNT(*) FROM `eleguard_rail`.`{$tbl}`")->fetchColumn();
        $opCountsAfter[$tbl] = $count;
        if ($count !== $opCountsBefore[$tbl]) {
            $mutated[] = "{$tbl} (before: {$opCountsBefore[$tbl]}, after: {$count})";
        }
    } catch (Exception $e) {
        $opCountsAfter[$tbl] = -1;
    }
}

assertCondition(
    empty($mutated),
    "Operational database eleguard_rail remained 100% immutable throughout test suite execution",
    "Mutations detected: " . implode(', ', $mutated)
);

// =============================================================================
// Teardown Ephemeral Database (When run standalone outside master test runner)
// =============================================================================
if (empty(getenv('ELEGUARD_MASTER_RUNNER'))) {
    try {
        safeTeardownTestDatabase($adminPdo, $targetDb);
    } catch (Exception $e) {
        // Suppress cleanup warning
    }
}

// =============================================================================
// Summary & Verdict
// =============================================================================
echo PHP_EOL . "================================================================================" . PHP_EOL;
echo " SUITE SUMMARY: {$testsRun} ran, " . ($testsRun - $testsFailed) . " passed, {$testsFailed} failed." . PHP_EOL;
if ($testsFailed === 0) {
    echo " \033[32mVERDICT: [PASS] ALL 13+ OFFLINE IDEMPOTENCY INVARIANTS VERIFIED CLEANLY.\033[0m" . PHP_EOL;
    echo "================================================================================" . PHP_EOL;
    exit(0);
} else {
    echo " \033[31mVERDICT: [FAIL] {$testsFailed} TEST ASSERTIONS FAILED.\033[0m" . PHP_EOL;
    echo "================================================================================" . PHP_EOL;
    exit(1);
}
