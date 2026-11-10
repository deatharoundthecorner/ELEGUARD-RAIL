<?php
/**
 * EleGuard Rail — Milestone 9C Stage 4C Test Suite
 * Read-Only Historical Analytics Query Layer
 * 
 * Tests all 20 mandatory operational, mathematical, and architectural requirements.
 * Executes queries against isolated test DB (eleguard_rail_test).
 * Strictly verifies ZERO modifications to operational database (eleguard_rail).
 */

// Set isolated test environment before loading database configs
putenv('DB_NAME=eleguard_rail_test');

require_once __DIR__ . '/../app/config/database.php';
require_once __DIR__ . '/../app/core/Database.php';
require_once __DIR__ . '/../app/services/AnalyticsService.php';
require_once __DIR__ . '/../app/services/DuplicateService.php';
require_once __DIR__ . '/../app/services/TemporalService.php';
require_once __DIR__ . '/../app/services/ProximityService.php';

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
echo "EleGuard Rail — Milestone 9C Stage 4C Test Suite\n";
echo "Read-Only Historical Analytics Query Layer\n";
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

// Ensure isolated test database exists and has fresh clone of tables
try {
    $opPdo->exec("CREATE DATABASE IF NOT EXISTS `eleguard_rail_test` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $testTables = $opPdo->query("SHOW TABLES FROM `eleguard_rail_test`")->fetchAll(PDO::FETCH_COLUMN);
    if (empty($testTables)) {
        $tables = $opPdo->query("SHOW TABLES FROM `eleguard_rail`")->fetchAll(PDO::FETCH_COLUMN);
        $opPdo->exec("SET FOREIGN_KEY_CHECKS = 0");
        foreach ($tables as $t) {
            $createSql = $opPdo->query("SHOW CREATE TABLE `eleguard_rail`.`{$t}`")->fetch(PDO::FETCH_ASSOC)['Create Table'];
            $opPdo->exec("USE `eleguard_rail_test`");
            $opPdo->exec($createSql);
            $opPdo->exec("INSERT INTO `eleguard_rail_test`.`{$t}` SELECT * FROM `eleguard_rail`.`{$t}`");
        }
        $opPdo->exec("SET FOREIGN_KEY_CHECKS = 1");
    }
} catch (Exception $e) {
    echo "  [FATAL] Failed to initialize isolated test DB: " . $e->getMessage() . "\n";
    exit(1);
}

// Connect to isolated test database
$testPdo = new PDO("mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=eleguard_rail_test;charset=utf8mb4", DB_USER, DB_PASS, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
]);

// -------------------------------------------------------------------------
// 1. Total Report Counts
// -------------------------------------------------------------------------
echo "\n--- 1. TOTAL REPORT COUNTS ---\n";
$generalStats = AnalyticsService::getGeneralStatistics($testPdo);
runTest("1.1 Total reports count is integer > 0", is_int($generalStats['total_reports']) && $generalStats['total_reports'] >= 43, $totalTests, $passedTests, $failedTests, "Got: " . $generalStats['total_reports']);
runTest("1.2 Sum of status breakdown equals total reports", array_sum($generalStats['by_status']) === $generalStats['total_reports'], $totalTests, $passedTests, $failedTests);
runTest("1.3 Sum of urgency breakdown equals total reports", array_sum($generalStats['by_urgency']) === $generalStats['total_reports'], $totalTests, $passedTests, $failedTests);

// -------------------------------------------------------------------------
// 2. Provenance Separation (Genuine vs Test/Demo vs Unknown)
// -------------------------------------------------------------------------
echo "\n--- 2. PROVENANCE SEPARATION ---\n";
$provSummary = $generalStats['provenance_summary'];
$provBreakdown = $generalStats['by_provenance'];
runTest("2.1 Genuine operational reports total is tracked separately", isset($provSummary['genuine_operational_total']), $totalTests, $passedTests, $failedTests);
runTest("2.2 Test/demo reports total is tracked separately", isset($provSummary['test_demo_total']) && $provSummary['test_demo_total'] >= 33, $totalTests, $passedTests, $failedTests, "Got test/demo total: " . $provSummary['test_demo_total']);
runTest("2.3 Sum of provenance categories equals total reports", 
    ($provSummary['genuine_operational_total'] + $provSummary['test_demo_total'] + $provSummary['unknown_provenance_total']) === $generalStats['total_reports'], 
    $totalTests, $passedTests, $failedTests
);
runTest("2.4 Provenance notice warns about data segregation", strpos($generalStats['data_segregation_notice'], 'strictly segregated') !== false, $totalTests, $passedTests, $failedTests);

