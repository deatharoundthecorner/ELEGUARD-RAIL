<?php
/**
 * Non-Destructive Spatial-Temporal Duplicate Detection Service
 * 
 * EleGuard Rail — Milestone 9C Stage 4B
 * 
 * Identifies potentially overlapping wildlife sighting reports and provides
 * explainable recommendations for authorized human reviewers (Wildlife Officers).
 * 
 * STRICT ARCHITECTURAL CONSTRAINTS:
 * 1. Never modifies historical observation timestamps.
 * 2. Never substitutes submission timestamps (created_at) for missing observation timestamps.
 * 3. Never modifies stored operational risk scores (elephant_reports.risk_score).
 * 4. Never automatically verifies, rejects, merges, or deletes reports.
 * 5. Never automatically generates or broadcasts alerts based on duplicate candidates.
 * 6. Preserves human-in-the-loop review authority for all duplicate confirmations.
 * 7. Protects sensitive wildlife coordinates via role-based coordinate masking.
 * 8. Distinguishes test/demo provenance from genuine field evidence.
 * 9. Employs non-transitive clustering to prevent false multi-hop grouping.
 */

require_once __DIR__ . '/../core/Database.php';
require_once __DIR__ . '/../services/TemporalService.php';
require_once __DIR__ . '/../services/ProximityService.php';

class DuplicateService {

    // Default Investigation Thresholds
    const DEFAULT_SPATIAL_THRESHOLD_METERS = 500.0;
    const STRONG_SPATIAL_THRESHOLD_METERS = 250.0;
    const DEFAULT_TEMPORAL_THRESHOLD_MINUTES = 60;
    const STRONG_TEMPORAL_THRESHOLD_MINUTES = 30;

    // Clustering Bounds (Cluster Diameter Limits)
    const DEFAULT_MAX_CLUSTER_DIAMETER_METERS = 750.0;
    const DEFAULT_MAX_CLUSTER_SPAN_MINUTES = 90;

    // GPS Telemetry Thresholds
    const POOR_GPS_ACCURACY_THRESHOLD_METERS = 100.0;

    // Timezone
    const DEFAULT_TIMEZONE = 'Asia/Colombo';

    // Candidate Classifications
    const CLASSIFICATION_STRONG = 'Strong candidate';
    const CLASSIFICATION_POSSIBLE = 'Possible candidate';
    const CLASSIFICATION_SPATIAL_ONLY = 'Spatial similarity only';
    const CLASSIFICATION_INSUFFICIENT = 'Insufficient evidence';
    const CLASSIFICATION_EXCLUDED_TEST = 'Excluded test/demo comparison';

    // Provenance Identifiers
    const PROVENANCE_GENUINE_BASELINE = 'genuine_baseline';
    const PROVENANCE_GENUINE_FIELD = 'genuine_field';
    const PROVENANCE_DEV_TEST = 'development_test';
    const PROVENANCE_AI_TEST = 'ai_test';
    const PROVENANCE_E2E_AUDIT = 'e2e_audit';
    const PROVENANCE_UNKNOWN = 'unknown';

    // Authorized roles permitted to view raw unmasked coordinates
    const AUTHORIZED_ROLES = ['admin', 'wildlife_officer', 'control_officer'];

    /**
     * Calculates the great-circle Haversine distance between two coordinates in metres.
     * Enforces strict bounding box validation for Sri Lanka (lat 5.8 to 10.0, lon 79.5 to 82.2).
     * 
     * @param float|null $lat1 Latitude of point 1 in decimal degrees
     * @param float|null $lon1 Longitude of point 1 in decimal degrees
     * @param float|null $lat2 Latitude of point 2 in decimal degrees
     * @param float|null $lon2 Longitude of point 2 in decimal degrees
     * @return float|null Distance in metres, or null if coordinates are missing or invalid
     */
    public static function calculateGeodesicDistance($lat1, $lon1, $lat2, $lon2) {
        if ($lat1 === null || $lon1 === null || $lat2 === null || $lon2 === null) {
            return null;
        }

        if (!is_numeric($lat1) || !is_numeric($lon1) || !is_numeric($lat2) || !is_numeric($lon2)) {
            return null;
        }

        $lat1 = (float)$lat1;
        $lon1 = (float)$lon1;
        $lat2 = (float)$lat2;
        $lon2 = (float)$lon2;

        // Strict Sri Lanka geographical bounding box check
        if (!self::isValidSriLankaCoordinate($lat1, $lon1) || !self::isValidSriLankaCoordinate($lat2, $lon2)) {
            return null;
        }

        // ProximityService::haversineDistance utilizes Earth radius 6,371,000m
        $distance = ProximityService::haversineDistance($lat1, $lon1, $lat2, $lon2);
        return round($distance, 2);
    }

    /**
     * Checks if coordinates fall within Sri Lanka's geographical bounding box.
     * 
     * @param float $lat
     * @param float $lon
     * @return bool
     */
    public static function isValidSriLankaCoordinate($lat, $lon) {
        return ($lat >= ProximityService::SRI_LANKA_MIN_LAT && $lat <= ProximityService::SRI_LANKA_MAX_LAT &&
                $lon >= ProximityService::SRI_LANKA_MIN_LON && $lon <= ProximityService::SRI_LANKA_MAX_LON);
    }

    /**
     * Calculates effective distance interval taking GPS horizontal accuracy into account.
     * 
     * @param float|null $nominalDistance Geodesic distance in metres
     * @param float|null $accuracyA GPS accuracy of report A in metres
     * @param float|null $accuracyB GPS accuracy of report B in metres
     * @return array|null
     */
    public static function calculateEffectiveDistanceRange($nominalDistance, $accuracyA, $accuracyB) {
        if ($nominalDistance === null) {
            return null;
        }

        $accA = ($accuracyA !== null && is_numeric($accuracyA)) ? max(0.0, (float)$accuracyA) : 0.0;
        $accB = ($accuracyB !== null && is_numeric($accuracyB)) ? max(0.0, (float)$accuracyB) : 0.0;
        $totalBuffer = $accA + $accB;

        return [
            'nominal_distance_meters' => $nominalDistance,
            'min_distance_meters' => round(max(0.0, $nominalDistance - $totalBuffer), 2),
            'max_distance_meters' => round($nominalDistance + $totalBuffer, 2),
            'total_buffer_meters' => round($totalBuffer, 2),
            'accuracy_a' => ($accuracyA !== null && is_numeric($accuracyA)) ? (float)$accuracyA : null,
            'accuracy_b' => ($accuracyB !== null && is_numeric($accuracyB)) ? (float)$accuracyB : null,
            'is_poor_accuracy' => ($accA > self::POOR_GPS_ACCURACY_THRESHOLD_METERS || $accB > self::POOR_GPS_ACCURACY_THRESHOLD_METERS)
        ];
    }

