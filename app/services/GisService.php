<?php
/**
 * EleGuard Rail - GIS Data & Mapping Service
 * 
 * Provides unified access to railway route geometries, wildlife hotspots,
 * elephant sighting reports, and calculates proximity metrics for geospatial analysis.
 */

require_once __DIR__ . '/../core/Database.php';
require_once __DIR__ . '/ProximityService.php';

class GisService {

    /**
     * Retrieves the authentic railway route geometries as GeoJSON FeatureCollection.
     * 
     * @return array Decoded GeoJSON FeatureCollection
     */
    public static function getRailwayRoutes() {
        return ProximityService::loadRailwayGeometry();
    }

    /**
     * Retrieves known elephant crossing hotspots with optional section and risk filtering.
     * 
     * @param int|null $sectionId Optional railway section filter
     * @param string|null $riskLevel Optional risk level filter ('Low', 'Medium', 'High', 'Critical')
     * @return array List of hotspot records with geographical coordinates
     */
    public static function getHotspots($sectionId = null, $riskLevel = null) {
        $db = Database::getInstance()->getConnection();

        $query = "
            SELECT 
                h.id,
                h.section_id,
                rs.section_name,
                rs.district,
                h.hotspot_name,
                h.latitude,
                h.longitude,
                h.risk_level,
                h.reason,
                h.created_at
            FROM hotspots h
            JOIN railway_sections rs ON h.section_id = rs.id
            WHERE 1=1
        ";
        $params = [];

        if ($sectionId !== null && is_numeric($sectionId)) {
            $query .= " AND h.section_id = :sec_id";
            $params[':sec_id'] = (int)$sectionId;
        }

        if (!empty($riskLevel)) {
            $query .= " AND LOWER(h.risk_level) = LOWER(:risk)";
            $params[':risk'] = trim($riskLevel);
        }

        $query .= " ORDER BY h.risk_level DESC, h.hotspot_name ASC";

        $stmt = $db->prepare($query);
        $stmt->execute($params);
        $hotspots = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Cast numeric types for JSON serialization
        foreach ($hotspots as &$h) {
            $h['id'] = (int)$h['id'];
            $h['section_id'] = (int)$h['section_id'];
            $h['latitude'] = $h['latitude'] !== null ? (float)$h['latitude'] : null;
            $h['longitude'] = $h['longitude'] !== null ? (float)$h['longitude'] : null;
        }
        unset($h);

        return $hotspots;
    }

