<?php
/**
 * EleGuard Rail — Analytics API Helper & Common Security Middleware
 * 
 * Milestone 9C Stage 4D.1
 * 
 * Provides:
 * - Strict GET method enforcement (405 Method Not Allowed)
 * - Session authentication verification (401 Unauthorized)
 * - Role-based authorization enforcement (403 Forbidden)
 * - Strict JSON response envelopes and security headers
 * - Request parameter validation (422 Unprocessable Entity)
 * - Dedicated read-only database connection management
 * - Role-isolated private short-lived cache (prevents cross-role leakage)
 */

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../core/Session.php';
require_once __DIR__ . '/../core/Auth.php';
require_once __DIR__ . '/../core/Database.php';
require_once __DIR__ . '/../services/AnalyticsService.php';

/**
 * Initializes the analytics API endpoint:
 * - Emits strict security headers.
 * - Enforces HTTP GET method.
 * - Enforces session authentication.
 * - Enforces role-based authorization against allowed roles.
 * 
 * @param array $allowedRoles List of role identifiers permitted to access the endpoint
 * @return array Array containing authenticated 'user' and 'role'
 */
function analytics_api_init(array $allowedRoles = []) {
    // 1. Strict security and JSON content headers
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, no-cache, no-store, must-revalidate, max-age=0');
    header('Pragma: no-cache');

    // 2. HTTP Method restriction: GET only
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if ($method !== 'GET') {
        header('Allow: GET');
        analytics_api_error('METHOD_NOT_ALLOWED', 'Method Not Allowed. Only GET requests are supported.', 405);
    }

    // 3. Authentication Check
    if (!Auth::check()) {
        analytics_api_error('UNAUTHORIZED', 'Authentication required. Please log in to access analytics.', 401);
    }

    // 4. Role-Based Authorization Check
    $user = Auth::user();
    $role = strtolower(trim((string)($user['role'] ?? '')));

    if (empty($role) || !in_array($role, $allowedRoles, true)) {
        analytics_api_error('FORBIDDEN', 'Access denied. Insufficient permissions for this analytics endpoint.', 403);
    }

    return [
        'user' => $user,
        'role' => $role
    ];
}

/**
 * Emits a standardized JSON success response and exits.
 * 
 * @param array $data Payload data
 * @param int $statusCode HTTP status code (default: 200)
 */
function analytics_api_success(array $data, $statusCode = 200) {
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => true,
        'status' => 'success',
        'timestamp' => date('c'),
        'data' => $data
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Emits a standardized JSON error response and exits.
 * 
 * @param string $code Machine-readable error code
 * @param string $message Safe human-readable error message
 * @param int $statusCode HTTP status code (default: 400)
 */
function analytics_api_error($code, $message, $statusCode = 400) {
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => false,
        'error' => [
            'code' => $code,
            'message' => $message
        ]
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Obtains the dedicated read-only database connection.
 * 
 * @return PDO
 */
function analytics_get_readonly_pdo() {
    try {
        return Database::getAnalyticsConnection();
    } catch (Exception $e) {
        // Log internally, never leak DB credentials or stack trace
        if (class_exists('Logger')) {
            Logger::error("Analytics DB connection failed: " . $e->getMessage());
        }
        analytics_api_error('DATABASE_ERROR', 'Service temporarily unavailable. Database connection failed.', 500);
    }
}

/**
 * Validates the 'provenance' query parameter.
 * 
 * @param mixed $val
 * @return string 'all' or 'genuine_only'
 */
function analytics_validate_provenance($val = null) {
    if ($val === null || $val === '') {
        return 'all';
    }
    $clean = strtolower(trim((string)$val));
    if (!in_array($clean, ['all', 'genuine_only'], true)) {
        analytics_api_error('VALIDATION_ERROR', "Invalid provenance parameter. Allowed values: 'all', 'genuine_only'.", 422);
    }
    return $clean;
}

/**
 * Validates the 'section_id' query parameter.
 * 
 * @param mixed $val
 * @return int|null Validated section ID (1-4) or null
 */
function analytics_validate_section_id($val = null) {
    if ($val === null || $val === '') {
        return null;
    }
    if (!is_numeric($val) || (int)$val != $val) {
        analytics_api_error('VALIDATION_ERROR', 'section_id must be a valid integer between 1 and 4.', 422);
    }
    $intVal = (int)$val;
    if ($intVal < 1 || $intVal > 4) {
        analytics_api_error('VALIDATION_ERROR', 'section_id must be a valid integer between 1 and 4.', 422);
    }
    return $intVal;
}

/**
 * Validates start_date and end_date query parameters.
 * 
 * @param mixed $start
 * @param mixed $end
 * @return array ['start_date' => string|null, 'end_date' => string|null]
 */
function analytics_validate_date_range($start = null, $end = null) {
    $startDate = null;
    $endDate = null;

    if ($start !== null && $start !== '') {
        $d = DateTime::createFromFormat('Y-m-d', $start);
        if (!$d || $d->format('Y-m-d') !== $start) {
            analytics_api_error('VALIDATION_ERROR', 'start_date must follow YYYY-MM-DD format.', 422);
        }
        $startDate = $start;
    }

    if ($end !== null && $end !== '') {
        $d = DateTime::createFromFormat('Y-m-d', $end);
        if (!$d || $d->format('Y-m-d') !== $end) {
            analytics_api_error('VALIDATION_ERROR', 'end_date must follow YYYY-MM-DD format.', 422);
        }
        $endDate = $end;
    }

    if ($startDate !== null && $endDate !== null && $startDate > $endDate) {
        analytics_api_error('VALIDATION_ERROR', 'end_date cannot precede start_date.', 422);
    }

    return [
        'start_date' => $startDate,
        'end_date' => $endDate
    ];
}

/**
 * Validates that no unsupported query parameters were supplied in the request.
 * 
 * @param array $allowedParams
 */
function analytics_validate_allowed_params(array $allowedParams) {
    foreach ($_GET as $key => $val) {
        if (!in_array($key, $allowedParams, true)) {
            analytics_api_error('VALIDATION_ERROR', "Unsupported query parameter '{$key}'.", 422);
        }
    }
}

/**
 * Retrieves a role-isolated short-lived cache item.
 * Cache key includes endpoint route, user role, and serialized query parameters to strictly prevent cross-role data leaks.
 * 
 * @param string $endpoint
 * @param string $role
 * @param array $params
 * @param int $ttlSeconds
 * @return array|null
 */
function analytics_cache_get($endpoint, $role, array $params = [], $ttlSeconds = 30) {
    // Generate isolated cache key
    $key = 'analytics_' . md5($endpoint . '|' . $role . '|' . serialize($params));
    $cacheFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'eleguard_' . $key . '.cache';

    if (file_exists($cacheFile)) {
        $mtime = filemtime($cacheFile);
        if ((time() - $mtime) < $ttlSeconds) {
            $content = @file_get_contents($cacheFile);
            if ($content !== false) {
                $decoded = json_decode($content, true);
                if (is_array($decoded)) {
                    return $decoded;
                }
            }
        }
    }
    return null;
}

/**
 * Stores a role-isolated short-lived cache item.
 * 
 * @param string $endpoint
 * @param string $role
 * @param array $params
 * @param array $data
 */
function analytics_cache_set($endpoint, $role, array $params, array $data) {
    $key = 'analytics_' . md5($endpoint . '|' . $role . '|' . serialize($params));
    $cacheFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'eleguard_' . $key . '.cache';
    @file_put_contents($cacheFile, json_encode($data), LOCK_EX);
}
