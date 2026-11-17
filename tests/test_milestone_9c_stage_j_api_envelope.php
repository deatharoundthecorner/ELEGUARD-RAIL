<?php
/**
 * EleGuard Rail — Milestone 9C Stage J Phase J.4
 * API Response Envelope Standardization Verification Suite
 * Change Reference: ER-M9C-STAGE-J-J4-006
 * 
 * Verifies 16 Core Invariants:
 * 1.  Analytics success envelope (success: true, status: 'success', timestamp, data)
 * 2.  Analytics error envelope (success: false, error.code, error.message)
 * 3.  API v2 success ingestion envelope (success: true, status: 'success', data.report_id, HTTP 201)
 * 4.  API v2 replay deduplication envelope (success: true, status: 'success', is_replay: true, HTTP 200)
 * 5.  RFC 9457 validation error envelope (success: false, type, title, detail, invalid_params, HTTP 422)
 * 6.  RFC 9457 authorization/forbidden error (status: 403 numeric, success: false, title, detail)
 * 7.  Numeric RFC 9457 status verification (is_int($res['status']) === true, strictly non-string)
 * 8.  Correct Content-Type headers (application/json vs application/problem+json)
 * 9.  GIS aggregate success envelope (success: true, status: 'success', HTTP 200)
 * 10. GIS error envelope (success: false, status: 'error', code: 403, HTTP 403)
 * 11. Root-level GeoJSON FeatureCollection preservation (routes, hotspots, reports, alerts unnested)
 * 12. HTTP 207 Multi-Status preservation (status: 'multi_status', batch_status, HTTP 207)
 * 13. Synced acknowledgement verification in batch (status: 'synced', http_status: 201)
 * 14. Replayed acknowledgement verification in batch (status: 'replayed', http_status: 200, is_replay: true)
 * 15. Rejected acknowledgement verification in batch (status: 'rejected', http_status: 422, error)
 * 16. Uncertain / topology ambiguity acknowledgement in batch (status: 'uncertain' / topology_ambiguity)
 */

define('ELEGUARD_TEST_SUITE', true);
define('ELEGUARD_TEST_ENVIRONMENT', true);

$baseDir = str_replace('\\', '/', dirname(__DIR__));
require_once $baseDir . '/app/config/database.php';
require_once $baseDir . '/tests/bootstrap.php';

// -----------------------------------------------------------------------------
// Target Database Resolution & Safety Verification
// -----------------------------------------------------------------------------
$targetDb = getenv('DB_NAME') ?: ($_ENV['DB_NAME'] ?? null);
$wasSelfGenerated = false;
if (empty($targetDb) || $targetDb === 'eleguard_rail_test' || $targetDb === 'eleguard_rail') {
    $targetDb = 'eleguard_test_tmp_' . bin2hex(random_bytes(6));
    putenv("DB_NAME={$targetDb}");
    $_ENV['DB_NAME'] = $targetDb;
    $wasSelfGenerated = true;
}

validateTestDatabaseTarget($targetDb);

$dbHost = defined('DB_HOST') ? DB_HOST : '127.0.0.1';
$dbPort = defined('DB_PORT') ? (int)DB_PORT : 3308;

$adminConfigFile = getenv('USERPROFILE') ? getenv('USERPROFILE') . '/.eleguard-test-admin.php' : 'C:/Users/user/.eleguard-test-admin.php';
if (file_exists($adminConfigFile)) {
    $adminConfig = require $adminConfigFile;
    $adminUser = $adminConfig['admin_user'];
    $adminPass = $adminConfig['admin_pass'];
} else {
    $adminUser = defined('DB_USER') ? DB_USER : 'root';
    $adminPass = defined('DB_PASS') ? DB_PASS : '';
}

$adminPdo = new PDO("mysql:host={$dbHost};port={$dbPort}", $adminUser, $adminPass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
]);

ensureTestDatabaseExists($adminPdo, $targetDb);

// Record baseline operational database state to verify zero modification
$opCountsBefore = [];
$opTables = ['elephant_reports', 'report_verifications', 'report_clarifications', 'section_attribution_audits', 'alerts', 'users'];
foreach ($opTables as $tbl) {
    try {
        $opCountsBefore[$tbl] = (int)$adminPdo->query("SELECT COUNT(*) FROM `eleguard_rail`.`{$tbl}`")->fetchColumn();
    } catch (Exception $e) {
        $opCountsBefore[$tbl] = -1;
    }
}