    /**
     * Retrieves elephant sighting reports with proximity calculations and privacy protection.
     * 
     * Personal identifiable information (reporter email, phone, name) is excluded
     * to protect community reporters and comply with privacy requirements.
     * 
     * @param array $filters Query filters [section_id, risk_level, report_status, date_from, date_to, has_gps_only, limit]
     * @param bool $includeProximity Whether to attach railway track distance calculations
     * @return array Sighting records enriched with proximity metrics
     */
    public static function getElephantReports(array $filters = [], $includeProximity = true) {
        $db = Database::getInstance()->getConnection();

        $query = "
            SELECT 
                r.id,
                r.section_id,
                rs.section_name,
                rs.district,
                r.elephant_count,
                r.location_description,
                r.latitude,
                r.longitude,
                r.gps_accuracy_meters,
                r.observer_latitude,
                r.observer_longitude,
                r.observer_gps_accuracy_meters,
                r.estimated_animal_latitude,
                r.estimated_animal_longitude,
                r.animal_location_uncertainty_meters,
                r.is_estimated_position,
                r.section_assignment_status,
                r.distance_to_track_meters,
                r.track_proximity_zone,
                r.urgency_level,
                r.risk_score,
                r.report_status,
                r.description,
                r.image_path,
                r.provenance,
                r.created_at
            FROM elephant_reports r
            LEFT JOIN railway_sections rs ON r.section_id = rs.id
            WHERE 1=1
        ";
        $params = [];

        // 1. Filter by Section
        if (!empty($filters['section_id']) && is_numeric($filters['section_id'])) {
            $query .= " AND r.section_id = :sec_id";
            $params[':sec_id'] = (int)$filters['section_id'];
        }

        // 2. Filter by Risk Level
        if (!empty($filters['risk_level'])) {
            $query .= " AND LOWER(r.risk_score) = LOWER(:risk)";
            $params[':risk'] = trim($filters['risk_level']);
        }

        // 3. Filter by Status (Pending, Verified, Rejected)
        if (!empty($filters['status'])) {
            $query .= " AND LOWER(r.report_status) = LOWER(:status)";
            $params[':status'] = trim($filters['status']);
        } elseif (!empty($filters['report_status'])) {
            $query .= " AND LOWER(r.report_status) = LOWER(:status)";
            $params[':status'] = trim($filters['report_status']);
        }

        // 4. Filter by Date Range
        if (!empty($filters['date_from'])) {
            $query .= " AND DATE(r.created_at) >= :date_from";
            $params[':date_from'] = trim($filters['date_from']);
        }
        if (!empty($filters['date_to'])) {
            $query .= " AND DATE(r.created_at) <= :date_to";
            $params[':date_to'] = trim($filters['date_to']);
        }

        // 5. GPS Only (default true for GIS maps unless explicitly false)
        $hasGpsOnly = !isset($filters['has_gps_only']) || (bool)$filters['has_gps_only'];
        if ($hasGpsOnly) {
            $query .= " AND r.latitude IS NOT NULL AND r.longitude IS NOT NULL";
        }

        // Ordering: latest sightings first
        $query .= " ORDER BY r.created_at DESC";

        // Limit
        $limit = isset($filters['limit']) && is_numeric($filters['limit']) 
            ? max(1, min(500, (int)$filters['limit'])) 
            : 100;
        $query .= " LIMIT :limit";

        $stmt = $db->prepare($query);
        foreach ($params as $paramKey => $paramVal) {
            $stmt->bindValue($paramKey, $paramVal);
        }
        $stmt->bindValue(':limit', (int)$limit, PDO::PARAM_INT);
        $stmt->execute();
        $reports = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Process records and compute proximity
        $processed = [];
        foreach ($reports as $report) {
            $reportId = (int)$report['id'];
            $sectionId = (int)$report['section_id'];
            $lat = $report['latitude'] !== null ? (float)$report['latitude'] : null;
            $lon = $report['longitude'] !== null ? (float)$report['longitude'] : null;
            $acc = $report['gps_accuracy_meters'] !== null ? (float)$report['gps_accuracy_meters'] : null;

            $item = [
                'id' => $reportId,
                'section_id' => $sectionId,
                'section_name' => $report['section_name'],
                'district' => $report['district'],
                'elephant_count' => (int)$report['elephant_count'],
                'location_description' => $report['location_description'],
                'latitude' => $lat,
                'longitude' => $lon,
                'gps_accuracy_meters' => $acc,
                'observer_latitude' => $report['observer_latitude'] !== null ? (float)$report['observer_latitude'] : null,
                'observer_longitude' => $report['observer_longitude'] !== null ? (float)$report['observer_longitude'] : null,
                'observer_gps_accuracy_meters' => $report['observer_gps_accuracy_meters'] !== null ? (float)$report['observer_gps_accuracy_meters'] : null,
                'estimated_animal_latitude' => $report['estimated_animal_latitude'] !== null ? (float)$report['estimated_animal_latitude'] : null,
                'estimated_animal_longitude' => $report['estimated_animal_longitude'] !== null ? (float)$report['estimated_animal_longitude'] : null,
                'animal_location_uncertainty_meters' => $report['animal_location_uncertainty_meters'] !== null ? (float)$report['animal_location_uncertainty_meters'] : null,
                'is_estimated_position' => isset($report['is_estimated_position']) ? (int)$report['is_estimated_position'] : 0,
                'section_assignment_status' => $report['section_assignment_status'] ?? 'provisional_estimated',
                'distance_to_track_meters' => $report['distance_to_track_meters'] !== null ? (float)$report['distance_to_track_meters'] : null,
                'track_proximity_zone' => $report['track_proximity_zone'] ?? null,
                'urgency_level' => $report['urgency_level'],
                'risk_level' => $report['risk_score'],
                'report_status' => $report['report_status'],
                'description' => $report['description'],
                'has_image' => !empty($report['image_path']),
                'provenance' => $report['provenance'] ?? 'unknown',
                'created_at' => $report['created_at'],
                'proximity' => null
            ];

            if ($includeProximity) {
                if ($lat !== null && $lon !== null) {
                    $proximity = ProximityService::calculateRailwayProximity($lat, $lon, $acc);
                    $item['proximity'] = [
                        'report_id' => $reportId,
                        'nearest_section_id' => $proximity['nearest_section_id'],
                        'nearest_section_name' => $proximity['nearest_section_name'],
                        'nearest_district' => $proximity['nearest_district'],
                        'distance_meters' => $proximity['distance_meters'],
                        'closest_track_point' => $proximity['closest_track_point'],
                        'gps_accuracy_meters' => $proximity['gps_accuracy_meters'],
                        'effective_uncertainty_range' => $proximity['effective_uncertainty_range'],
                        'uncertainty_range_text' => $proximity['uncertainty_range_text'],
                        'existing_risk_level' => $report['risk_score'],
                        'verification_status' => $report['report_status']
                    ];
                } else {
                    $item['proximity'] = [
                        'report_id' => $reportId,
                        'has_coordinates' => false,
                        'nearest_section_id' => null,
                        'nearest_section_name' => null,
                        'distance_meters' => null,
                        'closest_track_point' => null,
                        'gps_accuracy_meters' => null,
                        'effective_uncertainty_range' => null,
                        'uncertainty_range_text' => 'No GPS coordinates available',
                        'existing_risk_level' => $report['risk_score'],
                        'verification_status' => $report['report_status']
                    ];
                }
            }

            $processed[] = $item;
        }

        return $processed;
    }