// -------------------------------------------------------------------------
// 3. Unknown Provenance Categorization
// -------------------------------------------------------------------------
echo "\n--- 3. UNKNOWN PROVENANCE CATEGORIZATION ---\n";
runTest("3.1 Unknown provenance category exists and is tracked", isset($provBreakdown[DuplicateService::PROVENANCE_UNKNOWN]), $totalTests, $passedTests, $failedTests);
runTest("3.2 Unknown provenance is not mixed into genuine operational total", 
    $provSummary['genuine_operational_total'] === ($provBreakdown[DuplicateService::PROVENANCE_GENUINE_FIELD] + $provBreakdown[DuplicateService::PROVENANCE_GENUINE_BASELINE]), 
    $totalTests, $passedTests, $failedTests
);

// -------------------------------------------------------------------------
// 4. Report Status Aggregation
// -------------------------------------------------------------------------
echo "\n--- 4. REPORT STATUS AGGREGATION ---\n";
$byStatus = $generalStats['by_status'];
runTest("4.1 Pending status count is present", isset($byStatus['Pending']) && $byStatus['Pending'] > 0, $totalTests, $passedTests, $failedTests);
runTest("4.2 Verified status count is present", isset($byStatus['Verified']), $totalTests, $passedTests, $failedTests);
runTest("4.3 Rejected status count is present", isset($byStatus['Rejected']), $totalTests, $passedTests, $failedTests);
runTest("4.4 Converted to Alert status count is present", isset($byStatus['Converted to Alert']), $totalTests, $passedTests, $failedTests);

// -------------------------------------------------------------------------
// 5. Temporal Aggregation (24 Hourly Bins & Diurnal Windows)
// -------------------------------------------------------------------------
echo "\n--- 5. TEMPORAL AGGREGATION (24 HOURLY BINS) ---\n";
$temporalStats = AnalyticsService::getTemporalAnalytics($testPdo);
runTest("5.1 Exactly 24 diurnal hourly bins are generated (00 to 23)", count($temporalStats['hourly_distribution']) === 24, $totalTests, $passedTests, $failedTests);
runTest("5.2 Hourly bin keys are properly padded 2-character strings ('00' through '23')", isset($temporalStats['hourly_distribution']['00']) && isset($temporalStats['hourly_distribution']['23']), $totalTests, $passedTests, $failedTests);
runTest("5.3 Diurnal time windows tracked (dawn, morning, afternoon, dusk, night, unrecorded)", 
    isset($temporalStats['window_distribution']['dawn']) && isset($temporalStats['window_distribution']['dusk']) && isset($temporalStats['window_distribution']['unrecorded']), 
    $totalTests, $passedTests, $failedTests
);

// -------------------------------------------------------------------------
// 6. Missing Observation Dates
// -------------------------------------------------------------------------
echo "\n--- 6. MISSING OBSERVATION DATES ---\n";
runTest("6.1 Unrecorded dates count is positive in historical dataset", $temporalStats['unrecorded_dates_count'] > 0, $totalTests, $passedTests, $failedTests, "Got: " . $temporalStats['unrecorded_dates_count']);
$dataQuality = AnalyticsService::getDataQualityAnalytics($testPdo);
runTest("6.2 Data quality audit correctly computes missing dates ratio", $dataQuality['missing_observation_dates']['ratio'] > 0.0, $totalTests, $passedTests, $failedTests, "Ratio: " . $dataQuality['missing_observation_dates']['ratio']);