    /**
     * Extracts validated observation interval for a report.
     * Strictly avoids substituting submission timestamp (created_at) for missing observation time.
     * 
     * @param array $report
     * @return array
     */
    public static function extractObservationInterval(array $report) {
        $date = !empty($report['sighting_date']) ? trim($report['sighting_date']) : null;
        $time = !empty($report['sighting_time']) ? trim($report['sighting_time']) : null;
        $precision = !empty($report['sighting_time_precision']) 
            ? strtolower(trim((string)$report['sighting_time_precision'])) 
            : TemporalService::PRECISION_UNKNOWN;
        $window = !empty($report['sighting_time_window']) ? strtolower(trim((string)$report['sighting_time_window'])) : null;
        $tzName = !empty($report['sighting_timezone']) ? trim($report['sighting_timezone']) : self::DEFAULT_TIMEZONE;

        // If date or time is missing, or precision is unknown, temporal interval cannot be established
        if (empty($date) || empty($time) || $precision === TemporalService::PRECISION_UNKNOWN) {
            return [
                'has_interval' => false,
                'precision' => $precision,
                'window' => $window,
                'date' => $date,
                'time' => $time,
                'start_timestamp' => null,
                'end_timestamp' => null,
                'start_datetime' => null,
                'end_datetime' => null,
                'uncertainty_minutes' => null,
                'reason' => 'Observation time unknown or missing (submission timestamp created_at is strictly excluded)'
            ];
        }

        try {
            $tz = new DateTimeZone($tzName);
            $refDt = new DateTimeImmutable("$date $time", $tz);
        } catch (Exception $e) {
            return [
                'has_interval' => false,
                'precision' => $precision,
                'window' => $window,
                'date' => $date,
                'time' => $time,
                'start_timestamp' => null,
                'end_timestamp' => null,
                'start_datetime' => null,
                'end_datetime' => null,
                'uncertainty_minutes' => null,
                'reason' => 'Invalid date/time format or unknown timezone: ' . $e->getMessage()
            ];
        }

        // Determine uncertainty minutes based on precision and presets
        if ($precision === TemporalService::PRECISION_APPROXIMATE) {
            if (isset($report['sighting_time_uncertainty_minutes']) && is_numeric($report['sighting_time_uncertainty_minutes'])) {
                $uncertainty = (int)$report['sighting_time_uncertainty_minutes'];
            } elseif (!empty($window) && isset(TemporalService::PRESETS[$window])) {
                $uncertainty = (int)TemporalService::PRESETS[$window]['uncertainty'];
            } else {
                $uncertainty = 60; // Fallback default approximate uncertainty
            }
        } else {
            // Exact observation time: reference uncertainty is 0 (or exact timestamp)
            $uncertainty = 0;
        }

        $startDt = $refDt->modify("-{$uncertainty} minutes");
        $endDt = $refDt->modify("+{$uncertainty} minutes");

        return [
            'has_interval' => true,
            'precision' => $precision,
            'window' => $window,
            'date' => $date,
            'time' => $time,
            'reference_datetime' => $refDt->format('Y-m-d H:i:s'),
            'start_timestamp' => $startDt->getTimestamp(),
            'end_timestamp' => $endDt->getTimestamp(),
            'start_datetime' => $startDt->format('Y-m-d H:i:s'),
            'end_datetime' => $endDt->format('Y-m-d H:i:s'),
            'uncertainty_minutes' => $uncertainty,
            'reason' => null
        ];
    }

    /**
     * Compares the observation intervals of two reports.
     * Evaluates exact timestamps, uncertainty intervals, gaps, and midnight crossings.
     * 
     * @param array $reportA
     * @param array $reportB
     * @return array
     */
    public static function compareObservationIntervals(array $reportA, array $reportB) {
        $intA = self::extractObservationInterval($reportA);
        $intB = self::extractObservationInterval($reportB);

        if (!$intA['has_interval'] || !$intB['has_interval']) {
            return [
                'comparable' => false,
                'overlap' => false,
                'delta_minutes' => null,
                'status' => 'unknown_observation_time',
                'interval_a' => $intA,
                'interval_b' => $intB,
                'description' => 'Observation time unknown or missing in one or both reports. Temporal overlap cannot be established.'
            ];
        }

        $startA = $intA['start_timestamp'];
        $endA = $intA['end_timestamp'];
        $startB = $intB['start_timestamp'];
        $endB = $intB['end_timestamp'];

        // Overlap condition: max(startA, startB) <= min(endA, endB)
        $isOverlapping = (max($startA, $startB) <= min($endA, $endB));

        if ($isOverlapping) {
            return [
                'comparable' => true,
                'overlap' => true,
                'delta_minutes' => 0,
                'status' => 'overlapping',
                'interval_a' => $intA,
                'interval_b' => $intB,
                'description' => "Observation intervals overlap between {$intA['start_datetime']}--{$intA['end_datetime']} and {$intB['start_datetime']}--{$intB['end_datetime']}."
            ];
        }

        // When not overlapping, calculate the shortest boundary gap in minutes
        $gapSeconds = max(0, max($startA, $startB) - min($endA, $endB));
        $deltaMinutes = (int)round($gapSeconds / 60);

        $status = ($deltaMinutes <= self::DEFAULT_TEMPORAL_THRESHOLD_MINUTES) ? 'within_threshold' : 'exceeds_threshold';

        return [
            'comparable' => true,
            'overlap' => false,
            'delta_minutes' => $deltaMinutes,
            'status' => $status,
            'interval_a' => $intA,
            'interval_b' => $intB,
            'description' => "Observation intervals do not overlap. Boundary separation: {$deltaMinutes} minutes."
        ];
    }

