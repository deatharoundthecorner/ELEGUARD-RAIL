<?php
/**
 * EleGuard Rail — API v2: List Reporter's Submitted Reports
 * 
 * Endpoint: GET /api/v2/reports/my
 * Security: Session authentication required.
 *           Returns exclusively reports submitted by the authenticated user.
 *           Coordinates masked according to user role (anti-poaching).
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

$currentUser = require_api_auth(); // Any authenticated user

$statusFilter = isset($_GET['status']) ? trim($_GET['status']) : null;
$page = isset($_GET['page']) && is_numeric($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$perPage = isset($_GET['per_page']) && is_numeric($_GET['per_page']) ? max(1, min(50, (int)$_GET['per_page'])) : 15;

// Status parameter validation if provided
$allowedStatuses = ['Pending', 'Needs Information', 'Clarification Submitted', 'Verified', 'Rejected', 'all'];
if ($statusFilter !== null && $statusFilter !== '' && !in_array(strtolower($statusFilter), array_map('strtolower', $allowedStatuses), true)) {
    send_rfc9457_error(
        422,
        'https://eleguard.rail.gov.lk/errors/invalid-parameter',
        'Invalid Status Filter',
        "Status filter '{$statusFilter}' is not recognized. Allowed values: all, Pending, Needs Information, Clarification Submitted, Verified, Rejected."
    );
}

$result = FieldReportingService::getReportsByReporter(
    $currentUser['id'],
    $statusFilter,
    $page,
    $perPage,
    $currentUser['role']
);

send_api_json_response(200, [
    'status' => 'success',
    'data' => $result
]);