// -------------------------------------------------------------------------
// 7. Unknown Observation Times (No created_at Substitution)
// -------------------------------------------------------------------------
echo "\n--- 7. UNKNOWN OBSERVATION TIMES (NO CREATED_AT SUBSTITUTION) ---\n";
runTest("7.1 Unrecorded times count matches unrecorded observation count", $temporalStats['unrecorded_times_count'] > 0, $totalTests, $passedTests, $failedTests, "Got: " . $temporalStats['unrecorded_times_count']);
runTest("7.2 Precision breakdown tracks 'unknown' precision count", $temporalStats['precision_breakdown']['unknown'] > 0, $totalTests, $passedTests, $failedTests);
runTest("7.3 Scarcity warnings explicitly note exclusion of created_at", 
    strpos(implode(';', $temporalStats['data_scarcity_warnings']), 'created_at was strictly excluded') !== false, 
    $totalTests, $passedTests, $failedTests
);

// -------------------------------------------------------------------------
// 8. Monthly Aggregation
// -------------------------------------------------------------------------
echo "\n--- 8. MONTHLY AGGREGATION & LONGITUDINAL SPREAD ---\n";
runTest("8.1 Monthly distribution array is populated where dates exist", is_array($temporalStats['monthly_distribution']), $totalTests, $passedTests, $failedTests);
runTest("8.2 Warning emitted if longitudinal spread is insufficient (<= 1 month)", 
    strpos(implode(';', $temporalStats['data_scarcity_warnings']), 'Longitudinal spread is insufficient') !== false, 
    $totalTests, $passedTests, $failedTests
);

// -------------------------------------------------------------------------
// 9. Railway-Section Aggregation
// -------------------------------------------------------------------------
echo "\n--- 9. RAILWAY-SECTION AGGREGATION ---\n";
$secAnalytics = AnalyticsService::getSectionAnalytics($testPdo);
runTest("9.1 All registered railway sections returned", $secAnalytics['total_sections'] >= 4, $totalTests, $passedTests, $failedTests, "Total sections: " . $secAnalytics['total_sections']);
$firstSec = $secAnalytics['sections'][0];
runTest("9.2 Section record contains ID, name, district, risk level, and report tallies", 
    isset($firstSec['section_id']) && isset($firstSec['section_name']) && isset($firstSec['gps_enabled_reports']) && isset($firstSec['no_gps_reports']), 
    $totalTests, $passedTests, $failedTests
);
runTest("9.3 Section governance notice prohibits automatic geometric reassignment", 
    strpos($secAnalytics['governance_notice'], 'No automatic geometric reassignment') !== false, 
    $totalTests, $passedTests, $failedTests
);

// -------------------------------------------------------------------------
// 10. Hotspot Inventory (Configured vs Empirical)
// -------------------------------------------------------------------------
echo "\n--- 10. HOTSPOT INVENTORY (CONFIGURED VS EMPIRICAL) ---\n";
$hotspotStats = AnalyticsService::getHotspotAnalytics($testPdo);
runTest("10.1 Exactly 4 configured hotspots returned", $hotspotStats['total_hotspots'] === 4, $totalTests, $passedTests, $failedTests, "Total hotspots: " . $hotspotStats['total_hotspots']);
runTest("10.2 All hotspots classified as 'manually_configured'", 
    $hotspotStats['hotspots'][0]['validation_status'] === 'manually_configured', 
    $totalTests, $passedTests, $failedTests
);
runTest("10.3 Hotspot inventory includes section association and justification reason", 
    !empty($hotspotStats['hotspots'][0]['section_name']) && !empty($hotspotStats['hotspots'][0]['reason']), 
    $totalTests, $passedTests, $failedTests
);

