<?php
/**
 * EleGuard Rail — API v2: Verify Sighting Report
 * 
 * Endpoint: POST /api/v2/reports/{id}/verify
 * Security: RBAC restricted to wildlife_officer and admin
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

$allowedRoles = ['wildlife_officer', 'admin'];
$currentUser = require_api_auth($allowedRoles);

$reportId = isset($_GET['id']) && is_numeric($_GET['id']) ? (int)$_GET['id'] : 0;
$input = get_json_input();
$payload = $input['data'];

require_api_csrf($payload);

if ($reportId <= 0 && isset($payload['report_id']) && is_numeric($payload['report_id'])) {
    $reportId = (int)$payload['report_id'];
}

if ($reportId <= 0) {
    send_rfc9457_error(
        422,
        'https://eleguard.rail.gov.lk/errors/invalid-parameter',
        'Invalid Report Identifier',
        'A valid positive integer report ID is required.'
    );
}

$decision = trim($payload['decision'] ?? '');
$method = trim($payload['verification_method'] ?? 'remote_telemetry');
$notes = trim($payload['verification_notes'] ?? '');
$expectedPreviousStatus = isset($payload['expected_previous_status']) ? trim((string)$payload['expected_previous_status']) : null;

$result = FieldReportingService::verifyReport(
    $reportId,
    $decision,
    $method,
    $notes,
    (int)$currentUser['id'],
    $currentUser['role'],
    $expectedPreviousStatus
);

if ($result['success']) {
    send_api_json_response($result['status_code'], [
        'status' => 'success',
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
