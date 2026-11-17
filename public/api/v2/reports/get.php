<?php
/**
 * EleGuard Rail — API v2: Retrieve Report Details
 * 
 * Endpoint: GET /api/v2/reports/{id}
 * Security: Role-governed coordinate masking & reporter ownership authorization
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

$reportId = isset($_GET['id']) && is_numeric($_GET['id']) ? (int)$_GET['id'] : 0;
if ($reportId <= 0) {
    send_rfc9457_error(
        422,
        'https://eleguard.rail.gov.lk/errors/invalid-parameter',
        'Invalid Report Identifier',
        'A valid positive integer report ID is required.'
    );
}

$report = FieldReportingService::getReportById($reportId, $currentUser['role']);

if (!$report) {
    send_rfc9457_error(
        404,
        'https://eleguard.rail.gov.lk/errors/not-found',
        'Report Not Found',
        "Report #{$reportId} does not exist."
    );
}

// Ownership Enforcement: Reporters are strictly limited to their own reports
if ($currentUser['role'] === 'reporter') {
    if ((int)($report['reported_by'] ?? 0) !== (int)$currentUser['id']) {
        send_rfc9457_error(
            403,
            'https://eleguard.rail.gov.lk/errors/forbidden',
            'Forbidden Access',
            'Reporters are only authorized to inspect reports they submitted.'
        );
    }
}

send_api_json_response(200, [
    'status' => 'success',
    'data' => $report
]);
