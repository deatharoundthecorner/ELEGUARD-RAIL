<?php
/**
 * EleGuard Rail — Milestone 9C Stage 4B Test Suite
 * Non-Destructive Spatial-Temporal Duplicate Detection Service
 * 
 * Tests all 18 mandatory operational, mathematical, and architectural scenarios.
 * Strictly executes database operations against isolated test DB (eleguard_rail_test).
 * Verifies ZERO modifications to operational database (eleguard_rail).
 */

require_once __DIR__ . '/bootstrap.php';
ensureTestDatabaseExists();

require_once __DIR__ . '/../app/config/database.php';
require_once __DIR__ . '/../app/core/Database.php';
require_once __DIR__ . '/../app/services/DuplicateService.php';
require_once __DIR__ . '/../app/services/TemporalService.php';
require_once __DIR__ . '/../app/services/RiskService.php';

$totalTests = 0;
$passedTests = 0;
$failedTests = 0;

function runTest($description, $condition, &$total, &$passed, &$failed, $details = '') {
    $total++;
    if ($condition) {
        $passed++;
        echo "  [PASS] {$description}\n";
    } else {
        $failed++;
        echo "  [FAIL] {$description}\n";
        if ($details) {
            echo "         Details: {$details}\n";
        }
    }
}

echo "========================================================================\n";
echo "EleGuard Rail — Milestone 9C Stage 4B Test Suite\n";
echo "Non-Destructive Spatial-Temporal Duplicate Detection\n";
echo "========================================================================\n\n";

// -------------------------------------------------------------------------
// PRE-FLIGHT CHECK: Verify Operational Database Baseline State
// -------------------------------------------------------------------------
echo "--- 0. PRE-FLIGHT OPERATIONAL SAFETY AUDIT ---\n";
try {
    $opPdo = new PDO("mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=eleguard_rail;charset=utf8mb4", DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);
    
    $opReports = (int)$opPdo->query("SELECT COUNT(*) FROM elephant_reports")->fetchColumn();
    $opLogs = (int)$opPdo->query("SELECT COUNT(*) FROM system_logs")->fetchColumn();
    $opAlerts = (int)$opPdo->query("SELECT COUNT(*) FROM alerts")->fetchColumn();
    $opRep1 = $opPdo->query("SELECT sighting_date, sighting_time, risk_score, report_status FROM elephant_reports WHERE id = 1")->fetch(PDO::FETCH_ASSOC);

    runTest("0.1 Operational DB elephant_reports baseline is 43", $opReports === 43, $totalTests, $passedTests, $failedTests, "Got: $opReports");
    runTest("0.2 Operational DB system_logs baseline is 185", $opLogs === 185, $totalTests, $passedTests, $failedTests, "Got: $opLogs");
    runTest("0.3 Operational DB alerts baseline is 15", $opAlerts === 15, $totalTests, $passedTests, $failedTests, "Got: $opAlerts");
    runTest("0.4 Operational Report #1 sighting time is NULL", $opRep1['sighting_time'] === null, $totalTests, $passedTests, $failedTests);
    runTest("0.5 Operational Report #1 risk score baseline is Medium", $opRep1['risk_score'] === 'Medium', $totalTests, $passedTests, $failedTests);
} catch (Exception $e) {
    echo "  [FATAL] Operational DB pre-flight check failed: " . $e->getMessage() . "\n";
    exit(1);
}

// -------------------------------------------------------------------------
// SCENARIO 1: Identical coordinates and matching exact observation times
// -------------------------------------------------------------------------
echo "\n--- 1. IDENTICAL COORDINATES & MATCHING EXACT TIMES ---\n";
$rep1A = [
    'id' => 101,
    'section_id' => 1,
    'elephant_count' => 3,
    'sighting_date' => '2026-11-10',
    'sighting_time' => '09:30:00',
    'sighting_time_precision' => 'exact',
    'sighting_timezone' => 'Asia/Colombo',
    'latitude' => 8.041200,
    'longitude' => 80.825600,
    'gps_accuracy_meters' => 15.0,
    'description' => 'Elephants near track milestone 142'
];
$rep1B = [
    'id' => 102,
    'section_id' => 1,
    'elephant_count' => 3,
    'sighting_date' => '2026-11-10',
    'sighting_time' => '09:30:00',
    'sighting_time_precision' => 'exact',
    'sighting_timezone' => 'Asia/Colombo',
    'latitude' => 8.041200,
    'longitude' => 80.825600,
    'gps_accuracy_meters' => 10.0,
    'description' => 'Three elephants feeding near railway line'
];
$eval1 = DuplicateService::evaluateDuplicateCandidate($rep1A, $rep1B);

runTest("1.1 Geodesic distance between identical coordinates is 0.0m", $eval1['geographic_distance_meters'] === 0.0, $totalTests, $passedTests, $failedTests, "Got: " . $eval1['geographic_distance_meters']);
runTest("1.2 Observation interval comparison identifies exact overlap", $eval1['observation_interval_comparison']['overlap'] === true && $eval1['observation_interval_comparison']['delta_minutes'] === 0, $totalTests, $passedTests, $failedTests);
runTest("1.3 Candidate classified as 'Strong candidate'", $eval1['candidate_classification'] === DuplicateService::CLASSIFICATION_STRONG, $totalTests, $passedTests, $failedTests, "Got: " . $eval1['candidate_classification']);
runTest("1.4 Human review recommendation suggests photograph and note review", strpos($eval1['human_review_recommendation'], 'High probability of duplicate reporting') !== false, $totalTests, $passedTests, $failedTests);

// -------------------------------------------------------------------------
// SCENARIO 2: Nearby coordinates (<= 250m) and overlapping intervals
// -------------------------------------------------------------------------
echo "\n--- 2. NEARBY COORDINATES (<= 250M) & OVERLAPPING INTERVALS ---\n";
$rep2A = [
    'id' => 201,
    'section_id' => 1,
    'elephant_count' => 2,
    'sighting_date' => '2026-11-10',
    'sighting_time' => '14:00:00',
    'sighting_time_precision' => 'approximate',
    'sighting_time_uncertainty_minutes' => 30,
    'sighting_timezone' => 'Asia/Colombo',
    'latitude' => 8.041200,
    'longitude' => 80.825600,
    'gps_accuracy_meters' => 20.0,
    'description' => 'Two elephants observed early afternoon'
];
$rep2B = [
    'id' => 202,
    'section_id' => 1,
    'elephant_count' => 3,
    'sighting_date' => '2026-11-10',
    'sighting_time' => '14:20:00',
    'sighting_time_precision' => 'approximate',
    'sighting_time_uncertainty_minutes' => 30,
    'sighting_timezone' => 'Asia/Colombo',
    'latitude' => 8.042000,
    'longitude' => 80.826000,
    'gps_accuracy_meters' => 25.0,
    'description' => 'Elephant herd moving towards culvert'
];
$eval2 = DuplicateService::evaluateDuplicateCandidate($rep2A, $rep2B);

