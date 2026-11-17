<?php
/**
 * EleGuard Rail - Authenticated GIS Data API
 * 
 * Provides secure, role-restricted geospatial layers (railway tracks, hotspots,
 * elephant sighting reports with proximity metrics).
 * 
 * Access Control:
 * - Requires active session authentication.
 * - Restricted to authorized roles: wildlife_officer, control_officer, admin.
 * - Unauthorized roles (driver, public_reporter) receive 403 Forbidden to prevent
 *   exposure of sensitive live wildlife coordinates.
 */

// Set strict JSON response headers and cache controls for sensitive GIS telemetry
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

require_once __DIR__ . '/../../app/config/app.php';
require_once __DIR__ . '/../../app/core/Session.php';
require_once __DIR__ . '/../../app/core/Auth.php';
require_once __DIR__ . '/../../app/services/GisService.php';

// Helper function to send standardized JSON responses and terminate
function send_json_response($statusCode, array $data) {
    http_response_code($statusCode);
    if (isset($data['status'])) {
        if ($data['status'] === 'success' && !isset($data['success'])) {
            $data = array_merge(['success' => true], $data);
        } elseif ($data['status'] === 'error' && !isset($data['success'])) {
            $data = array_merge(['success' => false], $data);
        }
    }
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    // 1. Enforce HTTP GET method
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        header('Allow: GET');
        send_json_response(405, [
            'status' => 'error',
            'code' => 405,
            'message' => 'Method Not Allowed. This endpoint only supports GET requests.'
        ]);
    }

    // 2. Authentication Check
    if (!Auth::check()) {
        send_json_response(401, [
            'status' => 'error',
            'code' => 401,
            'message' => 'Authentication required. Please log in to access GIS layers.'
        ]);
    }

    // 3. Role-Based Authorization Check
    $user = Auth::user();
    $role = $user['role'] ?? '';
    $authorizedRoles = ['wildlife_officer', 'control_officer', 'admin'];

    if (!in_array($role, $authorizedRoles, true)) {
        send_json_response(403, [
            'status' => 'error',
            'code' => 403,
            'message' => 'Access denied. Precise wildlife coordinates are restricted to authorized wildlife and railway control officers.'
        ]);
    }

    // Role-based coordinate access: least-privilege model
    // Operational dispatchers and biologists require full precision coordinates.
    // Administrative users receive approximate masked coordinates (~2 decimal places, ~1.1km).
    // Any caller can explicitly opt for masked coordinates via ?mask_coordinates=1 or ?masked=1.
    $operationalRoles = ['wildlife_officer', 'control_officer'];
    $maskParams = [
        $_GET['mask_coordinates'] ?? null,
        $_GET['masked'] ?? null,
        $_GET['force_mask'] ?? null
    ];
    $forceMask = false;
    foreach ($maskParams as $mp) {
        if ($mp !== null && in_array(strtolower(trim((string)$mp)), ['1', 'true', 'yes'], true)) {
            $forceMask = true;
            break;
        }
    }
    $hasPreciseAccess = in_array($role, $operationalRoles, true) && !$forceMask;

    // 4. Input Validation & Parameter Sanitization
    $filters = [];

    // Filter: section_id
    if (isset($_GET['section_id']) && $_GET['section_id'] !== '') {
        if (!ctype_digit(strval($_GET['section_id']))) {
            send_json_response(400, [
                'status' => 'error',
                'code' => 400,
                'message' => 'Invalid section_id parameter. Must be an integer.'
            ]);
        }
        $filters['section_id'] = (int)$_GET['section_id'];
    }

    // Filter: risk_level
    if (isset($_GET['risk_level']) && $_GET['risk_level'] !== '') {
        $allowedRisks = ['low', 'medium', 'high', 'critical'];
        $cleanRisk = strtolower(trim($_GET['risk_level']));
        if (!in_array($cleanRisk, $allowedRisks, true)) {
            send_json_response(400, [
                'status' => 'error',
                'code' => 400,
                'message' => "Invalid risk_level parameter. Allowed values: Low, Medium, High, Critical."
            ]);
        }
        $filters['risk_level'] = ucfirst($cleanRisk);
    }

    // Filter: status / verification_status
    $statusParam = $_GET['status'] ?? $_GET['verification_status'] ?? null;
    if ($statusParam !== null && $statusParam !== '') {
        $allowedStatuses = ['pending', 'verified', 'rejected', 'converted to alert'];
        $cleanStatus = strtolower(trim($statusParam));
        if (!in_array($cleanStatus, $allowedStatuses, true)) {
            send_json_response(400, [
                'status' => 'error',
                'code' => 400,
                'message' => "Invalid status parameter. Allowed values: Pending, Verified, Rejected, Converted to Alert."
            ]);
        }
        $filters['status'] = ucwords($cleanStatus);
    }

    // Filter: date_from
    if (!empty($_GET['date_from'])) {
        $dateFrom = trim($_GET['date_from']);
        $d = DateTime::createFromFormat('Y-m-d', $dateFrom);
        if (!$d || $d->format('Y-m-d') !== $dateFrom) {
            send_json_response(400, [
                'status' => 'error',
                'code' => 400,
                'message' => 'Invalid date_from format. Use YYYY-MM-DD.'
            ]);
        }
        $filters['date_from'] = $dateFrom;
    }

    // Filter: date_to
    if (!empty($_GET['date_to'])) {
        $dateTo = trim($_GET['date_to']);
        $d = DateTime::createFromFormat('Y-m-d', $dateTo);
        if (!$d || $d->format('Y-m-d') !== $dateTo) {
            send_json_response(400, [
                'status' => 'error',
                'code' => 400,
                'message' => 'Invalid date_to format. Use YYYY-MM-DD.'
            ]);
        }
        $filters['date_to'] = $dateTo;
    }

    // Filter: limit
    if (isset($_GET['limit']) && $_GET['limit'] !== '') {
        if (!ctype_digit(strval($_GET['limit']))) {
            send_json_response(400, [
                'status' => 'error',
                'code' => 400,
                'message' => 'Invalid limit parameter. Must be an integer between 1 and 500.'
            ]);
        }
        $filters['limit'] = max(1, min(500, (int)$_GET['limit']));
    } else {
        $filters['limit'] = 100;
    }

    // Filter: layer (all, routes, hotspots, reports, alerts)
    $layer = isset($_GET['layer']) ? strtolower(trim($_GET['layer'])) : 'all';
    $validLayers = ['all', 'routes', 'hotspots', 'reports', 'alerts'];
    if (!in_array($layer, $validLayers, true)) {
        send_json_response(400, [
            'status' => 'error',
            'code' => 400,
            'message' => "Invalid layer parameter. Allowed values: all, routes, hotspots, reports, alerts."
        ]);
    }

    // 5. Generate Response Data
    if ($layer === 'routes') {
        $routes = GisService::getRailwayRoutes();
        send_json_response(200, [
            'status' => 'success',
            'layer' => 'routes',
            'data' => $routes
        ]);
    } elseif ($layer === 'hotspots') {
        $hotspots = GisService::getHotspots($filters['section_id'] ?? null, $filters['risk_level'] ?? null);
        send_json_response(200, [
            'status' => 'success',
            'layer' => 'hotspots',
            'data' => GisService::hotspotsToGeoJson($hotspots)
        ]);
    } elseif ($layer === 'reports') {
        $reports = GisService::getElephantReports($filters, true);
        send_json_response(200, [
            'status' => 'success',
            'layer' => 'reports',
            'coordinate_privacy' => $hasPreciseAccess ? 'full_precision_operational' : 'approximate_masked_2_decimals',
            'data' => GisService::reportsToGeoJson($reports, $hasPreciseAccess)
        ]);
    } elseif ($layer === 'alerts') {
        $alerts = GisService::getActiveAlertsGeoJson($filters['section_id'] ?? null);
        send_json_response(200, [
            'status' => 'success',
            'layer' => 'alerts',
            'data' => $alerts
        ]);
    } else {
        // Return full aggregated GIS payload
        $payload = GisService::getGisPayload($filters, $hasPreciseAccess);
        send_json_response(200, $payload);
    }

} catch (Exception $e) {
    error_log("EleGuard Rail GIS API Error: " . $e->getMessage() . " at " . $e->getFile() . ":" . $e->getLine());
    send_json_response(500, [
        'status' => 'error',
        'code' => 500,
        'message' => 'An internal server error occurred while processing GIS spatial data.'
    ]);
}
