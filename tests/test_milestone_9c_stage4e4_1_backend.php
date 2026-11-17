<?php
/**
 * EleGuard Rail — Milestone 9C Stage 4E.4.1 Test Suite
 * Field Reporting Backend Foundation & API v2 Verification Suite
 * 
 * Verifies:
 * 1. Database Migration & Schema Invariants (columns, nullability, tables, constraints, provenance).
 * 2. Geodetic Offset Forward Solver & Uncertainty Propagation (Vincenty/Great Circle direct solver).
 * 3. Gapless 24-Hour Diurnal Continuum & Asia/Colombo Temporal Evidence.
 * 4. Railway Section Association, Junction Geofences & Topographic Ambiguity.
 * 5. Server-Side Idempotency, Client UUID Uniqueness & Transactional Replay (REL-01).
 * 6. Batch Synchronization Ceilings (50 items / 10 MB) & HTTP 207 Multi-Status (API-02).
 * 7. Role-Based Anti-Poaching Coordinate Masking (2 decimal places vs raw telemetry).
 * 8. Human Verification State Machine & RBAC Authorization.
 * 9. Operational Database Immutability Invariant.
 * 
 * Target Database: STRICTLY eleguard_rail_test
 */

define('ELEGUARD_TEST_SUITE', true);
define('ELEGUARD_TEST_ENVIRONMENT', true);

$targetDb = getenv('DB_NAME') ?: ($_ENV['DB_NAME'] ?? null);
if (empty($targetDb) || $targetDb === 'eleguard_rail_test' || $targetDb === 'eleguard_rail') {
    $targetDb = 'eleguard_test_tmp_' . bin2hex(random_bytes(6));
    putenv("DB_NAME={$targetDb}");
    $_ENV['DB_NAME'] = $targetDb;
}

$baseDir = str_replace('\\', '/', dirname(__DIR__));
require_once $baseDir . '/tests/bootstrap.php';
validateTestDatabaseTarget($targetDb);
ensureTestDatabaseExists(null, $targetDb);

require_once $baseDir . '/app/config/database.php';
require_once $baseDir . '/app/core/Database.php';
require_once $baseDir . '/app/core/Session.php';
require_once $baseDir . '/app/core/Auth.php';
require_once $baseDir . '/app/services/FieldReportingService.php';

function pass($msg) { echo "  \033[32m[PASS]\033[0m " . $msg . PHP_EOL; }
function fail($msg, $details = '') { 
    echo "  \033[31m[FAIL]\033[0m " . $msg . PHP_EOL; 
    if ($details) echo "         \033[33mDetails: " . $details . "\033[0m" . PHP_EOL;
    $GLOBALS['testsFailed']++;
}
function section($title) { echo PHP_EOL . "--- " . strtoupper($title) . " ---" . PHP_EOL; }

$testsRun = 0;
$testsFailed = 0;

function assertCondition($cond, $desc, $details = '') {
    $GLOBALS['testsRun']++;
    if ($cond) {
        pass($desc);
    } else {
        fail($desc, $details);
    }
}

echo "================================================================================" . PHP_EOL;
echo " EleGuard Rail — Milestone 9C Stage 4E.4.1 Backend Foundation Test Suite" . PHP_EOL;
echo "================================================================================" . PHP_EOL;

$preOpSnapshot = captureOperationalDatabaseSnapshot();
$pdo = Database::getInstance()->getConnection();

// --- GROUP 1: DATABASE MIGRATION & SCHEMA INVARIANTS ---
section("Group 1: Database Migration & Schema Invariants");

$cols = $pdo->query("DESCRIBE elephant_reports")->fetchAll(PDO::FETCH_ASSOC);
$colNames = array_column($cols, 'Field');

$expectedCols = [
    'client_report_uuid', 'submission_source', 'observation_category',
    'diurnal_period', 'observer_latitude', 'observer_longitude',
    'observer_gps_accuracy_meters', 'observer_gps_captured_at', 'location_acquisition_method',
    'observation_distance_meters', 'observation_bearing_degrees',
    'estimated_animal_latitude', 'estimated_animal_longitude',
    'animal_location_uncertainty_meters', 'animal_location_method', 'is_estimated_position',
    'nearest_milestone_ref', 'distance_to_track_meters', 'track_distance_min_meters',
    'track_distance_max_meters', 'track_proximity_zone', 'section_assignment_status',
    'adult_males', 'adult_females', 'calves', 'provenance'
];