// -----------------------------------------------------------------------------
// Test Output Helpers
// -----------------------------------------------------------------------------
function pass($desc) { echo "  \033[32m[PASS]\033[0m " . $desc . PHP_EOL; }
function fail($desc, $err = '') { 
    echo "  \033[31m[FAIL]\033[0m " . $desc . ($err ? " — \033[33m" . $err . "\033[0m" : "") . PHP_EOL; 
    $GLOBALS['testsFailed']++;
}
function section($title) { echo PHP_EOL . "--- " . strtoupper($title) . " ---" . PHP_EOL; }

$testsRun = 0;
$testsFailed = 0;

function assertCondition($cond, $desc, $err = '') {
    $GLOBALS['testsRun']++;
    if ($cond) {
        pass($desc);
    } else {
        fail($desc, $err);
    }
}

echo "================================================================================" . PHP_EOL;
echo " EleGuard Rail — Stage J Phase J.4 API Response Envelope Standardization Suite" . PHP_EOL;
echo " Target Disposable Database: {$targetDb}" . PHP_EOL;
echo "================================================================================" . PHP_EOL;

/**
 * Executes a PHP code snippet via php-cgi to capture genuine HTTP status code,
 * response headers (Content-Type), and JSON response body.
 */
function runCgiRequest($phpCode) {
    global $targetDb, $baseDir;

    $wrapper = "<?php\n";
    $wrapper .= "define('ELEGUARD_TEST_ENVIRONMENT', true);\n";
    $wrapper .= "putenv('DB_NAME=" . addslashes($targetDb) . "');\n";
    $wrapper .= "\$_ENV['DB_NAME'] = '" . addslashes($targetDb) . "';\n";
    $wrapper .= "ini_set('display_errors', '0');\n";
    $wrapper .= "error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING);\n";
    $wrapper .= $phpCode;

    $tempFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'test_envelope_' . uniqid() . '.php';
    file_put_contents($tempFile, $wrapper);

    $phpCgi = 'C:\\xampp\\php\\php-cgi.exe';
    if (!file_exists($phpCgi)) {
        $phpCgi = 'php-cgi';
    }

    $cmd = "\"{$phpCgi}\" \"{$tempFile}\" 2>&1";
    $output = shell_exec($cmd);
    @unlink($tempFile);

    // Split headers and body
    $parts = explode("\r\n\r\n", $output, 2);
    if (count($parts) < 2) {
        $parts = explode("\n\n", $output, 2);
    }

    $rawHeaders = $parts[0] ?? '';
    $rawBody = $parts[1] ?? '';

    // Extract HTTP status code
    $statusCode = 200;
    if (preg_match('/Status:\s*(\d+)/i', $rawHeaders, $mStatus)) {
        $statusCode = (int)$mStatus[1];
    }

    // Extract Content-Type
    $contentType = '';
    if (preg_match('/Content-Type:\s*([^;\r\n]+)/i', $rawHeaders, $mType)) {
        $contentType = trim($mType[1]);
    }

    // Decode JSON body
    $json = json_decode($rawBody, true);

    return [
        'status_code'  => $statusCode,
        'content_type' => $contentType,
        'headers'      => $rawHeaders,
        'body'         => $json,
        'raw_body'     => $rawBody
    ];
}

// =============================================================================
// SECTION 1: Analytics API Response Envelope Standard
// =============================================================================
section("1. Analytics API Envelope Standard");

// Test 1: Analytics Success Envelope
$codeAnalyticsSuccess = <<<PHP
require_once "{$baseDir}/app/helpers/analytics_api.php";
analytics_api_success(['kpi_summary' => ['total_hotspots' => 12, 'risk_index' => 0.85]], 200);
PHP;

$res1 = runCgiRequest($codeAnalyticsSuccess);
assertCondition(
    $res1['status_code'] === 200 &&
    $res1['content_type'] === 'application/json' &&
    isset($res1['body']['success']) && $res1['body']['success'] === true &&
    isset($res1['body']['status']) && $res1['body']['status'] === 'success' &&
    isset($res1['body']['timestamp']) &&
    isset($res1['body']['data']['kpi_summary']['total_hotspots']) &&
    $res1['body']['data']['kpi_summary']['total_hotspots'] === 12,
    "Test 1: Analytics success envelope contains success: true, status: 'success', timestamp, and data",
    "Status: {$res1['status_code']}, Body: " . json_encode($res1['body'])
);

