<?php
/**
 * EleGuard Rail - Phase 6 Automated Test Suite
 * Tests GPS submissions, validation, persistence, authentication, and end-to-end alert workflows.
 */

// Configure CLI session handling cleanly
ini_set('session.use_cookies', 0);
@session_start();

require_once __DIR__ . '/bootstrap.php';
ensureTestDatabaseExists();

require_once __DIR__ . '/../app/config/database.php';
require_once __DIR__ . '/../app/core/Database.php';
require_once __DIR__ . '/../app/core/Auth.php';
require_once __DIR__ . '/../app/core/Session.php';
require_once __DIR__ . '/../app/includes/validation.php';
require_once __DIR__ . '/../app/services/ReportService.php';
require_once __DIR__ . '/../app/services/AlertService.php';
require_once __DIR__ . '/../app/services/RiskService.php';

$testResults = [];
function recordTest($name, $passed, $details = '') {
    global $testResults;
    $testResults[] = [
        'name' => $name,
        'passed' => $passed,
        'details' => $details
    ];
    $icon = $passed ? '✓ PASS' : '✗ FAIL';
    echo "[$icon] $name" . ($details ? " ($details)" : "") . "\n";
}

echo "========================================================\n";
echo "EleGuard Rail - Phase 6 Test Execution\n";
echo "========================================================\n\n";

$db = Database::getInstance()->getConnection();

// ---------------------------------------------------------
// TEST GROUP 1: Authentication & Role Verification
// ---------------------------------------------------------
echo "-- Test Group 1: Authentication & Roles --\n";

$rolesToTest = [
    ['admin@eleguard.lk', 'admin123', 'admin'],
    ['control@eleguard.lk', 'control123', 'control_officer'],
    ['driver@eleguard.lk', 'driver123', 'driver'],
    ['wildlife@eleguard.lk', 'wildlife123', 'wildlife_officer'],
    ['reporter@eleguard.lk', 'reporter123', 'reporter']
];

foreach ($rolesToTest as [$email, $password, $expectedRole]) {
    $ok = Auth::login($email, $password);
    $roleMatched = (Auth::role() === $expectedRole);
    recordTest("Authenticate {$expectedRole} ({$email})", $ok && $roleMatched, ($ok && $roleMatched) ? "Role matched: {$expectedRole}" : "Failed auth");
}

// Invalid password test
$badLogin = Auth::login('admin@eleguard.lk', 'wrong_password_999');
recordTest("Reject invalid password", ($badLogin === false), "Correctly returned false");

// ---------------------------------------------------------
// TEST GROUP 2: Coordinate Validation Unit Tests
// ---------------------------------------------------------
echo "\n-- Test Group 2: Coordinate Validation Unit Tests --\n";

// Valid coordinates
$v1 = Validation::coordinate('8.035412', '80.751104');
recordTest("Validate normal coordinates (8.0354, 80.7511)", empty($v1), "No errors");

// Empty/missing coordinates (legacy text-only reports)
$v2 = Validation::coordinate('', '');
recordTest("Validate empty coordinates (null/blank)", empty($v2), "Allowed for text-only reports");

// Out of bounds latitude (> 90)
$v3 = Validation::coordinate('95.1234', '80.7511');
recordTest("Reject latitude > 90 (95.1234)", !empty($v3['latitude']), $v3['latitude'] ?? '');

// Out of bounds latitude (< -90)
$v4 = Validation::coordinate('-95.1234', '80.7511');
recordTest("Reject latitude < -90 (-95.1234)", !empty($v4['latitude']), $v4['latitude'] ?? '');

// Out of bounds longitude (> 180)
$v5 = Validation::coordinate('8.0354', '185.0000');
recordTest("Reject longitude > 180 (185.0)", !empty($v5['longitude']), $v5['longitude'] ?? '');

// Non-numeric coordinate
$v6 = Validation::coordinate('invalid_lat', '80.7511');
recordTest("Reject non-numeric coordinate ('invalid_lat')", !empty($v6['latitude']), $v6['latitude'] ?? '');

// ---------------------------------------------------------
// TEST GROUP 3: Database Persistence Tests
// ---------------------------------------------------------
echo "\n-- Test Group 3: Database Persistence Tests --\n";

// Fetch reporter user ID
$repUser = $db->query("SELECT id FROM users WHERE role = 'reporter' LIMIT 1")->fetch();
$reporterId = $repUser['id'];

// Test 3.1: Existing text-only report submission (missing GPS)
$t1Desc = "QA-Test-TextOnly-" . time();
$s1 = ReportService::submitReport(1, $reporterId, 2, "Near Curve 44 (Text Only)", "Medium", "Testing text-only submission.", null, null, null, null);
$r1 = $db->query("SELECT * FROM elephant_reports WHERE location_description = 'Near Curve 44 (Text Only)' ORDER BY id DESC LIMIT 1")->fetch();
$t1Ok = ($s1 && $r1 && $r1['latitude'] === null && $r1['longitude'] === null && $r1['gps_accuracy_meters'] === null);
recordTest("Submit text-only report (GPS is NULL)", $t1Ok, "Report #{$r1['id']}, lat=NULL, lng=NULL");

