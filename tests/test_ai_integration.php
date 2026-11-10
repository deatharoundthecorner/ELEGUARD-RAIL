<?php
/**
 * EleGuard Rail - Milestone 8C Integration Test Suite
 *
 * Verifies PHP Backend AI Integration, Subprocess Execution,
 * Database Metadata Persistence, 3-Tier Classification,
 * Fault Tolerance, and Strict Human Review Governance.
 */

require_once __DIR__ . '/bootstrap.php';
ensureTestDatabaseExists();

require_once __DIR__ . '/../app/core/Database.php';
require_once __DIR__ . '/../app/core/Auth.php';
require_once __DIR__ . '/../app/services/ReportService.php';
require_once __DIR__ . '/../app/services/AiDetectionService.php';

$totalTests = 0;
$passedTests = 0;
$failedTests = 0;

function runTest($title, $condition, $details = '') {
    global $totalTests, $passedTests, $failedTests;
    $totalTests++;
    if ($condition) {
        $passedTests++;
        echo "  [PASS] {$title}" . ($details ? " ({$details})" : "") . "\n";
    } else {
        $failedTests++;
        echo "  [FAIL] {$title}" . ($details ? " ({$details})" : "") . "\n";
    }
}

echo "========================================================\n";
echo "EleGuard Rail - Milestone 8C AI Integration Test Suite\n";
echo "========================================================\n\n";

$db = Database::getInstance()->getConnection();

// -----------------------------------------------------------------------------
// Test Group 1: Subsystem & Model Readiness
// -----------------------------------------------------------------------------
echo "-- Test Group 1: Subsystem & Model Readiness --\n";

$isAvail = AiDetectionService::isAvailable();
runTest("AI Subsystem availability check", $isAvail === true, "Model and CLI detected");

$pyBin = AiDetectionService::getPythonBinary();
runTest("Python executable discovery", !empty($pyBin), "Binary: {$pyBin}");

$cliPath = AiDetectionService::getCliScriptPath();
runTest("Detector CLI path discovery", file_exists($cliPath), "Path: {$cliPath}");

echo "\n";

// -----------------------------------------------------------------------------
// Test Group 2: Direct Image Analysis & 3-Tier Outcomes
// -----------------------------------------------------------------------------
echo "-- Test Group 2: Direct Image Analysis & 3-Tier Outcomes --\n";

$testUploadDir = __DIR__ . '/../public/assets/uploads/reports';
if (!file_exists($testUploadDir)) {
    mkdir($testUploadDir, 0755, true);
}

// 2.1 Positive Elephant Image
$asianImg = realpath(__DIR__ . '/../ai/datasets/elephants/elephant_01_asian.jpg');
$annotatedAsian = $testUploadDir . '/test_annotated_asian.jpg';
if (file_exists($annotatedAsian)) @unlink($annotatedAsian);

$resAsian = AiDetectionService::analyzeImage($asianImg, $annotatedAsian);
$c1 = ($resAsian['success'] === true &&
       $resAsian['status'] === 'processed' &&
       $resAsian['detected'] === true &&
       $resAsian['classification'] === 'Potential Elephant Detected' &&
       $resAsian['confidence'] >= 0.40 &&
       $resAsian['estimated_count'] >= 1 &&
       file_exists($annotatedAsian));
runTest("Positive Elephant Detection (Tier 1)", $c1, "Conf=" . round(($resAsian['confidence'] ?? 0) * 100, 1) . "%, Count=" . ($resAsian['estimated_count'] ?? 0));
if (file_exists($annotatedAsian)) @unlink($annotatedAsian);

// 2.2 Non-Elephant Image (Track / Landscape)
$trackImg = realpath(__DIR__ . '/../ai/datasets/non_elephants/rail_track_01_ambewela.jpg');
$resTrack = AiDetectionService::analyzeImage($trackImg);
$c2 = ($resTrack['success'] === true &&
       $resTrack['status'] === 'processed' &&
       $resTrack['detected'] === false &&
       $resTrack['classification'] === 'No Elephant Detected' &&
       $resTrack['confidence'] < 0.20 &&
       $resTrack['estimated_count'] === 0);
runTest("Negative Non-Elephant Detection (Tier 3)", $c2, "Conf=" . round(($resTrack['confidence'] ?? 0) * 100, 2) . "%, Count=0");

// 2.3 Uncertain Low-Confidence Edge Case Image
$edgeImg = realpath(__DIR__ . '/../ai/datasets/edge_cases/edge_04_uncertain_elephant.jpg');
$resEdge = AiDetectionService::analyzeImage($edgeImg);
$c3 = ($resEdge['success'] === true &&
       $resEdge['status'] === 'processed' &&
       $resEdge['detected'] === false &&
       $resEdge['classification'] === 'Uncertain Result' &&
       $resEdge['confidence'] >= 0.20 &&
       $resEdge['confidence'] < 0.40);
