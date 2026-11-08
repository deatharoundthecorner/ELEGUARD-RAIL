<?php
/**
 * EleGuard Rail - Milestone 9A: Database Schema & Inventory Extractor
 * READ-ONLY audit tool.
 */

require_once __DIR__ . '/../../app/core/Database.php';

try {
    $db = Database::getInstance()->getConnection();
} catch (Exception $e) {
    echo json_encode(['error' => 'Database connection failed: ' . $e->getMessage()]);
    exit(1);
}

// 1. Get all tables in current database
$tablesStmt = $db->query("
    SELECT TABLE_NAME, TABLE_COMMENT, TABLE_ROWS, DATA_LENGTH, INDEX_LENGTH, CREATE_TIME, UPDATE_TIME, ENGINE
    FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE()
    ORDER BY TABLE_NAME
");
$tables = $tablesStmt->fetchAll(PDO::FETCH_ASSOC);

$inventory = [
    'database_name' => $db->query("SELECT DATABASE()")->fetchColumn(),
    'extracted_at' => date('c'),
    'table_count' => count($tables),
    'tables' => []
];

foreach ($tables as $t) {
    $tName = $t['TABLE_NAME'];

    // Columns
    $colStmt = $db->prepare("
        SELECT COLUMN_NAME, ORDINAL_POSITION, COLUMN_DEFAULT, IS_NULLABLE, DATA_TYPE, 
               CHARACTER_MAXIMUM_LENGTH, NUMERIC_PRECISION, NUMERIC_SCALE, COLUMN_TYPE, COLUMN_KEY, EXTRA, COLUMN_COMMENT
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :tname
        ORDER BY ORDINAL_POSITION
    ");
    $colStmt->execute([':tname' => $tName]);
    $columns = $colStmt->fetchAll(PDO::FETCH_ASSOC);

    // Indexes
    $idxStmt = $db->prepare("
        SELECT INDEX_NAME, NON_UNIQUE, SEQ_IN_INDEX, COLUMN_NAME, COLLATION, CARDINALITY, INDEX_TYPE
        FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :tname
        ORDER BY INDEX_NAME, SEQ_IN_INDEX
    ");
    $idxStmt->execute([':tname' => $tName]);
    $indexesRaw = $idxStmt->fetchAll(PDO::FETCH_ASSOC);
    $indexes = [];
    foreach ($indexesRaw as $idx) {
        $name = $idx['INDEX_NAME'];
        if (!isset($indexes[$name])) {
            $indexes[$name] = [
                'name' => $name,
                'unique' => ($idx['NON_UNIQUE'] == 0),
                'type' => $idx['INDEX_TYPE'],
                'columns' => []
            ];
        }
        $indexes[$name]['columns'][] = $idx['COLUMN_NAME'];
    }

    // Foreign Keys
    $fkStmt = $db->prepare("
        SELECT CONSTRAINT_NAME, COLUMN_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME
        FROM information_schema.KEY_COLUMN_USAGE
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :tname AND REFERENCED_TABLE_NAME IS NOT NULL
        ORDER BY CONSTRAINT_NAME, ORDINAL_POSITION
    ");
    $fkStmt->execute([':tname' => $tName]);
    $foreignKeys = $fkStmt->fetchAll(PDO::FETCH_ASSOC);

    // Actual live row count
    $liveCount = (int)$db->query("SELECT count(*) FROM `{$tName}`")->fetchColumn();

    $inventory['tables'][$tName] = [
        'name' => $tName,
        'engine' => $t['ENGINE'],
        'comment' => $t['TABLE_COMMENT'],
        'live_row_count' => $liveCount,
        'data_length_bytes' => (int)$t['DATA_LENGTH'],
        'index_length_bytes' => (int)$t['INDEX_LENGTH'],
        'create_time' => $t['CREATE_TIME'],
        'update_time' => $t['UPDATE_TIME'],
        'columns' => $columns,
        'indexes' => array_values($indexes),
        'foreign_keys' => $foreignKeys
    ];
}

$outputFile = __DIR__ . '/../../docs/phase9/database_inventory.json';
file_put_contents($outputFile, json_encode($inventory, JSON_PRETTY_PRINT));
echo "Successfully extracted database inventory for {$inventory['table_count']} tables to {$outputFile}\n";