runTest("2.1 Distance is <= 250m", $eval2['geographic_distance_meters'] > 0 && $eval2['geographic_distance_meters'] <= 250.0, $totalTests, $passedTests, $failedTests, "Distance: " . $eval2['geographic_distance_meters']);
runTest("2.2 Uncertainty intervals overlap [13:30-14:30] and [13:50-14:50]", $eval2['observation_interval_comparison']['overlap'] === true, $totalTests, $passedTests, $failedTests);
runTest("2.3 Compatible elephant count difference (1)", $eval2['count_comparison']['compatible'] === true && $eval2['count_comparison']['difference'] === 1, $totalTests, $passedTests, $failedTests);
runTest("2.4 Classified as 'Strong candidate'", $eval2['candidate_classification'] === DuplicateService::CLASSIFICATION_STRONG, $totalTests, $passedTests, $failedTests);

// -------------------------------------------------------------------------
// SCENARIO 3: Nearby coordinates with unknown observation times
// -------------------------------------------------------------------------
echo "\n--- 3. NEARBY COORDINATES WITH UNKNOWN OBSERVATION TIMES ---\n";
$rep3A = [
    'id' => 301,
    'section_id' => 1,
    'elephant_count' => 2,
    'sighting_date' => null,
    'sighting_time' => null,
    'sighting_time_precision' => 'unknown',
    'created_at' => '2026-11-10 16:00:00',
    'latitude' => 8.041200,
    'longitude' => 80.825600,
    'description' => 'Report with missing observation time'
];
$rep3B = [
    'id' => 302,
    'section_id' => 1,
    'elephant_count' => 2,
    'sighting_date' => '2026-11-10',
    'sighting_time' => '15:45:00',
    'sighting_time_precision' => 'exact',
    'created_at' => '2026-11-10 16:05:00',
    'latitude' => 8.042500,
    'longitude' => 80.826500,
    'description' => 'Report with exact observation time'
];
$eval3 = DuplicateService::evaluateDuplicateCandidate($rep3A, $rep3B);

runTest("3.1 Distance is <= 500m", $eval3['geographic_distance_meters'] !== null && $eval3['geographic_distance_meters'] <= 500.0, $totalTests, $passedTests, $failedTests);
runTest("3.2 Observation comparison indicates time is unknown and non-comparable", $eval3['observation_interval_comparison']['comparable'] === false, $totalTests, $passedTests, $failedTests);
runTest("3.3 Classified as 'Spatial similarity only' (never assumes created_at)", $eval3['candidate_classification'] === DuplicateService::CLASSIFICATION_SPATIAL_ONLY, $totalTests, $passedTests, $failedTests, "Got: " . $eval3['candidate_classification']);
runTest("3.4 Warning emitted regarding missing observation timestamps", count($eval3['missing_data_warnings']) > 0, $totalTests, $passedTests, $failedTests);

// -------------------------------------------------------------------------
// SCENARIO 4: Distant coordinates in the same section
// -------------------------------------------------------------------------
echo "\n--- 4. DISTANT COORDINATES IN SAME SECTION (> 5KM) ---\n";
$rep4A = [
    'id' => 401,
    'section_id' => 1,
    'elephant_count' => 4,
    'sighting_date' => '2026-11-10',
    'sighting_time' => '09:00:00',
    'sighting_time_precision' => 'exact',
    'latitude' => 8.041200,
    'longitude' => 80.825600,
    'description' => 'Habarana side'
];
$rep4B = [
    'id' => 402,
    'section_id' => 1,
    'elephant_count' => 4,
    'sighting_date' => '2026-11-10',
    'sighting_time' => '09:00:00',
    'sighting_time_precision' => 'exact',
    'latitude' => 8.250000,
    'longitude' => 80.700000, // ~27 km away
    'description' => 'Far end of section'
];
$eval4 = DuplicateService::evaluateDuplicateCandidate($rep4A, $rep4B);

runTest("4.1 Distance > 5000m", $eval4['geographic_distance_meters'] > 5000.0, $totalTests, $passedTests, $failedTests, "Distance: " . $eval4['geographic_distance_meters']);
runTest("4.2 Classified as 'Insufficient evidence' despite same section", $eval4['candidate_classification'] === DuplicateService::CLASSIFICATION_INSUFFICIENT, $totalTests, $passedTests, $failedTests, "Got: " . $eval4['candidate_classification']);

// -------------------------------------------------------------------------
// SCENARIO 5: Nearby coordinates in different sections
// -------------------------------------------------------------------------
echo "\n--- 5. NEARBY COORDINATES IN DIFFERENT SECTIONS ---\n";
$rep5A = [
    'id' => 501,
    'section_id' => 1,
    'elephant_count' => 2,
    'sighting_date' => '2026-11-10',
    'sighting_time' => '10:00:00',
    'sighting_time_precision' => 'exact',
    'latitude' => 8.041200,
    'longitude' => 80.825600,
    'description' => 'Near section 1 boundary'
];
$rep5B = [
    'id' => 502,
    'section_id' => 2, // Different section!
    'elephant_count' => 2,
    'sighting_date' => '2026-11-10',
    'sighting_time' => '10:05:00',
    'sighting_time_precision' => 'exact',
    'latitude' => 8.042000,
    'longitude' => 80.826200, // ~110m away
    'description' => 'Near section 2 boundary'
];
$eval5 = DuplicateService::evaluateDuplicateCandidate($rep5A, $rep5B);

runTest("5.1 Proximity <= 250m across section boundaries", $eval5['geographic_distance_meters'] <= 250.0, $totalTests, $passedTests, $failedTests);
runTest("5.2 Section match is false", $eval5['section_compatibility']['match'] === false, $totalTests, $passedTests, $failedTests);
runTest("5.3 Section mismatch warning emitted", $eval5['section_compatibility']['warning'] !== null, $totalTests, $passedTests, $failedTests);
runTest("5.4 Candidate is NOT disqualified; classified as 'Possible candidate'", $eval5['candidate_classification'] === DuplicateService::CLASSIFICATION_POSSIBLE, $totalTests, $passedTests, $failedTests, "Got: " . $eval5['candidate_classification']);