// Test 2: Analytics Error Envelope
$codeAnalyticsError = <<<PHP
require_once "{$baseDir}/app/helpers/analytics_api.php";
analytics_api_error('VALIDATION_ERROR', 'section_id must be a valid integer between 1 and 4.', 422);
PHP;

$res2 = runCgiRequest($codeAnalyticsError);
assertCondition(
    $res2['status_code'] === 422 &&
    $res2['content_type'] === 'application/json' &&
    isset($res2['body']['success']) && $res2['body']['success'] === false &&
    isset($res2['body']['error']['code']) && $res2['body']['error']['code'] === 'VALIDATION_ERROR' &&
    isset($res2['body']['error']['message']) && strpos($res2['body']['error']['message'], 'section_id') !== false,
    "Test 2: Analytics error envelope contains success: false, error.code, and error.message",
    "Status: {$res2['status_code']}, Body: " . json_encode($res2['body'])
);

// =============================================================================
// SECTION 2: API v2 Field Reporting Envelope Standard
// =============================================================================
section("2. API v2 Field Reporting Ingestion & Replay Standard");

// Test 3: API v2 Success Ingestion Envelope
$codeApiV2Success = <<<PHP
require_once "{$baseDir}/public/api/v2/reports/api_helper.php";
send_api_json_response(201, [
    'status' => 'success',
    'data' => [
        'report_id' => 9101,
        'client_uuid' => 'f47ac10b-58cc-4372-a567-0e02b2c3d479',
        'is_replay' => false,
        'server_timestamp' => date('c')
    ]
]);
PHP;

$res3 = runCgiRequest($codeApiV2Success);
assertCondition(
    $res3['status_code'] === 201 &&
    $res3['content_type'] === 'application/json' &&
    isset($res3['body']['success']) && $res3['body']['success'] === true &&
    isset($res3['body']['status']) && $res3['body']['status'] === 'success' &&
    isset($res3['body']['data']['report_id']) && $res3['body']['data']['report_id'] === 9101 &&
    isset($res3['body']['data']['is_replay']) && $res3['body']['data']['is_replay'] === false,
    "Test 3: API v2 success ingestion returns HTTP 201 with success: true, status: 'success', and data.report_id",
    "Status: {$res3['status_code']}, Body: " . json_encode($res3['body'])
);

// Test 4: API v2 Replay Deduplication Envelope
$codeApiV2Replay = <<<PHP
require_once "{$baseDir}/public/api/v2/reports/api_helper.php";
send_api_json_response(200, [
    'status' => 'success',
    'data' => [
        'report_id' => 9101,
        'client_uuid' => 'f47ac10b-58cc-4372-a567-0e02b2c3d479',
        'is_replay' => true,
        'server_timestamp' => date('c')
    ]
]);
PHP;

$res4 = runCgiRequest($codeApiV2Replay);
assertCondition(
    $res4['status_code'] === 200 &&
    $res4['content_type'] === 'application/json' &&
    isset($res4['body']['success']) && $res4['body']['success'] === true &&
    isset($res4['body']['status']) && $res4['body']['status'] === 'success' &&
    isset($res4['body']['data']['is_replay']) && $res4['body']['data']['is_replay'] === true,
    "Test 4: API v2 replay deduplication returns HTTP 200 with success: true, status: 'success', and is_replay: true",
    "Status: {$res4['status_code']}, Body: " . json_encode($res4['body'])
);

// =============================================================================
// SECTION 3: RFC 9457 Problem Details Standard
// =============================================================================
section("3. RFC 9457 Problem Details Standard");

// Test 5: RFC 9457 Validation Error Envelope
$codeRfc9457Validation = <<<PHP
require_once "{$baseDir}/public/api/v2/reports/api_helper.php";
send_rfc9457_error(
    422,
    'https://eleguard.rail.gov.lk/errors/validation',
    'Validation Error',
    'The submitted report payload failed validation.',
    [
        'elephant_count' => 'Elephant count must be an integer between 1 and 200',
        'section_id' => 'Target railway section does not exist'
    ],
    '/api/v2/reports/submit'
);
PHP;

