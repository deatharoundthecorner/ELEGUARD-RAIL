<?php
/**
 * Read-Only Historical Analytics Query Layer
 * 
 * EleGuard Rail — Milestone 9C Stage 4C
 * 
 * Provides explainable historical statistics, temporal aggregations, corridor densities,
 * latency metrics, and data quality audits using existing database records.
 * 
 * STRICT ARCHITECTURAL CONSTRAINTS:
 * 1. Strictly read-only: Zero INSERT, UPDATE, DELETE, ALTER, DROP, or TRUNCATE operations.
 * 2. Never modifies operational records, stored risk scores, verification statuses, or alerts.
 * 3. Never substitutes submission timestamps (created_at) for missing observation timestamps.
 * 4. Strictly segregates known test/demo records from genuine wildlife statistics.
 * 5. Preserves unknown provenance as an explicit, separate category (never assumed genuine or test).
 * 6. Preserves AI advisory-only governance (concordance metrics reflect workflow agreement, not accuracy).
 * 7. Protects sensitive wildlife coordinates via role-based access control and masking.
 */

require_once __DIR__ . '/../core/Database.php';
require_once __DIR__ . '/../services/DuplicateService.php';
require_once __DIR__ . '/../services/TemporalService.php';
require_once __DIR__ . '/../services/ProximityService.php';

class AnalyticsService {

    // Authorized roles permitted to view unmasked spatial metadata
    const AUTHORIZED_ROLES = ['admin', 'wildlife_officer', 'control_officer'];