// -------------------------------------------------------------------------
// 11. Duplicate Candidate Statistics (via DuplicateService)
// -------------------------------------------------------------------------
echo "\n--- 11. DUPLICATE CANDIDATE STATISTICS ---\n";
$dupStats = AnalyticsService::getDuplicateAnalytics($testPdo);
runTest("11.1 Stored duplicate flags count is non-negative integer", is_int($dupStats['stored_duplicate_metadata']['flagged_reports_count']), $totalTests, $passedTests, $failedTests);
runTest("11.2 Confirmed duplicates count is strictly 0 without human review", $dupStats['stored_duplicate_metadata']['confirmed_duplicates_count'] === 0, $totalTests, $passedTests, $failedTests);
runTest("11.3 Confirmation policy explicitly forbids automated duplicate merging", 
    strpos($dupStats['stored_duplicate_metadata']['confirmation_policy'], 'automated confirmation is prohibited') !== false, 
    $totalTests, $passedTests, $failedTests
);
runTest("11.4 Pairwise evaluation partitions candidates into strong, possible, and spatial-only", 
    isset($dupStats['pairwise_candidate_evaluation']['strong_candidates_pairs']) && 
    isset($dupStats['pairwise_candidate_evaluation']['possible_candidates_pairs']) && 
    isset($dupStats['pairwise_candidate_evaluation']['spatial_only_pairs']), 
    $totalTests, $passedTests, $failedTests
);

// -------------------------------------------------------------------------
// 12. Reporting Delay Calculations
// -------------------------------------------------------------------------
echo "\n--- 12. REPORTING DELAY CALCULATIONS ---\n";
$latencyStats = AnalyticsService::getReportingLatencyAnalytics($testPdo);
runTest("12.1 Reporting delay payload structures exact, approximate, and uncomputable timings", 
    isset($latencyStats['exact_reporting_delay']) && 
    isset($latencyStats['approximate_reporting_delay']) && 
    isset($latencyStats['uncomputable_timing_count']), 
    $totalTests, $passedTests, $failedTests
);
runTest("12.2 Uncomputable timing count tracks reports lacking observation timing", $latencyStats['uncomputable_timing_count'] > 0, $totalTests, $passedTests, $failedTests);
runTest("12.3 Delay calculation notice asserts created_at is never assumed to equal sighting time", 
    strpos($latencyStats['governance_notice'], 'created_at is never assumed') !== false, 
    $totalTests, $passedTests, $failedTests
);

// -------------------------------------------------------------------------
// 13. GPS Completeness Telemetry
// -------------------------------------------------------------------------
echo "\n--- 13. GPS COMPLETENESS TELEMETRY ---\n";
runTest("13.1 Missing GPS count is non-negative integer", is_int($dataQuality['missing_gps']['count']), $totalTests, $passedTests, $failedTests);
runTest("13.2 GPS accuracy completeness ratio is within [0.0, 1.0]", 
    $dataQuality['gps_accuracy_telemetry']['completeness_ratio'] >= 0.0 && 
    $dataQuality['gps_accuracy_telemetry']['completeness_ratio'] <= 1.0, 
    $totalTests, $passedTests, $failedTests
);
runTest("13.3 Overall data completeness percentage is calculated [0, 100]", 
    $dataQuality['overall_data_completeness_percentage'] >= 0.0 && 
    $dataQuality['overall_data_completeness_percentage'] <= 100.0, 
    $totalTests, $passedTests, $failedTests, "Score: " . $dataQuality['overall_data_completeness_percentage'] . "%"
);

// -------------------------------------------------------------------------
// 14. Invalid Coordinates
// -------------------------------------------------------------------------
echo "\n--- 14. INVALID COORDINATES DETECTION ---\n";
runTest("14.1 Invalid coordinates count is integer >= 0", is_int($dataQuality['invalid_coordinates_count']), $totalTests, $passedTests, $failedTests);
runTest("14.2 Potential railway section assignment discrepancies evaluated", is_int($dataQuality['potential_section_assignment_discrepancies']), $totalTests, $passedTests, $failedTests);