$res5 = runCgiRequest($codeRfc9457Validation);
assertCondition(
    $res5['status_code'] === 422 &&
    $res5['content_type'] === 'application/problem+json' &&
    isset($res5['body']['success']) && $res5['body']['success'] === false &&
    isset($res5['body']['type']) && $res5['body']['type'] === 'https://eleguard.rail.gov.lk/errors/validation' &&
    isset($res5['body']['title']) && $res5['body']['title'] === 'Validation Error' &&
    isset($res5['body']['status']) && $res5['body']['status'] === 422 &&
    isset($res5['body']['detail']) &&
    isset($res5['body']['invalid_params']['elephant_count']) &&
    isset($res5['body']['invalid_params']['section_id']),
    "Test 5: RFC 9457 validation error returns HTTP 422 with success: false, type, title, detail, and invalid_params map",
    "Status: {$res5['status_code']}, Body: " . json_encode($res5['body'])
);

// Test 6: RFC 9457 Authorization / Forbidden Error
$codeRfc9457Forbidden = <<<PHP
require_once "{$baseDir}/public/api/v2/reports/api_helper.php";
send_rfc9457_error(
    403,
    'https://eleguard.rail.gov.lk/errors/forbidden',
    'Forbidden Action',
    "Your role 'driver' is not authorized to access this resource.",
    [],
    '/api/v2/reports/12/verify'
);
PHP;

$res6 = runCgiRequest($codeRfc9457Forbidden);
assertCondition(
    $res6['status_code'] === 403 &&
    $res6['content_type'] === 'application/problem+json' &&
    isset($res6['body']['success']) && $res6['body']['success'] === false &&
    isset($res6['body']['status']) && $res6['body']['status'] === 403 &&
    isset($res6['body']['title']) && $res6['body']['title'] === 'Forbidden Action' &&
    strpos($res6['body']['detail'], 'driver') !== false,
    "Test 6: RFC 9457 authorization error returns HTTP 403 with status: 403 numeric, success: false, title, and detail",
    "Status: {$res6['status_code']}, Body: " . json_encode($res6['body'])
);

// Test 7: Strict RFC 9457 Numeric Status Type Verification
assertCondition(
    is_int($res5['body']['status']) && is_int($res6['body']['status']) &&
    !is_string($res5['body']['status']) && !is_string($res6['body']['status']) &&
    $res5['body']['status'] === 422 && $res6['body']['status'] === 403,
    "Test 7: RFC 9457 status member is strictly numeric integer and never string 'error' or numeric string",
    "res5 status type: " . gettype($res5['body']['status']) . ", res6 status type: " . gettype($res6['body']['status'])
);

// Test 8: Content-Type Header Verification
assertCondition(
    $res1['content_type'] === 'application/json' &&
    $res3['content_type'] === 'application/json' &&
    $res5['content_type'] === 'application/problem+json' &&
    $res6['content_type'] === 'application/problem+json',
    "Test 8: Success/Standard endpoints return application/json while RFC 9457 returns application/problem+json",
    "res1: {$res1['content_type']}, res3: {$res3['content_type']}, res5: {$res5['content_type']}, res6: {$res6['content_type']}"
);

// =============================================================================
// SECTION 4: GIS Geospatial API Envelope Standard
// =============================================================================
section("4. GIS Geospatial API Envelope Standard");

// Test 9: GIS Aggregate Success Envelope
$codeGisSuccess = <<<PHP
\$_SERVER['REQUEST_METHOD'] = 'GET';
\$_SERVER['SERVER_PROTOCOL'] = 'HTTP/1.1';
\$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
require_once "{$baseDir}/app/core/Session.php";
require_once "{$baseDir}/app/core/Auth.php";
Session::start();
Session::set('user_id', 2);
Session::set('user_role', 'wildlife_officer');
\$_GET = [];
require "{$baseDir}/public/api/gis_data.php";
PHP;

$res9 = runCgiRequest($codeGisSuccess);
assertCondition(
    $res9['status_code'] === 200 &&
    $res9['content_type'] === 'application/json' &&
    isset($res9['body']['success']) && $res9['body']['success'] === true &&
    isset($res9['body']['status']) && $res9['body']['status'] === 'success' &&
    isset($res9['body']['summary']) && is_array($res9['body']['summary']),
    "Test 9: GIS aggregate success response contains success: true, status: 'success', and summary object",
    "Status: {$res9['status_code']}, Body keys: " . implode(', ', array_keys($res9['body'] ?? []))
);

// Test 10: GIS Error Envelope (Access Denied for Driver)
$codeGisError = <<<PHP
\$_SERVER['REQUEST_METHOD'] = 'GET';
\$_SERVER['SERVER_PROTOCOL'] = 'HTTP/1.1';
\$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
require_once "{$baseDir}/app/core/Session.php";
require_once "{$baseDir}/app/core/Auth.php";
Session::start();
Session::set('user_id', 3);
Session::set('user_role', 'driver');
\$_GET = [];
require "{$baseDir}/public/api/gis_data.php";
PHP;

