<?php
/**
 * EleGuard Rail — API v2 Helper Routines
 * 
 * Standards: RFC 9457 (Problem Details for HTTP APIs)
 */

require_once __DIR__ . '/../../../../app/core/Session.php';
require_once __DIR__ . '/../../../../app/core/Auth.php';
require_once __DIR__ . '/../../../../app/services/FieldReportingService.php';

function init_api_headers() {
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
}

function send_rfc9457_error($statusCode, $type, $title, $detail, $invalidParams = [], $instance = null) {
    http_response_code($statusCode);
    header('Content-Type: application/problem+json; charset=utf-8');
    
    $problem = [
        'success' => false,
        'type' => $type,
        'title' => $title,
        'status' => (int)$statusCode,
        'detail' => $detail,
        'instance' => $instance ?: ($_SERVER['REQUEST_URI'] ?? '/api/v2/reports'),
        'timestamp' => (new DateTime('now', new DateTimeZone('Asia/Colombo')))->format(DateTime::ATOM)
    ];

    if (!empty($invalidParams)) {
        $problem['invalid_params'] = $invalidParams;
    }

    echo json_encode($problem, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

function send_api_json_response($statusCode, array $data) {
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');

    if (isset($data['status'])) {
        if ($data['status'] === 'success' && !isset($data['success'])) {
            $data = array_merge(['success' => true], $data);
        } elseif ($data['status'] === 'multi_status') {
            if (!isset($data['batch_status'])) {
                $synced = (int)($data['data']['synced_count'] ?? 0);
                $replayed = (int)($data['data']['replayed_count'] ?? 0);
                $rejected = (int)($data['data']['rejected_count'] ?? 0);
                $batchStatus = ($rejected === 0) ? 'complete' : (($synced > 0 || $replayed > 0) ? 'partial' : 'failed');
                $data['batch_status'] = $batchStatus;
            }
            if (!isset($data['success'])) {
                $rejected = (int)($data['data']['rejected_count'] ?? 0);
                $data = array_merge(['success' => ($rejected === 0)], $data);
            }
        }
    }

    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

function require_api_auth($allowedRoles = null) {
    init_api_headers();

    if (!Auth::check()) {
        send_rfc9457_error(
            401,
            'https://eleguard.rail.gov.lk/errors/unauthorized',
            'Authentication Required',
            'You must be authenticated to access this endpoint.'
        );
    }

    $currentUser = Auth::user();
    $role = $currentUser['role'] ?? '';

    if ($allowedRoles !== null) {
        $allowed = is_array($allowedRoles) ? $allowedRoles : [$allowedRoles];
        if (!in_array($role, $allowed, true)) {
            send_rfc9457_error(
                403,
                'https://eleguard.rail.gov.lk/errors/forbidden',
                'Forbidden Action',
                "Your role '{$role}' is not authorized to access this resource."
            );
        }
    }

    return $currentUser;
}

const MAX_REQUEST_BODY_BYTES = 10485760; // 10 MB

function get_json_input($maxBytes = MAX_REQUEST_BODY_BYTES) {
    // 1. Early Content-Length header inspection before reading request stream
    if (isset($_SERVER['CONTENT_LENGTH'])) {
        $contentLength = trim($_SERVER['CONTENT_LENGTH']);
        if (is_numeric($contentLength) && (int)$contentLength > $maxBytes) {
            send_rfc9457_error(
                413,
                'https://eleguard.rail.gov.lk/errors/payload-too-large',
                'Payload Too Large',
                'The request body exceeds the maximum allowed payload size of 10 MB.'
            );
        }
    }

    // 2. Read raw request body
    $raw = file_get_contents('php://input');
    if ($raw === false || $raw === '') {
        return ['raw' => '', 'data' => []];
    }

    // 3. Inspect actual raw body byte length before expensive json_decode()
    if (strlen($raw) > $maxBytes) {
        send_rfc9457_error(
            413,
            'https://eleguard.rail.gov.lk/errors/payload-too-large',
            'Payload Too Large',
            'The request body exceeds the maximum allowed payload size of 10 MB.'
        );
    }

    // 4. Parse JSON only after payload size guardrails pass
    $decoded = json_decode($raw, true);
    if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
        send_rfc9457_error(
            400,
            'https://eleguard.rail.gov.lk/errors/invalid-json',
            'Malformed JSON Payload',
            'Request body could not be parsed as valid JSON: ' . json_last_error_msg()
        );
    }
    return ['raw' => $raw, 'data' => is_array($decoded) ? $decoded : []];
}

function require_api_csrf(array $payload = []) {
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if (!in_array($method, ['POST', 'PUT', 'DELETE', 'PATCH'], true)) {
        return;
    }

    // In non-HTTP CLI executions without request headers, bypass
    if (php_sapi_name() === 'cli' && empty($_SERVER['HTTP_HOST']) && empty($_SERVER['REQUEST_METHOD'])) {
        return;
    }

    // Only enforce when session authentication is active
    if (!Auth::check()) {
        return; // require_api_auth handles 401
    }

    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_SERVER['HTTP_X_XSRF_TOKEN'] ?? ($payload['csrf_token'] ?? ''));

    if (empty($_SESSION['csrf_token'])) {
        Session::generateCsrfToken();
    }

    if (empty($token) || !Session::verifyCsrfToken($token)) {
        send_rfc9457_error(
            403,
            'https://eleguard.rail.gov.lk/errors/csrf-violation',
            'CSRF Validation Failed',
            'A valid CSRF token must be provided in the X-CSRF-Token header or csrf_token payload field for mutating requests.'
        );
    }
}