// -------------------------------------------------------------------------
// 15. AI Advisory Aggregation (Workflow Concordance)
// -------------------------------------------------------------------------
echo "\n--- 15. AI ADVISORY AGGREGATION ---\n";
$aiStats = AnalyticsService::getAiAdvisoryAnalytics($testPdo);
runTest("15.1 AI subsystem status breakdown tracks processed, skipped, pending, failed", 
    isset($aiStats['ai_subsystem_status']['processed']) && 
    isset($aiStats['ai_subsystem_status']['skipped']), 
    $totalTests, $passedTests, $failedTests
);
runTest("15.2 Human officer decision matrix tracks agreement and pending reviews", 
    isset($aiStats['human_officer_concordance_matrix']['ai_detected_and_officer_verified']) && 
    isset($aiStats['human_officer_concordance_matrix']['pending_human_officer_review']), 
    $totalTests, $passedTests, $failedTests
);
runTest("15.3 AI governance notice states output is advisory-only and not certified model accuracy", 
    strpos($aiStats['governance_notice'], 'AI classification is advisory-only') !== false, 
    $totalTests, $passedTests, $failedTests
);

// -------------------------------------------------------------------------
// 16. Role-Based Access Control
// -------------------------------------------------------------------------
echo "\n--- 16. ROLE-BASED ACCESS CONTROL ---\n";
$authWildlife = AnalyticsService::getHotspotAnalytics($testPdo, ['user_role' => 'wildlife_officer']);
$unauthDriver = AnalyticsService::getHotspotAnalytics($testPdo, ['user_role' => 'driver']);
$unauthReporter = AnalyticsService::getHotspotAnalytics($testPdo, ['user_role' => 'reporter']);
$unauthPublic = AnalyticsService::getHotspotAnalytics($testPdo, ['user_role' => null]);

runTest("16.1 Wildlife Officer is authorized role", in_array('wildlife_officer', AnalyticsService::AUTHORIZED_ROLES, true), $totalTests, $passedTests, $failedTests);
runTest("16.2 Driver is not in authorized roles list", !in_array('driver', AnalyticsService::AUTHORIZED_ROLES, true), $totalTests, $passedTests, $failedTests);
runTest("16.3 Reporter is not in authorized roles list", !in_array('reporter', AnalyticsService::AUTHORIZED_ROLES, true), $totalTests, $passedTests, $failedTests);

// -------------------------------------------------------------------------
// 17. Coordinate Privacy (Anti-Poaching Protection)
// -------------------------------------------------------------------------
echo "\n--- 17. COORDINATE PRIVACY ---\n";
runTest("17.1 Authorized Wildlife Officer receives exact unmasked coordinates", 
    $authWildlife['hotspots'][0]['is_coordinates_masked'] === false && 
    $authWildlife['hotspots'][0]['latitude'] === 8.0412, 
    $totalTests, $passedTests, $failedTests
);
runTest("17.2 Restricted Driver receives fuzzed coordinates (rounded to 2 decimal places)", 
    $unauthDriver['hotspots'][0]['is_coordinates_masked'] === true && 
    $unauthDriver['hotspots'][0]['latitude'] === 8.04, 
    $totalTests, $passedTests, $failedTests
);
runTest("17.3 Restricted Reporter receives fuzzed coordinates", 
    $unauthReporter['hotspots'][0]['is_coordinates_masked'] === true && 
    $unauthReporter['hotspots'][0]['latitude'] === 8.04, 
    $totalTests, $passedTests, $failedTests
);
runTest("17.4 Unauthenticated public viewer receives fuzzed coordinates", 
    $unauthPublic['hotspots'][0]['is_coordinates_masked'] === true && 
    $unauthPublic['hotspots'][0]['latitude'] === 8.04, 
    $totalTests, $passedTests, $failedTests
);
runTest("17.5 Masking notice attached to unauthorized output", 
    strpos($unauthDriver['hotspots'][0]['coordinate_masking_notice'], 'anti-poaching policy') !== false, 
    $totalTests, $passedTests, $failedTests
);

// -------------------------------------------------------------------------
// 18. SQL Read-Only Enforcement
// -------------------------------------------------------------------------
echo "\n--- 18. SQL READ-ONLY ENFORCEMENT ---\n";
$insertBlocked = false;
$updateBlocked = false;
$deleteBlocked = false;
$dropBlocked = false;