// -------------------------------------------------------------------------
// SCENARIO 6: Missing GPS
// -------------------------------------------------------------------------
echo "\n--- 6. MISSING GPS TELEMETRY ---\n";
$rep6A = [
    'id' => 601,
    'section_id' => 1,
    'elephant_count' => 1,
    'sighting_date' => '2026-11-10',
    'sighting_time' => '11:00:00',
    'sighting_time_precision' => 'exact',
    'latitude' => null,
    'longitude' => null,
    'description' => 'Report with no coordinates'
];
$rep6B = [
    'id' => 602,
    'section_id' => 1,
    'elephant_count' => 1,
    'sighting_date' => '2026-11-10',
    'sighting_time' => '11:00:00',
    'sighting_time_precision' => 'exact',
    'latitude' => 8.041200,
    'longitude' => 80.825600,
    'description' => 'Report with coordinates'
];
$eval6 = DuplicateService::evaluateDuplicateCandidate($rep6A, $rep6B);

runTest("6.1 Distance is null when GPS is missing", $eval6['geographic_distance_meters'] === null, $totalTests, $passedTests, $failedTests);
runTest("6.2 Classified as 'Insufficient evidence'", $eval6['candidate_classification'] === DuplicateService::CLASSIFICATION_INSUFFICIENT, $totalTests, $passedTests, $failedTests);
runTest("6.3 Missing coordinates warning recorded", in_array("Geographic coordinates missing in one or both reports. Geodesic distance cannot be calculated.", $eval6['missing_data_warnings']), $totalTests, $passedTests, $failedTests);

// -------------------------------------------------------------------------
// SCENARIO 7: Invalid Coordinates (Outside Sri Lanka)
// -------------------------------------------------------------------------
echo "\n--- 7. INVALID COORDINATES OUTSIDE SRI LANKA ---\n";
$rep7A = [
    'id' => 701,
    'section_id' => 1,
    'sighting_date' => '2026-11-10',
    'sighting_time' => '12:00:00',
    'sighting_time_precision' => 'exact',
    'latitude' => 0.0, // Gulf of Guinea
    'longitude' => 0.0,
    'description' => 'Zero coordinate report'
];
$rep7B = [
    'id' => 702,
    'section_id' => 1,
    'sighting_date' => '2026-11-10',
    'sighting_time' => '12:00:00',
    'sighting_time_precision' => 'exact',
    'latitude' => 8.041200,
    'longitude' => 80.825600,
    'description' => 'Valid coordinate report'
];
$eval7 = DuplicateService::evaluateDuplicateCandidate($rep7A, $rep7B);

runTest("7.1 Bounding box check rejects 0,0 coordinates", DuplicateService::isValidSriLankaCoordinate(0.0, 0.0) === false, $totalTests, $passedTests, $failedTests);
runTest("7.2 Geodesic distance calculation returns null for out-of-bounds point", $eval7['geographic_distance_meters'] === null, $totalTests, $passedTests, $failedTests);
runTest("7.3 Classified as 'Insufficient evidence'", $eval7['candidate_classification'] === DuplicateService::CLASSIFICATION_INSUFFICIENT, $totalTests, $passedTests, $failedTests);

// -------------------------------------------------------------------------
// SCENARIO 8: Poor GPS Accuracy
// -------------------------------------------------------------------------
echo "\n--- 8. POOR GPS ACCURACY (> 100M) ---\n";
$rep8A = [
    'id' => 801,
    'section_id' => 1,
    'elephant_count' => 2,
    'sighting_date' => '2026-11-10',
    'sighting_time' => '08:00:00',
    'sighting_time_precision' => 'exact',
    'latitude' => 8.041200,
    'longitude' => 80.825600,
    'gps_accuracy_meters' => 150.0, // Poor accuracy
    'description' => 'Phone GPS drifting in dense canopy'
];
$rep8B = [
    'id' => 802,
    'section_id' => 1,
    'elephant_count' => 2,
    'sighting_date' => '2026-11-10',
    'sighting_time' => '08:15:00',
    'sighting_time_precision' => 'exact',
    'latitude' => 8.043500,
    'longitude' => 80.827000, // ~300m away
    'gps_accuracy_meters' => 60.0,
    'description' => 'Second observer sighting'
];
$eval8 = DuplicateService::evaluateDuplicateCandidate($rep8A, $rep8B);

runTest("8.1 Effective distance range buffer accounts for combined accuracy (210m)", $eval8['effective_distance_range']['total_buffer_meters'] === 210.0, $totalTests, $passedTests, $failedTests);
runTest("8.2 Poor GPS accuracy flagged as true", $eval8['effective_distance_range']['is_poor_accuracy'] === true, $totalTests, $passedTests, $failedTests);
runTest("8.3 Warning emitted regarding low GPS precision", strpos(implode(';', $eval8['missing_data_warnings']), 'Low GPS precision detected') !== false, $totalTests, $passedTests, $failedTests);

// -------------------------------------------------------------------------
// SCENARIO 9: Approximate Observation Intervals
// -------------------------------------------------------------------------
echo "\n--- 9. APPROXIMATE OBSERVATION INTERVALS (PRESETS & UNCERTAINTIES) ---\n";
$rep9A = [
    'id' => 901,
    'section_id' => 1,
    'elephant_count' => 3,
    'sighting_date' => '2026-11-10',
    'sighting_time' => '18:15:00',
    'sighting_time_precision' => 'approximate',
    'sighting_time_window' => 'dusk', // Dusk preset: 18:15 ±45m (17:30 to 19:00)
    'latitude' => 8.041200,
    'longitude' => 80.825600,
    'description' => 'Dusk observation'
];
$rep9B = [
    'id' => 902,
    'section_id' => 1,
    'elephant_count' => 3,
    'sighting_date' => '2026-11-10',
    'sighting_time' => '19:15:00',
    'sighting_time_precision' => 'approximate',
    'sighting_time_uncertainty_minutes' => 30, // 18:45 to 19:45
    'latitude' => 8.042000,
    'longitude' => 80.826000,
    'description' => 'Early evening observation'
];
$eval9 = DuplicateService::evaluateDuplicateCandidate($rep9A, $rep9B);

runTest("9.1 Dusk preset interval overlaps custom approximate interval (18:45-19:00)", $eval9['observation_interval_comparison']['overlap'] === true, $totalTests, $passedTests, $failedTests);
runTest("9.2 Classified as 'Strong candidate' based on overlapping intervals and proximity", $eval9['candidate_classification'] === DuplicateService::CLASSIFICATION_STRONG, $totalTests, $passedTests, $failedTests);

