<?php
/**
 * EleGuard Rail - Milestone 9B: Hotspot Inventory & Geodesic Audit
 * 
 * Strict Read-Only Script.
 * Analyzes all records in `hotspots` table, calculates geodesic distance
 * to authentic railway LineString geometries, validates coordinate plausibility,
 * detects duplicates, and checks consistency with assigned railway sections.
 */

require_once __DIR__ . '/../../app/core/Database.php';
require_once __DIR__ . '/../../app/services/ProximityService.php';

try {
    $db = Database::getInstance()->getConnection();
} catch (Exception $e) {
    fwrite(STDERR, "Database connection failed: " . $e->getMessage() . "\n");
    exit(1);
}

// 1. Fetch all hotspots joined with railway_sections
$stmt = $db->query("
    SELECT 
        h.id,
        h.section_id,
        h.hotspot_name,
        h.latitude,
        h.longitude,
        h.risk_level,
        h.reason,
        h.created_at,
        rs.section_name,
        rs.district,
        rs.start_location,
        rs.end_location,
        rs.risk_level AS section_risk_level
    FROM hotspots h
    LEFT JOIN railway_sections rs ON h.section_id = rs.id
    ORDER BY h.id ASC
");

$hotspotRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
$totalHotspots = count($hotspotRows);

// 2. Load railway geometries
$railwayData = ProximityService::loadRailwayGeometry();
$features = $railwayData['features'] ?? [];

// 3. Process each hotspot
$analyzedHotspots = [];
$coordMap = [];
$duplicatesFound = [];

// Study area corridor bounds: North-Central & Eastern Sri Lanka railway network
$corridorBounds = [
    'min_lat' => 7.80,
    'max_lat' => 8.40,
    'min_lon' => 80.20,
    'max_lon' => 81.60
];

foreach ($hotspotRows as $row) {
    $id = (int)$row['id'];
    $lat = (float)$row['latitude'];
    $lon = (float)$row['longitude'];
    $assignedSectionId = (int)$row['section_id'];

    // Validation
    $coordValidation = ProximityService::validateCoordinate($lat, $lon);
    
    // Duplicate check (to 6 decimal places ~10cm)
    $coordKey = sprintf("%.6f,%.6f", $lat, $lon);
    if (isset($coordMap[$coordKey])) {
        $duplicatesFound[] = [
            'hotspot_id' => $id,
            'duplicate_of_id' => $coordMap[$coordKey],
            'coordinates' => [$lat, $lon]
        ];
    } else {
        $coordMap[$coordKey] = $id;
    }

    // Study corridor check
    $inStudyCorridor = ($lat >= $corridorBounds['min_lat'] && $lat <= $corridorBounds['max_lat'] &&
                        $lon >= $corridorBounds['min_lon'] && $lon <= $corridorBounds['max_lon']);

    // Distance to each railway section feature
    $sectionDistances = [];
    $minDist = INF;
    $nearestSectionId = null;
    $nearestSectionName = null;
    $closestTrackPoint = null;

    foreach ($features as $feat) {
        $secId = (int)($feat['properties']['section_id'] ?? 0);
        $coords = $feat['geometry']['coordinates'] ?? [];
        $res = ProximityService::pointToLineStringDistance($lat, $lon, $coords);
        
        $sectionDistances[$secId] = [
            'section_id' => $secId,
            'distance_meters' => round($res['distance'], 2),
            'distance_km' => round($res['distance'] / 1000.0, 3),
            'closest_point' => [
                'latitude' => round($res['closest_lat'], 6),
                'longitude' => round($res['closest_lon'], 6)
            ]
        ];

        if ($res['distance'] < $minDist) {
            $minDist = $res['distance'];
            $nearestSectionId = $secId;
            $closestTrackPoint = [
                'latitude' => round($res['closest_lat'], 6),
                'longitude' => round($res['closest_lon'], 6)
            ];
        }
    }

    // Section consistency check
    $isSectionConsistent = ($assignedSectionId === $nearestSectionId);
    $distanceToAssigned = isset($sectionDistances[$assignedSectionId]) ? $sectionDistances[$assignedSectionId]['distance_meters'] : null;

    $analyzedHotspots[] = [
        'id' => $id,
        'name' => $row['hotspot_name'],
        'risk_level' => $row['risk_level'],
        'reason' => $row['reason'],
        'created_at' => $row['created_at'],
        'latitude' => $lat,
        'longitude' => $lon,
        'is_coordinate_valid' => $coordValidation['valid'],
        'validation_reason' => $coordValidation['reason'],
        'in_study_corridor' => $inStudyCorridor,
        'assigned_section' => [
            'section_id' => $assignedSectionId,
            'section_name' => $row['section_name'],
            'district' => $row['district'],
            'start_location' => $row['start_location'],
            'end_location' => $row['end_location'],
            'risk_level' => $row['section_risk_level'],
            'distance_to_track_meters' => $distanceToAssigned,
            'distance_to_track_km' => $distanceToAssigned !== null ? round($distanceToAssigned / 1000.0, 3) : null
        ],
        'geometric_nearest_section' => [
            'section_id' => $nearestSectionId,
            'distance_to_track_meters' => round($minDist, 2),
            'distance_to_track_km' => round($minDist / 1000.0, 3),
            'closest_track_point' => $closestTrackPoint
        ],
        'is_section_consistent' => $isSectionConsistent,
        'section_mismatch_details' => $isSectionConsistent ? null : [
            'assigned_section_id' => $assignedSectionId,
            'nearest_geometric_section_id' => $nearestSectionId,
            'distance_difference_meters' => round(($distanceToAssigned ?? 0) - $minDist, 2)
        ],
        'distances_to_all_sections' => $sectionDistances
    ];
}

// 4. Pairwise distances between all hotspots
$pairwiseDistances = [];
for ($i = 0; $i < $totalHotspots; $i++) {
    for ($j = $i + 1; $j < $totalHotspots; $j++) {
        $hA = $analyzedHotspots[$i];
        $hB = $analyzedHotspots[$j];
        $dist = ProximityService::haversineDistance($hA['latitude'], $hA['longitude'], $hB['latitude'], $hB['longitude']);
        $pairwiseDistances[] = [
            'hotspot_a' => [
                'id' => $hA['id'],
                'name' => $hA['name']
            ],
            'hotspot_b' => [
                'id' => $hB['id'],
                'name' => $hB['name']
            ],
            'distance_meters' => round($dist, 2),
            'distance_km' => round($dist / 1000.0, 3)
        ];
    }
}

// 5. Structure final inventory report
$inventoryReport = [
    'audit_metadata' => [
        'generated_at' => date('c'),
        'total_hotspots_audited' => $totalHotspots,
        'study_corridor_bounds' => $corridorBounds,
        'railway_features_loaded' => count($features),
        'duplicates_detected' => count($duplicatesFound),
        'duplicate_details' => $duplicatesFound
    ],
    'hotspots' => $analyzedHotspots,
    'pairwise_distances' => $pairwiseDistances
];

$outputFile = __DIR__ . '/../../docs/phase9/hotspot_inventory.json';
file_put_contents($outputFile, json_encode($inventoryReport, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

echo "=== EleGuard Rail - Milestone 9B Hotspot Inventory Audit ===\n";
echo "Total hotspots audited: {$totalHotspots}\n";
echo "Duplicates detected: " . count($duplicatesFound) . "\n";
foreach ($analyzedHotspots as $h) {
    echo sprintf(
        "Hotspot %d [%s]: Lat %.4f, Lon %.4f | Assigned Sec: %d | Nearest Sec: %d (Dist: %.1fm) | Consistent: %s\n",
        $h['id'],
        $h['name'],
        $h['latitude'],
        $h['longitude'],
        $h['assigned_section']['section_id'],
        $h['geometric_nearest_section']['section_id'],
        $h['geometric_nearest_section']['distance_to_track_meters'],
        $h['is_section_consistent'] ? 'YES' : 'NO'
    );
}
echo "Exported full audit JSON to: {$outputFile}\n";