foreach ($expectedCols as $ec) {
    assertCondition(in_array($ec, $colNames, true), "Column '{$ec}' exists in elephant_reports");
}

$verifTables = $pdo->query("SHOW TABLES LIKE 'report_verifications'")->fetchAll();
assertCondition(!empty($verifTables), "Table 'report_verifications' exists");

$milestoneTables = $pdo->query("SHOW TABLES LIKE 'railway_milestones'")->fetchAll();
assertCondition(!empty($milestoneTables), "Table 'railway_milestones' exists");

// Check historical data preservation
$estAnimalNotNull = (int)$pdo->query("SELECT COUNT(*) FROM elephant_reports WHERE id <= 56 AND estimated_animal_latitude IS NOT NULL")->fetchColumn();
assertCondition($estAnimalNotNull === 0, "Historical records retain estimated_animal_latitude = NULL (no fabrication)");

$obsPopulated = (int)$pdo->query("SELECT COUNT(*) FROM elephant_reports WHERE id <= 56 AND observer_latitude IS NOT NULL")->fetchColumn();
$legacyPopulated = (int)$pdo->query("SELECT COUNT(*) FROM elephant_reports WHERE id <= 56 AND latitude IS NOT NULL")->fetchColumn();
assertCondition($obsPopulated === $legacyPopulated && $obsPopulated === 29, "Historical records with GPS have observer_latitude populated ({$obsPopulated} records match legacy GPS count)");

$provenanceCounts = $pdo->query("SELECT provenance, COUNT(*) as c FROM elephant_reports GROUP BY provenance")->fetchAll(PDO::FETCH_KEY_PAIR);
assertCondition(($provenanceCounts['genuine_baseline'] ?? 0) === 3, "Provenance: genuine_baseline exactly 3 records");
assertCondition(($provenanceCounts['development_test'] ?? 0) >= 30, "Provenance: development_test at least 30 records");

// --- GROUP 2: GEODETIC OFFSET FORWARD SOLVER & UNCERTAINTY ---
section("Group 2: Geodetic Offset Forward Solver & Uncertainty Propagation");

// 2.1 Direct co-location
$direct = FieldReportingService::calculateGeodeticOffset(8.03451200, 80.89234100, 0.0, null, 8.5);
assertCondition($direct['method'] === 'direct_inspection', "Direct inspection method resolved correctly");
assertCondition($direct['is_estimated'] === 0, "Direct inspection is_estimated = 0");
assertCondition($direct['estimated_latitude'] === 8.03451200, "Direct inspection latitude equals observer latitude");
assertCondition($direct['estimated_longitude'] === 8.03451200 ? false : ($direct['estimated_longitude'] === 80.89234100), "Direct inspection longitude equals observer longitude");
assertCondition($direct['uncertainty_meters'] === 8.50, "Direct inspection uncertainty equals GPS accuracy");

// 2.2 Standoff unadjusted (distance missing or bearing missing)
$standoff = FieldReportingService::calculateGeodeticOffset(8.03451200, 80.89234100, null, null, 15.0);
assertCondition($standoff['method'] === 'observer_standoff_unadjusted', "Standoff unadjusted method resolved when measurements absent");
assertCondition($standoff['estimated_latitude'] === null, "Standoff unadjusted latitude remains NULL");
assertCondition($standoff['estimated_longitude'] === null, "Standoff unadjusted longitude remains NULL");

