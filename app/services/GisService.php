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
                r.urgency_level,
                r.risk_score,
                r.report_status,
                r.description,
                r.image_path,
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
        $query .= " LIMIT " . $limit;

        $stmt = $db->prepare($query);
        $stmt->execute($params);
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
                'urgency_level' => $report['urgency_level'],
                'risk_level' => $report['risk_score'],
                'report_status' => $report['report_status'],
                'description' => $report['description'],
                'has_image' => !empty($report['image_path']),
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
     * Converts a list of sighting reports to a GeoJSON FeatureCollection.
     * 
     * @param array $reports
     * @return array GeoJSON FeatureCollection
     */
    public static function reportsToGeoJson(array $reports) {
        $features = [];
        foreach ($reports as $r) {
            if ($r['latitude'] === null || $r['longitude'] === null) {
                continue;
            }
            $features[] = [
                'type' => 'Feature',
                'id' => 'report_' . $r['id'],
                'geometry' => [
                    'type' => 'Point',
                    'coordinates' => [(float)$r['longitude'], (float)$r['latitude']]
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
                    'description' => $r['description'],
                    'has_image' => (bool)$r['has_image'],
                    'gps_accuracy_meters' => $r['gps_accuracy_meters'],
                    'proximity' => $r['proximity'],
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
     * Builds the complete consolidated GIS payload for map visualization and analysis.
     * 
     * @param array $filters Query filters
     * @return array Consolidated GIS response
     */
    public static function getGisPayload(array $filters = []) {
        $routes = self::getRailwayRoutes();
        $hotspots = self::getHotspots($filters['section_id'] ?? null, $filters['risk_level'] ?? null);
        $reports = self::getElephantReports($filters, true);

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
                'verified_reports' => $verifiedCount,
                'pending_reports' => $pendingCount,
                'critical_reports' => $criticalCount
            ],
            'filters_applied' => array_filter($filters, function($v) { return $v !== null && $v !== ''; }),
            'routes' => $routes,
            'hotspots' => self::hotspotsToGeoJson($hotspots),
            'reports' => self::reportsToGeoJson($reports)
        ];
    }
}
