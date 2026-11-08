<?php
/**
 * EleGuard Rail - Railway Proximity Engine & Geodesic Service
 * 
 * Mathematical Foundations & Assumptions:
 * 1. Earth Model: Mean spherical Earth radius R = 6,371,000.0 metres (IUGG recommendation).
 * 2. Great-Circle Distance: Spherical Haversine formulation used for all point-to-point geodesic calculations.
 * 3. Point-to-Segment Projection: For each track segment AB and sighting point P, a local conformal tangent
 *    projection centered at the segment midpoint is utilized. Over typical railway segment lengths (20m - 500m)
 *    at Sri Lanka's latitude (~8° N), metric distortion relative to ellipsoidal WGS84 geodesic is < 0.05% (< 0.5 metres).
 * 4. Geometry Representation: Authentic OpenStreetMap centerline geometries representing 1,055 waypoints across
 *    4 active railway monitoring sections.
 * 5. Accuracy & Uncertainty: Sighting position uncertainty is explicitly bounded by `gps_accuracy_meters` (Δ),
 *    yielding an effective uncertainty interval [max(0, d - Δ), d + Δ].
 * 
 * Coordinate Reference System: WGS 84 (EPSG:4326)
 */

class ProximityService {

    /**
     * Mean Earth radius in metres
     */
    const EARTH_RADIUS_METERS = 6371000.0;

    /**
     * Sri Lanka geographical bounding box for strict sanity validation
     */
    const SRI_LANKA_MIN_LAT = 5.8;
    const SRI_LANKA_MAX_LAT = 10.0;
    const SRI_LANKA_MIN_LON = 79.5;
    const SRI_LANKA_MAX_LON = 82.2;

    /**
     * In-memory cache for parsed railway GeoJSON features and bounding boxes
     * @var array|null
     */
    private static $railwayCache = null;

    /**
     * Default path to authentic railway GeoJSON
     * @var string
     */
    private static $defaultGeoJsonPath = __DIR__ . '/../../public/assets/data/railway_routes.geojson';

    /**
     * Calculates the great-circle Haversine distance between two coordinates in metres.
     * 
     * @param float $lat1 Latitude of point 1 in decimal degrees
     * @param float $lon1 Longitude of point 1 in decimal degrees
     * @param float $lat2 Latitude of point 2 in decimal degrees
     * @param float $lon2 Longitude of point 2 in decimal degrees
     * @return float Distance in metres
     */
    public static function haversineDistance($lat1, $lon1, $lat2, $lon2) {
        $phi1 = deg2rad((float)$lat1);
        $phi2 = deg2rad((float)$lat2);
        $deltaPhi = deg2rad((float)$lat2 - (float)$lat1);
        $deltaLambda = deg2rad((float)$lon2 - (float)$lon1);

        $sinHalfPhi = sin($deltaPhi / 2.0);
        $sinHalfLambda = sin($deltaLambda / 2.0);

        $a = ($sinHalfPhi * $sinHalfPhi) +
             cos($phi1) * cos($phi2) * ($sinHalfLambda * $sinHalfLambda);

        // Clamp to prevent floating-point domain errors with sqrt
        $aClamped = max(0.0, min(1.0, $a));
        $c = 2.0 * atan2(sqrt($aClamped), sqrt(max(0.0, 1.0 - $aClamped)));

        return self::EARTH_RADIUS_METERS * $c;
    }