    /**
     * Classifies report provenance based on affirmative signatures and operational metadata.
     * STRICT CONSTRAINTS (Milestone 9C Stage 4B.1):
     * 1. Never relies on hardcoded ID ranges (such as 4-40, 41-43, 53-56) as a permanent mechanism.
     * 2. Future genuine reports must never be excluded because of their IDs.
     * 3. Unknown reports are never automatically classified as genuine.
     * 4. Unknown reports are never automatically classified as test records.
     * 5. Never invents provenance; defaults strictly to 'unknown' when evidence is insufficient.
     * 
     * @param array $report Sighting report telemetry
     * @return string One of PROVENANCE_* constants
     */
    public static function classifyProvenance(array $report) {
        // 1. Explicit provenance field (if caller, database, or test harness explicitly supplies it)
        if (!empty($report['provenance'])) {
            $prov = strtolower(trim((string)$report['provenance']));
            if (in_array($prov, [
                self::PROVENANCE_GENUINE_BASELINE,
                self::PROVENANCE_GENUINE_FIELD,
                self::PROVENANCE_DEV_TEST,
                self::PROVENANCE_AI_TEST,
                self::PROVENANCE_E2E_AUDIT
            ], true)) {
                return $prov;
            }
        }

        $desc = !empty($report['description']) ? strtolower((string)$report['description']) : '';
        $loc = !empty($report['location_description']) ? strtolower((string)$report['location_description']) : '';
        $aiNotes = !empty($report['ai_detection_notes']) ? strtolower((string)$report['ai_detection_notes']) : '';
        $img = !empty($report['image_path']) ? strtolower((string)$report['image_path']) : '';

        // 2. E2E Audit Signatures (highest priority for audit scripts)
        if (strpos($loc, 'e2e-audit') !== false ||
            strpos($desc, 'e2e audit') !== false ||
            strpos($desc, 'governance test') !== false ||
            strpos($desc, 'governance audit') !== false) {
            return self::PROVENANCE_E2E_AUDIT;
        }

        // 3. Automated Development Test Signatures (automated triads, mock test descriptions)
        if (strpos($desc, 'testing text-only submission') !== false ||
            strpos($desc, 'testing gps-enabled submission') !== false ||
            strpos($desc, 'testing manual coordinate entry') !== false ||
            strpos($desc, 'automated test') !== false ||
            strpos($desc, 'test triad') !== false ||
            strpos($desc, 'test sighting') !== false ||
            strpos($desc, 'minneriya track observation') !== false ||
            strpos($desc, 'gal oya wildlife sighting') !== false ||
            strpos($loc, 'test location') !== false ||
            strpos($desc, 'dummy') !== false ||
            strpos($desc, 'mock') !== false ||
            strpos($desc, 'sample test') !== false) {
            return self::PROVENANCE_DEV_TEST;
        }

        // 4. Seeded AI Model Test Signatures
        if (strpos($img, 'report_habarana_herd.jpg') !== false ||
            strpos($img, 'report_ambewela_track.jpg') !== false ||
            strpos($img, 'report_minneriya_dusk.jpg') !== false ||
            strpos($img, 'ai_elephant_') !== false ||
            strpos($img, 'qa_test_elephant') !== false ||
            strpos($desc, 'ai model test') !== false ||
            strpos($aiNotes, 'seeded ai test') !== false) {
            return self::PROVENANCE_AI_TEST;
        }

        // 5. Genuine Baseline Records (historical commissioning records)
        // Identified by historical baseline text signatures
        if (strpos($desc, 'two adult elephants spotted drinking water') !== false ||
            strpos($desc, 'herd of 5 elephants including a calf') !== false ||
            strpos($desc, 'large bull elephant standing directly on the track') !== false) {
            return self::PROVENANCE_GENUINE_BASELINE;
        }

        // 6. Genuine Field Records:
        // Must have affirmative operational evidence:
        // non-empty description, valid coordinates or location, and a valid reported_by user,
        // WITHOUT any test signatures
        if (!empty($report['reported_by']) && is_numeric($report['reported_by']) &&
            (!empty($report['location_description']) || !empty($report['latitude'])) &&
            !empty($report['description'])) {
            return self::PROVENANCE_GENUINE_FIELD;
        }

        // 7. Unknown Provenance: default when metadata is insufficient or unverified
        return self::PROVENANCE_UNKNOWN;
    }

    /**
     * Checks if provenance represents a non-operational test or demo record.
     * 
     * @param string $provenance
     * @return bool
     */
    public static function isTestOrDemoProvenance($provenance) {
        return in_array($provenance, [
            self::PROVENANCE_DEV_TEST,
            self::PROVENANCE_AI_TEST,
            self::PROVENANCE_E2E_AUDIT
        ], true);
    }

    /**
     * Masks coordinates to protect sensitive elephant location data against poachers.
     * Authorized roles (admin, wildlife_officer, control_officer) receive exact coordinates.
     * Unauthorized roles (reporter, driver, unauthenticated/public) receive fuzzed coordinates (~1.1 km resolution).
     * 
     * @param float|null $latitude
     * @param float|null $longitude
     * @param string|null $userRole
     * @return array
     */
    public static function maskCoordinates($latitude, $longitude, $userRole = null) {
        $lat = ($latitude !== null && is_numeric($latitude)) ? (float)$latitude : null;
        $lon = ($longitude !== null && is_numeric($longitude)) ? (float)$longitude : null;

        $isAuthorized = ($userRole !== null && in_array(strtolower(trim($userRole)), self::AUTHORIZED_ROLES, true));

        if ($isAuthorized) {
            return [
                'latitude' => $lat,
                'longitude' => $lon,
                'is_masked' => false,
                'masking_notice' => null
            ];
        }

        return [
            'latitude' => $lat !== null ? round($lat, 2) : null,
            'longitude' => $lon !== null ? round($lon, 2) : null,
            'is_masked' => true,
            'masking_notice' => 'Precise coordinates masked for wildlife protection (anti-poaching policy).'
        ];
    }

