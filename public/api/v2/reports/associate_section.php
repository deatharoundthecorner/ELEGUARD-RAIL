<?php
/**
 * EleGuard Rail — API v2: Confirm Railway Section Attribution
 * 
 * Endpoint: POST /api/v2/reports/{id}/associate-section
 * Security: RBAC restricted to control_officer and admin
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

$allowedRoles = ['control_officer', 'admin'];
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

$sectionId = isset($payload['section_id']) && is_numeric($payload['section_id']) ? (int)$payload['section_id'] : 0;
if ($sectionId <= 0) {
    send_rfc9457_error(
        422,
        'https://eleguard.rail.gov.lk/errors/invalid-parameter',
        'Invalid Section Identifier',
        'A valid positive integer section ID is required.'
    );
}

$notes = trim($payload['notes'] ?? ($payload['reason'] ?? ''));
$assignmentType = trim($payload['assignment_type'] ?? 'controller_confirmation');
$assignmentMethod = trim($payload['assignment_method'] ?? 'manual_controller_override');

$result = FieldReportingService::confirmSectionAssociation(
    $reportId,
    $sectionId,
    $notes,
    (int)$currentUser['id'],
    $currentUser['role'],
    $assignmentType,
    $assignmentMethod
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