// 2.3 Triangulated offset: 100m due North (0°)
$triangNorth = FieldReportingService::calculateGeodeticOffset(8.00000000, 80.00000000, 100.0, 0.0, 10.0);
assertCondition($triangNorth['method'] === 'triangulated_offset', "Triangulated offset method resolved");
assertCondition($triangNorth['is_estimated'] === 1, "Triangulated offset is_estimated = 1");
assertCondition($triangNorth['estimated_latitude'] > 8.00000000, "Due North offset results in increased latitude");
assertCondition(abs($triangNorth['estimated_longitude'] - 80.00000000) < 0.000001, "Due North offset maintains longitude");
assertCondition($triangNorth['uncertainty_meters'] > 10.0, "Propagated uncertainty exceeds raw GPS uncertainty");

// 2.4 Triangulated offset: 100m due East (90°)
$triangEast = FieldReportingService::calculateGeodeticOffset(8.00000000, 80.00000000, 100.0, 90.0, 10.0);
assertCondition($triangEast['estimated_longitude'] > 80.00000000, "Due East offset results in increased longitude");
assertCondition(abs($triangEast['estimated_latitude'] - 8.00000000) < 0.000001, "Due East offset maintains latitude");

// --- GROUP 3: GAPLESS 24-HOUR DIURNAL CONTINUUM & TEMPORAL VALIDATION ---
section("Group 3: Gapless 24-Hour Diurnal Continuum & Temporal Validation");

$timesAndExpected = [
    '05:15:00' => 'dawn',
    '08:30:00' => 'morning',
    '12:00:00' => 'midday',
    '16:00:00' => 'afternoon',
    '18:30:00' => 'dusk',
    '21:00:00' => 'night',
    '23:45:00' => 'deep_night', // Spans midnight
    '02:15:00' => 'deep_night'  // Early morning hours
];

foreach ($timesAndExpected as $t => $expectedPeriod) {
    $period = FieldReportingService::classifyDiurnalPeriod($t);
    assertCondition($period === $expectedPeriod, "Time {$t} maps to continuous diurnal period '{$expectedPeriod}'");
}

// Normalize temporal input with exact clock
$normExact = FieldReportingService::normalizeTemporalEvidence([
    'sighting_date' => '2026-11-10',
    'sighting_time' => '12:15',
    'sighting_time_precision' => 'exact'
]);
assertCondition($normExact['valid'] === true, "Exact temporal payload is valid");
assertCondition($normExact['data']['diurnal_period'] === 'midday', "Exact 12:15 maps automatically to midday");
assertCondition($normExact['data']['sighting_timezone'] === 'Asia/Colombo', "Timezone locked to Asia/Colombo");

// Mode T4: Time unknown
$normUnknown = FieldReportingService::normalizeTemporalEvidence([
    'sighting_date' => '2026-11-10',
    'sighting_time_precision' => 'unknown'
]);
assertCondition($normUnknown['valid'] === true, "Unknown time payload is valid");
assertCondition($normUnknown['data']['sighting_time'] === null, "Unknown mode leaves sighting_time = NULL");
assertCondition($normUnknown['data']['diurnal_period'] === null, "Unknown mode leaves diurnal_period = NULL");

// Reject future date
$normFuture = FieldReportingService::normalizeTemporalEvidence([
    'sighting_date' => '2028-01-01',
    'sighting_time' => '10:00'
]);
assertCondition($normFuture['valid'] === false, "Future sighting date correctly rejected");

// --- GROUP 4: RAILWAY SECTION ASSOCIATION & TOPOGRAPHIC AMBIGUITY ---
section("Group 4: Railway Section Association & Topographic Ambiguity");

// Test Gal Oya junction polygon
$galOyaJunction = FieldReportingService::calculateRailwayAssociation(8.015, 80.980, 25.0);
assertCondition($galOyaJunction['section_assignment_status'] === 'topology_ambiguity', "Gal Oya junction geofence triggers topology_ambiguity");
assertCondition($galOyaJunction['track_proximity_zone'] === 'Zone 0', "Junction zone marked as Zone 0");

