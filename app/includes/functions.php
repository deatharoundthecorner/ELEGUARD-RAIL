<?php
/**
 * Utility helper functions
 */

require_once __DIR__ . '/../core/Session.php';

/**
 * Escapes HTML output safely
 */
function e($text) {
    return htmlspecialchars($text ?? '', ENT_QUOTES, 'UTF-8');
}

/**
 * Renders a hidden CSRF token field
 */
function csrf_field() {
    $token = Session::generateCsrfToken();
    return '<input type="hidden" name="csrf_token" value="' . e($token) . '">';
}

/**
 * Validates a CSRF token from a request
 */
function validate_csrf() {
    $token = $_POST['csrf_token'] ?? $_GET['csrf_token'] ?? '';
    if (!Session::verifyCsrfToken($token)) {
        header($_SERVER['SERVER_PROTOCOL'] . ' 403 Forbidden');
        echo "<h1>403 Forbidden</h1><p>CSRF Token verification failed.</p>";
        exit;
    }
}

/**
 * Returns CSS color class based on warning/risk level
 */
function get_risk_badge_class($level) {
    $lvl = strtolower($level ?? '');
    switch ($lvl) {
        case 'critical':
            return 'badge-danger';
        case 'high':
            return 'badge-warning-dark';
        case 'medium':
            return 'badge-warning';
        case 'low':
        default:
            return 'badge-success';
    }
}

/**
 * Formats standard timestamp
 */
function format_date($timestamp) {
    if (empty($timestamp)) return 'N/A';
    return date('Y-m-d h:i A', strtotime($timestamp));
}

/**
 * Formats a raw database Date field (Y-m-d)
 */
function format_raw_date($date) {
    if (empty($date)) return 'N/A';
    return date('M d, Y', strtotime($date));
}

/**
 * Generates severity alert indicator classes
 */
function get_alert_status_class($status) {
    $stat = strtolower($status ?? '');
    switch ($stat) {
        case 'active':
            return 'alert-status-active';
        case 'resolved':
            return 'alert-status-resolved';
        case 'false alert':
            return 'alert-status-false';
        default:
            return '';
    }
}