// Test 3.2: GPS-enabled submission (simulating captured Geolocation with accuracy)
$latTest = 8.041250;
$lngTest = 80.825640;
$accTest = 14.5;
$s2 = ReportService::submitReport(2, $reporterId, 3, "Near Minneriya Crossing Marker 105", "High", "Testing GPS-enabled submission.", null, $latTest, $lngTest, $accTest);
$r2 = $db->query("SELECT * FROM elephant_reports WHERE location_description = 'Near Minneriya Crossing Marker 105' ORDER BY id DESC LIMIT 1")->fetch();
$t2Ok = ($s2 && $r2 && abs((float)$r2['latitude'] - $latTest) < 0.0001 && abs((float)$r2['longitude'] - $lngTest) < 0.0001 && (float)$r2['gps_accuracy_meters'] == 14.5);
recordTest("Submit GPS-enabled report (Lat, Lng, Accuracy)", $t2Ok, "Report #{$r2['id']}, lat={$r2['latitude']}, lng={$r2['longitude']}, acc={$r2['gps_accuracy_meters']}m");

// Test 3.3: Manual coordinate submission (user typed coordinates, accuracy is null)
$latManual = 8.125600;
$lngManual = 80.912400;
$s3 = ReportService::submitReport(1, $reporterId, 1, "Gal Oya Manual Coordinates", "Low", "Testing manual coordinate entry.", null, $latManual, $lngManual, null);
$r3 = $db->query("SELECT * FROM elephant_reports WHERE location_description = 'Gal Oya Manual Coordinates' ORDER BY id DESC LIMIT 1")->fetch();
$t3Ok = ($s3 && $r3 && abs((float)$r3['latitude'] - $latManual) < 0.0001 && $r3['gps_accuracy_meters'] === null);
recordTest("Submit manual coordinate report (Accuracy is NULL)", $t3Ok, "Report #{$r3['id']}, lat={$r3['latitude']}, lng={$r3['longitude']}");

// ---------------------------------------------------------
// TEST GROUP 4: Full End-to-End Workflow Verification
// ---------------------------------------------------------
echo "\n-- Test Group 4: End-to-End Wildlife & Control Workflow --\n";

// Step 4.1: Ranger verifies the GPS report
$ranger = $db->query("SELECT id FROM users WHERE role = 'wildlife_officer' LIMIT 1")->fetch();
$verifySuccess = ReportService::updateStatus((int)$r2['id'], 'Verified', $ranger['id']);
$r2Updated = ReportService::getById((int)$r2['id']);
recordTest("Wildlife Officer marks GPS report #{$r2['id']} as 'Verified'", ($verifySuccess && $r2Updated['report_status'] === 'Verified'), "Status: {$r2Updated['report_status']}");

// Step 4.2: Control Officer converts verified GPS report into Active Alert
$controller = $db->query("SELECT id FROM users WHERE role = 'control_officer' LIMIT 1")->fetch();
$alertMsg = "COLLISION HAZARD: Sighting of {$r2Updated['elephant_count']} elephant(s) at {$r2Updated['location_description']} (GPS: {$r2Updated['latitude']}, {$r2Updated['longitude']}).";
$alertId = AlertService::createAlert((int)$r2['id'], (int)$r2Updated['section_id'], $r2Updated['risk_score'], $alertMsg, $controller['id']);

$alertRecord = $db->query("SELECT * FROM alerts WHERE id = " . (int)$alertId)->fetch();
$r2AfterAlert = ReportService::getById((int)$r2['id']);
$alertOk = ($alertId && $alertRecord && $alertRecord['alert_status'] === 'Active' && $r2AfterAlert['report_status'] === 'Converted to Alert');
recordTest("Control broadcasts active safety alert from verified GPS report", $alertOk, "Alert #{$alertId}, Status: Active, Report Status: Converted to Alert");

// Step 4.3: Train Driver acknowledges the alert
$driver = $db->query("SELECT id FROM users WHERE role = 'driver' LIMIT 1")->fetch();
$ackSuccess = AlertService::acknowledgeAlert((int)$alertId, $driver['id'], 'Slowing Down', 'Acknowledged hazard via cab dashboard. Reducing speed to 15km/h.');
$ackRecord = $db->query("SELECT * FROM driver_acknowledgements WHERE alert_id = " . (int)$alertId . " AND driver_id = " . $driver['id'])->fetch();
$ackOk = ($ackSuccess && $ackRecord && $ackRecord['action_status'] === 'Slowing Down');
recordTest("Train Driver submits speed response ('Slowing Down')", $ackOk, "Action: {$ackRecord['action_status']}, Note: {$ackRecord['note']}");

// Step 4.4: Verify system audit log
$logs = $db->query("SELECT COUNT(*) FROM system_logs WHERE action IN ('Report Updated', 'Alert Created', 'Driver Response')")->fetchColumn();
recordTest("System Audit Logs recorded for entire lifecycle", ($logs >= 3), "Found {$logs} audit log events");

echo "\n========================================================\n";
$totalTests = count($testResults);
$totalPass = count(array_filter($testResults, fn($t) => $t['passed']));
echo "Test Execution Summary: {$totalPass} / {$totalTests} PASSED\n";
echo "========================================================\n";

if ($totalPass === $totalTests) {
    exit(0);
} else {
    exit(1);
}