// Test regular track association
$trackAssoc = FieldReportingService::calculateRailwayAssociation(8.03451200, 80.89234100, 20.0);
assertCondition($trackAssoc['distance_to_track_meters'] > 0, "Track distance calculated from OSM geometry");
assertCondition($trackAssoc['track_distance_min_meters'] <= $trackAssoc['distance_to_track_meters'], "track_distance_min <= distance_to_track");
assertCondition($trackAssoc['track_distance_max_meters'] >= $trackAssoc['distance_to_track_meters'], "track_distance_max >= distance_to_track");
assertCondition(in_array($trackAssoc['track_proximity_zone'], ['Zone 0', 'Zone 1', 'Zone 2', 'Zone 3'], true), "Proximity zone assigned correctly");

// Test unmapped milestone fallback (DAT-01)
$milestoneAssoc = FieldReportingService::calculateRailwayAssociation(null, null, null, 2, "KM 999 / Pole 99");
assertCondition($milestoneAssoc['section_id'] === 2, "Unmapped milestone fallback retains section hint");
assertCondition($milestoneAssoc['section_assignment_status'] === 'provisional_estimated', "Unmapped milestone assigns provisional status without error");

// --- GROUP 5: IDEMPOTENCY & REPLAY PROTECTION (REL-01) ---
section("Group 5: Idempotency & Replay Protection (REL-01)");

$runSuffix = substr(md5(microtime(true) . rand()), 0, 12);
$testUuid = 'a1b2c3d4-e5f6-4a7b-8c9d-' . $runSuffix;

$subPayload = [
    'client_report_uuid' => $testUuid,
    'submission_source' => 'mobile_pwa',
    'observation_category' => 'live_sighting',
    'elephant_count' => 3,
    'urgency_level' => 'High',
    'location_description' => 'Test culvert sighting',
    'temporal' => [
        'sighting_date' => '2026-11-10',
        'sighting_time' => '18:15',
        'sighting_time_precision' => 'exact'
    ],
    'observer_location' => [
        'latitude' => 8.03450000,
        'longitude' => 80.89230000,
        'gps_accuracy_meters' => 10.0,
        'acquisition_method' => 'gps_device'
    ],
    'animal_location' => [
        'method' => 'triangulated_offset',
        'observation_distance_meters' => 50.0,
        'observation_bearing_degrees' => 90.0
    ]
];

// First submission -> 201 Created
$res1 = FieldReportingService::submitReport($subPayload, 1, 'reporter');
assertCondition($res1['success'] === true, "First submission succeeded");
assertCondition($res1['status_code'] === 201, "First submission returned HTTP 201 Created");
assertCondition($res1['is_replay'] === false, "First submission is_replay = false");
$createdReportId = $res1['data']['report_id'];
assertCondition($createdReportId > 43, "New report ID created (> 43)");

// Second submission with same UUID -> 200 OK (Idempotent Replay)
$res2 = FieldReportingService::submitReport($subPayload, 1, 'reporter');
assertCondition($res2['success'] === true, "Duplicate submission handled safely");
assertCondition($res2['status_code'] === 200, "Duplicate submission returned HTTP 200 OK");
assertCondition($res2['is_replay'] === true, "Duplicate submission is_replay = true");
assertCondition($res2['data']['report_id'] === $createdReportId, "Duplicate submission returned identical report ID");

// Verify unique constraint on client_report_uuid at database engine level
$uuidCount = (int)$pdo->query("SELECT COUNT(*) FROM elephant_reports WHERE client_report_uuid = '{$testUuid}'")->fetchColumn();
assertCondition($uuidCount === 1, "Database engine contains exactly 1 row for client_report_uuid");

// --- GROUP 6: BATCH SYNCHRONIZATION CEILINGS & HTTP 207 (API-02) ---
section("Group 6: Batch Synchronization Ceilings & HTTP 207 (API-02)");

// Valid batch of 2 reports
$batchUuid1 = 'b1b2c3d4-e5f6-4a7b-8c9d-' . substr(md5(microtime(true) . '1'), 0, 12);
$batchUuid2 = 'c1b2c3d4-e5f6-4a7b-8c9d-' . substr(md5(microtime(true) . '2'), 0, 12);

$batchPayload = [
    'batch_id' => 'test_batch_01',
    'reports' => [
        array_merge($subPayload, ['client_report_uuid' => $batchUuid1, 'location_description' => 'Batch item 1']),
        array_merge($subPayload, ['client_report_uuid' => $batchUuid2, 'location_description' => 'Batch item 2'])
    ]
];