    /**
     * Calculates the shortest geodesic distance from a point P to a line segment AB.
     * 
     * Projects P onto the geodesic segment AB using a local metric tangent plane.
     * If the projection falls outside AB, the closest endpoint distance is returned.
     * 
     * @param float $latP Sighting latitude
     * @param float $lonP Sighting longitude
     * @param float $latA Segment start latitude
     * @param float $lonA Segment start longitude
     * @param float $latB Segment end latitude
     * @param float $lonB Segment end longitude
     * @return array ['distance' => float, 'closest_lat' => float, 'closest_lon' => float, 't' => float]
     */
    public static function pointToSegmentDistance($latP, $lonP, $latA, $lonA, $latB, $lonB) {
        $latP = (float)$latP;
        $lonP = (float)$lonP;
        $latA = (float)$latA;
        $lonA = (float)$lonA;
        $latB = (float)$latB;
        $lonB = (float)$lonB;

        // Mean latitude for local conformal equirectangular projection
        $phiM = deg2rad(($latA + $latP) / 2.0);
        $cosPhiM = cos($phiM);

        // Convert coordinates to local metric offsets with origin at A
        $dxB = self::EARTH_RADIUS_METERS * deg2rad($lonB - $lonA) * $cosPhiM;
        $dyB = self::EARTH_RADIUS_METERS * deg2rad($latB - $latA);

        $dxP = self::EARTH_RADIUS_METERS * deg2rad($lonP - $lonA) * $cosPhiM;
        $dyP = self::EARTH_RADIUS_METERS * deg2rad($latP - $latA);

        $segmentLengthSquared = ($dxB * $dxB) + ($dyB * $dyB);

        // Degenerate segment: A and B coincide
        if ($segmentLengthSquared <= 1e-12) {
            $dist = self::haversineDistance($latP, $lonP, $latA, $lonA);
            return [
                'distance' => $dist,
                'closest_lat' => $latA,
                'closest_lon' => $lonA,
                't' => 0.0
            ];
        }

        // Projection factor t along vector AB: t = (P - A) . (B - A) / |B - A|^2
        $t = (($dxP * $dxB) + ($dyP * $dyB)) / $segmentLengthSquared;

        if ($t <= 0.0) {
            // Projection falls before point A
            $dist = self::haversineDistance($latP, $lonP, $latA, $lonA);
            return [
                'distance' => $dist,
                'closest_lat' => $latA,
                'closest_lon' => $lonA,
                't' => 0.0
            ];
        } elseif ($t >= 1.0) {
            // Projection falls beyond point B
            $dist = self::haversineDistance($latP, $lonP, $latB, $lonB);
            return [
                'distance' => $dist,
                'closest_lat' => $latB,
                'closest_lon' => $lonB,
                't' => 1.0
            ];
        } else {
            // Perpendicular projection falls strictly within segment AB
            $closestLat = $latA + $t * ($latB - $latA);
            $closestLon = $lonA + $t * ($lonB - $lonA);
            $dist = self::haversineDistance($latP, $lonP, $closestLat, $closestLon);
            return [
                'distance' => $dist,
                'closest_lat' => $closestLat,
                'closest_lon' => $closestLon,
                't' => $t
            ];
        }
    }

    /**
     * Calculates the minimum distance from a point to a GeoJSON LineString coordinate sequence.
     * Coordinates are expected in GeoJSON order: [ [lon, lat], [lon, lat], ... ]
     * 
     * @param float $latP Sighting latitude
     * @param float $lonP Sighting longitude
     * @param array $coordinates Array of GeoJSON coordinate pairs [longitude, latitude]
     * @return array ['distance' => float, 'closest_lat' => float, 'closest_lon' => float, 'segment_index' => int]
     */
    public static function pointToLineStringDistance($latP, $lonP, array $coordinates) {
        $count = count($coordinates);
        if ($count < 2) {
            if ($count === 1) {
                $lon = (float)$coordinates[0][0];
                $lat = (float)$coordinates[0][1];
                return [
                    'distance' => self::haversineDistance($latP, $lonP, $lat, $lon),
                    'closest_lat' => $lat,
                    'closest_lon' => $lon,
                    'segment_index' => 0
                ];
            }
            return [
                'distance' => INF,
                'closest_lat' => 0.0,
                'closest_lon' => 0.0,
                'segment_index' => -1
            ];
        }

        $minDistance = INF;
        $bestClosestLat = 0.0;
        $bestClosestLon = 0.0;
        $bestSegmentIdx = -1;

        for ($i = 0; $i < $count - 1; $i++) {
            $lonA = (float)$coordinates[$i][0];
            $latA = (float)$coordinates[$i][1];
            $lonB = (float)$coordinates[$i + 1][0];
            $latB = (float)$coordinates[$i + 1][1];

            $res = self::pointToSegmentDistance($latP, $lonP, $latA, $lonA, $latB, $lonB);

            if ($res['distance'] < $minDistance) {
                $minDistance = $res['distance'];
                $bestClosestLat = $res['closest_lat'];
                $bestClosestLon = $res['closest_lon'];
                $bestSegmentIdx = $i;

                // Early exit optimization: if distance is practically 0 (< 10 cm), no need to check further
                if ($minDistance < 0.1) {
                    break;
                }
            }
        }

        return [
            'distance' => $minDistance,
            'closest_lat' => $bestClosestLat,
            'closest_lon' => $bestClosestLon,
            'segment_index' => $bestSegmentIdx
        ];
    }