    /**
     * Sanitizes a report record according to the requesting user's authorization role.
     * Unauthorized roles (driver, reporter, public, unauthenticated) have their GPS coordinates
     * fuzzed to 2 decimal places (~1.1 km blur) to protect elephants from poachers.
     * 
     * @param array $report Report record
     * @param string|null $userRole User role
     * @return array Sanitized report record
     */
    public static function sanitizeReportForRole(array $report, $userRole = null) {
        $isAuthorized = ($userRole !== null && in_array(strtolower(trim($userRole)), self::AUTHORIZED_ROLES, true));
        $sanitized = $report;

        if (!$isAuthorized) {
            $masked = self::maskCoordinates($report['latitude'] ?? null, $report['longitude'] ?? null, $userRole);
            $sanitized['latitude'] = $masked['latitude'];
            $sanitized['longitude'] = $masked['longitude'];
            $sanitized['is_coordinates_masked'] = true;
            $sanitized['coordinate_masking_notice'] = $masked['masking_notice'];
        } else {
            $sanitized['is_coordinates_masked'] = false;
            $sanitized['coordinate_masking_notice'] = null;
        }

        return $sanitized;
    }

    /**
     * Sanitizes a list of report records according to the requesting user's authorization role.
     * 
     * @param array $reports List of report records
     * @param string|null $userRole User role
     * @return array List of sanitized report records
     */
    public static function sanitizeReportsForRole(array $reports, $userRole = null) {
        $out = [];
        foreach ($reports as $r) {
            $out[] = self::sanitizeReportForRole($r, $userRole);
        }
        return $out;
    }

    /**
     * Evaluates whether two reports represent a potential duplicate sighting candidate.
     * Produces a fully explainable recommendation for authorized human reviewers.
     * 
     * @param array $reportA Sighting report A
     * @param array $reportB Sighting report B
     * @param array $options Configurable thresholds:
     *                       - spatial_threshold_meters (default 500.0)
     *                       - temporal_threshold_minutes (default 60)
     *                       - user_role (default null)
     * @return array Explainable candidate comparison payload
     */
    public static function evaluateDuplicateCandidate(array $reportA, array $reportB, array $options = []) {
        $spatialThresh = isset($options['spatial_threshold_meters']) ? (float)$options['spatial_threshold_meters'] : self::DEFAULT_SPATIAL_THRESHOLD_METERS;
        $temporalThresh = isset($options['temporal_threshold_minutes']) ? (int)$options['temporal_threshold_minutes'] : self::DEFAULT_TEMPORAL_THRESHOLD_MINUTES;
        $userRole = isset($options['user_role']) ? $options['user_role'] : null;

        $idA = isset($reportA['id']) ? (int)$reportA['id'] : null;
        $idB = isset($reportB['id']) ? (int)$reportB['id'] : null;

        $matchingEvidence = [];
        $missingDataWarnings = [];
        $provenanceWarnings = [];

        // 1. Provenance Assessment
        $provA = self::classifyProvenance($reportA);
        $provB = self::classifyProvenance($reportB);
        $isTestA = self::isTestOrDemoProvenance($provA);
        $isTestB = self::isTestOrDemoProvenance($provB);
        $isTestComparison = ($isTestA || $isTestB);

        if ($isTestComparison) {
            $provenanceWarnings[] = "One or both reports are identified as non-operational test/demo artifacts (Report A: {$provA}, Report B: {$provB}).";
        } elseif ($provA === self::PROVENANCE_UNKNOWN || $provB === self::PROVENANCE_UNKNOWN) {
            $provenanceWarnings[] = "One or both reports have unverified provenance (unknown); observer credentials should be reviewed during verification.";
        }

        // 2. Geographic & GPS Comparison
        $latA = isset($reportA['latitude']) ? $reportA['latitude'] : null;
        $lonA = isset($reportA['longitude']) ? $reportA['longitude'] : null;
        $latB = isset($reportB['latitude']) ? $reportB['latitude'] : null;
        $lonB = isset($reportB['longitude']) ? $reportB['longitude'] : null;
        $accA = isset($reportA['gps_accuracy_meters']) ? $reportA['gps_accuracy_meters'] : null;
        $accB = isset($reportB['gps_accuracy_meters']) ? $reportB['gps_accuracy_meters'] : null;

        $distance = self::calculateGeodesicDistance($latA, $lonA, $latB, $lonB);
        $effectiveRange = self::calculateEffectiveDistanceRange($distance, $accA, $accB);

        if ($latA === null || $lonA === null || $latB === null || $lonB === null) {
            $missingDataWarnings[] = "Geographic coordinates missing in one or both reports. Geodesic distance cannot be calculated.";
        } elseif ($distance === null) {
            $missingDataWarnings[] = "Invalid coordinates detected: one or both coordinates lie outside Sri Lanka's geographical bounding box.";
        } else {
            if ($distance <= self::STRONG_SPATIAL_THRESHOLD_METERS) {
                $matchingEvidence[] = "High spatial proximity: {$distance}m (within strong threshold of " . self::STRONG_SPATIAL_THRESHOLD_METERS . "m).";
            } elseif ($distance <= $spatialThresh) {
                $matchingEvidence[] = "Spatial proximity: {$distance}m (within investigation threshold of {$spatialThresh}m).";
            } else {
                $missingDataWarnings[] = "Geographic distance ({$distance}m) exceeds investigation threshold of {$spatialThresh}m.";
            }

            if ($effectiveRange && $effectiveRange['is_poor_accuracy']) {
                $missingDataWarnings[] = "Low GPS precision detected (effective distance range: {$effectiveRange['min_distance_meters']}m – {$effectiveRange['max_distance_meters']}m).";
            }
        }

        // 3. Observation-Time Interval Comparison
        $temporalComparison = self::compareObservationIntervals($reportA, $reportB);
        if (!$temporalComparison['comparable']) {
            $missingDataWarnings[] = $temporalComparison['description'];
        } else {
            if ($temporalComparison['overlap']) {
                $matchingEvidence[] = "Observation intervals overlap: " . $temporalComparison['description'];
            } elseif ($temporalComparison['delta_minutes'] <= self::STRONG_TEMPORAL_THRESHOLD_MINUTES) {
                $matchingEvidence[] = "High temporal proximity: {$temporalComparison['delta_minutes']} minutes boundary separation (within strong threshold of " . self::STRONG_TEMPORAL_THRESHOLD_MINUTES . "m).";
            } elseif ($temporalComparison['delta_minutes'] <= $temporalThresh) {
                $matchingEvidence[] = "Temporal proximity: {$temporalComparison['delta_minutes']} minutes boundary separation (within threshold of {$temporalThresh}m).";
            } else {
                $missingDataWarnings[] = "Temporal separation ({$temporalComparison['delta_minutes']} min) exceeds investigation threshold of {$temporalThresh} min.";
            }
        }

        // 4. Railway Section Compatibility
        $secA = isset($reportA['section_id']) ? (int)$reportA['section_id'] : null;
        $secB = isset($reportB['section_id']) ? (int)$reportB['section_id'] : null;
        $sectionMatch = ($secA !== null && $secB !== null && $secA === $secB);
        $sectionWarning = null;

        if ($secA === null || $secB === null) {
            $missingDataWarnings[] = "Railway section unassigned in one or both reports.";
        } elseif ($sectionMatch) {
            $matchingEvidence[] = "Assigned railway section matches: Section #{$secA}.";
        } else {
            $sectionWarning = "Railway section mismatch: Report A assigned to Section #{$secA}, Report B assigned to Section #{$secB}.";
            $missingDataWarnings[] = $sectionWarning . " Boundary adjacency may account for discrepancies.";
        }

        // 5. Elephant Count Comparison
        $countA = isset($reportA['elephant_count']) ? (int)$reportA['elephant_count'] : 0;
        $countB = isset($reportB['elephant_count']) ? (int)$reportB['elephant_count'] : 0;
        $countDiff = abs($countA - $countB);
        $countCompatible = ($countDiff <= 2);

        if ($countDiff === 0) {
            $matchingEvidence[] = "Identical reported herd size: {$countA} elephants.";
        } elseif ($countCompatible) {
            $matchingEvidence[] = "Compatible reported herd size: {$countA} vs {$countB} elephants (difference of {$countDiff}).";
        } else {
            $missingDataWarnings[] = "Elephant count discrepancy: {$countA} vs {$countB} elephants (difference of {$countDiff}). Herds may have split or observation angles varied.";
        }

        // 6. Classification Determination
        $classification = self::determineClassification(
            $isTestComparison,
            $distance,
            $spatialThresh,
            $temporalComparison,
            $temporalThresh,
            $sectionMatch,
            $countCompatible
        );

        // 7. Human Review Recommendation
        $recommendation = self::generateHumanReviewRecommendation(
            $classification,
            $distance,
            $temporalComparison,
            $sectionMatch,
            $countDiff
        );

        // 8. Mask Coordinates for display based on role
        $maskedCoordsA = self::maskCoordinates($latA, $lonA, $userRole);
        $maskedCoordsB = self::maskCoordinates($latB, $lonB, $userRole);

        return [
            'report_ids' => [$idA, $idB],
            'candidate_classification' => $classification,
            'geographic_distance_meters' => $distance,
            'effective_distance_range' => $effectiveRange,
            'observation_interval_comparison' => $temporalComparison,
            'gps_accuracy_information' => [
                'accuracy_a' => $effectiveRange ? $effectiveRange['accuracy_a'] : null,
                'accuracy_b' => $effectiveRange ? $effectiveRange['accuracy_b'] : null,
                'is_poor_accuracy' => $effectiveRange ? $effectiveRange['is_poor_accuracy'] : false
            ],
            'section_compatibility' => [
                'match' => $sectionMatch,
                'section_a' => $secA,
                'section_b' => $secB,
                'warning' => $sectionWarning
            ],
            'count_comparison' => [
                'count_a' => $countA,
                'count_b' => $countB,
                'difference' => $countDiff,
                'compatible' => $countCompatible
            ],
            'provenance' => [
                'report_a' => $provA,
                'report_b' => $provB,
                'is_test_comparison' => $isTestComparison
            ],
            'coordinates_display' => [
                'report_a' => $maskedCoordsA,
                'report_b' => $maskedCoordsB
            ],
            'matching_evidence' => $matchingEvidence,
            'missing_data_warnings' => $missingDataWarnings,
            'provenance_warnings' => $provenanceWarnings,
            'human_review_recommendation' => $recommendation
        ];
    }