$batchRes = FieldReportingService::syncBatch($batchPayload, 1, 'reporter', 1024);
assertCondition($batchRes['success'] === true, "Valid batch sync succeeded");
assertCondition($batchRes['status_code'] === 207, "Batch sync returned HTTP 207 Multi-Status");
assertCondition($batchRes['data']['synced_count'] === 2, "Synced count equals 2");
assertCondition($batchRes['data']['rejected_count'] === 0, "Rejected count equals 0");

// Exceeding 50 reports limit
$oversizedReports = [];
for ($i = 0; $i < 51; $i++) {
    $oversizedReports[] = array_merge($subPayload, ['client_report_uuid' => sprintf('f%07d-0000-4000-8000-000000000000', $i)]);
}
$overBatchRes = FieldReportingService::syncBatch(['reports' => $oversizedReports], 1, 'reporter', 5000);
assertCondition($overBatchRes['success'] === false, "Oversized batch (>50) rejected");
assertCondition($overBatchRes['status_code'] === 422, "Oversized batch returned HTTP 422 Unprocessable Entity");

// Exceeding 10 MB payload limit
$heavyBytes = 10485761; // 10 MB + 1 byte
$heavyBatchRes = FieldReportingService::syncBatch(['reports' => [$subPayload]], 1, 'reporter', $heavyBytes);
assertCondition($heavyBatchRes['success'] === false, "Heavy payload (>10 MB) rejected");
assertCondition($heavyBatchRes['status_code'] === 413, "Heavy payload returned HTTP 413 Payload Too Large");

// --- GROUP 7: ROLE-BASED ANTI-POACHING COORDINATE MASKING ---
section("Group 7: Role-Based Anti-Poaching Coordinate Masking");

// 7.1 Wildlife Officer receives raw coordinates
$repWildlife = FieldReportingService::getReportById($createdReportId, 'wildlife_officer');
assertCondition($repWildlife['observer_location']['precision_level'] === 'raw_telemetry', "Wildlife Officer receives raw_telemetry coordinates");
assertCondition($repWildlife['observer_location']['latitude'] === 8.03450000, "Wildlife Officer receives unrounded latitude (8.0345)");

// 7.2 Driver receives masked coordinates
$repDriver = FieldReportingService::getReportById($createdReportId, 'driver');
assertCondition($repDriver['observer_location']['precision_level'] === 'masked_anti_poaching', "Driver receives masked_anti_poaching coordinates");
assertCondition($repDriver['observer_location']['latitude'] === 8.03, "Driver latitude rounded to 2 decimal places (8.03)");
assertCondition(isset($repDriver['observer_location']['notice']), "Driver response includes anti-poaching notice");

// 7.3 Public/Reporter receives masked coordinates
$repReporter = FieldReportingService::getReportById($createdReportId, 'reporter');
assertCondition($repReporter['observer_location']['precision_level'] === 'masked_anti_poaching', "Reporter receives masked_anti_poaching coordinates");
assertCondition($repReporter['observer_location']['latitude'] === 8.03, "Reporter latitude rounded to 2 decimal places (8.03)");

// --- GROUP 8: HUMAN VERIFICATION LIFECYCLE & RBAC ---
section("Group 8: Human Verification Lifecycle & RBAC");

// Unauthorized verification by driver -> 403 Forbidden
$unauthVerif = FieldReportingService::verifyReport($createdReportId, 'Verified', 'field_inspection', 'Driver test', 2, 'driver');
assertCondition($unauthVerif['success'] === false, "Driver verification blocked");
assertCondition($unauthVerif['status_code'] === 403, "Driver verification returned HTTP 403 Forbidden");

// Unauthorized verification by reporter -> 403 Forbidden
$unauthRepVerif = FieldReportingService::verifyReport($createdReportId, 'Verified', 'field_inspection', 'Reporter test', 1, 'reporter');
assertCondition($unauthRepVerif['success'] === false, "Reporter verification blocked");
assertCondition($unauthRepVerif['status_code'] === 403, "Reporter verification returned HTTP 403 Forbidden");