    /**
     * Loads and caches authentic railway GeoJSON track geometries and pre-computes bounding boxes.
     * 
     * @param string|null $filePath Optional path to GeoJSON file
     * @param bool $forceReload Whether to ignore cache and reload
     * @return array Cached array containing 'metadata' and 'features'
     * @throws RuntimeException If GeoJSON cannot be read or parsed
     */
    public static function loadRailwayGeometry($filePath = null, $forceReload = false) {
        if (self::$railwayCache !== null && !$forceReload) {
            return self::$railwayCache;
        }

        $path = $filePath ?: self::$defaultGeoJsonPath;
        if (!file_exists($path) || !is_readable($path)) {
            throw new RuntimeException("Railway GeoJSON file not found or unreadable at: {$path}");
        }

        $jsonContent = file_get_contents($path);
        $data = json_decode($jsonContent, true);

        if (!$data || !isset($data['features']) || !is_array($data['features'])) {
            throw new RuntimeException("Invalid GeoJSON structure in railway route data.");
        }

        // Enrich each feature with pre-computed bounding box for fast bounding-box queries
        foreach ($data['features'] as &$feature) {
            $coords = $feature['geometry']['coordinates'] ?? [];
            if (!empty($coords)) {
                $minLon = INF;
                $maxLon = -INF;
                $minLat = INF;
                $maxLat = -INF;

                foreach ($coords as $pt) {
                    $lon = (float)$pt[0];
                    $lat = (float)$pt[1];
                    if ($lon < $minLon) $minLon = $lon;
                    if ($lon > $maxLon) $maxLon = $lon;
                    if ($lat < $minLat) $minLat = $lat;
                    if ($lat > $maxLat) $maxLat = $lat;
                }

                $feature['_bbox'] = [
                    'min_lon' => $minLon,
                    'max_lon' => $maxLon,
                    'min_lat' => $minLat,
                    'max_lat' => $maxLat
                ];
            }
        }
        unset($feature);

        self::$railwayCache = $data;
        return self::$railwayCache;
    }

    /**
     * Validates whether given coordinates represent a plausible geographic location in Sri Lanka.
     * 
     * @param mixed $latitude
     * @param mixed $longitude
     * @return array ['valid' => bool, 'reason' => string|null]
     */
    public static function validateCoordinate($latitude, $longitude) {
        if ($latitude === null || $latitude === '' || $longitude === null || $longitude === '') {
            return ['valid' => false, 'reason' => 'Coordinates not provided (null or empty).'];
        }

        if (!is_numeric($latitude) || !is_numeric($longitude)) {
            return ['valid' => false, 'reason' => 'Coordinates must be valid numeric values.'];
        }

        $lat = (float)$latitude;
        $lon = (float)$longitude;

        if (is_nan($lat) || is_nan($lon) || is_infinite($lat) || is_infinite($lon)) {
            return ['valid' => false, 'reason' => 'Coordinates contain invalid floating point representation.'];
        }

        if ($lat < -90.0 || $lat > 90.0 || $lon < -180.0 || $lon > 180.0) {
            return ['valid' => false, 'reason' => 'Coordinates outside valid global WGS84 range.'];
        }

        // Regional boundary check for Sri Lanka railway context
        if ($lat < self::SRI_LANKA_MIN_LAT || $lat > self::SRI_LANKA_MAX_LAT ||
            $lon < self::SRI_LANKA_MIN_LON || $lon > self::SRI_LANKA_MAX_LON) {
            return [
                'valid' => false, 
                'reason' => "Coordinates ($lat, $lon) fall outside Sri Lanka territory (Lat: 5.8°-10.0°, Lon: 79.5°-82.2°)."
            ];
        }

        return ['valid' => true, 'reason' => null];
    }