    /**
     * Converts a list of hotspot records to a GeoJSON FeatureCollection.
     * 
     * @param array $hotspots
     * @return array GeoJSON FeatureCollection
     */
    public static function hotspotsToGeoJson(array $hotspots) {
        $features = [];
        foreach ($hotspots as $h) {
            if ($h['latitude'] === null || $h['longitude'] === null) {
                continue;
            }
            $features[] = [
                'type' => 'Feature',
                'id' => 'hotspot_' . $h['id'],
                'geometry' => [
                    'type' => 'Point',
                    'coordinates' => [(float)$h['longitude'], (float)$h['latitude']]
                ],
                'properties' => [
                    'id' => (int)$h['id'],
                    'type' => 'hotspot',
                    'name' => $h['hotspot_name'],
                    'hotspot_name' => $h['hotspot_name'],
                    'section_id' => (int)$h['section_id'],
                    'section_name' => $h['section_name'],
                    'district' => $h['district'],
                    'risk_level' => $h['risk_level'],
                    'reason' => $h['reason'],
                    'created_at' => $h['created_at']
                ]
            ];
        }

        return [
            'type' => 'FeatureCollection',
            'features' => $features
        ];
    }

    /**
     * Converts a list of sighting reports to a GeoJSON FeatureCollection with optional coordinate privacy masking.
     * 
     * @param array $reports
     * @param bool $hasPreciseAccess Whether authenticated role is authorized for full-precision coordinates
     * @return array GeoJSON FeatureCollection
     */
    public static function reportsToGeoJson(array $reports, $hasPreciseAccess = true) {
        $features = [];
        foreach ($reports as $r) {
            if ($r['latitude'] === null || $r['longitude'] === null) {
                continue;
            }

            // Apply server-side coordinate masking if user role lacks precise access
            if ($hasPreciseAccess) {
                $lat = (float)$r['latitude'];
                $lon = (float)$r['longitude'];
                $obsLat = $r['observer_latitude'] !== null ? (float)$r['observer_latitude'] : null;
                $obsLon = $r['observer_longitude'] !== null ? (float)$r['observer_longitude'] : null;
                $distMeters = $r['distance_to_track_meters'] ?? ($r['proximity']['distance_meters'] ?? null);
                $proxData = $r['proximity'];
                $obsAcc = $r['observer_gps_accuracy_meters'] ?? null;
                $animUncert = $r['animal_location_uncertainty_meters'] ?? null;
                $gpsAcc = $r['gps_accuracy_meters'] ?? null;
            } else {
                // Approximate 2-decimal masking (~1.1 km resolution)
                $lat = round((float)$r['latitude'], 2);
                $lon = round((float)$r['longitude'], 2);
                $obsLat = $r['observer_latitude'] !== null ? round((float)$r['observer_latitude'], 2) : null;
                $obsLon = $r['observer_longitude'] !== null ? round((float)$r['observer_longitude'], 2) : null;

                // Mask primary distance to nearest 100 meters
                $rawDist = $r['distance_to_track_meters'] ?? ($r['proximity']['distance_meters'] ?? null);
                $distMeters = $rawDist !== null ? round($rawDist / 100) * 100 : null;

                // Strip sub-meter telemetry and suppress precise uncertainty fields
                $obsAcc = null;
                $animUncert = null;
                $gpsAcc = null;

                $proxData = $r['proximity'];
                if (is_array($proxData)) {
                    $proxData['distance_meters'] = $distMeters;
                    $proxData['closest_track_point'] = null; // Suppress exact track point coordinates
                    $proxData['gps_accuracy_meters'] = null; // Suppress GPS accuracy to prevent range reconstruction

                    // Mask effective uncertainty range: construct coarse 100m interval around fuzzed distance
                    // preventing arithmetic reconstruction of exact distance: (min + max) / 2 = distMeters
                    if (isset($proxData['effective_uncertainty_range']) && is_array($proxData['effective_uncertainty_range'])) {
                        $proxData['effective_uncertainty_range'] = [
                            'min_distance_meters' => $distMeters !== null ? max(0, $distMeters - 100) : null,
                            'max_distance_meters' => $distMeters !== null ? ($distMeters + 100) : null,
                            'accuracy_meters' => null
                        ];
                    }

                    // Replace precise textual distance ranges with generalized approximate text
                    if (isset($proxData['uncertainty_range_text'])) {
                        if ($distMeters !== null) {
                            $approxKm = round($distMeters / 1000, 1);
                            $proxData['uncertainty_range_text'] = "Approx. {$approxKm} km from corridor (coarse estimate)";
                        } else {
                            $proxData['uncertainty_range_text'] = "Approximate distance unavailable";
                        }
                    }
                }
            }

            $obsCoords = ($obsLat !== null && $obsLon !== null) ? [$obsLon, $obsLat] : null;

            $features[] = [
                'type' => 'Feature',
                'id' => 'report_' . $r['id'],
                'geometry' => [
                    'type' => 'Point',
                    'coordinates' => [$lon, $lat]
                ],
                'properties' => [
                    'id' => (int)$r['id'],
                    'type' => 'sighting_report',
                    'section_id' => (int)$r['section_id'],
                    'section_name' => $r['section_name'],
                    'district' => $r['district'],
                    'elephant_count' => (int)$r['elephant_count'],
                    'location_description' => $r['location_description'],
                    'urgency_level' => $r['urgency_level'],
                    'risk_level' => $r['risk_level'],
                    'report_status' => $r['report_status'],
                    'verification_status' => $r['report_status'],
                    'section_assignment_status' => $r['section_assignment_status'] ?? 'provisional_estimated',
                    'is_estimated_position' => (int)($r['is_estimated_position'] ?? 0),
                    'observer_coordinates' => $obsCoords,
                    'observer_accuracy_meters' => $obsAcc,
                    'animal_uncertainty_meters' => $animUncert,
                    'description' => $r['description'],
                    'has_image' => (bool)$r['has_image'],
                    'gps_accuracy_meters' => $gpsAcc,
                    'distance_to_track_meters' => $distMeters,
                    'track_proximity_zone' => $r['track_proximity_zone'] ?? null,
                    'proximity' => $proxData,
                    'is_masked' => !$hasPreciseAccess,
                    'coordinate_privacy' => $hasPreciseAccess ? 'full_precision_operational' : 'approximate_masked_2_decimals',
                    'provenance' => $r['provenance'] ?? 'unknown',
                    'is_synthetic' => (isset($r['provenance']) && ($r['provenance'] === 'development_test' || strpos($r['provenance'], 'test') !== false || strpos($r['provenance'], 'demo') !== false)) ? true : false,
                    'created_at' => $r['created_at']
                ]
            ];
        }

        return [
            'type' => 'FeatureCollection',
            'features' => $features
        ];
    }