    /**
     * Determines the explainable candidate classification.
     */
    private static function determineClassification(
        $isTestComparison,
        $distance,
        $spatialThresh,
        $temporalComparison,
        $temporalThresh,
        $sectionMatch,
        $countCompatible
    ) {
        // Rule 1: Test or demo records must never form genuine duplicate candidates
        if ($isTestComparison) {
            return self::CLASSIFICATION_EXCLUDED_TEST;
        }

        // Rule 2: Missing or invalid spatial coordinates
        if ($distance === null) {
            return self::CLASSIFICATION_INSUFFICIENT;
        }

        // Rule 3: Geographic distance strictly exceeds investigation threshold
        if ($distance > $spatialThresh) {
            return self::CLASSIFICATION_INSUFFICIENT;
        }

        // Rule 4: Proximity is detected, but observation time is completely missing or unknown
        if (!$temporalComparison['comparable']) {
            return self::CLASSIFICATION_SPATIAL_ONLY;
        }

        // Rule 5: Temporal difference strictly exceeds temporal threshold
        if (!$temporalComparison['overlap'] && $temporalComparison['delta_minutes'] > $temporalThresh) {
            return self::CLASSIFICATION_INSUFFICIENT;
        }

        // Rule 6: Strong candidate criteria
        // Distance <= 250m, overlapping or <= 30 min gap, matching section, compatible herd count
        $isStrongSpatial = ($distance <= self::STRONG_SPATIAL_THRESHOLD_METERS);
        $isStrongTemporal = ($temporalComparison['overlap'] || $temporalComparison['delta_minutes'] <= self::STRONG_TEMPORAL_THRESHOLD_MINUTES);

        if ($isStrongSpatial && $isStrongTemporal && $sectionMatch && $countCompatible) {
            return self::CLASSIFICATION_STRONG;
        }

        // Rule 7: Possible candidate
        return self::CLASSIFICATION_POSSIBLE;
    }

