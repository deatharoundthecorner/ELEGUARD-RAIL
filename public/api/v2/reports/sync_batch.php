<?php
/**
 * EleGuard Rail — API v2: Offline Batch Synchronization
 * 
 * Endpoint: POST /api/v2/reports/sync-batch
 * Standards: HTTP 207 Multi-Status, RFC 9457 Errors
 * Server Guardrails: Max 50 Reports / Max 10 MB Request Body (API-02)
 */

require_once __DIR__ . '/api_helper.php';

init_api_headers();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    send_rfc9457_error(
        405,
        'https://eleguard.rail.gov.lk/errors/method-not-allowed',
        'Method Not Allowed',
        'This endpoint only accepts HTTP POST requests.'
    );
}

$allowedRoles = ['reporter', 'wildlife_officer', 'control_officer', 'admin'];
$currentUser = require_api_auth($allowedRoles);

$input = get_json_input();
$rawBytes = strlen($input['raw']);
$payload = $input['data'];

require_api_csrf($payload);

$result = FieldReportingService::syncBatch($payload, (int)$currentUser['id'], $currentUser['role'], $rawBytes);

if ($result['success']) {
    send_api_json_response($result['status_code'], [
        'status' => 'multi_status',
        'data' => $result['data']
    ]);
} else {
    $err = $result['error'];
    send_rfc9457_error(
        $result['status_code'],
        $err['type'],
        $err['title'],
        $err['detail'],
        $err['invalid_params'] ?? []
    );
}