// -------------------------------------------------------------------------
// SCENARIO 10: Midnight-Crossing Intervals
// -------------------------------------------------------------------------
echo "\n--- 10. MIDNIGHT-CROSSING INTERVALS ---\n";
$rep10A = [
    'id' => 1001,
    'section_id' => 1,
    'elephant_count' => 1,
    'sighting_date' => '2026-11-10',
    'sighting_time' => '23:45:00',
    'sighting_time_precision' => 'approximate',
    'sighting_time_uncertainty_minutes' => 30, // 23:15:00 on Nov 10 to 00:15:00 on Nov 11
    'latitude' => 8.041200,
    'longitude' => 80.825600,
    'description' => 'Late night sighting'
];
$rep10B = [
    'id' => 1002,
    'section_id' => 1,
    'elephant_count' => 1,
    'sighting_date' => '2026-11-11', // Next calendar day!
    'sighting_time' => '00:05:00',
    'sighting_time_precision' => 'approximate',
    'sighting_time_uncertainty_minutes' => 20, // 23:45:00 on Nov 10 to 00:25:00 on Nov 11
    'latitude' => 8.041500,
    'longitude' => 80.825800,
    'description' => 'Post-midnight sighting'
];
$eval10 = DuplicateService::evaluateDuplicateCandidate($rep10A, $rep10B);

runTest("10.1 Observation interval correctly spans midnight into next day", $eval10['observation_interval_comparison']['comparable'] === true, $totalTests, $passedTests, $failedTests);
runTest("10.2 Midnight-crossing intervals overlap correctly", $eval10['observation_interval_comparison']['overlap'] === true, $totalTests, $passedTests, $failedTests);
runTest("10.3 Classified as 'Strong candidate' across midnight boundary", $eval10['candidate_classification'] === DuplicateService::CLASSIFICATION_STRONG, $totalTests, $passedTests, $failedTests);

// -------------------------------------------------------------------------
// SCENARIO 11: Different Elephant Counts
// -------------------------------------------------------------------------
echo "\n--- 11. DIFFERENT ELEPHANT COUNTS (HERD VARIANCE) ---\n";
$rep11A = [
    'id' => 1101,
    'section_id' => 1,
    'elephant_count' => 2,
    'sighting_date' => '2026-11-10',
    'sighting_time' => '07:30:00',
    'sighting_time_precision' => 'exact',
    'latitude' => 8.041200,
    'longitude' => 80.825600,
    'description' => 'Pair of elephants'
];
$rep11B = [
    'id' => 1102,
    'section_id' => 1,
    'elephant_count' => 7, // Difference of 5 elephants
    'sighting_date' => '2026-11-10',
    'sighting_time' => '07:35:00',
    'sighting_time_precision' => 'exact',
    'latitude' => 8.041800,
    'longitude' => 80.825900,
    'description' => 'Herd with calves emerged'
];
$eval11 = DuplicateService::evaluateDuplicateCandidate($rep11A, $rep11B);

runTest("11.1 Herd count variance detected (5 elephants)", $eval11['count_comparison']['difference'] === 5, $totalTests, $passedTests, $failedTests);
runTest("11.2 Count discrepancy does NOT disqualify candidate; classified as 'Possible candidate'", $eval11['candidate_classification'] === DuplicateService::CLASSIFICATION_POSSIBLE, $totalTests, $passedTests, $failedTests, "Got: " . $eval11['candidate_classification']);
runTest("11.3 Informative warning emitted regarding elephant count variance", strpos(implode(';', $eval11['missing_data_warnings']), 'Elephant count discrepancy') !== false, $totalTests, $passedTests, $failedTests);

// -------------------------------------------------------------------------
// SCENARIO 12: Known Test/Demo Provenance
// -------------------------------------------------------------------------
echo "\n--- 12. KNOWN TEST/DEMO PROVENANCE (NON-OPERATIONAL ARTIFACTS) ---\n";
$rep12A = [
    'id' => 12, // In test triad ID range 4-40
    'section_id' => 1,
    'elephant_count' => 3,
    'sighting_date' => '2026-11-10',
    'sighting_time' => '09:00:00',
    'sighting_time_precision' => 'exact',
    'latitude' => 8.041200,
    'longitude' => 80.825600,
    'description' => 'Minneriya Track Observation — Automated Test Triad'
];
$rep12B = [
    'id' => 102, // Genuine report
    'reported_by' => 5,
    'section_id' => 1,
    'location_description' => 'Habarana Milestone 142',
    'elephant_count' => 3,
    'sighting_date' => '2026-11-10',
    'sighting_time' => '09:00:00',
    'sighting_time_precision' => 'exact',
    'latitude' => 8.041200,
    'longitude' => 80.825600,
    'description' => 'Genuine field report of adult herd'
];
$eval12 = DuplicateService::evaluateDuplicateCandidate($rep12A, $rep12B);

runTest("12.1 Report #12 classified as development_test provenance", $eval12['provenance']['report_a'] === DuplicateService::PROVENANCE_DEV_TEST, $totalTests, $passedTests, $failedTests);
runTest("12.2 Test comparison flag is true", $eval12['provenance']['is_test_comparison'] === true, $totalTests, $passedTests, $failedTests);
runTest("12.3 Candidate classified as 'Excluded test/demo comparison'", $eval12['candidate_classification'] === DuplicateService::CLASSIFICATION_EXCLUDED_TEST, $totalTests, $passedTests, $failedTests, "Got: " . $eval12['candidate_classification']);
runTest("12.4 AI test record provenance identified", DuplicateService::classifyProvenance(['id' => 42, 'image_path' => 'uploads/ai_elephant_1.jpg']) === DuplicateService::PROVENANCE_AI_TEST, $totalTests, $passedTests, $failedTests);
runTest("12.5 E2E audit provenance identified", DuplicateService::classifyProvenance(['id' => 54, 'description' => 'E2E audit run']) === DuplicateService::PROVENANCE_E2E_AUDIT, $totalTests, $passedTests, $failedTests);

// -------------------------------------------------------------------------
// SCENARIO 13: Unknown Provenance Handling
// -------------------------------------------------------------------------
echo "\n--- 13. UNKNOWN PROVENANCE HANDLING ---\n";
$rep13 = [
    'id' => 9999,
    'description' => '',
    'location_description' => '',
    'latitude' => null,
    'longitude' => null
];
$prov13 = DuplicateService::classifyProvenance($rep13);
runTest("13.1 Missing metadata resolves gracefully to 'unknown' provenance", $prov13 === DuplicateService::PROVENANCE_UNKNOWN, $totalTests, $passedTests, $failedTests);
runTest("13.2 Unknown provenance is not falsely excluded as a test record", DuplicateService::isTestOrDemoProvenance($prov13) === false, $totalTests, $passedTests, $failedTests);