    /**
     * Generates actionable guidance for authorized Wildlife Officers.
     */
    private static function generateHumanReviewRecommendation(
        $classification,
        $distance,
        $temporalComparison,
        $sectionMatch,
        $countDiff
    ) {
        switch ($classification) {
            case self::CLASSIFICATION_STRONG:
                return "High probability of duplicate reporting. Recommend Wildlife Officer inspect photograph evidence and observer notes to consider linking in review interface.";

            case self::CLASSIFICATION_POSSIBLE:
                $reasons = [];
                if ($distance > self::STRONG_SPATIAL_THRESHOLD_METERS) {
                    $reasons[] = "spatial separation ({$distance}m)";
                }
                if (!$sectionMatch) {
                    $reasons[] = "differing railway sections";
                }
                if ($countDiff > 2) {
                    $reasons[] = "herd count variance ({$countDiff} elephants)";
                }
                $reasonStr = !empty($reasons) ? " due to " . implode(', ', $reasons) : "";
                return "Potential duplicate candidate with telemetry variance{$reasonStr}. Human verification recommended before taking administrative action.";

            case self::CLASSIFICATION_SPATIAL_ONLY:
                return "Sighting locations are proximate ({$distance}m), but observation timestamps are unavailable. Do not merge or confirm without field observation verification.";

            case self::CLASSIFICATION_EXCLUDED_TEST:
                return "Automated test or seeded demo record excluded from operational wildlife duplicate analysis. No human review required.";

            case self::CLASSIFICATION_INSUFFICIENT:
            default:
                return "Telemetry does not meet spatial or temporal correlation thresholds. Independent sighting reports should be preserved.";
        }
    }

    /**
     * Scans an array of reports for potential duplicate candidates for a specific target report.
     * 
     * @param array|int $targetReport Report array or Report ID
     * @param array $allReports Candidate pool of reports
     * @param array $options Configurable thresholds
     * @return array List of candidate comparison payloads, sorted by relevance
     */
    public static function findDuplicatesForReport($targetReport, array $allReports, array $options = []) {
        $target = null;
        if (is_numeric($targetReport)) {
            $targetId = (int)$targetReport;
            foreach ($allReports as $rep) {
                if (isset($rep['id']) && (int)$rep['id'] === $targetId) {
                    $target = $rep;
                    break;
                }
            }
        } elseif (is_array($targetReport)) {
            $target = $targetReport;
        }

        if (!$target) {
            return [];
        }

        $candidates = [];
        $targetId = isset($target['id']) ? (int)$target['id'] : null;

        foreach ($allReports as $rep) {
            $repId = isset($rep['id']) ? (int)$rep['id'] : null;
            if ($targetId !== null && $repId !== null && $targetId === $repId) {
                continue; // Do not compare report with itself
            }

            $evaluation = self::evaluateDuplicateCandidate($target, $rep, $options);
            $cls = $evaluation['candidate_classification'];

            // Keep strong candidates, possible candidates, and spatial similarity
            if (in_array($cls, [self::CLASSIFICATION_STRONG, self::CLASSIFICATION_POSSIBLE, self::CLASSIFICATION_SPATIAL_ONLY], true)) {
                $candidates[] = $evaluation;
            }
        }

        // Sort candidates by priority: Strong > Possible > Spatial Only
        usort($candidates, function($a, $b) {
            $order = [
                self::CLASSIFICATION_STRONG => 1,
                self::CLASSIFICATION_POSSIBLE => 2,
                self::CLASSIFICATION_SPATIAL_ONLY => 3
            ];
            $rankA = $order[$a['candidate_classification']] ?? 99;
            $rankB = $order[$b['candidate_classification']] ?? 99;

            if ($rankA !== $rankB) {
                return $rankA <=> $rankB;
            }

            // Secondary sort: closest distance first
            $distA = $a['geographic_distance_meters'] ?? 999999;
            $distB = $b['geographic_distance_meters'] ?? 999999;
            return $distA <=> $distB;
        });

        return $candidates;
    }