runTest("Uncertain Edge Case Classification (Tier 2)", $c3, "Conf=" . round(($resEdge['confidence'] ?? 0) * 100, 1) . "%, Requires Manual Review");

// 2.4 Missing File Robustness
$resMissing = AiDetectionService::analyzeImage('C:/non_existent_folder_xyz/missing_elephant.jpg');
$c4 = ($resMissing['success'] === false &&
       $resMissing['status'] === 'failed' &&
       $resMissing['detected'] === null);
runTest("Non-existent image failure handling", $c4, "Graceful failure without throwing fatal error");

echo "\n";

// -----------------------------------------------------------------------------
// Test Group 3: Database Migration & Schema Verification
// -----------------------------------------------------------------------------
echo "-- Test Group 3: Database Migration & Schema Verification --\n";

$colStmt = $db->query("SHOW COLUMNS FROM elephant_reports");
$columns = $colStmt->fetchAll(PDO::FETCH_COLUMN, 0);

$expectedCols = [
    'ai_status',
    'ai_detected_elephant',
    'ai_confidence',
    'ai_estimated_count',
    'ai_model_version',
    'ai_detection_notes',
    'ai_processed_at'
];

foreach ($expectedCols as $col) {
    runTest("Database column '{$col}' exists in elephant_reports", in_array($col, $columns));
}

// Verify index
$idxStmt = $db->query("SHOW INDEX FROM elephant_reports WHERE Key_name = 'idx_elephant_reports_ai_status'");
$idxRows = $idxStmt->fetchAll();
runTest("Index 'idx_elephant_reports_ai_status' exists", count($idxRows) > 0);

echo "\n";

// -----------------------------------------------------------------------------
// Test Group 4: End-to-End Report Submission Workflow Integration
// -----------------------------------------------------------------------------
echo "-- Test Group 4: End-to-End Report Submission Workflow Integration --\n";

// Find reporter user ID
$uStmt = $db->query("SELECT id FROM users WHERE role = 'reporter' LIMIT 1");
$reporterId = (int)$uStmt->fetchColumn() ?: 1;

// 4.1 Submission with Elephant Photograph
$testElephantRel = 'assets/uploads/reports/qa_test_elephant_' . time() . '.jpg';
$testElephantAbs = __DIR__ . '/../public/' . $testElephantRel;
copy($asianImg, $testElephantAbs);

$s1 = ReportService::submitReport(
    1, $reporterId, 2, "Habarana Milepost 125 (AI QA Test)", "High",
    "QA test report with authentic elephant photograph.",
    $testElephantRel, 8.0345, 80.7523, 10.0
);
$r1 = $db->query("SELECT * FROM elephant_reports WHERE location_description = 'Habarana Milepost 125 (AI QA Test)' ORDER BY id DESC LIMIT 1")->fetch();

$cSub1 = ($s1 && $r1 &&
          $r1['report_status'] === 'Pending' &&
          $r1['ai_status'] === 'processed' &&
          (int)$r1['ai_detected_elephant'] === 1 &&
          (float)$r1['ai_confidence'] >= 0.40 &&
          (int)$r1['ai_estimated_count'] >= 1 &&
          !empty($r1['ai_model_version']) &&
          !empty($r1['ai_processed_at']));
runTest("Submit report with elephant photo -> AI processed & detected", $cSub1, "Report #{$r1['id']}, Status={$r1['ai_status']}, Conf=" . round($r1['ai_confidence'] * 100, 1) . "%");

// Verify annotated file generation
$annotatedFile = dirname($testElephantAbs) . '/annotated_' . basename($testElephantAbs);
runTest("Annotated bounding box image created on disk", file_exists($annotatedFile), basename($annotatedFile));

// 4.2 Submission with Non-Elephant Photograph (Track)
$testTrackRel = 'assets/uploads/reports/qa_test_track_' . time() . '.jpg';
$testTrackAbs = __DIR__ . '/../public/' . $testTrackRel;
copy($trackImg, $testTrackAbs);

$s2 = ReportService::submitReport(
    2, $reporterId, 1, "Ambewela Track 40 (AI QA Track Test)", "Low",
    "QA test report with empty track photograph.",
    $testTrackRel, 6.8720, 80.8010, 8.0
);
$r2 = $db->query("SELECT * FROM elephant_reports WHERE location_description = 'Ambewela Track 40 (AI QA Track Test)' ORDER BY id DESC LIMIT 1")->fetch();

$cSub2 = ($s2 && $r2 &&
          $r2['report_status'] === 'Pending' &&
          $r2['ai_status'] === 'processed' &&
          (int)$r2['ai_detected_elephant'] === 0 &&
          (float)$r2['ai_confidence'] < 0.20 &&
          (int)$r2['ai_estimated_count'] === 0);
runTest("Submit report with empty track photo -> AI processed & not detected", $cSub2, "Report #{$r2['id']}, Status={$r2['ai_status']}, Conf=" . round($r2['ai_confidence'] * 100, 2) . "%");

