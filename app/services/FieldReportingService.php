<?php
/**
 * EleGuard Rail — Next-Generation Field Reporting Backend Service
 * 
 * Milestone 9C Stage 4E.4.1: Field Reporting Backend Foundation
 * 
 * Implements decoupled observer/animal georeferencing, geodetic offset forward projection,
 * gapless 7-period diurnal continuum, topological railway section association,
 * server-side transactional idempotency, anti-poaching coordinate masking,
 * and human verification lifecycle auditing.
 * 
 * Architectural Standard: RFC 9457 (Problem Details for HTTP APIs)
 */

require_once __DIR__ . '/../core/Database.php';
require_once __DIR__ . '/../core/Auth.php';
require_once __DIR__ . '/ProximityService.php';
require_once __DIR__ . '/TemporalService.php';
require_once __DIR__ . '/RiskService.php';
require_once __DIR__ . '/AiDetectionService.php';
require_once __DIR__ . '/VerificationService.php';

class FieldReportingService {

    // Earth radius in metres (spherical IUGG model)
    const EARTH_RADIUS_METERS = 6371000.0;

    // Sri Lanka territorial bounding box
    const SRI_LANKA_MIN_LAT = 5.80000000;
    const SRI_LANKA_MAX_LAT = 10.00000000;
    const SRI_LANKA_MIN_LON = 79.50000000;
    const SRI_LANKA_MAX_LON = 82.20000000;

    // Batch Limits (API-02)
    const MAX_BATCH_REPORTS = 50;
    const MAX_BATCH_PAYLOAD_BYTES = 10485760; // 10 MB

    // Authorized roles for unmasked high-precision coordinates
    const UNMASKED_COORDINATE_ROLES = ['wildlife_officer', 'admin'];

    // Authorized roles for official verification lifecycle
    const VERIFICATION_ROLES = ['wildlife_officer', 'admin'];

    // Authorized roles for railway section confirmation
    const SECTION_CONFIRMATION_ROLES = ['control_officer', 'admin'];

    // Diurnal Period Taxonomy (7 gapless continuous periods)
    const DIURNAL_PERIODS = [
        'dawn'        => ['start' => '04:00:00', 'end' => '07:00:00', 'midpoint' => '05:30:00', 'uncertainty' => 90],
        'morning'     => ['start' => '07:00:00', 'end' => '11:00:00', 'midpoint' => '09:00:00', 'uncertainty' => 120],
        'midday'      => ['start' => '11:00:00', 'end' => '14:00:00', 'midpoint' => '12:30:00', 'uncertainty' => 90],
        'afternoon'   => ['start' => '14:00:00', 'end' => '17:00:00', 'midpoint' => '15:30:00', 'uncertainty' => 90],
        'dusk'        => ['start' => '17:00:00', 'end' => '19:30:00', 'midpoint' => '18:15:00', 'uncertainty' => 75],
        'night'       => ['start' => '19:30:00', 'end' => '23:30:00', 'midpoint' => '21:30:00', 'uncertainty' => 120],
        'deep_night'  => ['start' => '23:30:00', 'end' => '04:00:00', 'midpoint' => '01:45:00', 'uncertainty' => 135]
    ];