    /**
     * Retrieves active railway safety alerts formatted as GeoJSON.
     * 
     * @param int|null $sectionId Optional railway section filter
     * @return array GeoJSON FeatureCollection
     */
    public static function getActiveAlertsGeoJson($sectionId = null) {
        $db = Database::getInstance()->getConnection();
        $query = "
            SELECT 
                a.id,
                a.report_id,
                a.section_id,
                rs.section_name,
                rs.district,
                a.alert_level,
                a.alert_message,
                a.alert_status,
                a.created_at
            FROM alerts a
            LEFT JOIN railway_sections rs ON a.section_id = rs.id
            WHERE a.alert_status = 'Active'
        ";
        $params = [];
        if ($sectionId !== null && is_numeric($sectionId)) {
            $query .= " AND a.section_id = :sec_id";
            $params[':sec_id'] = (int)$sectionId;
        }
        $query .= " ORDER BY a.created_at DESC";
        $stmt = $db->prepare($query);
        $stmt->execute($params);
        $alerts = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $features = [];
        foreach ($alerts as $a) {
            // Heuristic caution speed recommendation based on alert level (system advisory only)
            $speedKmh = null;
            $lvl = strtolower($a['alert_level'] ?? '');
            if ($lvl === 'critical') $speedKmh = 20;
            elseif ($lvl === 'high') $speedKmh = 30;
            elseif ($lvl === 'medium') $speedKmh = 45;
            elseif ($lvl === 'low') $speedKmh = 60;

            $features[] = [
                'type' => 'Feature',
                'id' => 'alert_' . $a['id'],
                'geometry' => null,
                'properties' => [
                    'id' => (int)$a['id'],
                    'report_id' => $a['report_id'] !== null ? (int)$a['report_id'] : null,
                    'type' => 'active_alert',
                    'section_id' => (int)$a['section_id'],
                    'section_name' => $a['section_name'],
                    'district' => $a['district'],
                    'alert_level' => $a['alert_level'],
                    'suggested_caution_speed_kmh' => $speedKmh,
                    'speed_restriction_kmh' => $speedKmh, // Preserved for API backward-compatibility
                    'speed_recommendation_label' => $speedKmh ? "System Advisory: {$speedKmh} km/h Caution" : null,
                    'is_advisory_only' => true,
                    'advisory_disclaimer' => 'ADVISORY RECOMMENDATION ONLY — NOT AN OFFICIAL TRAIN ORDER',
                    'message' => $a['alert_message'],
                    'alert_status' => $a['alert_status'],
                    'status' => $a['alert_status'],
                    'created_at' => $a['created_at']
                ]
            ];
        }

        return [
            'type' => 'FeatureCollection',
            'features' => $features
        ];
    }