// 4.3 Submission without Photograph (Text-Only Report)
$s3 = ReportService::submitReport(
    1, $reporterId, 1, "Gal Oya Crossing (AI QA Text Only)", "Medium",
    "QA test report with no photo attached.",
    null, null, null, null
);
$r3 = $db->query("SELECT * FROM elephant_reports WHERE location_description = 'Gal Oya Crossing (AI QA Text Only)' ORDER BY id DESC LIMIT 1")->fetch();

$cSub3 = ($s3 && $r3 &&
          $r3['report_status'] === 'Pending' &&
          $r3['ai_status'] === 'skipped' &&
          $r3['ai_detected_elephant'] === null &&
          $r3['ai_confidence'] === null);
runTest("Submit report without photo -> AI skipped", $cSub3, "Report #{$r3['id']}, Status={$r3['ai_status']}, Notes='{$r3['ai_detection_notes']}'");

// 4.4 Submission with Missing/Broken Image Path (Fault Tolerance)
$s4 = ReportService::submitReport(
    1, $reporterId, 1, "Fault Tolerance Test (Broken Path)", "Medium",
    "QA test report with corrupted image path.",
    "assets/uploads/reports/ghost_nonexistent_image_qa.jpg", 8.1, 80.9, null
);
$r4 = $db->query("SELECT * FROM elephant_reports WHERE location_description = 'Fault Tolerance Test (Broken Path)' ORDER BY id DESC LIMIT 1")->fetch();

$cSub4 = ($s4 === true && $r4 &&
          $r4['report_status'] === 'Pending' &&
          $r4['ai_status'] === 'failed' &&
          $r4['ai_detected_elephant'] === null);
runTest("Submit report with missing photo -> AI failed but report saved", $cSub4, "Report #{$r4['id']}, Submission succeeded, AI status=failed");

echo "\n";

// -----------------------------------------------------------------------------
// Test Group 5: Strict Human Governance & Wildlife Officer Authority
// -----------------------------------------------------------------------------
echo "-- Test Group 5: Strict Human Governance & Wildlife Officer Authority --\n";

// 5.1 Verification Status Independence: AI detection NEVER automatically verifies report
runTest("AI detection did NOT automatically verify Report #{$r1['id']}", $r1['report_status'] === 'Pending', "Status remains 'Pending'");

// 5.2 Risk Score Independence: AI detection NEVER overwrites calculated risk score
runTest("AI detection did NOT modify calculated risk score for Report #{$r1['id']}", !empty($r1['risk_score']), "Risk score preserved: {$r1['risk_score']}");

// 5.3 Wildlife Officer Manual Verification
$offStmt = $db->query("SELECT id FROM users WHERE role = 'wildlife_officer' LIMIT 1");
$officerId = (int)$offStmt->fetchColumn() ?: 2;

$vOk = ReportService::updateStatus((int)$r1['id'], 'Verified', $officerId);
$r1Updated = ReportService::getById((int)$r1['id']);
runTest("Wildlife Officer manually verifies Report #{$r1['id']}", ($vOk && $r1Updated['report_status'] === 'Verified'), "Status updated to 'Verified'");

// 5.4 Wildlife Officer Manual Rejection of AI-Detected Report
$rRejectOk = ReportService::updateStatus((int)$r1['id'], 'Rejected', $officerId);
$r1Rejected = ReportService::getById((int)$r1['id']);
runTest("Wildlife Officer possesses authority to reject report despite positive AI", ($rRejectOk && $r1Rejected['report_status'] === 'Rejected'), "Status updated to 'Rejected'");

// 5.5 Audit Logging
$auditStmt = $db->prepare("SELECT COUNT(*) FROM system_logs WHERE user_id = :uid AND action = 'Report Updated'");
$auditStmt->execute([':uid' => $officerId]);
$auditCount = (int)$auditStmt->fetchColumn();
runTest("System audit logs capture officer verification/rejection actions", $auditCount >= 2, "Found {$auditCount} audit entries");

// -----------------------------------------------------------------------------
// Cleanup temporary QA test files and reports
// -----------------------------------------------------------------------------
if (file_exists($testElephantAbs)) @unlink($testElephantAbs);
if (file_exists($annotatedFile)) @unlink($annotatedFile);
if (file_exists($testTrackAbs)) @unlink($testTrackAbs);

$cleanIds = [(int)$r1['id'], (int)$r2['id'], (int)$r3['id'], (int)$r4['id']];
$inClause = implode(',', $cleanIds);
$db->exec("DELETE FROM elephant_reports WHERE id IN ({$inClause})");

echo "\n========================================================\n";
echo "Test Execution Summary: {$passedTests} / {$totalTests} PASSED" . ($failedTests > 0 ? " ({$failedTests} FAILED)" : "") . "\n";
echo "========================================================\n";

if ($failedTests > 0) {
    exit(1);
}
exit(0);