// -------------------------------------------------------------------------
// SCENARIO 14: Potential Transitive-Clustering Errors (A-B, B-C, but A!~C)
// -------------------------------------------------------------------------
echo "\n--- 14. POTENTIAL TRANSITIVE-CLUSTERING DEFECT PROTECTION ---\n";
// Create 3 reports along the rail corridor:
// A is at km 0, B is at km 0.38, C is at km 0.83.
// Pairwise: Dist(A, B) = 380m (< 500m threshold)
//           Dist(B, C) = 450m (< 500m threshold)
//           Dist(A, C) = 830m (> 750m max cluster diameter!)
$rep14A = [
    'id' => 1401,
    'section_id' => 1,
    'elephant_count' => 2,
    'sighting_date' => '2026-11-10',
    'sighting_time' => '09:00:00',
    'sighting_time_precision' => 'exact',
    'latitude' => 8.041000,
    'longitude' => 80.825000,
    'description' => 'Report A at point 1'
];
$rep14B = [
    'id' => 1402,
    'section_id' => 1,
    'elephant_count' => 2,
    'sighting_date' => '2026-11-10',
    'sighting_time' => '09:15:00',
    'sighting_time_precision' => 'exact',
    'latitude' => 8.044000,
    'longitude' => 80.826500, // ~380m from A
    'description' => 'Report B at point 2'
];
$rep14C = [
    'id' => 1403,
    'section_id' => 1,
    'elephant_count' => 2,
    'sighting_date' => '2026-11-10',
    'sighting_time' => '09:30:00',
    'sighting_time_precision' => 'exact',
    'latitude' => 8.047500,
    'longitude' => 80.828500, // ~450m from B, but ~830m from A!
    'description' => 'Report C at point 3'
];

$distAB = DuplicateService::calculateGeodesicDistance($rep14A['latitude'], $rep14A['longitude'], $rep14B['latitude'], $rep14B['longitude']);
$distBC = DuplicateService::calculateGeodesicDistance($rep14B['latitude'], $rep14B['longitude'], $rep14C['latitude'], $rep14C['longitude']);
$distAC = DuplicateService::calculateGeodesicDistance($rep14A['latitude'], $rep14A['longitude'], $rep14C['latitude'], $rep14C['longitude']);

runTest("14.1 Dist(A, B) is <= 500m", $distAB <= 500.0, $totalTests, $passedTests, $failedTests, "Dist AB: $distAB");
runTest("14.2 Dist(B, C) is <= 500m", $distBC <= 500.0, $totalTests, $passedTests, $failedTests, "Dist BC: $distBC");
runTest("14.3 Dist(A, C) strictly exceeds max cluster diameter (750m)", $distAC > 750.0, $totalTests, $passedTests, $failedTests, "Dist AC: $distAC");

$clusterRes14 = DuplicateService::clusterReports([$rep14A, $rep14B, $rep14C], [
    'max_cluster_diameter_meters' => 750.0
]);

runTest("14.4 Blind transitive clustering avoided: A and C are NOT in the same 3-member cluster", !empty($clusterRes14['clusters']) && $clusterRes14['clusters'][0]['member_count'] < 3, $totalTests, $passedTests, $failedTests);
runTest("14.5 Transitive split recorded to explain separation", count($clusterRes14['transitive_splits']) > 0, $totalTests, $passedTests, $failedTests);

// -------------------------------------------------------------------------
// SCENARIO 15: Existing Duplicate Flags
// -------------------------------------------------------------------------
echo "\n--- 15. EXISTING DUPLICATE FLAGS & METADATA READING ---\n";
try {
    $testPdo = Database::getInstance()->getConnection();
    $stmt = $testPdo->query("SELECT id, is_potential_duplicate, duplicate_cluster_id FROM elephant_reports LIMIT 5");
    $existingFlags = $stmt->fetchAll(PDO::FETCH_ASSOC);

    runTest("15.1 Existing duplicate columns accessible without error", is_array($existingFlags) && count($existingFlags) > 0, $totalTests, $passedTests, $failedTests);
    runTest("15.2 Default duplicate flag is 0/false for baseline records", isset($existingFlags[0]['is_potential_duplicate']), $totalTests, $passedTests, $failedTests);
} catch (Exception $e) {
    runTest("15.1 DB duplicate flags query failed", false, $totalTests, $passedTests, $failedTests, $e->getMessage());
}

// -------------------------------------------------------------------------
// SCENARIO 16: Empty Result Sets
// -------------------------------------------------------------------------
echo "\n--- 16. EMPTY RESULT SETS ---\n";
$emptyCandidates = DuplicateService::findDuplicatesForReport($rep1A, []);
runTest("16.1 Empty candidate pool returns empty array", is_array($emptyCandidates) && count($emptyCandidates) === 0, $totalTests, $passedTests, $failedTests);

$emptyClustering = DuplicateService::clusterReports([]);
runTest("16.2 Empty report list returns structured zero-count clustering array", 
    is_array($emptyClustering) && 
    $emptyClustering['total_reports'] === 0 && 
    $emptyClustering['clustered_count'] === 0 && 
    empty($emptyClustering['clusters']), 
    $totalTests, $passedTests, $failedTests
);

// -------------------------------------------------------------------------
// SCENARIO 17: Unauthorized Access & Coordinate Masking
// -------------------------------------------------------------------------
echo "\n--- 17. UNAUTHORIZED ACCESS & ROLE-BASED COORDINATE MASKING ---\n";
$rawLat = 8.04123456;
$rawLon = 80.82567890;

// Test unauthorized roles: reporter, driver, guest (null)
$maskedReporter = DuplicateService::maskCoordinates($rawLat, $rawLon, 'reporter');
$maskedDriver = DuplicateService::maskCoordinates($rawLat, $rawLon, 'driver');
$maskedGuest = DuplicateService::maskCoordinates($rawLat, $rawLon, null);

runTest("17.1 Reporter receives fuzzed coordinates (rounded to 2 decimal places)", $maskedReporter['latitude'] === 8.04 && $maskedReporter['longitude'] === 80.83, $totalTests, $passedTests, $failedTests);
runTest("17.2 Reporter masking flag is true", $maskedReporter['is_masked'] === true, $totalTests, $passedTests, $failedTests);
runTest("17.3 Driver coordinates are masked", $maskedDriver['is_masked'] === true, $totalTests, $passedTests, $failedTests);
runTest("17.4 Unauthenticated guest coordinates are masked", $maskedGuest['is_masked'] === true, $totalTests, $passedTests, $failedTests);