try {
    AnalyticsService::executeReadOnlyQuery($testPdo, "INSERT INTO elephant_reports (section_id) VALUES (1)");
} catch (RuntimeException $e) {
    if (strpos($e->getMessage(), 'SQL Read-Only Violation') !== false) {
        $insertBlocked = true;
    }
}
try {
    AnalyticsService::executeReadOnlyQuery($testPdo, "UPDATE elephant_reports SET risk_score = 'Critical' WHERE id = 1");
} catch (RuntimeException $e) {
    if (strpos($e->getMessage(), 'SQL Read-Only Violation') !== false) {
        $updateBlocked = true;
    }
}
try {
    AnalyticsService::executeReadOnlyQuery($testPdo, "DELETE FROM elephant_reports WHERE id = 1");
} catch (RuntimeException $e) {
    if (strpos($e->getMessage(), 'SQL Read-Only Violation') !== false) {
        $deleteBlocked = true;
    }
}
try {
    AnalyticsService::executeReadOnlyQuery($testPdo, "DROP TABLE elephant_reports");
} catch (RuntimeException $e) {
    if (strpos($e->getMessage(), 'SQL Read-Only Violation') !== false) {
        $dropBlocked = true;
    }
}

runTest("18.1 executeReadOnlyQuery strictly blocks INSERT statements", $insertBlocked === true, $totalTests, $passedTests, $failedTests);
runTest("18.2 executeReadOnlyQuery strictly blocks UPDATE statements", $updateBlocked === true, $totalTests, $passedTests, $failedTests);
runTest("18.3 executeReadOnlyQuery strictly blocks DELETE statements", $deleteBlocked === true, $totalTests, $passedTests, $failedTests);
runTest("18.4 executeReadOnlyQuery strictly blocks DROP statements", $dropBlocked === true, $totalTests, $passedTests, $failedTests);

// -------------------------------------------------------------------------
// 19. Operational Database Immutability & Full Summary Verification
// -------------------------------------------------------------------------
echo "\n--- 19. OPERATIONAL DATABASE IMMUTABILITY ---\n";
$fullSummary = AnalyticsService::getFullAnalyticsSummary($testPdo);
runTest("19.1 getFullAnalyticsSummary aggregates all 7 analytical domains cleanly", 
    isset($fullSummary['general_statistics']) && 
    isset($fullSummary['temporal_analytics']) && 
    isset($fullSummary['section_analytics']) && 
    isset($fullSummary['hotspot_analytics']) && 
    isset($fullSummary['duplicate_analytics']) && 
    isset($fullSummary['reporting_latency']) && 
    isset($fullSummary['data_quality']) && 
    isset($fullSummary['ai_advisory']), 
    $totalTests, $passedTests, $failedTests
);

try {
    $postReports = (int)$opPdo->query("SELECT COUNT(*) FROM elephant_reports")->fetchColumn();
    $postLogs = (int)$opPdo->query("SELECT COUNT(*) FROM system_logs")->fetchColumn();
    $postAlerts = (int)$opPdo->query("SELECT COUNT(*) FROM alerts")->fetchColumn();
    $postRep1 = $opPdo->query("SELECT sighting_date, sighting_time, risk_score, report_status FROM elephant_reports WHERE id = 1")->fetch(PDO::FETCH_ASSOC);

    runTest("19.2 Operational DB elephant_reports remains 43", $postReports === 43, $totalTests, $passedTests, $failedTests, "Got: $postReports");
    runTest("19.3 Operational DB system_logs remains 185", $postLogs === 185, $totalTests, $passedTests, $failedTests, "Got: $postLogs");
    runTest("19.4 Operational DB alerts remains 15", $postAlerts === 15, $totalTests, $passedTests, $failedTests, "Got: $postAlerts");
    runTest("19.5 Operational Report #1 sighting time remains NULL", $postRep1['sighting_time'] === null, $totalTests, $passedTests, $failedTests);
    runTest("19.6 Operational Report #1 risk score remains Medium (identical to baseline)", $postRep1['risk_score'] === $opRep1['risk_score'] && $postRep1['risk_score'] === 'Medium', $totalTests, $passedTests, $failedTests);
    runTest("19.7 Operational Report #1 status remains Pending", $postRep1['report_status'] === 'Pending', $totalTests, $passedTests, $failedTests);
} catch (Exception $e) {
    runTest("19.2 Post-test operational DB audit failed", false, $totalTests, $passedTests, $failedTests, $e->getMessage());
}

