<?php
/**
 * EleGuard Rail — API v2: Retrieve Report Verification & Audit History
 * 
 * Endpoint: GET /api/v2/reports/{id}/history
 * Security: RBAC restricted to authenticated roles (admin, wildlife_officer, control_officer, reporter)
 */

require_once __DIR__ . '/api_helper.php';

init_api_headers();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('Allow: GET');
    send_rfc9457_error(
        405,
        'https://eleguard.rail.gov.lk/errors/method-not-allowed',
        'Method Not Allowed',
        'This endpoint only accepts HTTP GET requests.'
    );
}

$allowedRoles = ['admin', 'wildlife_officer', 'control_officer', 'reporter'];
$currentUser = require_api_auth($allowedRoles);

$reportId = isset($_GET['id']) && is_numeric($_GET['id']) ? (int)$_GET['id'] : 0;

if ($reportId <= 0) {
    send_rfc9457_error(
        422,
        'https://eleguard.rail.gov.lk/errors/invalid-parameter',
        'Invalid Report Identifier',
        'A valid positive integer report ID is required.'
    );
}

$result = FieldReportingService::getAuditHistory($reportId, $currentUser['role'], (int)$currentUser['id']);

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
        $err['detail']
    );
}
