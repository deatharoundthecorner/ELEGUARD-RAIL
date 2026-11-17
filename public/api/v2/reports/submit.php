<?php
/**
 * EleGuard Rail — API v2: Submit Field Sighting Report
 * 
 * Endpoint: POST /api/v2/reports/submit
 * Idempotency: RFC 4122 v4 UUID via X-Idempotency-Key header or payload client_report_uuid
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

// Authorized roles: reporters, rangers, controllers, admins
$allowedRoles = ['reporter', 'wildlife_officer', 'control_officer', 'admin'];
$currentUser = require_api_auth($allowedRoles);

$input = get_json_input();
$payload = $input['data'];

require_api_csrf($payload);

// Read X-Idempotency-Key if present and not in body
$headerIdempotency = $_SERVER['HTTP_X_IDEMPOTENCY_KEY'] ?? null;
if (!empty($headerIdempotency) && empty($payload['client_report_uuid'])) {
    $payload['client_report_uuid'] = trim($headerIdempotency);
}

$result = FieldReportingService::submitReport($payload, (int)$currentUser['id'], $currentUser['role']);

if ($result['success']) {
    send_api_json_response($result['status_code'], [
        'status' => 'success',
        'is_replay' => $result['is_replay'] ?? false,
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