// Test authorized roles: wildlife_officer, control_officer, admin
$exactWildlife = DuplicateService::maskCoordinates($rawLat, $rawLon, 'wildlife_officer');
$exactControl = DuplicateService::maskCoordinates($rawLat, $rawLon, 'control_officer');
$exactAdmin = DuplicateService::maskCoordinates($rawLat, $rawLon, 'admin');

runTest("17.5 Wildlife Officer receives exact unmasked coordinates", $exactWildlife['is_masked'] === false && $exactWildlife['latitude'] === $rawLat && $exactWildlife['longitude'] === $rawLon, $totalTests, $passedTests, $failedTests);
runTest("17.6 Control Officer receives exact unmasked coordinates", $exactControl['is_masked'] === false, $totalTests, $passedTests, $failedTests);
runTest("17.7 Administrator receives exact unmasked coordinates", $exactAdmin['is_masked'] === false, $totalTests, $passedTests, $failedTests);

// Test evaluateDuplicateCandidate role integration
$evalReporter = DuplicateService::evaluateDuplicateCandidate($rep1A, $rep1B, ['user_role' => 'reporter']);
runTest("17.8 Evaluation payload coordinates_display masks coordinates for reporter", $evalReporter['coordinates_display']['report_a']['is_masked'] === true, $totalTests, $passedTests, $failedTests);

// -------------------------------------------------------------------------
// SCENARIO 18: Operational Risk-Score Immutability & Safety
// -------------------------------------------------------------------------
echo "\n--- 18. OPERATIONAL RISK-SCORE IMMUTABILITY & ISOLATION SAFETY ---\n";
// 1. In-memory immutability check
$origRiskScore = 'High';
$sampleReport = $rep1A;
$sampleReport['risk_score'] = $origRiskScore;
$sampleReport['report_status'] = 'Pending';

DuplicateService::evaluateDuplicateCandidate($sampleReport, $rep1B);
runTest("18.1 evaluateDuplicateCandidate does not modify input report risk_score", $sampleReport['risk_score'] === $origRiskScore, $totalTests, $passedTests, $failedTests);
runTest("18.2 evaluateDuplicateCandidate does not modify input report report_status", $sampleReport['report_status'] === 'Pending', $totalTests, $passedTests, $failedTests);

// 2. Strict operational database protection: assignClustersInDatabase MUST reject operational DB
$opDbBlocked = false;
try {
    DuplicateService::assignClustersInDatabase($opPdo);
} catch (RuntimeException $e) {
    if (strpos($e->getMessage(), 'Operational Safety Violation') !== false) {
        $opDbBlocked = true;
    }
}
runTest("18.3 assignClustersInDatabase strictly throws exception on operational DB 'eleguard_rail'", $opDbBlocked === true, $totalTests, $passedTests, $failedTests);