    /**
     * Groups multiple sighting reports into non-destructive clusters.
     * Enforces strict non-transitive isolation:
     * A-B similarity and B-C similarity does NOT automatically establish A-C duplication.
     * Reports in a proposed cluster must satisfy mutual spatial diameter and temporal span limits.
     * 
     * @param array $reports Array of sighting reports
     * @param array $options Configurable thresholds:
     *                       - max_cluster_diameter_meters (default 750.0)
     *                       - max_cluster_span_minutes (default 90)
     * @return array Structured clustering results
     */
    public static function clusterReports(array $reports, array $options = []) {
        $maxDiameter = isset($options['max_cluster_diameter_meters']) ? (float)$options['max_cluster_diameter_meters'] : self::DEFAULT_MAX_CLUSTER_DIAMETER_METERS;
        $maxSpan = isset($options['max_cluster_span_minutes']) ? (int)$options['max_cluster_span_minutes'] : self::DEFAULT_MAX_CLUSTER_SPAN_MINUTES;

        $n = count($reports);
        if ($n === 0) {
            return [
                'clusters' => [],
                'unclustered_reports' => [],
                'total_reports' => 0,
                'clustered_count' => 0,
                'transitive_splits' => []
            ];
        }

        // 1. Identify pairwise candidates (Strong or Possible)
        $pairs = [];
        for ($i = 0; $i < $n; $i++) {
            for ($j = $i + 1; $j < $n; $j++) {
                $eval = self::evaluateDuplicateCandidate($reports[$i], $reports[$j], $options);
                $cls = $eval['candidate_classification'];
                if ($cls === self::CLASSIFICATION_STRONG || $cls === self::CLASSIFICATION_POSSIBLE) {
                    $pairs[] = [
                        'index_a' => $i,
                        'index_b' => $j,
                        'eval' => $eval
                    ];
                }
            }
        }

        // 2. Sort pairs by similarity: Strong candidates first, then shortest distance
        usort($pairs, function($a, $b) {
            $clsA = $a['eval']['candidate_classification'];
            $clsB = $b['eval']['candidate_classification'];
            if ($clsA !== $clsB) {
                return ($clsA === self::CLASSIFICATION_STRONG) ? -1 : 1;
            }
            $distA = $a['eval']['geographic_distance_meters'] ?? 999999;
            $distB = $b['eval']['geographic_distance_meters'] ?? 999999;
            return $distA <=> $distB;
        });

        // 3. Cluster formation with non-transitive diameter and span protection
        $clusters = []; // List of clusters: ['indices' => [...], 'report_ids' => [...], 'evaluations' => [...]]
        $assignedCluster = array_fill(0, $n, null);
        $transitiveSplits = [];
        $nextClusterId = 1;

        foreach ($pairs as $p) {
            $i = $p['index_a'];
            $j = $p['index_b'];
            $cI = $assignedCluster[$i];
            $cJ = $assignedCluster[$j];

            // Case 1: Neither report is clustered yet -> Create new cluster if within diameter & span
            if ($cI === null && $cJ === null) {
                if (self::validateClusterDiameterAndSpan([$i, $j], $reports, $maxDiameter, $maxSpan)) {
                    $clusterIdx = count($clusters);
                    $clusters[$clusterIdx] = [
                        'cluster_id' => $nextClusterId++,
                        'indices' => [$i, $j],
                        'report_ids' => [
                            isset($reports[$i]['id']) ? $reports[$i]['id'] : "index_$i",
                            isset($reports[$j]['id']) ? $reports[$j]['id'] : "index_$j"
                        ],
                        'candidate_classification' => $p['eval']['candidate_classification'],
                        'evaluations' => [$p['eval']]
                    ];
                    $assignedCluster[$i] = $clusterIdx;
                    $assignedCluster[$j] = $clusterIdx;
                } else {
                    $transitiveSplits[] = [
                        'candidate_a' => $i,
                        'candidate_b' => $j,
                        'reason' => "Pair rejected from cluster formation: exceeds maximum diameter ({$maxDiameter}m) or temporal span ({$maxSpan}m) limit."
                    ];
                }
                continue;
            }

            // Case 2: One report is clustered, the other is unassigned
            if ($cI !== null && $cJ === null) {
                if (self::canJoinCluster($j, $clusters[$cI]['indices'], $reports, $maxDiameter, $maxSpan, $options)) {
                    $clusters[$cI]['indices'][] = $j;
                    $clusters[$cI]['report_ids'][] = isset($reports[$j]['id']) ? $reports[$j]['id'] : "index_$j";
                    $clusters[$cI]['evaluations'][] = $p['eval'];
                    $assignedCluster[$j] = $cI;
                } else {
                    $transitiveSplits[] = [
                        'candidate_index' => $j,
                        'cluster_id' => $clusters[$cI]['cluster_id'],
                        'reason' => "Report cannot join cluster #{$clusters[$cI]['cluster_id']}: violates cluster diameter ({$maxDiameter}m) or time span ({$maxSpan}m) limit."
                    ];
                }
                continue;
            }

            if ($cJ !== null && $cI === null) {
                if (self::canJoinCluster($i, $clusters[$cJ]['indices'], $reports, $maxDiameter, $maxSpan, $options)) {
                    $clusters[$cJ]['indices'][] = $i;
                    $clusters[$cJ]['report_ids'][] = isset($reports[$i]['id']) ? $reports[$i]['id'] : "index_$i";
                    $clusters[$cJ]['evaluations'][] = $p['eval'];
                    $assignedCluster[$i] = $cJ;
                } else {
                    $transitiveSplits[] = [
                        'candidate_index' => $i,
                        'cluster_id' => $clusters[$cJ]['cluster_id'],
                        'reason' => "Report cannot join cluster #{$clusters[$cJ]['cluster_id']}: violates cluster diameter ({$maxDiameter}m) or time span ({$maxSpan}m) limit."
                    ];
                }
                continue;
            }

            // Case 3: Both reports are in different clusters -> Merge only if diameter and span limits hold for ALL members
            if ($cI !== null && $cJ !== null && $cI !== $cJ) {
                $mergedIndices = array_merge($clusters[$cI]['indices'], $clusters[$cJ]['indices']);
                if (self::validateClusterDiameterAndSpan($mergedIndices, $reports, $maxDiameter, $maxSpan)) {
                    // Merge cluster cJ into cluster cI
                    foreach ($clusters[$cJ]['indices'] as $memberIdx) {
                        $clusters[$cI]['indices'][] = $memberIdx;
                        $clusters[$cI]['report_ids'][] = isset($reports[$memberIdx]['id']) ? $reports[$memberIdx]['id'] : "index_$memberIdx";
                        $assignedCluster[$memberIdx] = $cI;
                    }
                    $clusters[$cI]['evaluations'] = array_merge($clusters[$cI]['evaluations'], $clusters[$cJ]['evaluations'], [$p['eval']]);
                    $clusters[$cJ] = null; // Mark old cluster as vacated
                } else {
                    $transitiveSplits[] = [
                        'cluster_a_id' => $clusters[$cI]['cluster_id'],
                        'cluster_b_id' => $clusters[$cJ]['cluster_id'],
                        'reason' => "Transitive merger rejected: combining Cluster #{$clusters[$cI]['cluster_id']} and Cluster #{$clusters[$cJ]['cluster_id']} would exceed maximum diameter or temporal span."
                    ];
                }
            }
        }

        $userRole = isset($options['user_role']) ? $options['user_role'] : null;

        // 4. Assemble final cluster summaries
        $finalClusters = [];
        $clusteredCount = 0;
        foreach ($clusters as $c) {
            if ($c === null) continue;
            $memberIndices = $c['indices'];
            $memberCount = count($memberIndices);
            $clusteredCount += $memberCount;

            // Compute actual diameter and span
            $metrics = self::computeClusterMetrics($memberIndices, $reports);

            $memberReports = [];
            foreach ($memberIndices as $idx) {
                $memberReports[] = self::sanitizeReportForRole($reports[$idx], $userRole);
            }

            $finalClusters[] = [
                'cluster_id' => $c['cluster_id'],
                'report_ids' => $c['report_ids'],
                'member_count' => $memberCount,
                'candidate_classification' => $c['candidate_classification'],
                'max_distance_meters' => $metrics['max_distance_meters'],
                'time_span_minutes' => $metrics['time_span_minutes'],
                'rationale' => "Non-destructive candidate cluster of {$memberCount} reports. Maximum internal separation: {$metrics['max_distance_meters']}m, temporal span: {$metrics['time_span_minutes']} min.",
                'reports' => $memberReports
            ];
        }

        // Assemble unclustered reports
        $unclustered = [];
        for ($i = 0; $i < $n; $i++) {
            if ($assignedCluster[$i] === null) {
                $unclustered[] = self::sanitizeReportForRole($reports[$i], $userRole);
            }
        }

        return [
            'clusters' => $finalClusters,
            'unclustered_reports' => $unclustered,
            'total_reports' => $n,
            'clustered_count' => $clusteredCount,
            'transitive_splits' => $transitiveSplits
        ];
    }

