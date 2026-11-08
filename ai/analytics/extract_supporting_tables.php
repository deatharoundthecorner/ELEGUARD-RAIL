<?php
/**
 * EleGuard Rail - Milestone 9A: Supporting Tables Extractor
 * READ-ONLY audit tool.
 */

require_once __DIR__ . '/../../app/core/Database.php';

$db = Database::getInstance()->getConnection();

$data = [
    'extracted_at' => date('c'),
    'railway_sections' => $db->query("SELECT * FROM railway_sections ORDER BY id")->fetchAll(PDO::FETCH_ASSOC),
    'hotspots' => $db->query("SELECT * FROM hotspots ORDER BY id")->fetchAll(PDO::FETCH_ASSOC),
    'incident_history' => $db->query("SELECT * FROM incident_history ORDER BY id")->fetchAll(PDO::FETCH_ASSOC),
    'alerts' => $db->query("SELECT * FROM alerts ORDER BY id")->fetchAll(PDO::FETCH_ASSOC),
    'driver_acknowledgements' => $db->query("SELECT * FROM driver_acknowledgements ORDER BY id")->fetchAll(PDO::FETCH_ASSOC),
    'system_logs_summary' => [
        'total_events' => (int)$db->query("SELECT count(*) FROM system_logs")->fetchColumn(),
        'actions' => $db->query("SELECT action, count(*) as count FROM system_logs GROUP BY action ORDER BY count DESC")->fetchAll(PDO::FETCH_ASSOC),
        'earliest' => $db->query("SELECT min(created_at) FROM system_logs")->fetchColumn(),
        'latest' => $db->query("SELECT max(created_at) FROM system_logs")->fetchColumn()
    ]
];

$outputFile = __DIR__ . '/../../docs/phase9/supporting_tables.json';
file_put_contents($outputFile, json_encode($data, JSON_PRETTY_PRINT));
echo "Successfully extracted supporting tables to {$outputFile}\n";