$res10 = runCgiRequest($codeGisError);
assertCondition(
    $res10['status_code'] === 403 &&
    $res10['content_type'] === 'application/json' &&
    isset($res10['body']['success']) && $res10['body']['success'] === false &&
    isset($res10['body']['status']) && $res10['body']['status'] === 'error' &&
    isset($res10['body']['code']) && $res10['body']['code'] === 403 &&
    isset($res10['body']['message']) && strpos($res10['body']['message'], 'Access denied') !== false,
    "Test 10: GIS error response contains success: false, status: 'error', code: 403, and safe message",
    "Status: {$res10['status_code']}, Body: " . json_encode($res10['body'])
);

// Test 11: Root-Level GeoJSON FeatureCollection Preservation
assertCondition(
    isset($res9['body']['routes']) && is_array($res9['body']['routes']) &&
    isset($res9['body']['hotspots']) && is_array($res9['body']['hotspots']) &&
    isset($res9['body']['reports']) && is_array($res9['body']['reports']) &&
    isset($res9['body']['alerts']) && is_array($res9['body']['alerts']) &&
    ($res9['body']['hotspots']['type'] ?? '') === 'FeatureCollection' &&
    ($res9['body']['reports']['type'] ?? '') === 'FeatureCollection' &&
    !isset($res9['body']['data']), // Explicitly verify GIS layers are NOT nested under data
    "Test 11: GIS payload preserves root-level GeoJSON FeatureCollections (routes, hotspots, reports, alerts) unnested",
    "Root keys: " . implode(', ', array_keys($res9['body'] ?? []))
);

// =============================================================================
// SECTION 5: HTTP 207 Multi-Status Batch Synchronization Standard
// =============================================================================
section("5. HTTP 207 Multi-Status Batch Synchronization Standard");

// Test 12: HTTP 207 Multi-Status Envelope Structure
$codeBatchMultiStatus = <<<PHP
require_once "{$baseDir}/public/api/v2/reports/api_helper.php";
send_api_json_response(207, [
    'status' => 'multi_status',
    'data' => [
        'total_processed' => 4,
        'synced_count' => 1,
        'replayed_count' => 1,
        'rejected_count' => 1,
        'uncertain_count' => 1,
        'acknowledgements' => [
            [
                'client_uuid' => '11111111-1111-1111-1111-111111111111',
                'status' => 'synced',
                'http_status' => 201,
                'report_id' => 9201,
                'sync_token' => 'st_synced_001'
            ],
            [
                'client_uuid' => '22222222-2222-2222-2222-222222222222',
                'status' => 'replayed',
                'http_status' => 200,
                'report_id' => 9202,
                'is_replay' => true,
                'sync_token' => 'st_replayed_002'
            ],
            [
                'client_uuid' => '33333333-3333-3333-3333-333333333333',
                'status' => 'rejected',
                'http_status' => 422,
                'error' => [
                    'type' => 'https://eleguard.rail.gov.lk/errors/validation',
                    'detail' => 'Coordinates out of Sri Lanka territory'
                ]
            ],
            [
                'client_uuid' => '44444444-4444-4444-4444-444444444444',
                'status' => 'uncertain',
                'http_status' => 201,
                'report_id' => 9204,
                'section_assignment_status' => 'topology_ambiguity',
                'requires_clarification' => true
            ]
        ]
    ]
]);
PHP;

$res12 = runCgiRequest($codeBatchMultiStatus);
assertCondition(
    $res12['status_code'] === 207 &&
    $res12['content_type'] === 'application/json' &&
    isset($res12['body']['status']) && $res12['body']['status'] === 'multi_status' &&
    isset($res12['body']['batch_status']) && $res12['body']['batch_status'] === 'partial' &&
    isset($res12['body']['success']) && $res12['body']['success'] === false &&
    isset($res12['body']['data']['acknowledgements']) && count($res12['body']['data']['acknowledgements']) === 4,
    "Test 12: HTTP 207 Multi-Status envelope returns wire status 207, status: 'multi_status', batch_status: 'partial', and success: false",
    "Status: {$res12['status_code']}, Body: " . json_encode($res12['body'])
);

