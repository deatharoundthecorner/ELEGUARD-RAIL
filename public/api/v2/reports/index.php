<?php
/**
 * EleGuard Rail — API v2 Reports Front Controller & Router
 * 
 * Routes incoming REST requests to the appropriate endpoint handler.
 */

require_once __DIR__ . '/api_helper.php';

init_api_headers();

$uri = $_SERVER['REQUEST_URI'] ?? '';
$path = parse_url($uri, PHP_URL_PATH);

// Normalize path relative to /api/v2/reports
$relPath = preg_replace('#^.*?/api/v2/reports/?#i', '', $path);
$relPath = trim($relPath, '/');
$segments = explode('/', $relPath);
$action = $segments[0] ?? '';

// Dispatch logic
if ($action === 'submit') {
    require __DIR__ . '/submit.php';
    exit;
}

if ($action === 'sync-batch' || $action === 'sync_batch') {
    require __DIR__ . '/sync_batch.php';
    exit;
}

if ($action === 'my') {
    require __DIR__ . '/my.php';
    exit;
}

// Check for /{id}/action pattern
if (is_numeric($action) && (int)$action > 0) {
    $_GET['id'] = (int)$action;
    $subAction = $segments[1] ?? '';

    if ($subAction === 'verify') {
        require __DIR__ . '/verify.php';
        exit;
    }
    if ($subAction === 'request-info' || $subAction === 'request_info') {
        require __DIR__ . '/request_info.php';
        exit;
    }
    if ($subAction === 'clarify') {
        require __DIR__ . '/clarify.php';
        exit;
    }
    if ($subAction === 'associate-section' || $subAction === 'associate_section') {
        require __DIR__ . '/associate_section.php';
        exit;
    }
    if ($subAction === 'history') {
        require __DIR__ . '/history.php';
        exit;
    }
    if (empty($subAction)) {
        require __DIR__ . '/get.php';
        exit;
    }
}

// Fallback for direct script requests or default
if (empty($action) && $_SERVER['REQUEST_METHOD'] === 'GET') {
    // Return list or API documentation status
    send_api_json_response(200, [
        'status' => 'success',
        'api' => 'EleGuard Rail Field Reporting API v2',
        'version' => '2.0.0',
        'endpoints' => [
            'POST /api/v2/reports/submit',
            'POST /api/v2/reports/sync-batch',
            'GET /api/v2/reports/my',
            'GET /api/v2/reports/{id}',
            'POST /api/v2/reports/{id}/verify',
            'POST /api/v2/reports/{id}/request-info',
            'POST /api/v2/reports/{id}/clarify',
            'POST /api/v2/reports/{id}/associate-section',
            'GET /api/v2/reports/{id}/history'
        ]
    ]);
}

send_rfc9457_error(
    404,
    'https://eleguard.rail.gov.lk/errors/route-not-found',
    'Resource Not Found',
    "The requested API path '{$path}' is not mapped to an active endpoint."
);