    /**
     * Validates an incoming sighting observation payload.
     * 
     * @param array $payload Raw user input data
     * @return array ['valid' => bool, 'errors' => array, 'data' => array]
     */
    public static function validateObservationPayload(array $payload) {
        $errors = [];
        $sanitized = [];

        // 1. Client Report UUID (RFC 4122 v4)
        $clientUuid = trim($payload['client_report_uuid'] ?? '');
        if (!empty($clientUuid)) {
            if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $clientUuid)) {
                $errors['client_report_uuid'] = 'Invalid RFC 4122 v4 UUID format.';
            } else {
                $sanitized['client_report_uuid'] = strtolower($clientUuid);
            }
        } else {
            $sanitized['client_report_uuid'] = null;
        }

        // 2. Submission Source
        $validSources = ['web_portal', 'mobile_pwa', 'station_master_vhf', 'offline_sync'];
        $source = trim($payload['submission_source'] ?? 'web_portal');
        $sanitized['submission_source'] = in_array($source, $validSources, true) ? $source : 'web_portal';

        // 3. Observation Category
        $validCategories = ['live_sighting', 'tracks_or_dung', 'crop_damage', 'carcass', 'fence_breach'];
        $category = trim($payload['observation_category'] ?? 'live_sighting');
        if (!in_array($category, $validCategories, true)) {
            $errors['observation_category'] = 'Invalid observation category.';
        } else {
            $sanitized['observation_category'] = $category;
        }

        // 4. Demographics & Counts
        $count = $payload['elephant_count'] ?? ($payload['sighting_details']['elephant_count'] ?? 1);
        if (!is_numeric($count) || (int)$count < 1) {
            $errors['elephant_count'] = 'Elephant count must be a positive integer (minimum 1).';
        } else {
            $sanitized['elephant_count'] = (int)$count;
        }

        $sanitized['adult_males'] = isset($payload['adult_males']) && is_numeric($payload['adult_males']) && (int)$payload['adult_males'] >= 0 ? (int)$payload['adult_males'] : null;
        $sanitized['adult_females'] = isset($payload['adult_females']) && is_numeric($payload['adult_females']) && (int)$payload['adult_females'] >= 0 ? (int)$payload['adult_females'] : null;
        $sanitized['calves'] = isset($payload['calves']) && is_numeric($payload['calves']) && (int)$payload['calves'] >= 0 ? (int)$payload['calves'] : null;

        // 5. Urgency Level
        $validUrgency = ['Low', 'Medium', 'High', 'Critical'];
        $urgency = trim($payload['urgency_level'] ?? ($payload['sighting_details']['urgency_level'] ?? 'Medium'));
        $sanitized['urgency_level'] = in_array($urgency, $validUrgency, true) ? $urgency : 'Medium';

        // 6. Textual Descriptions
        $locationDesc = trim($payload['location_description'] ?? ($payload['sighting_details']['location_description'] ?? ''));
        if (empty($locationDesc)) {
            $errors['location_description'] = 'Location description is required.';
        } else {
            $sanitized['location_description'] = mb_substr($locationDesc, 0, 255);
        }

        $notes = trim($payload['description'] ?? ($payload['sighting_details']['observer_notes'] ?? ''));
        $sanitized['description'] = !empty($notes) ? $notes : null;

        $sanitized['nearest_milestone_ref'] = !empty($payload['nearest_milestone_ref']) 
            ? mb_substr(trim($payload['nearest_milestone_ref']), 0, 64) 
            : (!empty($payload['railway_context']['nearest_milestone_ref']) ? mb_substr(trim($payload['railway_context']['nearest_milestone_ref']), 0, 64) : null);

        $sanitized['image_path'] = !empty($payload['image_path']) ? trim($payload['image_path']) : (!empty($payload['sighting_details']['image_path']) ? trim($payload['sighting_details']['image_path']) : null);

        // 6b. Railway Section Validation (LOW-01)
        $rawSectionId = $payload['section_id'] ?? ($payload['railway_context']['section_id'] ?? null);
        if ($rawSectionId !== null && $rawSectionId !== '') {
            if (!is_numeric($rawSectionId) || (int)$rawSectionId < 1 || (int)$rawSectionId != $rawSectionId) {
                $errors['section_id'] = 'Railway section ID must be a valid positive integer.';
            } else {
                $secId = (int)$rawSectionId;
                try {
                    $db = Database::getInstance()->getConnection();
                    $stmt = $db->prepare("SELECT id FROM railway_sections WHERE id = :id");
                    $stmt->execute([':id' => $secId]);
                    if (!$stmt->fetchColumn()) {
                        $errors['section_id'] = "The selected railway section ID ({$secId}) does not exist.";
                    } else {
                        $sanitized['section_id'] = $secId;
                    }
                } catch (Exception $e) {
                    $errors['section_id'] = 'Unable to validate railway section ID.';
                }
            }
        } else {
            $sanitized['section_id'] = null;
        }

        // 7. Observer Georeferencing
        $obsLoc = $payload['observer_location'] ?? $payload;
        $obsLat = isset($obsLoc['latitude']) && $obsLoc['latitude'] !== '' ? (float)$obsLoc['latitude'] : null;
        $obsLon = isset($obsLoc['longitude']) && $obsLoc['longitude'] !== '' ? (float)$obsLoc['longitude'] : null;
        $obsAcc = isset($obsLoc['gps_accuracy_meters']) && is_numeric($obsLoc['gps_accuracy_meters']) ? (float)$obsLoc['gps_accuracy_meters'] : null;
        $obsCapAt = !empty($obsLoc['gps_captured_at']) ? trim($obsLoc['gps_captured_at']) : null;
        $obsMethod = trim($obsLoc['acquisition_method'] ?? ($obsLoc['location_acquisition_method'] ?? 'gps_device'));

        $validAcqMethods = ['gps_device', 'manual_map_pinpoint', 'network_coarse', 'landmark_only'];
        $sanitized['location_acquisition_method'] = in_array($obsMethod, $validAcqMethods, true) ? $obsMethod : 'gps_device';

        if ($obsLat !== null || $obsLon !== null) {
            if ($obsLat === null || $obsLon === null) {
                $errors['observer_coordinates'] = 'Both latitude and longitude must be provided together.';
            } elseif ($obsLat < self::SRI_LANKA_MIN_LAT || $obsLat > self::SRI_LANKA_MAX_LAT) {
                $errors['observer_latitude'] = "Latitude {$obsLat} is outside Sri Lanka bounds (" . self::SRI_LANKA_MIN_LAT . " to " . self::SRI_LANKA_MAX_LAT . ").";
            } elseif ($obsLon < self::SRI_LANKA_MIN_LON || $obsLon > self::SRI_LANKA_MAX_LON) {
                $errors['observer_longitude'] = "Longitude {$obsLon} is outside Sri Lanka bounds (" . self::SRI_LANKA_MIN_LON . " to " . self::SRI_LANKA_MAX_LON . ").";
            } else {
                $sanitized['observer_latitude'] = round($obsLat, 8);
                $sanitized['observer_longitude'] = round($obsLon, 8);
            }
        } else {
            $sanitized['observer_latitude'] = null;
            $sanitized['observer_longitude'] = null;
        }

        $sanitized['observer_gps_accuracy_meters'] = ($obsAcc !== null && $obsAcc >= 0) ? round($obsAcc, 2) : null;
        $sanitized['observer_gps_captured_at'] = $obsCapAt;

        // 8. Animal Location & Offset Measurements
        $animalLoc = $payload['animal_location'] ?? [];
        $locMethod = trim($animalLoc['method'] ?? ($payload['animal_location_method'] ?? ''));
        $distance = isset($animalLoc['observation_distance_meters']) && $animalLoc['observation_distance_meters'] !== '' ? (float)$animalLoc['observation_distance_meters'] : (isset($payload['observation_distance_meters']) ? (float)$payload['observation_distance_meters'] : null);
        $bearing = isset($animalLoc['observation_bearing_degrees']) && $animalLoc['observation_bearing_degrees'] !== '' ? (float)$animalLoc['observation_bearing_degrees'] : (isset($payload['observation_bearing_degrees']) ? (float)$payload['observation_bearing_degrees'] : null);

        $sanitized['observation_distance_meters'] = ($distance !== null && $distance >= 0) ? round($distance, 2) : null;
        $sanitized['observation_bearing_degrees'] = ($bearing !== null && $bearing >= 0 && $bearing < 360) ? round($bearing, 2) : null;

        // Validate method against available measurements
        $validLocMethods = ['direct_inspection', 'triangulated_offset', 'milestone_reference', 'observer_standoff_unadjusted', 'descriptive_landmark'];
        if (empty($locMethod)) {
            if (($sanitized['observer_latitude'] ?? null) === null) {
                $locMethod = 'descriptive_landmark';
            } elseif ($sanitized['observation_distance_meters'] === 0.0) {
                $locMethod = 'direct_inspection';
            } elseif ($sanitized['observation_distance_meters'] !== null && $sanitized['observation_bearing_degrees'] !== null) {
                $locMethod = 'triangulated_offset';
            } else {
                $locMethod = 'observer_standoff_unadjusted';
            }
        } elseif (!in_array($locMethod, $validLocMethods, true)) {
            $errors['animal_location_method'] = 'Invalid animal location method.';
        }
        $sanitized['animal_location_method'] = $locMethod;

        // 9. Temporal Evidence Normalization
        $tempInput = $payload['temporal'] ?? $payload;
        $tempNorm = self::normalizeTemporalEvidence($tempInput);
        if (!$tempNorm['valid']) {
            $errors = array_merge($errors, $tempNorm['errors']);
        } else {
            $sanitized = array_merge($sanitized, $tempNorm['data']);
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'data' => $sanitized
        ];
    }

    /**
     * Solves the forward geodetic direct problem using Spherical Great-Circle Forward Projection.
     * Decouples observer GNSS from estimated animal position and propagates spatial uncertainty.
     * 
     * @param float $lat1 Observer latitude in decimal degrees
     * @param float $lon1 Observer longitude in decimal degrees
     * @param float|null $distMeters Standoff distance in metres
     * @param float|null $bearingDeg Compass azimuth (0° - 359°)
     * @param float|null $gpsAccuracy Hardware GPS uncertainty radius in metres (null if unavailable)
     * @return array Resolved target coordinates, method, uncertainty, and estimation flag
     */
    public static function calculateGeodeticOffset($lat1, $lon1, $distMeters, $bearingDeg, $gpsAccuracy = null) {
        $lat1 = (float)$lat1;
        $lon1 = (float)$lon1;
        $cleanGpsAccuracy = ($gpsAccuracy !== null && is_numeric($gpsAccuracy) && (float)$gpsAccuracy > 0) ? (float)$gpsAccuracy : null;

        // Mode 1: Direct Co-location (distance = 0m, e.g. dung or track inspection on ballast)
        if ($distMeters !== null && (float)$distMeters === 0.0) {
            return [
                'estimated_latitude' => round($lat1, 8),
                'estimated_longitude' => round($lon1, 8),
                'uncertainty_meters' => $cleanGpsAccuracy !== null ? round($cleanGpsAccuracy, 2) : null,
                'method' => 'direct_inspection',
                'is_estimated' => 0
            ];
        }

        // Mode 2: Unadjusted Standoff (distance or bearing missing) -> Animal coords remain strictly NULL
        if ($distMeters === null || $bearingDeg === null || (float)$distMeters < 0) {
            return [
                'estimated_latitude' => null,
                'estimated_longitude' => null,
                'uncertainty_meters' => null,
                'method' => 'observer_standoff_unadjusted',
                'is_estimated' => 0
            ];
        }

        // Mode 3: Triangulated Geodesic Offset
        $d = (float)$distMeters;
        $theta = deg2rad((float)$bearingDeg);
        $phi1 = deg2rad($lat1);
        $lambda1 = deg2rad($lon1);
        $delta = $d / self::EARTH_RADIUS_METERS;

        // Great-Circle Forward Equations
        $phi2 = asin(sin($phi1) * cos($delta) + cos($phi1) * sin($delta) * cos($theta));
        $lambda2 = $lambda1 + atan2(sin($theta) * sin($delta) * cos($phi1), cos($delta) - sin($phi1) * sin($phi2));

        $lat2 = rad2deg($phi2);
        $lon2 = rad2deg($lambda2);

        // Propagated Spatial Uncertainty Formula:
        // sigma_pos = sqrt(sigma_gps^2 + sigma_range^2 + (d * sin(sigma_bearing))^2)
        // If device GPS accuracy is missing/unknown, total spatial uncertainty remains strictly null
        if ($cleanGpsAccuracy === null) {
            $totalUncertainty = null;
        } else {
            $sigmaRange = max(5.0, 0.20 * $d); // 20% visual range error or 5m minimum
            $sigmaBearingRad = deg2rad(10.0);   // ±10° compass accuracy
            $transverseError = $d * sin($sigmaBearingRad);
            $totalUncertainty = round(sqrt(($cleanGpsAccuracy * $cleanGpsAccuracy) + ($sigmaRange * $sigmaRange) + ($transverseError * $transverseError)), 2);
        }

        return [
            'estimated_latitude' => round($lat2, 8),
            'estimated_longitude' => round($lon2, 8),
            'uncertainty_meters' => $totalUncertainty,
            'method' => 'triangulated_offset',
            'is_estimated' => 1
        ];
    }

    /**
     * Normalizes and validates temporal evidence into the continuous 7-period diurnal continuum.
     * 
     * @param array $input Raw temporal fields
     * @return array ['valid' => bool, 'errors' => array, 'data' => array]
     */
    public static function normalizeTemporalEvidence(array $input) {
        $errors = [];
        $data = [
            'sighting_date' => null,
            'sighting_time' => null,
            'sighting_time_precision' => 'unknown',
            'diurnal_period' => null,
            'sighting_time_uncertainty_minutes' => null,
            'sighting_timezone' => 'Asia/Colombo'
        ];

        // 1. Sighting Date
        $sightingDate = trim($input['sighting_date'] ?? '');
        $dateUnknown = !empty($input['sighting_date_unknown']) || empty($sightingDate);

        if (!$dateUnknown) {
            $d = DateTime::createFromFormat('Y-m-d', $sightingDate, new DateTimeZone('Asia/Colombo'));
            if (!$d || $d->format('Y-m-d') !== $sightingDate) {
                $errors['sighting_date'] = 'Invalid date format. Expected YYYY-MM-DD.';
            } else {
                // Cannot be in the future
                $now = new DateTime('now', new DateTimeZone('Asia/Colombo'));
                if ($d > $now) {
                    $errors['sighting_date'] = 'Sighting date cannot be in the future.';
                } else {
                    $data['sighting_date'] = $sightingDate;
                }
            }
        }

        // 2. Precision Mode
        $precision = trim($input['sighting_time_precision'] ?? '');
        $validPrecisions = ['exact', 'approximate', 'interval', 'unknown'];
        if (!in_array($precision, $validPrecisions, true)) {
            $precision = !empty($input['sighting_time']) ? 'exact' : (!empty($input['diurnal_period']) ? 'approximate' : 'unknown');
        }

        // 3. Clock Time & Diurnal Period Mapping
        $rawTime = trim($input['sighting_time'] ?? '');
        $diurnal = trim($input['diurnal_period'] ?? ($input['sighting_time_window'] ?? ''));

        if ($precision === 'exact' && !empty($rawTime)) {
            // Validate HH:MM or HH:MM:SS
            if (preg_match('/^([01][0-9]|2[0-3]):([0-5][0-9])(:([0-5][0-9]))?$/', $rawTime, $m)) {
                $formattedTime = sprintf('%02d:%02d:00', (int)$m[1], (int)$m[2]);
                $data['sighting_time'] = $formattedTime;
                $data['sighting_time_precision'] = 'exact';
                $data['sighting_time_uncertainty_minutes'] = 5;

                // Map automatically to continuous 7-period continuum
                $data['diurnal_period'] = self::classifyDiurnalPeriod($formattedTime);
            } else {
                $errors['sighting_time'] = 'Invalid clock time format. Expected HH:MM.';
            }
        } elseif ($precision === 'approximate' || !empty($diurnal)) {
            if (array_key_exists($diurnal, self::DIURNAL_PERIODS)) {
                $data['diurnal_period'] = $diurnal;
                $data['sighting_time_precision'] = 'approximate';
                $data['sighting_time_uncertainty_minutes'] = self::DIURNAL_PERIODS[$diurnal]['uncertainty'];
                // Set midpoint as representative time
                $data['sighting_time'] = self::DIURNAL_PERIODS[$diurnal]['midpoint'];
            } else {
                $errors['diurnal_period'] = 'Invalid diurnal period. Allowed: dawn, morning, midday, afternoon, dusk, night, deep_night.';
            }
        } else {
            // Unknown time (Mode T4)
            $data['sighting_time_precision'] = 'unknown';
            $data['sighting_time'] = null;
            $data['diurnal_period'] = null;
            $data['sighting_time_uncertainty_minutes'] = null;
        }

        // 4. Validate that combined sighting timestamp is not in the future (Asia/Colombo)
        if ($data['sighting_date'] !== null && $data['sighting_time'] !== null && empty($errors)) {
            $tz = new DateTimeZone('Asia/Colombo');
            $now = new DateTime('now', $tz);
            $combinedStr = $data['sighting_date'] . ' ' . $data['sighting_time'];
            $dt = DateTime::createFromFormat('Y-m-d H:i:s', $combinedStr, $tz);
            if ($dt && $dt > $now) {
                if ($data['sighting_time_precision'] === 'approximate') {
                    $errors['diurnal_period'] = 'Selected diurnal period has not occurred yet today.';
                } else {
                    $errors['sighting_time'] = 'Sighting timestamp cannot be in the future.';
                }
            }
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'data' => $data
        ];
    }

    /**
     * Determines the continuous diurnal period for a given 24-hour clock time.
     * 
     * @param string $time HH:MM:SS
     * @return string Diurnal period code
     */
    public static function classifyDiurnalPeriod($time) {
        $t = substr($time, 0, 5); // HH:MM
        if ($t >= '04:00' && $t < '07:00') return 'dawn';
        if ($t >= '07:00' && $t < '11:00') return 'morning';
        if ($t >= '11:00' && $t < '14:00') return 'midday';
        if ($t >= '14:00' && $t < '17:00') return 'afternoon';
        if ($t >= '17:00' && $t < '19:30') return 'dusk';
        if ($t >= '19:30' && $t < '23:30') return 'night';
        // Deep night interval: 23:30 to 04:00 (spans across midnight boundary)
        return 'deep_night';
    }

    /**
     * Calculates railway section association and proximity metrics from OSM track geometries.
     * Incorporates junction geofences, parallel-track divergence ratios, and distance intervals.
     * 
     * @param float|null $targetLat Animal or observer latitude
     * @param float|null $targetLon Animal or observer longitude
     * @param float|null $uncertaintyMeters Combined spatial uncertainty
     * @param int|null $preferredSectionId User-provided section hint
     * @param string|null $milestoneRef Textual kilometer post reference
     * @return array Topographic railway association metadata
     */
    public static function calculateRailwayAssociation($targetLat, $targetLon, $uncertaintyMeters = null, $preferredSectionId = null, $milestoneRef = null) {
        $uncertainty = ($uncertaintyMeters !== null && $uncertaintyMeters >= 0) ? (float)$uncertaintyMeters : 25.0;

        if ($targetLat === null || $targetLon === null) {
            return [
                'section_id' => $preferredSectionId ?: 1,
                'distance_to_track_meters' => null,
                'track_distance_min_meters' => null,
                'track_distance_max_meters' => null,
                'track_proximity_zone' => null,
                'section_assignment_status' => 'provisional_estimated',
                'osm_disclaimer' => 'No spatial coordinates provided; section based on textual reference.'
            ];
        }

        // 1. Junction Topology Geofences (Gal Oya & Maho Junctions)
        $isGalOyaJunction = ($targetLat >= 7.990 && $targetLat <= 8.040 && $targetLon >= 80.950 && $targetLon <= 81.010);
        $isMahoJunction   = ($targetLat >= 7.800 && $targetLat <= 7.840 && $targetLon >= 80.250 && $targetLon <= 80.300);

        if ($isGalOyaJunction || $isMahoJunction) {
            return [
                'section_id' => $preferredSectionId ?: null,
                'distance_to_track_meters' => 15.0,
                'track_distance_min_meters' => 0.0,
                'track_distance_max_meters' => round(15.0 + $uncertainty, 2),
                'track_proximity_zone' => 'Zone 0',
                'section_assignment_status' => 'topology_ambiguity',
                'osm_disclaimer' => 'Sighting falls within railway junction polygon; automated section attribution suppressed.'
            ];
        }

        // 2. Load Railway Geometry via ProximityService
        $routesGeoJson = ProximityService::loadRailwayGeometry();
        $sectionDistances = [];

        if (!empty($routesGeoJson['features'])) {
            foreach ($routesGeoJson['features'] as $feature) {
                $secId = (int)($feature['properties']['section_id'] ?? 1);
                $geom = $feature['geometry'] ?? [];
                if (($geom['type'] ?? '') === 'LineString' && !empty($geom['coordinates'])) {
                    $coords = $geom['coordinates'];
                    $minDist = PHP_FLOAT_MAX;
                    for ($i = 0; $i < count($coords) - 1; $i++) {
                        $pA = $coords[$i];
                        $pB = $coords[$i + 1];
                        $segDist = ProximityService::pointToSegmentDistance($targetLat, $targetLon, $pA[1], $pA[0], $pB[1], $pB[0]);
                        if ($segDist['distance'] < $minDist) {
                            $minDist = $segDist['distance'];
                        }
                    }
                    if ($minDist < PHP_FLOAT_MAX) {
                        $sectionDistances[$secId] = $minDist;
                    }
                }
            }
        }

        if (empty($sectionDistances)) {
            // Fallback default if geometry missing
            return [
                'section_id' => $preferredSectionId ?: 1,
                'distance_to_track_meters' => 100.0,
                'track_distance_min_meters' => max(0.0, 100.0 - $uncertainty),
                'track_distance_max_meters' => 100.0 + $uncertainty,
                'track_proximity_zone' => 'Zone 1',
                'section_assignment_status' => 'provisional_estimated',
                'osm_disclaimer' => 'Calculated from fallback OSM track vector.'
            ];
        }

        asort($sectionDistances);
        $closestSectionId = (int)key($sectionDistances);
        $closestDist = (float)current($sectionDistances);

        // 3. Parallel Track Divergence Ratio Check
        $assignmentStatus = 'provisional_estimated';
        if (count($sectionDistances) >= 2) {
            next($sectionDistances);
            $secondDist = (float)current($sectionDistances);
            $ratio = $closestDist / max(1.0, $secondDist);
            if ($ratio >= 0.70 && abs($closestDist - $secondDist) <= 300.0) {
                $assignmentStatus = 'topology_ambiguity';
            }
        }

        // 4. Calculate Distance Interval & Proximity Zone
        $distMin = max(0.0, $closestDist - $uncertainty);
        $distMax = $closestDist + $uncertainty;

        if ($closestDist <= 15.0) {
            $zone = 'Zone 0'; // Track Clearance Zone
        } elseif ($closestDist <= 100.0) {
            $zone = 'Zone 1'; // Near-Corridor Zone
        } elseif ($closestDist <= 1000.0) {
            $zone = 'Zone 2'; // Corridor Buffer Zone
        } else {
            $zone = 'Zone 3'; // Off-Corridor Habitat Zone
        }

        return [
            'section_id' => $closestSectionId,
            'distance_to_track_meters' => round($closestDist, 2),
            'track_distance_min_meters' => round($distMin, 2),
            'track_distance_max_meters' => round($distMax, 2),
            'track_proximity_zone' => $zone,
            'section_assignment_status' => $assignmentStatus,
            'osm_disclaimer' => 'Distance derived from OpenStreetMap geometry (10-30m planar uncertainty); not official engineering survey.'
        ];
    }

    /**
     * Computes a deterministic SHA-256 canonical payload fingerprint for observation data.
     * Excludes server-generated metadata, auto-increment IDs, timestamps, calculated risk, and session fields.
     *
     * @param array $data Validated observation payload attributes
     * @return string 64-character hexadecimal SHA-256 digest
     */
    public static function computePayloadFingerprint(array $data) {
        $canonical = [
            'observation_category' => isset($data['observation_category']) ? (string)$data['observation_category'] : null,
            'sighting_date' => isset($data['sighting_date']) ? (string)$data['sighting_date'] : null,
            'sighting_time' => isset($data['sighting_time']) && $data['sighting_time'] !== '' ? (string)$data['sighting_time'] : null,
            'sighting_time_precision' => isset($data['sighting_time_precision']) ? (string)$data['sighting_time_precision'] : null,
            'diurnal_period' => isset($data['diurnal_period']) && $data['diurnal_period'] !== '' ? (string)$data['diurnal_period'] : null,
            'sighting_time_uncertainty_minutes' => (isset($data['sighting_time_uncertainty_minutes']) && $data['sighting_time_uncertainty_minutes'] !== null && $data['sighting_time_uncertainty_minutes'] !== '') ? (int)$data['sighting_time_uncertainty_minutes'] : null,
            'sighting_timezone' => isset($data['sighting_timezone']) ? (string)$data['sighting_timezone'] : null,
            'observer_latitude' => (isset($data['observer_latitude']) && $data['observer_latitude'] !== null && $data['observer_latitude'] !== '') ? sprintf('%.8f', (float)$data['observer_latitude']) : null,
            'observer_longitude' => (isset($data['observer_longitude']) && $data['observer_longitude'] !== null && $data['observer_longitude'] !== '') ? sprintf('%.8f', (float)$data['observer_longitude']) : null,
            'observer_gps_accuracy_meters' => (isset($data['observer_gps_accuracy_meters']) && $data['observer_gps_accuracy_meters'] !== null && $data['observer_gps_accuracy_meters'] !== '') ? sprintf('%.2f', (float)$data['observer_gps_accuracy_meters']) : null,
            'location_acquisition_method' => isset($data['location_acquisition_method']) ? (string)$data['location_acquisition_method'] : null,
            'observation_distance_meters' => (isset($data['observation_distance_meters']) && $data['observation_distance_meters'] !== null && $data['observation_distance_meters'] !== '') ? sprintf('%.2f', (float)$data['observation_distance_meters']) : null,
            'observation_bearing_degrees' => (isset($data['observation_bearing_degrees']) && $data['observation_bearing_degrees'] !== null && $data['observation_bearing_degrees'] !== '') ? sprintf('%.2f', (float)$data['observation_bearing_degrees']) : null,
            'nearest_milestone_ref' => (isset($data['nearest_milestone_ref']) && trim((string)$data['nearest_milestone_ref']) !== '') ? trim((string)$data['nearest_milestone_ref']) : null,
            'elephant_count' => isset($data['elephant_count']) ? (int)$data['elephant_count'] : 0,
            'adult_males' => (isset($data['adult_males']) && $data['adult_males'] !== null && $data['adult_males'] !== '') ? (int)$data['adult_males'] : null,
            'adult_females' => (isset($data['adult_females']) && $data['adult_females'] !== null && $data['adult_females'] !== '') ? (int)$data['adult_females'] : null,
            'calves' => (isset($data['calves']) && $data['calves'] !== null && $data['calves'] !== '') ? (int)$data['calves'] : null,
            'urgency_level' => isset($data['urgency_level']) ? (string)$data['urgency_level'] : null,
            'location_description' => isset($data['location_description']) ? trim((string)$data['location_description']) : '',
            'description' => isset($data['description']) ? trim((string)$data['description']) : ''
        ];

        // Normalize Unicode to NFC if normalizer_normalize is available
        if (function_exists('normalizer_normalize')) {
            foreach ($canonical as $k => $v) {
                if (is_string($v)) {
                    $norm = normalizer_normalize($v, Normalizer::FORM_C);
                    if ($norm !== false) {
                        $canonical[$k] = $norm;
                    }
                }
            }
        }

        ksort($canonical);
        $canonicalJson = json_encode($canonical, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return hash('sha256', $canonicalJson);
    }

    /**
     * Submits an empirical field report with server-side transactional idempotency.
     * 
     * @param array $payload Validated observation payload
     * @param int $userId Authenticated submitting user ID
     * @param string $userRole Authenticated submitting user role
     * @return array Standardized result envelope with report ID and metadata
     */
    public static function submitReport(array $payload, $userId, $userRole) {
        $val = self::validateObservationPayload($payload);
        if (!$val['valid']) {
            return [
                'success' => false,
                'status_code' => 422,
                'error' => [
                    'type' => 'https://eleguard.rail.gov.lk/errors/validation-error',
                    'title' => 'Invalid Observation Payload',
                    'status' => 422,
                    'detail' => 'The provided sighting report contains validation errors.',
                    'invalid_params' => $val['errors']
                ]
            ];
        }

        $d = $val['data'];
        $db = Database::getInstance()->getConnection();

        // 1. Geodetic Target Position Resolution
        $offsetResult = self::calculateGeodeticOffset(
            $d['observer_latitude'],
            $d['observer_longitude'],
            $d['observation_distance_meters'],
            $d['observation_bearing_degrees'],
            $d['observer_gps_accuracy_meters']
        );

        $estLat = $offsetResult['estimated_latitude'];
        $estLon = $offsetResult['estimated_longitude'];
        $spatialUncertainty = $offsetResult['uncertainty_meters'];
        $locMethod = $offsetResult['method'];
        $isEstimated = $offsetResult['is_estimated'];

        // 2. Railway Section Association
        $railway = self::calculateRailwayAssociation(
            $estLat ?: $d['observer_latitude'],
            $estLon ?: $d['observer_longitude'],
            $spatialUncertainty,
            $d['section_id'] ?? null,
            $d['nearest_milestone_ref']
        );

        $assignedSectionId = $railway['section_id'] ?: ($d['section_id'] ?: 1);

        // 3. Dynamic Initial Risk Level Calculation
        $secStmt = $db->prepare("SELECT risk_level FROM railway_sections WHERE id = :sec_id");
        $secStmt->execute([':sec_id' => $assignedSectionId]);
        $sectionRisk = $secStmt->fetchColumn() ?: 'Low';

        $reportTime = date('Y-m-d H:i:s');
        $calculatedRisk = RiskService::calculateRiskScore(
            $sectionRisk,
            $d['urgency_level'],
            $d['elephant_count'],
            $reportTime,
            false
        );

        $clientUuid = $d['client_report_uuid'];
        $initialAiStatus = !empty($d['image_path']) ? 'pending' : 'skipped';
        $initialAiNotes = !empty($d['image_path']) ? 'Awaiting AI advisory inference.' : 'No photograph attached.';
        $payloadHash = self::computePayloadFingerprint($d);

        // 4. Transactional Ingestion with Safe Duplicate Key Handling (REL-01)
        try {
            $db->beginTransaction();

            // Insert into elephant_reports
            $stmt = $db->prepare("
                INSERT INTO elephant_reports (
                    client_report_uuid, payload_hash, section_id, reported_by, submission_source, observation_category,
                    sighting_date, sighting_time, sighting_time_precision, diurnal_period,
                    sighting_time_uncertainty_minutes, sighting_timezone,
                    observer_latitude, observer_longitude, observer_gps_accuracy_meters, observer_gps_captured_at, location_acquisition_method,
                    observation_distance_meters, observation_bearing_degrees,
                    estimated_animal_latitude, estimated_animal_longitude, animal_location_uncertainty_meters, animal_location_method, is_estimated_position,
                    nearest_milestone_ref, distance_to_track_meters, track_distance_min_meters, track_distance_max_meters, track_proximity_zone, section_assignment_status,
                    elephant_count, adult_males, adult_females, calves, urgency_level, risk_score,
                    location_description, description, image_path, report_status, provenance,
                    ai_status, ai_detection_notes, created_at,
                    latitude, longitude, gps_accuracy_meters, gps_captured_at
                ) VALUES (
                    :client_uuid, :payload_hash, :section_id, :reported_by, :sub_source, :obs_category,
                    :s_date, :s_time, :s_precision, :diurnal,
                    :s_uncertainty, :s_tz,
                    :obs_lat, :obs_lon, :obs_acc, :obs_cap_at, :obs_method,
                    :dist_m, :bearing_deg,
                    :est_lat, :est_lon, :spat_unc, :anim_method, :is_est,
                    :milestone_ref, :dist_track, :track_min, :track_max, :prox_zone, :sec_status,
                    :count, :males, :females, :calves, :urgency, :risk,
                    :loc_desc, :desc, :img_path, 'Pending', 'genuine_field',
                    :ai_status, :ai_notes, :created_at,
                    :legacy_lat, :legacy_lon, :legacy_acc, :legacy_cap_at
                )
            ");

            $stmt->execute([
                ':client_uuid'   => $clientUuid,
                ':payload_hash'  => ($clientUuid !== null ? $payloadHash : null),
                ':section_id'    => $assignedSectionId,
                ':reported_by'   => $userId,
                ':sub_source'    => $d['submission_source'],
                ':obs_category'  => $d['observation_category'],
                ':s_date'        => $d['sighting_date'],
                ':s_time'        => $d['sighting_time'],
                ':s_precision'   => $d['sighting_time_precision'],
                ':diurnal'       => $d['diurnal_period'],
                ':s_uncertainty' => $d['sighting_time_uncertainty_minutes'],
                ':s_tz'          => $d['sighting_timezone'],
                ':obs_lat'       => $d['observer_latitude'],
                ':obs_lon'       => $d['observer_longitude'],
                ':obs_acc'       => $d['observer_gps_accuracy_meters'],
                ':obs_cap_at'    => $d['observer_gps_captured_at'],
                ':obs_method'    => $d['location_acquisition_method'],
                ':dist_m'        => $d['observation_distance_meters'],
                ':bearing_deg'   => $d['observation_bearing_degrees'],
                ':est_lat'       => $estLat,
                ':est_lon'       => $estLon,
                ':spat_unc'      => $spatialUncertainty,
                ':anim_method'   => $locMethod,
                ':is_est'        => $isEstimated,
                ':milestone_ref' => $d['nearest_milestone_ref'],
                ':dist_track'    => $railway['distance_to_track_meters'],
                ':track_min'     => $railway['track_distance_min_meters'],
                ':track_max'     => $railway['track_distance_max_meters'],
                ':prox_zone'     => $railway['track_proximity_zone'],
                ':sec_status'    => $railway['section_assignment_status'],
                ':count'         => $d['elephant_count'],
                ':males'         => $d['adult_males'],
                ':females'       => $d['adult_females'],
                ':calves'        => $d['calves'],
                ':urgency'       => $d['urgency_level'],
                ':risk'          => $calculatedRisk,
                ':loc_desc'      => $d['location_description'],
                ':desc'          => $d['description'],
                ':img_path'      => $d['image_path'],
                ':ai_status'     => $initialAiStatus,
                ':ai_notes'      => $initialAiNotes,
                ':created_at'    => $reportTime,
                ':legacy_lat'    => $d['observer_latitude'],
                ':legacy_lon'    => $d['observer_longitude'],
                ':legacy_acc'    => $d['observer_gps_accuracy_meters'],
                ':legacy_cap_at' => $d['observer_gps_captured_at']
            ]);

            $reportId = (int)$db->lastInsertId();
            $db->commit();

            // Log submission in system_logs
            Auth::logActivity($userId, 'Sighting Report Ingested', "Report #{$reportId} ingested (UUID: {$clientUuid}).");

            return [
                'success' => true,
                'status_code' => 201,
                'is_replay' => false,
                'data' => [
                    'report_id' => $reportId,
                    'client_report_uuid' => $clientUuid,
                    'report_status' => 'Pending',
                    'created_at' => $reportTime,
                    'observer_location' => self::maskCoordinatesForRole($d['observer_latitude'], $d['observer_longitude'], $userRole),
                    'estimated_animal_location' => [
                        'coordinates' => self::maskCoordinatesForRole($estLat, $estLon, $userRole),
                        'uncertainty_meters' => $spatialUncertainty,
                        'method' => $locMethod,
                        'is_estimated' => (bool)$isEstimated
                    ],
                    'railway_association' => $railway
                ]
            ];

        } catch (PDOException $e) {
            $db->rollBack();

            // Safe duplicate key handling for idempotent replay (REL-01, J5-01, J5-02)
            if ($clientUuid !== null && ($e->getCode() === '23000' || (isset($e->errorInfo[1]) && $e->errorInfo[1] === 1062))) {
                $checkStmt = $db->prepare("SELECT id, reported_by, payload_hash, report_status, created_at FROM elephant_reports WHERE client_report_uuid = :uuid LIMIT 1");
                $checkStmt->execute([':uuid' => $clientUuid]);
                $existing = $checkStmt->fetch(PDO::FETCH_ASSOC);

                if ($existing) {
                    $ownerId = (int)$existing['reported_by'];
                    $callerId = (int)$userId;

                    // J5-01: Cross-user UUID collision isolation (zero disclosure of internal record state)
                    if ($ownerId !== $callerId && $userRole !== 'admin') {
                        return [
                            'success' => false,
                            'status_code' => 409,
                            'error' => [
                                'type' => 'https://eleguard.rail.gov.lk/errors/uuid-conflict',
                                'title' => 'UUID Conflict',
                                'status' => 409,
                                'detail' => 'The provided client_report_uuid conflicts with an existing submission.'
                            ]
                        ];
                    }

                    // J5-02: Deterministic payload fingerprint verification
                    // If an existing record has a stored hash and it diverges from the incoming hash, reject as payload mismatch
                    if (!empty($existing['payload_hash']) && $existing['payload_hash'] !== $payloadHash) {
                        return [
                            'success' => false,
                            'status_code' => 409,
                            'error' => [
                                'type' => 'https://eleguard.rail.gov.lk/errors/payload-mismatch',
                                'title' => 'Payload Mismatch for Existing UUID',
                                'status' => 409,
                                'detail' => 'The provided client_report_uuid was previously submitted with different observation attributes.'
                            ]
                        ];
                    }

                    // Exact idempotent replay: same owner and matching (or legacy null) fingerprint
                    return [
                        'success' => true,
                        'status_code' => 200,
                        'is_replay' => true,
                        'data' => [
                            'report_id' => (int)$existing['id'],
                            'client_report_uuid' => $clientUuid,
                            'report_status' => $existing['report_status'],
                            'created_at' => $existing['created_at'],
                            'notice' => 'Idempotent replay: record previously ingested.'
                        ]
                    ];
                }
            }

            // General unhandled DB error
            return [
                'success' => false,
                'status_code' => 500,
                'error' => [
                    'type' => 'https://eleguard.rail.gov.lk/errors/database-error',
                    'title' => 'Database Ingestion Error',
                    'status' => 500,
                    'detail' => 'An internal database error occurred while committing the report.'
                ]
            ];
        }
    }

    /**
     * Synchronizes a batch of offline reports with strict payload limits (API-02).
     * 
     * @param array $batchPayload Decoded batch payload
     * @param int $userId Submitting user ID
     * @param string $userRole Submitting user role
     * @param int $rawPayloadBytes Total byte length of JSON payload
     * @return array Standardized multi-status response
     */
    public static function syncBatch(array $batchPayload, $userId, $userRole, $rawPayloadBytes = 0) {
        // Enforce maximum body size (10 MB)
        if ($rawPayloadBytes > self::MAX_BATCH_PAYLOAD_BYTES) {
            return [
                'success' => false,
                'status_code' => 413,
                'error' => [
                    'type' => 'https://eleguard.rail.gov.lk/errors/payload-too-large',
                    'title' => 'Payload Too Large',
                    'status' => 413,
                    'detail' => 'The sync batch exceeds the maximum allowed payload size of 10 MB.'
                ]
            ];
        }

        $reports = $batchPayload['reports'] ?? [];
        if (!is_array($reports) || empty($reports)) {
            return [
                'success' => false,
                'status_code' => 422,
                'error' => [
                    'type' => 'https://eleguard.rail.gov.lk/errors/empty-batch',
                    'title' => 'Empty Batch',
                    'status' => 422,
                    'detail' => 'Batch payload must contain a non-empty reports array.'
                ]
            ];
        }

        // Enforce maximum item limit (50 reports)
        if (count($reports) > self::MAX_BATCH_REPORTS) {
            return [
                'success' => false,
                'status_code' => 422,
                'error' => [
                    'type' => 'https://eleguard.rail.gov.lk/errors/batch-limit-exceeded',
                    'title' => 'Batch Limit Exceeded',
                    'status' => 422,
                    'detail' => 'Batch contains ' . count($reports) . ' reports. Maximum allowed is ' . self::MAX_BATCH_REPORTS . '.'
                ]
            ];
        }

        $acknowledgements = [];
        $syncedCount = 0;
        $replayedCount = 0;
        $rejectedCount = 0;

        foreach ($reports as $index => $repPayload) {
            $repPayload['submission_source'] = 'offline_sync';
            $clientUuid = $repPayload['client_report_uuid'] ?? "batch_item_{$index}";

            $result = self::submitReport($repPayload, $userId, $userRole);
            if ($result['success']) {
                if (!empty($result['is_replay'])) {
                    $replayedCount++;
                } else {
                    $syncedCount++;
                }
                $acknowledgements[] = [
                    'client_report_uuid' => $result['data']['client_report_uuid'],
                    'server_report_id' => $result['data']['report_id'],
                    'status' => $result['is_replay'] ? 'replayed' : 'synced',
                    'created_at' => $result['data']['created_at']
                ];
            } else {
                $rejectedCount++;
                $acknowledgements[] = [
                    'client_report_uuid' => $clientUuid,
                    'status' => 'rejected',
                    'error' => $result['error']['detail'] ?? 'Validation failure.'
                ];
            }
        }

        return [
            'success' => true,
            'status_code' => 207, // Multi-Status
            'data' => [
                'total_received' => count($reports),
                'synced_count' => $syncedCount,
                'replayed_count' => $replayedCount,
                'rejected_count' => $rejectedCount,
                'acknowledgements' => $acknowledgements
            ]
        ];
    }

    /**
     * Retrieves a report by primary key with role-based coordinate masking.
     * 
     * @param int $reportId Report ID
     * @param string $userRole Requesting user role
     * @return array|null Report data or null if not found
     */
    public static function getReportById($reportId, $userRole) {
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("
            SELECT r.*, rs.section_name, rs.district, u.name as reporter_name
            FROM elephant_reports r
            LEFT JOIN railway_sections rs ON r.section_id = rs.id
            LEFT JOIN users u ON r.reported_by = u.id
            WHERE r.id = :id
            LIMIT 1
        ");
        $stmt->execute([':id' => (int)$reportId]);
        $r = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$r) {
            return null;
        }

        // Apply coordinate privacy transformation
        $obsCoords = self::maskCoordinatesForRole(
            $r['observer_latitude'] !== null ? (float)$r['observer_latitude'] : null,
            $r['observer_longitude'] !== null ? (float)$r['observer_longitude'] : null,
            $userRole
        );

        $animCoords = self::maskCoordinatesForRole(
            $r['estimated_animal_latitude'] !== null ? (float)$r['estimated_animal_latitude'] : null,
            $r['estimated_animal_longitude'] !== null ? (float)$r['estimated_animal_longitude'] : null,
            $userRole
        );

        // Fetch verification history
        $verifStmt = $db->prepare("
            SELECT v.*, u.name as verified_by_name
            FROM report_verifications v
            JOIN users u ON v.verified_by = u.id
            WHERE v.report_id = :id
            ORDER BY v.verified_at DESC
        ");
        $verifStmt->execute([':id' => (int)$reportId]);
        $history = $verifStmt->fetchAll(PDO::FETCH_ASSOC);

        return [
            'report_id' => (int)$r['id'],
            'client_report_uuid' => $r['client_report_uuid'],
            'reported_by' => $r['reported_by'] !== null ? (int)$r['reported_by'] : null,
            'reporter_name' => $r['reporter_name'] ?? null,
            'section_id' => $r['section_id'] !== null ? (int)$r['section_id'] : null,
            'section_name' => $r['section_name'],
            'district' => $r['district'],
            'submission_source' => $r['submission_source'],
            'observation_category' => $r['observation_category'],
            'temporal' => [
                'sighting_date' => $r['sighting_date'],
                'sighting_time' => $r['sighting_time'],
                'sighting_time_precision' => $r['sighting_time_precision'],
                'diurnal_period' => $r['diurnal_period'],
                'uncertainty_minutes' => $r['sighting_time_uncertainty_minutes'] !== null ? (int)$r['sighting_time_uncertainty_minutes'] : null,
                'timezone' => $r['sighting_timezone']
            ],
            'observer_location' => array_merge($obsCoords, [
                'accuracy_meters' => $r['observer_gps_accuracy_meters'] !== null ? (float)$r['observer_gps_accuracy_meters'] : null,
                'acquisition_method' => $r['location_acquisition_method']
            ]),
            'estimated_animal_location' => [
                'coordinates' => $animCoords,
                'observation_distance_meters' => $r['observation_distance_meters'] !== null ? (float)$r['observation_distance_meters'] : null,
                'observation_bearing_degrees' => $r['observation_bearing_degrees'] !== null ? (float)$r['observation_bearing_degrees'] : null,
                'uncertainty_meters' => $r['animal_location_uncertainty_meters'] !== null ? (float)$r['animal_location_uncertainty_meters'] : null,
                'method' => $r['animal_location_method'],
                'is_estimated' => (bool)$r['is_estimated_position']
            ],
            'railway_association' => [
                'nearest_milestone_ref' => $r['nearest_milestone_ref'],
                'distance_to_track_meters' => $r['distance_to_track_meters'] !== null ? (float)$r['distance_to_track_meters'] : null,
                'distance_interval' => [
                    'min_meters' => $r['track_distance_min_meters'] !== null ? (float)$r['track_distance_min_meters'] : null,
                    'max_meters' => $r['track_distance_max_meters'] !== null ? (float)$r['track_distance_max_meters'] : null,
                ],
                'proximity_zone' => $r['track_proximity_zone'],
                'assignment_status' => $r['section_assignment_status']
            ],
            'demographics' => [
                'total_count' => (int)$r['elephant_count'],
                'adult_males' => $r['adult_males'] !== null ? (int)$r['adult_males'] : null,
                'adult_females' => $r['adult_females'] !== null ? (int)$r['adult_females'] : null,
                'calves' => $r['calves'] !== null ? (int)$r['calves'] : null
            ],
            'urgency_level' => $r['urgency_level'],
            'risk_score' => $r['risk_score'],
            'location_description' => $r['location_description'],
            'description' => $r['description'],
            'image_path' => $r['image_path'],
            'report_status' => $r['report_status'],
            'provenance' => $r['provenance'],
            'ai_advisory' => [
                'status' => $r['ai_status'],
                'confidence' => $r['ai_confidence'] !== null ? (float)$r['ai_confidence'] : null,
                'estimated_count' => $r['ai_estimated_count'] !== null ? (int)$r['ai_estimated_count'] : null,
                'notes' => $r['ai_detection_notes']
            ],
            'verification_history' => $history,
            'created_at' => $r['created_at']
        ];
    }

    /**
     * Executes human verification transition (Verified or Rejected) via VerificationService.
     * 
     * @param int $reportId Report ID
     * @param string $decision 'Verified' or 'Rejected'
     * @param string $method Verification method enum
     * @param string|null $notes Officer notes
     * @param int $officerId Authenticated officer ID
     * @param string $officerRole Authenticated officer role
     * @param string|null $expectedPreviousStatus Optional optimistic concurrency precondition
     * @return array Standardized result envelope
     */
    public static function verifyReport($reportId, $decision, $method, $notes, $officerId, $officerRole, $expectedPreviousStatus = null) {
        return VerificationService::verifyReport(
            (int)$reportId,
            (string)$decision,
            (string)$method,
            $notes !== null ? (string)$notes : null,
            (int)$officerId,
            (string)$officerRole,
            $expectedPreviousStatus
        );
    }

    /**
     * Requests clarifying information from the reporter ('Needs Information') via VerificationService.
     * 
     * @param int $reportId Report ID
     * @param string $inquiryNotes Specific details requested
     * @param int $officerId Authenticated officer ID
     * @param string $officerRole Authenticated officer role
     * @param string|null $expectedPreviousStatus Optional concurrency precondition
     * @param int|null $parentClarificationId Optional parent clarification message ID
     * @return array Standardized result envelope
     */
    public static function requestAdditionalInfo($reportId, $inquiryNotes, $officerId, $officerRole, $expectedPreviousStatus = null, $parentClarificationId = null) {
        return VerificationService::requestAdditionalInfo(
            (int)$reportId,
            (string)$inquiryNotes,
            (int)$officerId,
            (string)$officerRole,
            $expectedPreviousStatus,
            $parentClarificationId !== null ? (int)$parentClarificationId : null
        );
    }

    /**
     * Submits an observer response to a clarification inquiry via VerificationService.
     * 
     * @param int $reportId Report ID
     * @param string $replyText Observer response
     * @param int|null $parentClarificationId Parent message ID
     * @param int $userId Authenticated user ID
     * @param string $userRole Authenticated user role
     * @return array Standardized result envelope
     */
    public static function submitClarificationReply($reportId, $replyText, $parentClarificationId, $userId, $userRole) {
        return VerificationService::submitClarificationReply(
            (int)$reportId,
            (string)$replyText,
            $parentClarificationId !== null ? (int)$parentClarificationId : null,
            (int)$userId,
            (string)$userRole
        );
    }

    /**
     * Confirms the railway section association via VerificationService.
     * 
     * @param int $reportId Report ID
     * @param int $confirmedSectionId Section ID confirmed by human operator
     * @param string|null $notes Operational dispatch notes
     * @param int $officerId Authenticated controller ID
     * @param string $officerRole Authenticated controller role
     * @param string $assignmentType Assignment type enum
     * @param string $assignmentMethod Assignment method enum
     * @return array Standardized result envelope
     */
    public static function confirmSectionAssociation($reportId, $confirmedSectionId, $notes, $officerId, $officerRole, $assignmentType = 'controller_confirmation', $assignmentMethod = 'manual_controller_override') {
        return VerificationService::confirmOrReassignSection(
            (int)$reportId,
            (int)$confirmedSectionId,
            (string)$assignmentType,
            (string)$assignmentMethod,
            !empty($notes) ? (string)$notes : 'Controller confirmed corridor attribution',
            (int)$officerId,
            (string)$officerRole
        );
    }

    /**
     * Retrieves full chronological audit history for a report via VerificationService.
     * 
     * @param int $reportId Report ID
     * @param string $requestingRole Requesting user role
     * @param int|null $requestingUserId Authenticated user ID (required for reporter ownership check)
     * @return array Standardized result envelope
     */
    public static function getAuditHistory($reportId, $requestingRole, $requestingUserId = null) {
        return VerificationService::getAuditHistory(
            (int)$reportId,
            (string)$requestingRole,
            $requestingUserId !== null ? (int)$requestingUserId : null
        );
    }

    /**
     * Applies anti-poaching coordinate masking based on user role.
     * Unauthenticated, driver, and public roles receive coordinates rounded to 2 decimal places.
     * 
     * @param float|null $lat Latitude
     * @param float|null $lon Longitude
     * @param string $role Requesting role
     * @return array Masked or unmasked coordinate structure
     */
    public static function maskCoordinatesForRole($lat, $lon, $role) {
        if ($lat === null || $lon === null) {
            return [
                'latitude' => null,
                'longitude' => null,
                'precision_level' => 'unknown'
            ];
        }

        if (in_array($role, self::UNMASKED_COORDINATE_ROLES, true)) {
            return [
                'latitude' => (float)$lat,
                'longitude' => (float)$lon,
                'precision_level' => 'raw_telemetry'
            ];
        }

        // Masked coordinates for anti-poaching security (~1.1 km footprint)
        return [
            'latitude' => round((float)$lat, 2),
            'longitude' => round((float)$lon, 2),
            'precision_level' => 'masked_anti_poaching',
            'notice' => 'Coordinates blurred to 2 decimal places (~1.1 km) for anti-poaching security.'
        ];
    }

    /**
     * Retrieves paginated, status-filtered reports submitted by a specific reporter.
     * Enforces anti-poaching coordinate masking based on user role.
     * 
     * @param int $reporterId Authenticated user ID
     * @param string|null $statusFilter Optional status filter
     * @param int $page 1-based page index (default 1)
     * @param int $perPage Items per page (default 15, max 50)
     * @param string $userRole Requesting user role for coordinate privacy
     * @return array Standardized result envelope with 'reports' and 'meta'
     */
    public static function getReportsByReporter($reporterId, $statusFilter = null, $page = 1, $perPage = 15, $userRole = 'reporter') {
        $db = Database::getInstance()->getConnection();
        
        $reporterId = (int)$reporterId;
        $page = max(1, (int)$page);
        $perPage = max(1, min(50, (int)$perPage));
        $offset = ($page - 1) * $perPage;

        // Canonical verification statuses
        $allowedStatuses = ['Pending', 'Needs Information', 'Clarification Submitted', 'Verified', 'Rejected'];
        $normalizedFilter = null;
        if ($statusFilter !== null && $statusFilter !== '' && strtolower($statusFilter) !== 'all') {
            foreach ($allowedStatuses as $st) {
                if (strcasecmp($st, trim($statusFilter)) === 0) {
                    $normalizedFilter = $st;
                    break;
                }
            }
        }

        // 1. Compute status counts for reporter across all statuses
        $countStmt = $db->prepare("
            SELECT report_status, COUNT(*) as cnt
            FROM elephant_reports
            WHERE reported_by = :reporter_id
            GROUP BY report_status
        ");
        $countStmt->execute([':reporter_id' => $reporterId]);
        $rawCounts = $countStmt->fetchAll(PDO::FETCH_KEY_PAIR);

        $statusCounts = [
            'all' => 0,
            'pending' => (int)($rawCounts['Pending'] ?? 0),
            'needs_information' => (int)($rawCounts['Needs Information'] ?? 0),
            'clarification_submitted' => (int)($rawCounts['Clarification Submitted'] ?? 0),
            'verified' => (int)($rawCounts['Verified'] ?? 0),
            'rejected' => (int)($rawCounts['Rejected'] ?? 0)
        ];
        $statusCounts['all'] = array_sum($statusCounts);

        // 2. Total records for current query
        if ($normalizedFilter !== null) {
            $totalRecords = (int)($rawCounts[$normalizedFilter] ?? 0);
        } else {
            $totalRecords = $statusCounts['all'];
        }
        $totalPages = $totalRecords > 0 ? (int)ceil($totalRecords / $perPage) : 1;

        // 3. Main paginated query with LEFT JOIN for sections and clarification subquery
        $sql = "
            SELECT r.*, rs.section_name, rs.district,
                   (SELECT MAX(created_at) FROM report_clarifications WHERE report_id = r.id) as last_clarification_at
            FROM elephant_reports r
            LEFT JOIN railway_sections rs ON r.section_id = rs.id
            WHERE r.reported_by = :reporter_id
        ";
        $params = [':reporter_id' => $reporterId];

        if ($normalizedFilter !== null) {
            $sql .= " AND r.report_status = :status_filter";
            $params[':status_filter'] = $normalizedFilter;
        }

        $sql .= " ORDER BY r.created_at DESC LIMIT :limit OFFSET :offset";

        $stmt = $db->prepare($sql);
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->bindValue(':limit', (int)$perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', (int)$offset, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $reports = [];
        foreach ($rows as $r) {
            $obsCoords = self::maskCoordinatesForRole(
                $r['observer_latitude'] !== null ? (float)$r['observer_latitude'] : null,
                $r['observer_longitude'] !== null ? (float)$r['observer_longitude'] : null,
                $userRole
            );
            $animCoords = self::maskCoordinatesForRole(
                $r['estimated_animal_latitude'] !== null ? (float)$r['estimated_animal_latitude'] : null,
                $r['estimated_animal_longitude'] !== null ? (float)$r['estimated_animal_longitude'] : null,
                $userRole
            );

            $reports[] = [
                'id' => (int)$r['id'],
                'client_report_uuid' => $r['client_report_uuid'],
                'sighting_date' => $r['sighting_date'],
                'sighting_time' => $r['sighting_time'],
                'sighting_time_precision' => $r['sighting_time_precision'],
                'sighting_time_window' => $r['sighting_time_window'] ?? null,
                'sighting_time_uncertainty_minutes' => $r['sighting_time_uncertainty_minutes'] !== null ? (int)$r['sighting_time_uncertainty_minutes'] : null,
                'elephant_count' => (int)$r['elephant_count'],
                'observation_category' => $r['observation_category'],
                'urgency_level' => $r['urgency_level'],
                'report_status' => $r['report_status'],
                'section_id' => $r['section_id'] !== null ? (int)$r['section_id'] : null,
                'section_name' => $r['section_name'],
                'district' => $r['district'],
                'nearest_milestone_ref' => $r['nearest_milestone_ref'],
                'image_path' => $r['image_path'],
                'has_pending_inquiry' => ($r['report_status'] === 'Needs Information'),
                'last_clarification_at' => $r['last_clarification_at'],
                'created_at' => $r['created_at'],
                'updated_at' => $r['updated_at'] ?? $r['created_at'],
                'observer_location' => $obsCoords,
                'estimated_animal_location' => $animCoords,
                'description' => $r['description']
            ];
        }

        return [
            'reports' => $reports,
            'meta' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total_records' => $totalRecords,
                'total_pages' => $totalPages,
                'status_counts' => $statusCounts
            ]
        ];
    }
}
