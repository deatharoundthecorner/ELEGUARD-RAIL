<?php
/**
 * EleGuard Rail — Concurrency Worker Script
 * 
 * Invoked by test runner as an isolated background child process to simulate
 * genuine multi-process race conditions submitting identical client_report_uuid.
 * 
 * Milestone 9C Stage 4E.4.1-B (Remediation MED-02)
 */

define('ELEGUARD_TEST_SUITE', true);
define('ELEGUARD_TEST_ENVIRONMENT', true);

$targetDb = getenv('DB_NAME') ?: ($_ENV['DB_NAME'] ?? null);
if (empty($targetDb) || $targetDb === 'eleguard_rail_test' || $targetDb === 'eleguard_rail') {
    $targetDb = 'eleguard_test_tmp_' . bin2hex(random_bytes(6));
    putenv("DB_NAME={$targetDb}");
    $_ENV['DB_NAME'] = $targetDb;
}

$baseDir = str_replace('\\', '/', dirname(__DIR__, 2));
require_once $baseDir . '/tests/bootstrap.php';
validateTestDatabaseTarget($targetDb);

require_once $baseDir . '/app/config/database.php';
require_once $baseDir . '/app/core/Database.php';
require_once $baseDir . '/app/core/Session.php';
require_once $baseDir . '/app/core/Auth.php';
require_once $baseDir . '/app/services/FieldReportingService.php';

$uuid = $argv[1] ?? null;
$barrierFile = $argv[2] ?? null;
$outputFile = $argv[3] ?? null;
$processIndex = (int)($argv[4] ?? 1);

if (!$uuid || !$barrierFile || !$outputFile) {
    file_put_contents($outputFile, json_encode(['error' => 'Invalid worker arguments']));
    exit(1);
}

// Prepare observation payload
$payload = [
    'client_report_uuid' => $uuid,
    'submission_source' => 'mobile_pwa',
    'observation_category' => 'live_sighting',
    'elephant_count' => 2,
    'urgency_level' => 'High',
    'location_description' => "Concurrent race test observation location",
    'temporal' => [
        'sighting_date' => '2026-11-10',
        'sighting_time' => '17:30',
        'sighting_time_precision' => 'exact'
    ],
    'observer_location' => [
        'latitude' => 8.03450000,
        'longitude' => 80.89230000,
        'gps_accuracy_meters' => 12.0,
        'acquisition_method' => 'gps_device'
    ],
    'animal_location' => [
        'method' => 'triangulated_offset',
        'observation_distance_meters' => 60.0,
        'observation_bearing_degrees' => 45.0
    ]
];

// Signal ready: create ready flag
$readyFile = $barrierFile . ".ready.{$processIndex}";
touch($readyFile);

// Spin-wait at barrier until parent releases trigger file (or timeout after 5s)
$startWait = microtime(true);
while (!file_exists($barrierFile) && (microtime(true) - $startWait) < 5.0) {
    usleep(200); // 0.2ms resolution for tight sync
}

// Barrier released! Execute submitReport at identical instant
$t0 = microtime(true);
$result = FieldReportingService::submitReport($payload, 1, 'reporter');
$t1 = microtime(true);

$result['worker_id'] = $processIndex;
$result['execution_duration_ms'] = round(($t1 - $t0) * 1000, 3);
$result['invoked_at'] = $t0;

file_put_contents($outputFile, json_encode($result));
exit($result['success'] ? 0 : 2);