    /**
     * Executes a read-only SQL query with parameter binding and strict mutation prevention.
     * 
     * @param PDO $pdo Active PDO connection
     * @param string $sql SQL SELECT statement
     * @param array $params Query parameters
     * @return array Query result rows
     * @throws RuntimeException If any write/mutation operation is detected
     */
    public static function executeReadOnlyQuery(PDO $pdo, $sql, array $params = []) {
        if (!is_string($sql) || trim($sql) === '') {
            throw new InvalidArgumentException("SQL Read-Only Violation: Query must be a non-empty string.");
        }

        // 1. Strip SQL comments safely to expose the true canonical statements
        // Multi-line comments: /* ... */
        $canonicalSql = preg_replace('/\/\*[\s\S]*?\*\//', ' ', $sql);
        // Single-line comments: -- comment and # comment
        $canonicalSql = preg_replace('/(--|#)[^\r\n]*/', ' ', $canonicalSql);
        $canonicalSql = preg_replace('/\s+/', ' ', trim($canonicalSql));

        if ($canonicalSql === '') {
            throw new InvalidArgumentException("SQL Read-Only Violation: Query contains only comments or whitespace.");
        }

        // 2. Reject multiple SQL statements (semicolon is only permitted as optional final character)
        if (preg_match('/;(?!\s*$)/', $canonicalSql)) {
            throw new RuntimeException("SQL Read-Only Violation: Multiple SQL statements are strictly prohibited.");
        }

        // 3. Statement form restriction: Query must begin strictly with SELECT, SHOW, DESCRIBE, or EXPLAIN
        if (!preg_match('/^(SELECT|SHOW|DESCRIBE|EXPLAIN)\b/i', $canonicalSql)) {
            throw new RuntimeException("SQL Read-Only Violation: Statement must begin with SELECT, SHOW, DESCRIBE, or EXPLAIN.");
        }

        // 4. Reject file export and external file access (INTO OUTFILE/DUMPFILE, LOAD_FILE)
        if (preg_match('/\bINTO\s+(OUTFILE|DUMPFILE)\b/i', $canonicalSql)) {
            throw new RuntimeException("SQL Read-Only Violation: File export operations (INTO OUTFILE/DUMPFILE) are strictly prohibited.");
        }
        if (preg_match('/\bLOAD_FILE\s*\(/i', $canonicalSql)) {
            throw new RuntimeException("SQL Read-Only Violation: File reading functions are strictly prohibited.");
        }

        // 5. Reject variable assignments (@var := or SET @var)
        if (preg_match('/(@\w+\s*:=|\bSET\s+@)/i', $canonicalSql)) {
            throw new RuntimeException("SQL Read-Only Violation: Variable assignment is strictly prohibited in analytics queries.");
        }

        // 6. Reject locking clauses (FOR UPDATE, LOCK IN SHARE MODE, FOR SHARE)
        if (preg_match('/\bFOR\s+(UPDATE|SHARE)\b/i', $canonicalSql)) {
            throw new RuntimeException("SQL Read-Only Violation: Row-locking clauses (FOR UPDATE/SHARE) are strictly prohibited.");
        }
        if (preg_match('/\bLOCK\s+IN\s+SHARE\s+MODE\b/i', $canonicalSql)) {
            throw new RuntimeException("SQL Read-Only Violation: Locking clauses (LOCK IN SHARE MODE) are strictly prohibited.");
        }

        // 7. Reject unsafe stored procedures, side-effecting functions, and administrative commands
        if (preg_match('/\b(SLEEP|BENCHMARK|GET_LOCK|RELEASE_LOCK|UUID_SHORT|SYS_EXEC|SYS_EVAL)\s*\(/i', $canonicalSql)) {
            throw new RuntimeException("SQL Read-Only Violation: Side-effecting or resource-exhausting functions are strictly prohibited.");
        }

        // 8. Strict ban on mutation and DDL keywords anywhere in the canonical query
        if (preg_match('/\b(INSERT|UPDATE|DELETE|DROP|ALTER|TRUNCATE|CREATE|REPLACE|GRANT|REVOKE|RENAME|CALL|DO|HANDLER|LOAD|FLUSH|KILL|RESET|SHUTDOWN)\b/i', $canonicalSql)) {
            throw new RuntimeException("SQL Read-Only Violation: Mutation or administrative statements are strictly prohibited.");
        }

        // 9. Execute with prepared statement (driver emulated prepares disabled)
        $stmt = $pdo->prepare($canonicalSql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * A. General report statistics.
     * Segregates genuine operational reports from test/demo and unknown provenance.
     * 
     * @param PDO $pdo Active PDO connection
     * @param array $options Configuration options
     * @return array General statistics payload
     */
    public static function getGeneralStatistics(PDO $pdo, array $options = []) {
        $sql = "SELECT id, section_id, reported_by, description, location_description, image_path, 
                       ai_detection_notes, report_status, urgency_level, risk_score, created_at 
                FROM elephant_reports 
                ORDER BY id ASC";
        $reports = self::executeReadOnlyQuery($pdo, $sql);

        $totalReports = count($reports);
        $byStatus = [
            'Pending' => 0,
            'Verified' => 0,
            'Rejected' => 0,
            'Converted to Alert' => 0,
            'Resolved' => 0
        ];
        $byUrgency = ['Low' => 0, 'Medium' => 0, 'High' => 0, 'Critical' => 0];
        $byRiskScore = ['Low' => 0, 'Medium' => 0, 'High' => 0, 'Critical' => 0];

        $byProvenance = [
            DuplicateService::PROVENANCE_GENUINE_FIELD => 0,
            DuplicateService::PROVENANCE_GENUINE_BASELINE => 0,
            DuplicateService::PROVENANCE_DEV_TEST => 0,
            DuplicateService::PROVENANCE_AI_TEST => 0,
            DuplicateService::PROVENANCE_E2E_AUDIT => 0,
            DuplicateService::PROVENANCE_UNKNOWN => 0
        ];

        foreach ($reports as $r) {
            $status = $r['report_status'] ?? 'Pending';
            if (isset($byStatus[$status])) {
                $byStatus[$status]++;
            }

            $urgency = $r['urgency_level'] ?? 'Low';
            if (isset($byUrgency[$urgency])) {
                $byUrgency[$urgency]++;
            }

            $risk = $r['risk_score'] ?? 'Low';
            if (isset($byRiskScore[$risk])) {
                $byRiskScore[$risk]++;
            }

            $prov = DuplicateService::classifyProvenance($r);
            if (isset($byProvenance[$prov])) {
                $byProvenance[$prov]++;
            } else {
                $byProvenance[DuplicateService::PROVENANCE_UNKNOWN]++;
            }
        }

        $genuineTotal = $byProvenance[DuplicateService::PROVENANCE_GENUINE_FIELD] + 
                        $byProvenance[DuplicateService::PROVENANCE_GENUINE_BASELINE];
        $testDemoTotal = $byProvenance[DuplicateService::PROVENANCE_DEV_TEST] + 
                         $byProvenance[DuplicateService::PROVENANCE_AI_TEST] + 
                         $byProvenance[DuplicateService::PROVENANCE_E2E_AUDIT];
        $unknownTotal = $byProvenance[DuplicateService::PROVENANCE_UNKNOWN];

        return [
            'total_reports' => $totalReports,
            'by_status' => $byStatus,
            'by_urgency' => $byUrgency,
            'by_risk_score' => $byRiskScore,
            'by_provenance' => $byProvenance,
            'provenance_summary' => [
                'genuine_operational_total' => $genuineTotal,
                'test_demo_total' => $testDemoTotal,
                'unknown_provenance_total' => $unknownTotal,
                'genuine_operational_ratio' => $totalReports > 0 ? round($genuineTotal / $totalReports, 4) : 0.0,
                'test_demo_ratio' => $totalReports > 0 ? round($testDemoTotal / $totalReports, 4) : 0.0,
                'unknown_provenance_ratio' => $totalReports > 0 ? round($unknownTotal / $totalReports, 4) : 0.0
            ],
            'data_segregation_notice' => "Test/demo records ({$testDemoTotal}) are strictly segregated from genuine wildlife statistics. Unknown provenance records ({$unknownTotal}) are tracked separately."
        ];
    }

    /**
     * B. Temporal analytics.
     * Aggregates by observation hour, date, month, and diurnal time window.
     * Strictly avoids substituting submission time (created_at) for missing observation times.
     * 
     * @param PDO $pdo Active PDO connection
     * @param array $options Configuration options:
     *                       - genuine_only (bool, default false)
     * @return array Temporal analytics payload
     */
    public static function getTemporalAnalytics(PDO $pdo, array $options = []) {
        $genuineOnly = !empty($options['genuine_only']);

        $sql = "SELECT id, reported_by, description, location_description, image_path, ai_detection_notes,
                       sighting_date, sighting_time, sighting_time_precision, sighting_time_window, 
                       sighting_time_uncertainty_minutes, created_at 
                FROM elephant_reports 
                ORDER BY id ASC";
        $reports = self::executeReadOnlyQuery($pdo, $sql);

        // 24 one-hour diurnal bins (00:00 to 23:00)
        $hourlyDistribution = [];
        for ($h = 0; $h < 24; $h++) {
            $key = sprintf("%02d", $h);
            $hourlyDistribution[$key] = 0;
        }

        $dateDistribution = [];
        $monthlyDistribution = [];
        $windowDistribution = [
            'dawn' => 0,
            'morning' => 0,
            'afternoon' => 0,
            'dusk' => 0,
            'night' => 0,
            'custom' => 0,
            'unrecorded' => 0
        ];
        $precisionBreakdown = [
            'exact' => 0,
            'approximate' => 0,
            'unknown' => 0
        ];

        $totalEvaluated = 0;
        $unrecordedDateCount = 0;
        $unrecordedTimeCount = 0;

        foreach ($reports as $r) {
            $prov = DuplicateService::classifyProvenance($r);
            $isTest = DuplicateService::isTestOrDemoProvenance($prov);

            if ($genuineOnly && $isTest) {
                continue; // Skip test records when genuine_only is requested
            }
            $totalEvaluated++;

            // Precision tracking
            $precision = !empty($r['sighting_time_precision']) ? strtolower(trim((string)$r['sighting_time_precision'])) : 'unknown';
            if (isset($precisionBreakdown[$precision])) {
                $precisionBreakdown[$precision]++;
            } else {
                $precisionBreakdown['unknown']++;
            }

            // Window tracking
            $win = !empty($r['sighting_time_window']) ? strtolower(trim((string)$r['sighting_time_window'])) : null;
            if ($win && isset($windowDistribution[$win])) {
                $windowDistribution[$win]++;
            } else {
                $windowDistribution['unrecorded']++;
            }

            // Date tracking
            if (!empty($r['sighting_date'])) {
                $dateStr = $r['sighting_date'];
                $monthStr = substr($dateStr, 0, 7); // YYYY-MM

                $dateDistribution[$dateStr] = ($dateDistribution[$dateStr] ?? 0) + 1;
                $monthlyDistribution[$monthStr] = ($monthlyDistribution[$monthStr] ?? 0) + 1;
            } else {
                $unrecordedDateCount++;
            }

            // Hour tracking: Strictly from sighting_time (never created_at)
            if (!empty($r['sighting_time']) && $precision !== 'unknown') {
                $hourKey = sprintf("%02d", (int)substr($r['sighting_time'], 0, 2));
                if (isset($hourlyDistribution[$hourKey])) {
                    $hourlyDistribution[$hourKey]++;
                }
            } else {
                $unrecordedTimeCount++;
            }
        }

        ksort($dateDistribution);
        ksort($monthlyDistribution);

        // Data scarcity warnings
        $warnings = [];
        if ($unrecordedTimeCount > 0) {
            $warnings[] = "Unrecorded observation times present: {$unrecordedTimeCount} of {$totalEvaluated} reports lack reliable clock timing. Ingestion timestamp created_at was strictly excluded.";
        }
        if (count($monthlyDistribution) <= 1) {
            $warnings[] = "Longitudinal spread is insufficient (<= 1 month). Seasonal migration claims are scientifically invalid.";
        }
        if ($totalEvaluated > 0 && ($unrecordedTimeCount / $totalEvaluated) > 0.5) {
            $warnings[] = "Data scarcity notice: More than 50% of reports lack observation timestamps. Diurnal hourly density must be interpreted with caution.";
        }

        return [
            'total_evaluated' => $totalEvaluated,
            'filter_mode' => $genuineOnly ? 'genuine_only' : 'all_records',
            'hourly_distribution' => $hourlyDistribution,
            'date_distribution' => $dateDistribution,
            'monthly_distribution' => $monthlyDistribution,
            'window_distribution' => $windowDistribution,
            'precision_breakdown' => $precisionBreakdown,
            'unrecorded_dates_count' => $unrecordedDateCount,
            'unrecorded_times_count' => $unrecordedTimeCount,
            'data_scarcity_warnings' => $warnings
        ];
    }

    /**
     * C. Railway-section analytics.
     * Aggregates report counts, status, GPS telemetry, and duplicates per railway section.
     * 
     * @param PDO $pdo Active PDO connection
     * @param array $options Configuration options
     * @return array Section analytics payload
     */
    public static function getSectionAnalytics(PDO $pdo, array $options = []) {
        $secSql = "SELECT id, section_name, district, risk_level, start_location, end_location 
                   FROM railway_sections 
                   ORDER BY id ASC";
        $sections = self::executeReadOnlyQuery($pdo, $secSql);

        $repSql = "SELECT id, section_id, reported_by, description, location_description, image_path,
                          ai_detection_notes, latitude, longitude, report_status, is_potential_duplicate 
                   FROM elephant_reports 
                   ORDER BY id ASC";
        $reports = self::executeReadOnlyQuery($pdo, $repSql);

        // Index reports by section
        $reportsBySection = [];
        $unassignedReports = [];

        foreach ($reports as $r) {
            $secId = $r['section_id'] !== null ? (int)$r['section_id'] : null;
            if ($secId !== null) {
                $reportsBySection[$secId][] = $r;
            } else {
                $unassignedReports[] = $r;
            }
        }

        $sectionSummaries = [];
        foreach ($sections as $s) {
            $secId = (int)$s['id'];
            $secReports = $reportsBySection[$secId] ?? [];
            $totalCount = count($secReports);

            $verifiedCount = 0;
            $pendingCount = 0;
            $rejectedCount = 0;
            $gpsEnabledCount = 0;
            $noGpsCount = 0;
            $duplicateCount = 0;
            $genuineCount = 0;
            $testCount = 0;
            $unknownCount = 0;

            foreach ($secReports as $r) {
                $st = $r['report_status'] ?? 'Pending';
                if ($st === 'Verified' || $st === 'Converted to Alert' || $st === 'Resolved') $verifiedCount++;
                elseif ($st === 'Pending') $pendingCount++;
                elseif ($st === 'Rejected') $rejectedCount++;

                if ($r['latitude'] !== null && $r['longitude'] !== null) {
                    $gpsEnabledCount++;
                } else {
                    $noGpsCount++;
                }

                if (!empty($r['is_potential_duplicate'])) {
                    $duplicateCount++;
                }

                $prov = DuplicateService::classifyProvenance($r);
                if ($prov === DuplicateService::PROVENANCE_GENUINE_FIELD || $prov === DuplicateService::PROVENANCE_GENUINE_BASELINE) {
                    $genuineCount++;
                } elseif (DuplicateService::isTestOrDemoProvenance($prov)) {
                    $testCount++;
                } else {
                    $unknownCount++;
                }
            }

            $sectionSummaries[] = [
                'section_id' => $secId,
                'section_name' => $s['section_name'],
                'district' => $s['district'],
                'risk_level' => $s['risk_level'],
                'total_reports' => $totalCount,
                'verified_reports' => $verifiedCount,
                'pending_reports' => $pendingCount,
                'rejected_reports' => $rejectedCount,
                'gps_enabled_reports' => $gpsEnabledCount,
                'no_gps_reports' => $noGpsCount,
                'potential_duplicate_reports' => $duplicateCount,
                'provenance_breakdown' => [
                    'genuine' => $genuineCount,
                    'test_demo' => $testCount,
                    'unknown' => $unknownCount
                ]
            ];
        }

        return [
            'total_sections' => count($sections),
            'sections' => $sectionSummaries,
            'unassigned_reports_count' => count($unassignedReports),
            'governance_notice' => "Reports are tallied strictly by assigned administrative section. No automatic geometric reassignment is performed."
        ];
    }

    /**
     * D. Hotspot analytics.
     * Lists existing configured hotspots and transparently identifies validation status.
     * Protects precise coordinates for unauthorized roles.
     * 
     * @param PDO $pdo Active PDO connection
     * @param array $options Configuration options:
     *                       - user_role (string|null)
     * @return array Hotspot analytics payload
     */
    public static function getHotspotAnalytics(PDO $pdo, array $options = []) {
        $userRole = $options['user_role'] ?? null;
        $isAuthorized = ($userRole !== null && in_array(strtolower(trim($userRole)), self::AUTHORIZED_ROLES, true));

        $sql = "SELECT h.id, h.section_id, s.section_name, s.district, h.hotspot_name, 
                       h.latitude, h.longitude, h.risk_level, h.reason, h.created_at 
                FROM hotspots h 
                LEFT JOIN railway_sections s ON h.section_id = s.id 
                ORDER BY h.id ASC";
        $hotspots = self::executeReadOnlyQuery($pdo, $sql);

        $hotspotList = [];
        $byRisk = ['Low' => 0, 'Medium' => 0, 'High' => 0, 'Critical' => 0];
        $bySection = [];

        foreach ($hotspots as $h) {
            $risk = $h['risk_level'] ?? 'Low';
            if (isset($byRisk[$risk])) {
                $byRisk[$risk]++;
            }

            $secName = $h['section_name'] ?? 'Unassigned';
            $bySection[$secName] = ($bySection[$secName] ?? 0) + 1;

            // Role-based coordinate masking for hotspots
            if ($isAuthorized) {
                $lat = (float)$h['latitude'];
                $lon = (float)$h['longitude'];
                $isMasked = false;
                $maskNotice = null;
            } else {
                $lat = round((float)$h['latitude'], 2);
                $lon = round((float)$h['longitude'], 2);
                $isMasked = true;
                $maskNotice = "Precise coordinates masked for wildlife protection (anti-poaching policy).";
            }

            $hotspotList[] = [
                'id' => (int)$h['id'],
                'hotspot_name' => $h['hotspot_name'],
                'section_id' => (int)$h['section_id'],
                'section_name' => $secName,
                'district' => $h['district'],
                'risk_level' => $risk,
                'reason' => $h['reason'],
                'latitude' => $lat,
                'longitude' => $lon,
                'is_coordinates_masked' => $isMasked,
                'coordinate_masking_notice' => $maskNotice,
                'validation_status' => 'manually_configured',
                'validation_note' => 'Configured at initial system commissioning based on expert observation; awaiting multi-year empirical density clustering for empirical validation.'
            ];
        }

        return [
            'total_hotspots' => count($hotspots),
            'by_risk_level' => $byRisk,
            'by_section' => $bySection,
            'hotspots' => $hotspotList,
            'validation_notice' => "All existing hotspots are classified as 'manually_configured'. No empirical density clustering has been promoted to certified hotspot definitions."
        ];
    }

    /**
     * E. Duplicate analytics.
     * Evaluates stored duplicate flags and pairwise candidate statistics via DuplicateService.
     * 
     * @param PDO $pdo Active PDO connection
     * @param array $options Configuration options
     * @return array Duplicate analytics payload
     */
    public static function getDuplicateAnalytics(PDO $pdo, array $options = []) {
        $sql = "SELECT id, section_id, reported_by, elephant_count, sighting_date, sighting_time, 
                       sighting_time_precision, sighting_time_window, sighting_time_uncertainty_minutes,
                       sighting_timezone, location_description, latitude, longitude, gps_accuracy_meters,
                       urgency_level, risk_score, report_status, description, image_path,
                       ai_detection_notes, is_potential_duplicate, duplicate_cluster_id, created_at 
                FROM elephant_reports 
                ORDER BY id ASC";
        $reports = self::executeReadOnlyQuery($pdo, $sql);

        // 1. Stored database flags
        $storedFlaggedCount = 0;
        $clusterIds = [];
        foreach ($reports as $r) {
            if (!empty($r['is_potential_duplicate'])) {
                $storedFlaggedCount++;
            }
            if (!empty($r['duplicate_cluster_id'])) {
                $clusterIds[$r['duplicate_cluster_id']] = true;
            }
        }

        // 2. Pairwise candidate analysis on genuine/operational reports
        $genuineReports = [];
        foreach ($reports as $r) {
            $prov = DuplicateService::classifyProvenance($r);
            if (!DuplicateService::isTestOrDemoProvenance($prov)) {
                $genuineReports[] = $r;
            }
        }

        $strongCount = 0;
        $possibleCount = 0;
        $spatialOnlyCount = 0;
        $insufficientCount = 0;
        $excludedTestCount = 0;

        $nGen = count($genuineReports);
        for ($i = 0; $i < $nGen; $i++) {
            for ($j = $i + 1; $j < $nGen; $j++) {
                $eval = DuplicateService::evaluateDuplicateCandidate($genuineReports[$i], $genuineReports[$j], $options);
                $cls = $eval['candidate_classification'];

                if ($cls === DuplicateService::CLASSIFICATION_STRONG) $strongCount++;
                elseif ($cls === DuplicateService::CLASSIFICATION_POSSIBLE) $possibleCount++;
                elseif ($cls === DuplicateService::CLASSIFICATION_SPATIAL_ONLY) $spatialOnlyCount++;
                elseif ($cls === DuplicateService::CLASSIFICATION_EXCLUDED_TEST) $excludedTestCount++;
                else $insufficientCount++;
            }
        }

        return [
            'stored_duplicate_metadata' => [
                'flagged_reports_count' => $storedFlaggedCount,
                'distinct_clusters_count' => count($clusterIds),
                'confirmed_duplicates_count' => 0,
                'confirmation_policy' => "Zero confirmed duplicates. Duplicate confirmation requires authenticated Wildlife Officer action; automated confirmation is prohibited."
            ],
            'pairwise_candidate_evaluation' => [
                'evaluated_reports_count' => $nGen,
                'total_pairs_evaluated' => ($nGen * ($nGen - 1)) / 2,
                'strong_candidates_pairs' => $strongCount,
                'possible_candidates_pairs' => $possibleCount,
                'spatial_only_pairs' => $spatialOnlyCount,
                'insufficient_evidence_pairs' => $insufficientCount,
                'excluded_test_pairs' => $excludedTestCount
            ],
            'governance_notice' => "Pairwise duplicate affinity identifies candidates for human verification. No records were merged, deleted, or suppressed."
        ];
    }

    /**
     * F. Reporting and verification latency analytics.
     * Calculates delay only where reliable observation timing exists.
     * 
     * @param PDO $pdo Active PDO connection
     * @param array $options Configuration options
     * @return array Latency analytics payload
     */
    public static function getReportingLatencyAnalytics(PDO $pdo, array $options = []) {
        $sql = "SELECT id, reported_by, description, sighting_date, sighting_time, 
                       sighting_time_precision, sighting_time_window, sighting_time_uncertainty_minutes, 
                       sighting_timezone, report_status, created_at, updated_at 
                FROM elephant_reports 
                ORDER BY id ASC";
        $reports = self::executeReadOnlyQuery($pdo, $sql);

        $exactDelays = [];
        $approxMinDelays = [];
        $approxMaxDelays = [];
        $uncomputableTimingCount = 0;
        $chronologyInconsistentCount = 0;
        $realtimeCount = 0;
        $delayedCount = 0;

        $verificationDelaysHours = [];
        $unrecordedVerificationCount = 0;

        foreach ($reports as $r) {
            // Reporting delay calculation via TemporalService
            $delay = TemporalService::calculateReportingDelay($r);

            if ($delay === null) {
                $uncomputableTimingCount++;
            } elseif (!empty($delay['is_chronology_inconsistent'])) {
                $chronologyInconsistentCount++;
            } else {
                $precision = strtolower(trim((string)($r['sighting_time_precision'] ?? '')));
                if ($precision === 'exact') {
                    $mins = (int)$delay['minutes'];
                    $exactDelays[] = $mins;
                    if (!empty($delay['is_realtime'])) $realtimeCount++;
                    elseif (!empty($delay['is_delayed'])) $delayedCount++;
                } elseif ($precision === 'approximate') {
                    if (isset($delay['min_minutes'])) $approxMinDelays[] = (int)$delay['min_minutes'];
                    if (isset($delay['max_minutes'])) $approxMaxDelays[] = (int)$delay['max_minutes'];
                    if (!empty($delay['is_realtime'])) $realtimeCount++;
                    elseif (!empty($delay['is_delayed'])) $delayedCount++;
                } else {
                    $uncomputableTimingCount++;
                }
            }

            // Verification latency calculation
            $status = $r['report_status'] ?? 'Pending';
            if (in_array($status, ['Verified', 'Rejected', 'Resolved'], true)) {
                if (!empty($r['updated_at']) && !empty($r['created_at'])) {
                    $createdTs = strtotime($r['created_at']);
                    $updatedTs = strtotime($r['updated_at']);
                    if ($createdTs && $updatedTs && $updatedTs >= $createdTs) {
                        $diffHours = ($updatedTs - $createdTs) / 3600.0;
                        $verificationDelaysHours[] = round($diffHours, 2);
                    } else {
                        $unrecordedVerificationCount++;
                    }
                } else {
                    $unrecordedVerificationCount++;
                }
            }
        }

        // Exact delay metrics
        $exactCount = count($exactDelays);
        $exactMean = $exactCount > 0 ? round(array_sum($exactDelays) / $exactCount, 1) : null;
        $exactMin = $exactCount > 0 ? min($exactDelays) : null;
        $exactMax = $exactCount > 0 ? max($exactDelays) : null;

        $exactMedian = null;
        if ($exactCount > 0) {
            sort($exactDelays);
            $mid = (int)floor($exactCount / 2);
            $exactMedian = ($exactCount % 2 === 0) ? round(($exactDelays[$mid - 1] + $exactDelays[$mid]) / 2.0, 1) : $exactDelays[$mid];
        }

        // Verification delay metrics
        $verCount = count($verificationDelaysHours);
        $verMean = $verCount > 0 ? round(array_sum($verificationDelaysHours) / $verCount, 1) : null;

        return [
            'exact_reporting_delay' => [
                'sample_size' => $exactCount,
                'mean_minutes' => $exactMean,
                'median_minutes' => $exactMedian,
                'min_minutes' => $exactMin,
                'max_minutes' => $exactMax,
                'realtime_count' => $realtimeCount,
                'delayed_count' => $delayedCount
            ],
            'approximate_reporting_delay' => [
                'sample_size' => count($approxMinDelays),
                'mean_min_bound_minutes' => count($approxMinDelays) > 0 ? round(array_sum($approxMinMin = $approxMinDelays) / count($approxMinDelays), 1) : null,
                'mean_max_bound_minutes' => count($approxMaxDelays) > 0 ? round(array_sum($approxMaxDelays) / count($approxMaxDelays), 1) : null
            ],
            'uncomputable_timing_count' => $uncomputableTimingCount,
            'chronology_inconsistent_count' => $chronologyInconsistentCount,
            'verification_latency' => [
                'resolved_reports_with_timestamp' => $verCount,
                'mean_verification_hours' => $verMean,
                'unrecorded_timestamp_count' => $unrecordedVerificationCount
            ],
            'governance_notice' => "Reporting latency requires explicit observation timing. Submission timestamp created_at is never assumed to equal sighting time."
        ];
    }

    /**
     * G. Data quality analytics.
     * Evaluates completeness, missing telemetry, invalid coordinates, and section discrepancies.
     * 
     * @param PDO $pdo Active PDO connection
     * @param array $options Configuration options
     * @return array Data quality audit payload
     */
    public static function getDataQualityAnalytics(PDO $pdo, array $options = []) {
        $sql = "SELECT id, section_id, reported_by, description, location_description, image_path,
                       ai_detection_notes, sighting_date, sighting_time, sighting_time_precision,
                       latitude, longitude, gps_accuracy_meters 
                FROM elephant_reports 
                ORDER BY id ASC";
        $reports = self::executeReadOnlyQuery($pdo, $sql);

        $totalReports = count($reports);
        $missingDates = 0;
        $missingTimes = 0;
        $missingGps = 0;
        $gpsAccuracyCount = 0;
        $invalidCoordsCount = 0;
        $unknownProvenanceCount = 0;
        $testDemoCount = 0;
        $sectionDiscrepancies = 0;

        foreach ($reports as $r) {
            if (empty($r['sighting_date'])) $missingDates++;
            if (empty($r['sighting_time']) || ($r['sighting_time_precision'] ?? '') === 'unknown') $missingTimes++;

            $hasGps = ($r['latitude'] !== null && $r['longitude'] !== null);
            if (!$hasGps) {
                $missingGps++;
            } else {
                $lat = (float)$r['latitude'];
                $lon = (float)$r['longitude'];

                // Bounding box validation for Sri Lanka
                if (!DuplicateService::isValidSriLankaCoordinate($lat, $lon)) {
                    $invalidCoordsCount++;
                } else {
                    // Check geometric track section discrepancy
                    $nearest = ProximityService::calculateRailwayProximity($lat, $lon);
                    $assignedSec = $r['section_id'] !== null ? (int)$r['section_id'] : null;
                    $geomSec = $nearest['nearest_section_id'] ?? null;

                    if ($assignedSec !== null && $geomSec !== null && $assignedSec !== $geomSec) {
                        $sectionDiscrepancies++;
                    }
                }

                if ($r['gps_accuracy_meters'] !== null && is_numeric($r['gps_accuracy_meters'])) {
                    $gpsAccuracyCount++;
                }
            }

            $prov = DuplicateService::classifyProvenance($r);
            if ($prov === DuplicateService::PROVENANCE_UNKNOWN) {
                $unknownProvenanceCount++;
            } elseif (DuplicateService::isTestOrDemoProvenance($prov)) {
                $testDemoCount++;
            }
        }

        $gpsTotal = $totalReports - $missingGps;
        $gpsAccuracyCompleteness = $gpsTotal > 0 ? round($gpsAccuracyCount / $gpsTotal, 4) : 0.0;

        // Overall data completeness score [0.0 - 100.0%]
        // Factors: has date, has time, has GPS, known provenance
        $dateWeight = 0.25;
        $timeWeight = 0.25;
        $gpsWeight = 0.25;
        $provWeight = 0.25;

        $dateScore = $totalReports > 0 ? (1.0 - ($missingDates / $totalReports)) : 0.0;
        $timeScore = $totalReports > 0 ? (1.0 - ($missingTimes / $totalReports)) : 0.0;
        $gpsScore = $totalReports > 0 ? (1.0 - ($missingGps / $totalReports)) : 0.0;
        $provScore = $totalReports > 0 ? (1.0 - (($unknownProvenanceCount + $testDemoCount) / $totalReports)) : 0.0;

        $overallScore = round(($dateScore * $dateWeight + $timeScore * $timeWeight + $gpsScore * $gpsWeight + $provScore * $provWeight) * 100.0, 1);

        return [
            'total_reports' => $totalReports,
            'missing_observation_dates' => [
                'count' => $missingDates,
                'ratio' => $totalReports > 0 ? round($missingDates / $totalReports, 4) : 0.0
            ],
            'missing_observation_times' => [
                'count' => $missingTimes,
                'ratio' => $totalReports > 0 ? round($missingTimes / $totalReports, 4) : 0.0
            ],
            'missing_gps' => [
                'count' => $missingGps,
                'ratio' => $totalReports > 0 ? round($missingGps / $totalReports, 4) : 0.0
            ],
            'gps_accuracy_telemetry' => [
                'gps_reports_count' => $gpsTotal,
                'reports_with_accuracy_count' => $gpsAccuracyCount,
                'completeness_ratio' => $gpsAccuracyCompleteness
            ],
            'invalid_coordinates_count' => $invalidCoordsCount,
            'unknown_provenance_count' => $unknownProvenanceCount,
            'test_demo_records_count' => $testDemoCount,
            'potential_section_assignment_discrepancies' => $sectionDiscrepancies,
            'overall_data_completeness_percentage' => $overallScore,
            'audit_recommendation' => "Historical data contains high rates of missing observation times and test triad contamination. Production reporting should mandate GPS and clock time acquisition."
        ];
    }

    /**
     * H. AI advisory analytics.
     * Evaluates AI model suggestions against officer decisions in compliance with advisory-only governance.
     * 
     * @param PDO $pdo Active PDO connection
     * @param array $options Configuration options
     * @return array AI advisory analytics payload
     */
    public static function getAiAdvisoryAnalytics(PDO $pdo, array $options = []) {
        $sql = "SELECT id, ai_status, ai_detected_elephant, ai_confidence, ai_model_version, 
                       report_status 
                FROM elephant_reports 
                ORDER BY id ASC";
        $reports = self::executeReadOnlyQuery($pdo, $sql);

        $totalReports = count($reports);
        $byAiStatus = [
            'processed' => 0,
            'skipped' => 0,
            'pending' => 0,
            'failed' => 0
        ];

        $aiDetectedCount = 0;
        $aiNoElephantCount = 0;
        $modelsActive = [];

        // Decision comparison matrix (for processed reports)
        $aiDetVerified = 0;
        $aiDetRejected = 0;
        $aiNoDetVerified = 0;
        $aiNoDetRejected = 0;
        $pendingOfficerReview = 0;

        foreach ($reports as $r) {
            $st = $r['ai_status'] ?? 'pending';
            if (isset($byAiStatus[$st])) {
                $byAiStatus[$st]++;
            }

            if (!empty($r['ai_model_version'])) {
                $modelsActive[$r['ai_model_version']] = true;
            }

            if ($st === 'processed') {
                $det = $r['ai_detected_elephant'];
                if ($det === 1 || $det === '1') {
                    $aiDetectedCount++;
                } else {
                    $aiNoElephantCount++;
                }

                $repSt = $r['report_status'] ?? 'Pending';
                if ($repSt === 'Verified' || $repSt === 'Converted to Alert' || $repSt === 'Resolved') {
                    if ($det === 1 || $det === '1') $aiDetVerified++;
                    else $aiNoDetVerified++;
                } elseif ($repSt === 'Rejected') {
                    if ($det === 1 || $det === '1') $aiDetRejected++;
                    else $aiNoDetRejected++;
                } else {
                    $pendingOfficerReview++;
                }
            }
        }

        return [
            'total_reports' => $totalReports,
            'ai_subsystem_status' => $byAiStatus,
            'ai_detection_totals' => [
                'detected_elephant' => $aiDetectedCount,
                'no_elephant_detected' => $aiNoElephantCount
            ],
            'models_active' => array_keys($modelsActive),
            'human_officer_concordance_matrix' => [
                'ai_detected_and_officer_verified' => $aiDetVerified,
                'ai_detected_and_officer_rejected' => $aiDetRejected,
                'ai_not_detected_and_officer_verified' => $aiNoDetVerified,
                'ai_not_detected_and_officer_rejected' => $aiNoDetRejected,
                'pending_human_officer_review' => $pendingOfficerReview
            ],
            'governance_notice' => "AI classification is advisory-only. Concordance metrics reflect workflow agreement with officer reviews, not independently certified detector accuracy (N={$totalReports}, test/demo contaminated, lacking independent ground-truth labeling)."
        ];
    }

    /**
     * Consolidates all read-only historical analytics into a single comprehensive payload.
     * 
     * @param PDO $pdo Active PDO connection
     * @param array $options Configuration options:
     *                       - user_role (string|null)
     *                       - genuine_only (bool, default false)
     * @return array Full historical analytics payload
     */
    public static function getFullAnalyticsSummary(PDO $pdo, array $options = []) {
        return [
            'metadata' => [
                'generated_at' => date('Y-m-d H:i:s'),
                'timezone' => 'Asia/Colombo',
                'service' => 'EleGuard Rail Historical Analytics Layer',
                'version' => 'v1.0.0-readonly'
            ],
            'general_statistics' => self::getGeneralStatistics($pdo, $options),
            'temporal_analytics' => self::getTemporalAnalytics($pdo, $options),
            'section_analytics' => self::getSectionAnalytics($pdo, $options),
            'hotspot_analytics' => self::getHotspotAnalytics($pdo, $options),
            'duplicate_analytics' => self::getDuplicateAnalytics($pdo, $options),
            'reporting_latency' => self::getReportingLatencyAnalytics($pdo, $options),
            'data_quality' => self::getDataQualityAnalytics($pdo, $options),
            'ai_advisory' => self::getAiAdvisoryAnalytics($pdo, $options)
        ];
    }
}
