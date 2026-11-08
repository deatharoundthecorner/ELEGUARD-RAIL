<?php
/**
 * EleGuard Rail - Milestone 9A: Independent Live Database Verification Script
 * STRICTLY READ-ONLY
 */

require_once __DIR__ . '/../../app/core/Database.php';

try {
    $db = Database::getInstance()->getConnection();
} catch (Exception $e) {
    echo json_encode(['error' => 'Connection failed: ' . $e->getMessage()]);
    exit(1);
}

// 1. Total row count
$totalCount = (int)$db->query("SELECT count(*) FROM elephant_reports")->fetchColumn();

// 2. Exact IDs
$ids = $db->query("SELECT id FROM elephant_reports ORDER BY id ASC")->fetchAll(PDO::FETCH_COLUMN);
$ids = array_map('intval', $ids);

// 3. Status breakdown
$statusStmt = $db->query("SELECT report_status, count(*) as cnt FROM elephant_reports GROUP BY report_status ORDER BY cnt DESC");
$statuses = $statusStmt->fetchAll(PDO::FETCH_KEY_PAIR);

// 4. GPS breakdown
$gpsCount = (int)$db->query("SELECT count(*) FROM elephant_reports WHERE latitude IS NOT NULL AND longitude IS NOT NULL")->fetchColumn();
$noGpsCount = (int)$db->query("SELECT count(*) FROM elephant_reports WHERE latitude IS NULL OR longitude IS NULL")->fetchColumn();

// 5. AI status breakdown
$aiStatusStmt = $db->query("SELECT ai_status, count(*) as cnt FROM elephant_reports GROUP BY ai_status ORDER BY cnt DESC");
$aiStatuses = $aiStatusStmt->fetchAll(PDO::FETCH_KEY_PAIR);

// 6. AI detection values
$aiProcessedRows = $db->query("
    SELECT id, report_status, ai_status, ai_detected_elephant, ai_confidence, ai_estimated_count, ai_model_version, location_description, image_path
    FROM elephant_reports 
    WHERE ai_status = 'processed'
    ORDER BY id ASC
")->fetchAll(PDO::FETCH_ASSOC);

// 7. Verify historical seed rows (IDs 1, 2, 3)
$historicalRows = $db->query("
    SELECT id, section_id, reported_by, elephant_count, location_description, urgency_level, risk_score, report_status, latitude, longitude
    FROM elephant_reports 
    WHERE id IN (1, 2, 3)
    ORDER BY id ASC
")->fetchAll(PDO::FETCH_ASSOC);

// 8. Columns and indexes verification
$colStmt = $db->query("
    SELECT COLUMN_NAME, DATA_TYPE, IS_NULLABLE, COLUMN_DEFAULT, COLUMN_KEY 
    FROM information_schema.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'elephant_reports'
    ORDER BY ORDINAL_POSITION
");
$columns = $colStmt->fetchAll(PDO::FETCH_ASSOC);

$idxStmt = $db->query("
    SELECT DISTINCT INDEX_NAME, NON_UNIQUE 
    FROM information_schema.STATISTICS 
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'elephant_reports'
");
$indexes = $idxStmt->fetchAll(PDO::FETCH_ASSOC);

$out = [
    'verified_at' => date('c'),
    'total_reports' => $totalCount,
    'ids' => $ids,
    'statuses' => $statuses,
    'gps' => [
        'has_gps' => $gpsCount,
        'no_gps' => $noGpsCount
    ],
    'ai_statuses' => $aiStatuses,
    'ai_processed_details' => $aiProcessedRows,
    'historical_seeds' => $historicalRows,
    'column_count' => count($columns),
    'columns' => array_column($columns, 'COLUMN_NAME'),
    'index_count' => count($indexes),
    'indexes' => $indexes
];

echo json_encode($out, JSON_PRETTY_PRINT);