    /**
     * Checks if a candidate report can join an existing cluster without exceeding diameter or temporal span.
     */
    private static function canJoinCluster($candidateIdx, array $clusterIndices, array $reports, $maxDiameter, $maxSpan, array $options) {
        $candidate = $reports[$candidateIdx];
        $candInt = self::extractObservationInterval($candidate);

        // A report with unknown observation time CANNOT join a temporal cluster
        if (!$candInt['has_interval']) {
            return false;
        }

        $allStarts = [$candInt['start_timestamp']];
        $allEnds = [$candInt['end_timestamp']];

        foreach ($clusterIndices as $cIdx) {
            $existing = $reports[$cIdx];
            $existInt = self::extractObservationInterval($existing);

            if (!$existInt['has_interval']) {
                return false;
            }

            // 1. Distance check
            $dist = self::calculateGeodesicDistance(
                $candidate['latitude'] ?? null, $candidate['longitude'] ?? null,
                $existing['latitude'] ?? null, $existing['longitude'] ?? null
            );
            if ($dist === null || $dist > $maxDiameter) {
                return false;
            }

            $allStarts[] = $existInt['start_timestamp'];
            $allEnds[] = $existInt['end_timestamp'];
        }

        // 2. Overall cluster temporal span check: (max(allEnds) - min(allStarts))
        $totalClusterSpanSec = max($allEnds) - min($allStarts);
        $totalClusterSpanMin = (int)round($totalClusterSpanSec / 60);
        if ($totalClusterSpanMin > $maxSpan) {
            return false;
        }

        return true;
    }

    /**
     * Validates that all members of a candidate merged cluster satisfy diameter and temporal limits.
     */
    private static function validateClusterDiameterAndSpan(array $indices, array $reports, $maxDiameter, $maxSpan) {
        $k = count($indices);
        $allStarts = [];
        $allEnds = [];

        for ($a = 0; $a < $k; $a++) {
            $intA = self::extractObservationInterval($reports[$indices[$a]]);
            if (!$intA['has_interval']) {
                return false;
            }
            $allStarts[] = $intA['start_timestamp'];
            $allEnds[] = $intA['end_timestamp'];

            for ($b = $a + 1; $b < $k; $b++) {
                $repA = $reports[$indices[$a]];
                $repB = $reports[$indices[$b]];

                $dist = self::calculateGeodesicDistance(
                    $repA['latitude'] ?? null, $repA['longitude'] ?? null,
                    $repB['latitude'] ?? null, $repB['longitude'] ?? null
                );
                if ($dist === null || $dist > $maxDiameter) {
                    return false;
                }
            }
        }

        $totalClusterSpanSec = max($allEnds) - min($allStarts);
        $totalClusterSpanMin = (int)round($totalClusterSpanSec / 60);
        if ($totalClusterSpanMin > $maxSpan) {
            return false;
        }

        return true;
    }

    /**
     * Computes the maximum pairwise distance and temporal span among cluster members.
     */
    private static function computeClusterMetrics(array $indices, array $reports) {
        $maxDist = 0.0;
        $k = count($indices);
        $allStarts = [];
        $allEnds = [];

        for ($a = 0; $a < $k; $a++) {
            $repA = $reports[$indices[$a]];
            $intA = self::extractObservationInterval($repA);
            if ($intA['has_interval']) {
                $allStarts[] = $intA['start_timestamp'];
                $allEnds[] = $intA['end_timestamp'];
            }

            for ($b = $a + 1; $b < $k; $b++) {
                $repB = $reports[$indices[$b]];
                $d = self::calculateGeodesicDistance(
                    $repA['latitude'] ?? null, $repA['longitude'] ?? null,
                    $repB['latitude'] ?? null, $repB['longitude'] ?? null
                );
                if ($d !== null && $d > $maxDist) {
                    $maxDist = $d;
                }
            }
        }

        $maxSpanMin = (!empty($allStarts) && !empty($allEnds))
            ? (int)round((max($allEnds) - min($allStarts)) / 60)
            : 0;

        return [
            'max_distance_meters' => round($maxDist, 2),
            'time_span_minutes' => $maxSpanMin
        ];
    }

    /**
     * Non-destructively assigns duplicate clusters in the database.
     * STRICT SAFETY REQUIREMENT:
     * Fails immediately if executed against the operational database 'eleguard_rail'.
     * Only permitted against isolated test databases (e.g. eleguard_rail_test).
     * 
     * @param PDO $pdo Active PDO connection
     * @param array $options Clustering options
     * @return array Summary of updated records
     * @throws RuntimeException If called against the operational database
     */
    public static function assignClustersInDatabase(PDO $pdo, array $options = []) {
        // Enforce strict operational safety check
        $dbNameStmt = $pdo->query("SELECT DATABASE()");
        $currentDb = $dbNameStmt ? $dbNameStmt->fetchColumn() : '';

        if ($currentDb === 'eleguard_rail') {
            throw new RuntimeException("Operational Safety Violation: Writing duplicate clusters to the operational database 'eleguard_rail' is strictly prohibited in Milestone 9C Stage 4B. Run only against isolated test databases.");
        }

        // Fetch reports eligible for clustering from the test database
        $stmt = $pdo->query("SELECT * FROM elephant_reports ORDER BY id ASC");
        $reports = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $clusteringResult = self::clusterReports($reports, $options);
        $updatedCount = 0;

        $updateStmt = $pdo->prepare("UPDATE elephant_reports SET is_potential_duplicate = :dup, duplicate_cluster_id = :cluster_id WHERE id = :id");

        foreach ($clusteringResult['clusters'] as $c) {
            $cid = $c['cluster_id'];
            foreach ($c['report_ids'] as $repId) {
                if (is_numeric($repId)) {
                    $updateStmt->execute([
                        ':dup' => 1,
                        ':cluster_id' => $cid,
                        ':id' => (int)$repId
                    ]);
                    $updatedCount++;
                }
            }
        }

        return [
            'database' => $currentDb,
            'total_reports' => count($reports),
            'clusters_formed' => count($clusteringResult['clusters']),
            'records_updated' => $updatedCount,
            'transitive_splits' => count($clusteringResult['transitive_splits'])
        ];
    }
}