    /**
     * Builds the complete consolidated GIS payload for map visualization and analysis.
     * 
     * @param array $filters Query filters
     * @param bool $hasPreciseAccess Whether caller has operational precise coordinates access
     * @return array Consolidated GIS response
     */
    public static function getGisPayload(array $filters = [], $hasPreciseAccess = true) {
        $routes = self::getRailwayRoutes();
        $hotspots = self::getHotspots($filters['section_id'] ?? null, $filters['risk_level'] ?? null);
        $reports = self::getElephantReports($filters, true);
        $alerts = self::getActiveAlertsGeoJson($filters['section_id'] ?? null);

        // Compute summary counts
        $totalReports = count($reports);
        $verifiedCount = 0;
        $pendingCount = 0;
        $criticalCount = 0;

        foreach ($reports as $r) {
            if (strcasecmp($r['report_status'], 'Verified') === 0) $verifiedCount++;
            if (strcasecmp($r['report_status'], 'Pending') === 0) $pendingCount++;
            if (strcasecmp($r['risk_level'], 'Critical') === 0) $criticalCount++;
        }

        return [
            'status' => 'success',
            'generated_at' => date('Y-m-d\TH:i:sP'),
            'summary' => [
                'total_routes' => count($routes['features'] ?? []),
                'total_track_length_km' => $routes['metadata']['total_network_length_km'] ?? 138.29,
                'total_hotspots' => count($hotspots),
                'total_sighting_reports' => $totalReports,
                'total_active_alerts' => count($alerts['features']),
                'verified_reports' => $verifiedCount,
                'pending_reports' => $pendingCount,
                'critical_reports' => $criticalCount
            ],
            'filters_applied' => array_filter($filters, function($v) { return $v !== null && $v !== ''; }),
            'routes' => $routes,
            'hotspots' => self::hotspotsToGeoJson($hotspots),
            'reports' => self::reportsToGeoJson($reports, $hasPreciseAccess),
            'alerts' => $alerts
        ];
    }
}
