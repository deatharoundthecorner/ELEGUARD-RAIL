<?php
/**
 * EleGuard Rail - Milestone 9A: Elephant Reports & Proximity Extractor
 * READ-ONLY audit tool.
 */

require_once __DIR__ . '/../../app/core/Database.php';
require_once __DIR__ . '/../../app/services/ProximityService.php';

$db = Database::getInstance()->getConnection();

$query = "
    SELECT 
        er.id,
        er.section_id AS assigned_section_id,
        rs.section_name AS assigned_section_name,
        rs.district AS assigned_section_district,
        rs.start_location AS assigned_section_start,
        rs.end_location AS assigned_section_end,
        rs.risk_level AS assigned_section_risk,
        er.reported_by AS reporter_user_id,
        u.role AS reporter_role,
        er.elephant_count,
        er.location_description,
        er.latitude,
        er.longitude,
        er.gps_accuracy_meters,
        er.urgency_level,
        er.risk_score,
        er.report_status,
        er.description,
        er.image_path,
        er.ai_status,
        er.ai_detected_elephant,
        er.ai_confidence,
        er.ai_estimated_count,
        er.ai_model_version,
        er.ai_detection_notes,
        er.ai_processed_at,
        er.is_potential_duplicate,
        er.duplicate_cluster_id,
        er.created_at,
        er.updated_at
    FROM elephant_reports er
    LEFT JOIN railway_sections rs ON er.section_id = rs.id
    LEFT JOIN users u ON er.reported_by = u.id
    ORDER BY er.id ASC
";

$stmt = $db->query($query);
$reports = $stmt->fetchAll(PDO::FETCH_ASSOC);

$extracted = [];
$publicDir = realpath(__DIR__ . '/../../public');

foreach ($reports as $r) {
    $hasGps = ($r['latitude'] !== null && $r['latitude'] !== '' && $r['longitude'] !== null && $r['longitude'] !== '');
    
    // Proximity calculation
    $prox = null;
    if ($hasGps) {
        $prox = ProximityService::calculateRailwayProximity(
            $r['latitude'],
            $r['longitude'],
            $r['gps_accuracy_meters']
        );
    } else {
        $prox = [
            'has_coordinates' => false,
            'is_valid' => false,
            'status_message' => 'Coordinates not provided (null or empty).'
        ];
    }

    // Image file check
    $imageExists = false;
    $imageSha256 = null;
    $imageSizeBytes = null;
    if (!empty($r['image_path'])) {
        $fullImgPath = $publicDir . DIRECTORY_SEPARATOR . ltrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $r['image_path']), DIRECTORY_SEPARATOR);
        if (file_exists($fullImgPath)) {
            $imageExists = true;
            $imageSizeBytes = filesize($fullImgPath);
            $imageSha256 = hash_file('sha256', $fullImgPath);
        }
    }

    // Annotated image check
    $annotatedExists = false;
    $annotatedSha256 = null;
    if (!empty($r['image_path'])) {
        $annotatedRel = dirname($r['image_path']) . '/annotated_' . basename($r['image_path']);
        $fullAnnotated = $publicDir . DIRECTORY_SEPARATOR . ltrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $annotatedRel), DIRECTORY_SEPARATOR);
        if (file_exists($fullAnnotated)) {
            $annotatedExists = true;
            $annotatedSha256 = hash_file('sha256', $fullAnnotated);
        }
    }

    $r['has_gps'] = $hasGps;
    $r['proximity'] = $prox;
    $r['image_meta'] = [
        'exists' => $imageExists,
        'size_bytes' => $imageSizeBytes,
        'sha256' => $imageSha256,
        'annotated_exists' => $annotatedExists,
        'annotated_sha256' => $annotatedSha256
    ];

    $extracted[] = $r;
}

$outputFile = __DIR__ . '/../../docs/phase9/elephant_reports_extracted.json';
file_put_contents($outputFile, json_encode($extracted, JSON_PRETTY_PRINT));
echo "Successfully extracted " . count($extracted) . " elephant reports with proximity calculations to {$outputFile}\n";