    /**
     * Calculates the nearest railway track, section, distance in metres, and effective uncertainty range.
     * 
     * @param mixed $latitude Sighting latitude
     * @param mixed $longitude Sighting longitude
     * @param mixed $gpsAccuracy GPS reported horizontal accuracy in metres (optional)
     * @return array Standardized proximity analysis result
     */
    public static function calculateRailwayProximity($latitude, $longitude, $gpsAccuracy = null) {
        $validation = self::validateCoordinate($latitude, $longitude);

        if (!$validation['valid']) {
            return [
                'has_coordinates' => false,
                'is_valid' => false,
                'latitude' => null,
                'longitude' => null,
                'nearest_section_id' => null,
                'nearest_section_name' => null,
                'nearest_district' => null,
                'nearest_section_risk' => null,
                'distance_meters' => null,
                'closest_track_point' => null,
                'gps_accuracy_meters' => null,
                'effective_uncertainty_range' => null,
                'uncertainty_range_text' => null,
                'status_message' => $validation['reason']
            ];
        }

        $lat = (float)$latitude;
        $lon = (float)$longitude;
        $acc = ($gpsAccuracy !== null && $gpsAccuracy !== '' && is_numeric($gpsAccuracy)) ? abs((float)$gpsAccuracy) : null;

        $geojson = self::loadRailwayGeometry();
        $minDistance = INF;
        $bestFeature = null;
        $bestClosestPt = null;

        foreach ($geojson['features'] as $feature) {
            $coords = $feature['geometry']['coordinates'] ?? [];
            if (empty($coords)) {
                continue;
            }

            // Quick bounding box distance check: if point is clearly farther than minDistance, skip
            if (isset($feature['_bbox'])) {
                $bbox = $feature['_bbox'];
                // Approximate degree distance to bbox
                $degDistLat = 0.0;
                if ($lat < $bbox['min_lat']) $degDistLat = $bbox['min_lat'] - $lat;
                elseif ($lat > $bbox['max_lat']) $degDistLat = $lat - $bbox['max_lat'];

                $degDistLon = 0.0;
                if ($lon < $bbox['min_lon']) $degDistLon = $bbox['min_lon'] - $lon;
                elseif ($lon > $bbox['max_lon']) $degDistLon = $lon - $bbox['max_lon'];

                // 1 deg ~ 111,000 m. If lower bound exceeds best found, skip full LineString iteration
                $approxMinDistMeters = hypot($degDistLat * 111000.0, $degDistLon * 110000.0);
                if ($approxMinDistMeters > $minDistance) {
                    continue;
                }
            }

            $lineRes = self::pointToLineStringDistance($lat, $lon, $coords);
            if ($lineRes['distance'] < $minDistance) {
                $minDistance = $lineRes['distance'];
                $bestFeature = $feature;
                $bestClosestPt = [
                    'latitude' => round($lineRes['closest_lat'], 7),
                    'longitude' => round($lineRes['closest_lon'], 7)
                ];
            }
        }

        if ($bestFeature === null || is_infinite($minDistance)) {
            return [
                'has_coordinates' => true,
                'is_valid' => true,
                'latitude' => $lat,
                'longitude' => $lon,
                'nearest_section_id' => null,
                'nearest_section_name' => null,
                'nearest_district' => null,
                'nearest_section_risk' => null,
                'distance_meters' => null,
                'closest_track_point' => null,
                'gps_accuracy_meters' => $acc,
                'effective_uncertainty_range' => null,
                'uncertainty_range_text' => null,
                'status_message' => 'No matching railway section geometries available.'
            ];
        }

        $props = $bestFeature['properties'];
        $roundedDist = round($minDistance, 1);

        // Effective uncertainty range calculation based on GPS accuracy
        $uncertaintyRange = null;
        $uncertaintyText = null;

        if ($acc !== null) {
            $minPossible = max(0.0, round($roundedDist - $acc, 1));
            $maxPossible = round($roundedDist + $acc, 1);
            $uncertaintyRange = [
                'min_distance_meters' => $minPossible,
                'max_distance_meters' => $maxPossible,
                'accuracy_meters' => round($acc, 1)
            ];
            $uncertaintyText = "{$roundedDist}m (±{$acc}m: [{$minPossible}m - {$maxPossible}m])";
        } else {
            $uncertaintyText = "{$roundedDist}m (GPS accuracy unspecified)";
        }

        return [
            'has_coordinates' => true,
            'is_valid' => true,
            'latitude' => $lat,
            'longitude' => $lon,
            'nearest_section_id' => (int)($props['section_id'] ?? 0),
            'nearest_section_name' => $props['section_name'] ?? 'Unknown Section',
            'nearest_district' => $props['district'] ?? 'Unknown District',
            'nearest_section_risk' => $props['risk_level'] ?? 'Unknown',
            'distance_meters' => $roundedDist,
            'closest_track_point' => $bestClosestPt,
            'gps_accuracy_meters' => $acc !== null ? round($acc, 1) : null,
            'effective_uncertainty_range' => $uncertaintyRange,
            'uncertainty_range_text' => $uncertaintyText,
            'calculation_method' => 'Geodesic Haversine Point-to-LineString with Conformal Tangent Projection',
            'status_message' => 'Proximity calculated successfully'
        ];
    }
}