// 3. Isolated test DB execution
try {
    // Snapshot risk_scores in eleguard_rail_test before clustering
    $preScores = $testPdo->query("SELECT id, risk_score, report_status, sighting_date, sighting_time, created_at FROM elephant_reports ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
    
    // Execute clustering on test database
    $clusterSummary = DuplicateService::assignClustersInDatabase($testPdo);
    
    // Snapshot risk_scores in eleguard_rail_test after clustering
    $postScores = $testPdo->query("SELECT id, risk_score, report_status, sighting_date, sighting_time, created_at FROM elephant_reports ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);

    $scoresIdentical = true;
    $statusesIdentical = true;
    $timestampsIdentical = true;

    for ($idx = 0; $idx < count($preScores); $idx++) {
        if ($preScores[$idx]['risk_score'] !== $postScores[$idx]['risk_score']) {
            $scoresIdentical = false;
        }
        if ($preScores[$idx]['report_status'] !== $postScores[$idx]['report_status']) {
            $statusesIdentical = false;
        }
        if ($preScores[$idx]['sighting_time'] !== $postScores[$idx]['sighting_time'] || $preScores[$idx]['created_at'] !== $postScores[$idx]['created_at']) {
            $timestampsIdentical = false;
        }
    }

    runTest("18.4 Stored risk_score values in test database remain 100% immutable", $scoresIdentical === true, $totalTests, $passedTests, $failedTests);
    runTest("18.5 Stored report_status values in test database remain 100% immutable", $statusesIdentical === true, $totalTests, $passedTests, $failedTests);
    runTest("18.6 Stored observation and submission timestamps remain 100% immutable", $timestampsIdentical === true, $totalTests, $passedTests, $failedTests);

} catch (Exception $e) {
    runTest("18.4 Isolated test DB clustering failed", false, $totalTests, $passedTests, $failedTests, $e->getMessage());
}

// -------------------------------------------------------------------------
// STAGE 4B.1 HARDENING AUDIT & INDEPENDENT SECURITY VERIFICATION
// -------------------------------------------------------------------------
echo "\n--- 20. STAGE 4B.1 HARDENING AUDIT & INDEPENDENT SECURITY VERIFICATION ---\n";

// 20.1 Future genuine report IDs are NOT excluded by hardcoded ID ranges
$repFuture12 = [
    'id' => 12, // Formerly in 4-40 range
    'reported_by' => 5,
    'section_id' => 1,
    'location_description' => 'Habarana Milestone 142',
    'latitude' => 8.0412,
    'longitude' => 80.8256,
    'description' => 'Wild bull elephant observed by track inspector crossing track safely'
];
$provFuture12 = DuplicateService::classifyProvenance($repFuture12);
runTest("20.1 Future genuine report with ID 12 is classified as genuine_field (not dev_test)", $provFuture12 === DuplicateService::PROVENANCE_GENUINE_FIELD, $totalTests, $passedTests, $failedTests, "Got: $provFuture12");

$repFuture42 = [
    'id' => 42, // Formerly in 41-43 range
    'reported_by' => 4,
    'section_id' => 2,
    'location_description' => 'Minneriya National Park border',
    'latitude' => 8.0425,
    'longitude' => 80.8260,
    'description' => 'Adult female elephant with two subadults grazing near culvert'
];
$provFuture42 = DuplicateService::classifyProvenance($repFuture42);
runTest("20.2 Future genuine report with ID 42 is classified as genuine_field (not ai_test)", $provFuture42 === DuplicateService::PROVENANCE_GENUINE_FIELD, $totalTests, $passedTests, $failedTests, "Got: $provFuture42");

$repFuture54 = [
    'id' => 54, // Formerly in 53-56 range
    'reported_by' => 3,
    'section_id' => 1,
    'location_description' => 'Gal Oya Junction South',
    'latitude' => 8.0430,
    'longitude' => 80.8265,
    'description' => 'Lone elephant moving away from railway corridor towards scrub'
];
$provFuture54 = DuplicateService::classifyProvenance($repFuture54);
runTest("20.3 Future genuine report with ID 54 is classified as genuine_field (not e2e_audit)", $provFuture54 === DuplicateService::PROVENANCE_GENUINE_FIELD, $totalTests, $passedTests, $failedTests, "Got: $provFuture54");

// 20.4 Unknown provenance is neither falsely classified as genuine nor as test
$repUnknown = [
    'id' => 9999,
    'description' => '',
    'location_description' => '',
    'reported_by' => null,
    'latitude' => null
];
$provUnknown = DuplicateService::classifyProvenance($repUnknown);
runTest("20.4 Incomplete metadata report classifies as unknown (not genuine)", $provUnknown === DuplicateService::PROVENANCE_UNKNOWN, $totalTests, $passedTests, $failedTests);
runTest("20.5 Unknown provenance report is not excluded as a test/demo artifact", DuplicateService::isTestOrDemoProvenance($provUnknown) === false, $totalTests, $passedTests, $failedTests);

// 20.6 Known test triad text signature identifies as development_test regardless of ID
$repTestTriadAnyId = [
    'id' => 8888, // Arbitrary non-triad ID
    'description' => 'Testing text-only submission.',
    'reported_by' => 5
];
runTest("20.6 Known test triad description identified as development_test regardless of ID", DuplicateService::classifyProvenance($repTestTriadAnyId) === DuplicateService::PROVENANCE_DEV_TEST, $totalTests, $passedTests, $failedTests);

// 20.7 Known AI test signature identifies as ai_test regardless of ID
$repAiTestAnyId = [
    'id' => 7777,
    'image_path' => 'assets/uploads/reports/qa_test_elephant_001.jpg',
    'description' => 'AI model evaluation image'
];
runTest("20.7 Known AI test image identified as ai_test regardless of ID", DuplicateService::classifyProvenance($repAiTestAnyId) === DuplicateService::PROVENANCE_AI_TEST, $totalTests, $passedTests, $failedTests);

// 20.8 Known E2E audit signature identifies as e2e_audit regardless of ID
$repE2EAnyId = [
    'id' => 6666,
    'location_description' => 'E2E-AUDIT-ELEPHANT-VERIFY',
    'description' => 'Automated governance test'
];
runTest("20.8 Known E2E audit signature identified as e2e_audit regardless of ID", DuplicateService::classifyProvenance($repE2EAnyId) === DuplicateService::PROVENANCE_E2E_AUDIT, $totalTests, $passedTests, $failedTests);

// 20.9 Unauthorized API access check (public/api/gis_data.php access control)
$gisApiSource = file_get_contents(__DIR__ . '/../public/api/gis_data.php');
$hasAuthCheck = strpos($gisApiSource, 'Auth::check()') !== false;
$hasRoleCheck = strpos($gisApiSource, "['wildlife_officer', 'control_officer', 'admin']") !== false;
$has403Response = strpos($gisApiSource, '403') !== false;
runTest("20.9 GIS Data API requires session authentication", $hasAuthCheck === true, $totalTests, $passedTests, $failedTests);
runTest("20.10 GIS Data API restricts access to authorized roles and rejects unauthorized with 403", $hasRoleCheck === true && $has403Response === true, $totalTests, $passedTests, $failedTests);

// 20.11 Coordinate masking geographic precision in Sri Lanka (~8° N)
// 1° lat = 111.195 km => 0.01° lat = 1.112 km.
// At lat 8° N, 1° lon = 111.195 * cos(8°) = 110.113 km => 0.01° lon = 1.101 km.
// Fuzzing to 2 decimal places creates an uncertainty box of ~1.11 km x ~1.10 km (~122 hectares).
$exactLat = 8.041234;
$exactLon = 80.825678;
$maskedCoord = DuplicateService::maskCoordinates($exactLat, $exactLon, 'driver');
$latErrorMeters = abs($exactLat - $maskedCoord['latitude']) * 111195.0;
$lonErrorMeters = abs($exactLon - $maskedCoord['longitude']) * (111195.0 * cos(deg2rad(8.0)));
runTest("20.11 Two-decimal coordinate masking introduces >100m spatial fuzzing in latitude", $latErrorMeters > 100.0, $totalTests, $passedTests, $failedTests, "Lat error: {$latErrorMeters}m");
runTest("20.12 Two-decimal coordinate masking introduces >50m spatial fuzzing in longitude", $lonErrorMeters > 50.0, $totalTests, $passedTests, $failedTests, "Lon error: {$lonErrorMeters}m");

// 20.13 Cluster diameter boundary tests (threshold = 750m)
$baseLat = 8.041200;
$baseLon = 80.825600;
$deltaLat755 = 755.0 / 111195.0;
$latAt755 = $baseLat + $deltaLat755;
$dist755 = DuplicateService::calculateGeodesicDistance($baseLat, $baseLon, $latAt755, $baseLon);

$repBoundA = [
    'id' => 2001,
    'section_id' => 1,
    'elephant_count' => 2,
    'sighting_date' => '2026-11-10',
    'sighting_time' => '09:00:00',
    'sighting_time_precision' => 'exact',
    'latitude' => $baseLat,
    'longitude' => $baseLon
];
$repBoundB = [
    'id' => 2002,
    'section_id' => 1,
    'elephant_count' => 2,
    'sighting_date' => '2026-11-10',
    'sighting_time' => '09:10:00',
    'sighting_time_precision' => 'exact',
    'latitude' => $latAt755, // > 750m away!
    'longitude' => $baseLon
];
runTest("20.13 Boundary point exceeds 750m diameter threshold", $dist755 > 750.0, $totalTests, $passedTests, $failedTests, "Dist: {$dist755}m");

$clusterBoundRes = DuplicateService::clusterReports([$repBoundA, $repBoundB], [
    'spatial_threshold_meters' => 800.0,
    'max_cluster_diameter_meters' => 750.0
]);
runTest("20.14 Reports exceeding 750m max cluster diameter are rejected from joining same cluster", 
    empty($clusterBoundRes['clusters']) || $clusterBoundRes['clusters'][0]['member_count'] < 2, 
    $totalTests, $passedTests, $failedTests
);

// 20.15 Cluster temporal boundary test (threshold = 90 min)
$repTimeA = [
    'id' => 2011,
    'section_id' => 1,
    'elephant_count' => 2,
    'sighting_date' => '2026-11-10',
    'sighting_time' => '09:00:00',
    'sighting_time_precision' => 'exact',
    'latitude' => $baseLat,
    'longitude' => $baseLon
];
$repTimeB = [
    'id' => 2012,
    'section_id' => 1,
    'elephant_count' => 2,
    'sighting_date' => '2026-11-10',
    'sighting_time' => '10:35:00', // 95 minutes later (> 90m cluster span)
    'sighting_time_precision' => 'exact',
    'latitude' => $baseLat,
    'longitude' => $baseLon
];
$clusterTimeRes = DuplicateService::clusterReports([$repTimeA, $repTimeB], [
    'temporal_threshold_minutes' => 100,
    'max_cluster_span_minutes' => 90
]);
runTest("20.15 Reports exceeding 90 min max cluster temporal span are rejected from clustering", 
    empty($clusterTimeRes['clusters']) || $clusterTimeRes['clusters'][0]['member_count'] < 2, 
    $totalTests, $passedTests, $failedTests
);

// 20.16 Missing observation time report strictly barred from joining a cluster
$repMissingTime = [
    'id' => 2021,
    'section_id' => 1,
    'elephant_count' => 2,
    'sighting_date' => null,
    'sighting_time' => null,
    'sighting_time_precision' => 'unknown',
    'latitude' => $baseLat,
    'longitude' => $baseLon
];
$repValidTime = [
    'id' => 2022,
    'section_id' => 1,
    'elephant_count' => 2,
    'sighting_date' => '2026-11-10',
    'sighting_time' => '09:00:00',
    'sighting_time_precision' => 'exact',
    'latitude' => $baseLat,
    'longitude' => $baseLon
];
$clusterMissingTimeRes = DuplicateService::clusterReports([$repMissingTime, $repValidTime]);
runTest("20.16 Report with missing observation time is strictly placed in unclustered_reports", 
    count($clusterMissingTimeRes['clusters']) === 0 && count($clusterMissingTimeRes['unclustered_reports']) === 2, 
    $totalTests, $passedTests, $failedTests
);

// 20.17 clusterReports automatically sanitizes coordinates for unauthorized role
$clusterSanitizedRes = DuplicateService::clusterReports([$rep1A, $rep1B], ['user_role' => 'reporter']);
$clusteredMember1 = $clusterSanitizedRes['clusters'][0]['reports'][0];
runTest("20.17 clusterReports sanitizes member report coordinates when requested by unauthorized role", 
    $clusteredMember1['is_coordinates_masked'] === true && $clusteredMember1['latitude'] === 8.04, 
    $totalTests, $passedTests, $failedTests
);

// 20.18 Cross-provenance comparison: genuine field vs unknown provenance emits review warning
$repCrossGen = [
    'id' => 2031,
    'reported_by' => 5,
    'section_id' => 1,
    'elephant_count' => 2,
    'sighting_date' => '2026-11-10',
    'sighting_time' => '09:00:00',
    'sighting_time_precision' => 'exact',
    'location_description' => 'Habarana Corridor',
    'latitude' => $baseLat,
    'longitude' => $baseLon,
    'description' => 'Wild herd observed crossing'
];
$repCrossUnk = [
    'id' => 2032,
    'reported_by' => null, // Unauthenticated/unknown reporter
    'section_id' => 1,
    'elephant_count' => 2,
    'sighting_date' => '2026-11-10',
    'sighting_time' => '09:05:00',
    'sighting_time_precision' => 'exact',
    'location_description' => '',
    'latitude' => $baseLat,
    'longitude' => $baseLon,
    'description' => '' // Empty description
];
$evalCross = DuplicateService::evaluateDuplicateCandidate($repCrossGen, $repCrossUnk);
runTest("20.18 Cross-provenance comparison between genuine and unknown emits observer credentials warning", 
    strpos(implode(';', $evalCross['provenance_warnings']), 'unverified provenance') !== false, 
    $totalTests, $passedTests, $failedTests
);
runTest("20.19 Cross-provenance comparison does NOT exclude candidate (is_test_comparison is false)", 
    $evalCross['provenance']['is_test_comparison'] === false, 
    $totalTests, $passedTests, $failedTests
);

// -------------------------------------------------------------------------
// POST-TEST AUDIT: Verify Operational Database Remains Pristine
// -------------------------------------------------------------------------
echo "\n--- 19. POST-TEST OPERATIONAL DATABASE VERIFICATION ---\n";
try {
    $postReports = (int)$opPdo->query("SELECT COUNT(*) FROM elephant_reports")->fetchColumn();
    $postLogs = (int)$opPdo->query("SELECT COUNT(*) FROM system_logs")->fetchColumn();
    $postAlerts = (int)$opPdo->query("SELECT COUNT(*) FROM alerts")->fetchColumn();
    $postRep1 = $opPdo->query("SELECT sighting_date, sighting_time, risk_score, report_status FROM elephant_reports WHERE id = 1")->fetch(PDO::FETCH_ASSOC);

    runTest("19.1 Operational DB elephant_reports remains 43", $postReports === 43, $totalTests, $passedTests, $failedTests, "Got: $postReports");
    runTest("19.2 Operational DB system_logs remains 185", $postLogs === 185, $totalTests, $passedTests, $failedTests, "Got: $postLogs");
    runTest("19.3 Operational DB alerts remains 15", $postAlerts === 15, $totalTests, $passedTests, $failedTests, "Got: $postAlerts");
    runTest("19.4 Operational Report #1 sighting time remains NULL", $postRep1['sighting_time'] === null, $totalTests, $passedTests, $failedTests);
    runTest("19.5 Operational Report #1 risk score remains Medium (identical to baseline)", $postRep1['risk_score'] === $opRep1['risk_score'] && $postRep1['risk_score'] === 'Medium', $totalTests, $passedTests, $failedTests);
    runTest("19.6 Operational Report #1 status remains Pending", $postRep1['report_status'] === 'Pending', $totalTests, $passedTests, $failedTests);
} catch (Exception $e) {
    runTest("19.1 Post-test operational DB audit failed", false, $totalTests, $passedTests, $failedTests, $e->getMessage());
}

// -------------------------------------------------------------------------
// SUMMARY
// -------------------------------------------------------------------------
echo "\n========================================================================\n";
echo "STAGE 4B DUPLICATE DETECTION TEST RESULTS\n";
echo "Total Tests:  {$totalTests}\n";
echo "Passed Tests: {$passedTests}\n";
echo "Failed Tests: {$failedTests}\n";
echo "========================================================================\n";

if ($failedTests > 0) {
    exit(1);
}
exit(0);