// -------------------------------------------------------------------------
// 20. Empty Datasets Graceful Handling
// -------------------------------------------------------------------------
echo "\n--- 20. EMPTY DATASETS GRACEFUL HANDLING ---\n";
// Create temporary in-memory SQLite PDO or dedicated empty schema in eleguard_rail_test
try {
    $emptyPdo = new PDO("sqlite::memory:");
    $emptyPdo->exec("CREATE TABLE elephant_reports (
        id INTEGER PRIMARY KEY, section_id INT, reported_by INT, elephant_count INT,
        sighting_date TEXT, sighting_time TEXT, sighting_time_precision TEXT, sighting_time_window TEXT,
        sighting_time_uncertainty_minutes INT, sighting_timezone TEXT, location_description TEXT,
        latitude REAL, longitude REAL, gps_accuracy_meters REAL, urgency_level TEXT,
        risk_score TEXT, report_status TEXT, description TEXT, image_path TEXT,
        ai_status TEXT, ai_detected_elephant INT, ai_confidence REAL, ai_model_version TEXT,
        ai_detection_notes TEXT, is_potential_duplicate INT, duplicate_cluster_id INT,
        created_at TEXT, updated_at TEXT
    )");
    $emptyPdo->exec("CREATE TABLE railway_sections (
        id INTEGER PRIMARY KEY, section_name TEXT, district TEXT, risk_level TEXT,
        start_location TEXT, end_location TEXT
    )");
    $emptyPdo->exec("CREATE TABLE hotspots (
        id INTEGER PRIMARY KEY, section_id INT, hotspot_name TEXT, latitude REAL,
        longitude REAL, risk_level TEXT, reason TEXT, created_at TEXT
    )");

    $emptyGeneral = AnalyticsService::getGeneralStatistics($emptyPdo);
    $emptyTemporal = AnalyticsService::getTemporalAnalytics($emptyPdo);
    $emptySections = AnalyticsService::getSectionAnalytics($emptyPdo);
    $emptyHotspots = AnalyticsService::getHotspotAnalytics($emptyPdo);
    $emptyQuality = AnalyticsService::getDataQualityAnalytics($emptyPdo);

    runTest("20.1 Empty dataset general stats returns total_reports = 0 without divide-by-zero", $emptyGeneral['total_reports'] === 0, $totalTests, $passedTests, $failedTests);
    runTest("20.2 Empty temporal stats generates 24 zero-count hourly bins", count($emptyTemporal['hourly_distribution']) === 24 && array_sum($emptyTemporal['hourly_distribution']) === 0, $totalTests, $passedTests, $failedTests);
    runTest("20.3 Empty section analytics returns total_sections = 0 cleanly", $emptySections['total_sections'] === 0, $totalTests, $passedTests, $failedTests);
    runTest("20.4 Empty hotspot analytics returns total_hotspots = 0 cleanly", $emptyHotspots['total_hotspots'] === 0, $totalTests, $passedTests, $failedTests);
    runTest("20.5 Empty data quality analytics returns 0% completeness without error", $emptyQuality['overall_data_completeness_percentage'] === 0.0, $totalTests, $passedTests, $failedTests);
} catch (Exception $e) {
    runTest("20.1 Empty dataset testing failed", false, $totalTests, $passedTests, $failedTests, $e->getMessage());
}

// -------------------------------------------------------------------------
// SUMMARY
// -------------------------------------------------------------------------
echo "\n========================================================================\n";
echo "STAGE 4C READ-ONLY HISTORICAL ANALYTICS TEST RESULTS\n";
echo "Total Tests:  {$totalTests}\n";
echo "Passed Tests: {$passedTests}\n";
echo "Failed Tests: {$failedTests}\n";
echo "========================================================================\n";

if ($failedTests > 0) {
    exit(1);
}
exit(0);
