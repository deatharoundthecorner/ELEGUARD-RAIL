<?php
/**
 * EleGuard Rail — Analytics API: High-Level Summary Endpoint
 * 
 * Route: GET /api/analytics/summary.php
 * Milestone 9C Stage 4D.1
 * 
 * Access Control:
 * - Authorized: admin, control_officer, wildlife_officer
 * - Denied: driver (403), reporter (403), unauthenticated (401)
 * 
 * Query Parameters:
 * - provenance (optional): 'all' (default) | 'genuine_only'
 */

require_once __DIR__ . '/../../../app/helpers/analytics_api.php';

// 1. Enforce GET, authentication, and role authorization
$auth = analytics_api_init(['admin', 'control_officer', 'wildlife_officer']);
$role = $auth['role'];

// 2. Validate request parameters
analytics_validate_allowed_params(['provenance']);
$provenance = analytics_validate_provenance($_GET['provenance'] ?? null);
$genuineOnly = ($provenance === 'genuine_only');

// 3. Role-isolated caching (30s)
$cacheParams = ['provenance' => $provenance];
$cached = analytics_cache_get('summary', $role, $cacheParams, 30);
if ($cached !== null) {
    analytics_api_success($cached);
}

// 4. Retrieve data using dedicated read-only connection
$pdo = analytics_get_readonly_pdo();
$options = [
    'user_role' => $role,
    'genuine_only' => $genuineOnly
];

$summary = AnalyticsService::getFullAnalyticsSummary($pdo, $options);

// Tailor content based on role principle of least privilege
if ($role === 'control_officer') {
    // Control officers focus on track safety and alert conversion; detailed AI concordance is restricted
    if (isset($summary['ai_advisory']['human_officer_concordance_matrix'])) {
        unset($summary['ai_advisory']['human_officer_concordance_matrix']);
        $summary['ai_advisory']['access_notice'] = "Detailed AI concordance matrix is restricted to Wildlife Officers and Administrators.";
    }
}

$responsePayload = [
    'metadata' => [
        'generated_at' => date('Y-m-d H:i:s'),
        'role' => $role,
        'filter_mode' => $provenance,
        'scarcity_notice' => 'Operational baseline contains 3 genuine reports (N=3). 100% of historical records have unrecorded observation times. Ingestion time (created_at) is never substituted for observation time.',
        'shadow_mode_notice' => 'Experimental shadow temporal risk scores (v1.0.0) are advisory only and never overwrite operational risk scores or drive automated alerts.'
    ],
    'general_statistics' => $summary['general_statistics'],
    'temporal_analytics' => $summary['temporal_analytics'],
    'section_analytics' => $summary['section_analytics'],
    'hotspot_analytics' => $summary['hotspot_analytics'],
    'duplicate_analytics' => $summary['duplicate_analytics'],
    'reporting_latency' => $summary['reporting_latency'],
    'data_quality' => $summary['data_quality'],
    'ai_advisory' => $summary['ai_advisory']
];

analytics_cache_set('summary', $role, $cacheParams, $responsePayload);
analytics_api_success($responsePayload);