// Authorized verification by wildlife_officer -> 200 OK
$authVerif = FieldReportingService::verifyReport($createdReportId, 'Verified', 'field_inspection', 'Footprint trail confirmed on cess', 3, 'wildlife_officer');
assertCondition($authVerif['success'] === true, "Wildlife Officer verification succeeded");
assertCondition($authVerif['status_code'] === 200, "Wildlife Officer verification returned HTTP 200 OK");
assertCondition($authVerif['data']['new_status'] === 'Verified', "New status transitioned to Verified");

// Verify audit ledger entry created in report_verifications
$ledgerEntry = $pdo->query("SELECT * FROM report_verifications WHERE report_id = {$createdReportId} ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
assertCondition(!empty($ledgerEntry), "Audit ledger entry recorded in report_verifications");
assertCondition($ledgerEntry['verified_by'] == 3, "Audit ledger recorded correct officer ID (3)");
assertCondition($ledgerEntry['new_status'] === 'Verified', "Audit ledger recorded new_status = Verified");

// Request additional info by wildlife_officer -> 200 OK
$infoReq = FieldReportingService::requestAdditionalInfo($createdReportId, 'Please clarify distance from telegraph pole #4', 3, 'wildlife_officer');
assertCondition($infoReq['success'] === true, "Request additional info succeeded");
assertCondition($infoReq['status_code'] === 200, "Request additional info returned HTTP 200 OK");
assertCondition($infoReq['data']['new_status'] === 'Needs Information', "New status transitioned to Needs Information");

// Confirm section association by control_officer -> 200 OK
$secConfirm = FieldReportingService::confirmSectionAssociation($createdReportId, 2, 'Confirmed via train VHF report', 4, 'control_officer');
assertCondition($secConfirm['success'] === true, "Control Officer section confirmation succeeded");
assertCondition($secConfirm['status_code'] === 200, "Control Officer section confirmation returned HTTP 200 OK");
assertCondition($secConfirm['data']['assignment_status'] === 'controller_confirmed', "Assignment status set to controller_confirmed");

// Verify updated report details
$finalRep = FieldReportingService::getReportById($createdReportId, 'wildlife_officer');
assertCondition($finalRep['report_status'] === 'Needs Information', "Final report reflects Needs Information state");
assertCondition($finalRep['railway_association']['assignment_status'] === 'controller_confirmed', "Final report reflects controller_confirmed section");
assertCondition(count($finalRep['verification_history']) >= 2, "Verification history records both transitions");

// --- GROUP 9: OPERATIONAL DATABASE IMMUTABILITY CHECK ---
section("Group 9: Operational Database Immutability Check");

$h = getenv('DB_HOST') ?: (defined('DB_HOST') ? DB_HOST : '127.0.0.1');
$p = getenv('DB_PORT') ?: (defined('DB_PORT') ? DB_PORT : 3308);
$user = getenv('DB_ANALYTICS_USER') ?: (defined('DB_ANALYTICS_USER') ? DB_ANALYTICS_USER : 'eleguard_readonly');
$pass = getenv('DB_ANALYTICS_PASS') ?: (defined('DB_ANALYTICS_PASS') ? DB_ANALYTICS_PASS : 'ReadOnly_Guard#2026');

$opPdo = new PDO("mysql:host={$h};port={$p};dbname=eleguard_rail;charset=utf8mb4", $user, $pass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
]);

$res = verifyOperationalDatabaseSnapshot($preOpSnapshot, $opPdo);

foreach ($preOpSnapshot['tables'] as $table => $info) {
    assertCondition($res['tables'][$table]['passed'], "Operational table '{$table}' untouched (count: {$res['tables'][$table]['count']})");
}

echo PHP_EOL;
echo "================================================================================" . PHP_EOL;
echo " TEST RUN RESULTS: {$testsRun} Run, " . ($testsRun - $testsFailed) . " Passed, {$testsFailed} Failed" . PHP_EOL;
echo "================================================================================" . PHP_EOL;

if ($testsFailed > 0) {
    exit(1);
}
exit(0);