// Test 13: Synced Item Acknowledgement Verification in Batch
$ackSynced = $res12['body']['data']['acknowledgements'][0] ?? [];
assertCondition(
    ($ackSynced['status'] ?? '') === 'synced' &&
    ($ackSynced['http_status'] ?? 0) === 201 &&
    ($ackSynced['report_id'] ?? 0) === 9201 &&
    !empty($ackSynced['sync_token']),
    "Test 13: Synced item acknowledgement conveys status: 'synced', http_status: 201, and valid report_id",
    "Ack: " . json_encode($ackSynced)
);

// Test 14: Replayed Item Acknowledgement Verification in Batch
$ackReplayed = $res12['body']['data']['acknowledgements'][1] ?? [];
assertCondition(
    ($ackReplayed['status'] ?? '') === 'replayed' &&
    ($ackReplayed['http_status'] ?? 0) === 200 &&
    ($ackReplayed['is_replay'] ?? false) === true &&
    ($ackReplayed['report_id'] ?? 0) === 9202,
    "Test 14: Replayed item acknowledgement conveys status: 'replayed', http_status: 200, and is_replay: true",
    "Ack: " . json_encode($ackReplayed)
);

// Test 15: Rejected Item Acknowledgement Verification in Batch
$ackRejected = $res12['body']['data']['acknowledgements'][2] ?? [];
assertCondition(
    ($ackRejected['status'] ?? '') === 'rejected' &&
    ($ackRejected['http_status'] ?? 0) === 422 &&
    isset($ackRejected['error']['type']) &&
    isset($ackRejected['error']['detail']),
    "Test 15: Rejected item acknowledgement conveys status: 'rejected', http_status: 422, and error details",
    "Ack: " . json_encode($ackRejected)
);

// Test 16: Uncertain / Topology Ambiguity Acknowledgement Verification in Batch
$ackUncertain = $res12['body']['data']['acknowledgements'][3] ?? [];
assertCondition(
    ($ackUncertain['status'] ?? '') === 'uncertain' &&
    ($ackUncertain['section_assignment_status'] ?? '') === 'topology_ambiguity' &&
    ($ackUncertain['requires_clarification'] ?? false) === true &&
    ($ackUncertain['report_id'] ?? 0) === 9204,
    "Test 16: Uncertain item acknowledgement conveys status: 'uncertain' with section_assignment_status: 'topology_ambiguity'",
    "Ack: " . json_encode($ackUncertain)
);

// =============================================================================
// Operational Database Invariant Verification
// =============================================================================
section("Operational Database Immutability Check");
$opCountsAfter = [];
$mutated = [];
foreach ($opTables as $tbl) {
    try {
        $count = (int)$adminPdo->query("SELECT COUNT(*) FROM `eleguard_rail`.`{$tbl}`")->fetchColumn();
        $opCountsAfter[$tbl] = $count;
        if ($count !== $opCountsBefore[$tbl]) {
            $mutated[] = "{$tbl} (before: {$opCountsBefore[$tbl]}, after: {$count})";
        }
    } catch (Exception $e) {
        $opCountsAfter[$tbl] = -1;
    }
}

assertCondition(
    empty($mutated),
    "Operational database eleguard_rail remained 100% immutable throughout test suite execution",
    "Mutations detected: " . implode(', ', $mutated)
);

// =============================================================================
// Teardown Ephemeral Database (When run standalone outside master test runner)
// =============================================================================
if (empty(getenv('ELEGUARD_MASTER_RUNNER'))) {
    try {
        safeTeardownTestDatabase($adminPdo, $targetDb);
    } catch (Exception $e) {
        // Suppress cleanup warning
    }
}

// =============================================================================
// Summary & Verdict
// =============================================================================
echo PHP_EOL . "================================================================================" . PHP_EOL;
echo " SUITE SUMMARY: {$testsRun} ran, " . ($testsRun - $testsFailed) . " passed, {$testsFailed} failed." . PHP_EOL;
if ($testsFailed === 0) {
    echo " \033[32mVERDICT: [PASS] ALL 16 API RESPONSE ENVELOPE INVARIANTS VERIFIED CLEANLY.\033[0m" . PHP_EOL;
    echo "================================================================================" . PHP_EOL;
    exit(0);
} else {
    echo " \033[31mVERDICT: [FAIL] {$testsFailed} TEST ASSERTIONS FAILED.\033[0m" . PHP_EOL;
    echo "================================================================================" . PHP_EOL;
    exit(1);
}
