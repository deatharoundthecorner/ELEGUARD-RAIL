<?php
/**
 * EleGuard Rail — Analytics API: AI Advisory & Concordance Telemetry Endpoint
 * 
 * Route: GET /api/analytics/ai_advisory.php
 * Milestone 9C Stage 4D.1
 * 
 * Access Control:
 * - Authorized: wildlife_officer, admin
 * - Denied: control_officer (403), driver (403), reporter (403), unauthenticated (401)
 * 
 * Query Parameters: None
 * 
 * Critical Governance Safeguards:
 * - Computer vision & AI detections are strictly advisory-only.
 * - AI results NEVER automatically verify, reject, or modify reports.
 * - AI results NEVER directly trigger or modify train collision alerts.
 * - Concordance matrix measures human workflow agreement, NOT certified ground-truth model accuracy.
 */

require_once __DIR__ . '/../../../app/helpers/analytics_api.php';

// 1. Enforce GET, authentication, and role authorization
$auth = analytics_api_init(['admin', 'wildlife_officer']);
$role = $auth['role'];

// 2. Validate request parameters
analytics_validate_allowed_params([]);

// 3. Role-isolated caching (30s)
$cached = analytics_cache_get('ai_advisory', $role, [], 30);
if ($cached !== null) {
    analytics_api_success($cached);
}

// 4. Retrieve data using dedicated read-only connection
$pdo = analytics_get_readonly_pdo();
$options = [
    'user_role' => $role
];

$aiData = AnalyticsService::getAiAdvisoryAnalytics($pdo, $options);

$responsePayload = [
    'metadata' => [
        'generated_at' => date('Y-m-d H:i:s'),
        'role' => $role,
        'governance_status' => 'Advisory Only. Automated verification and alert triggering are strictly prohibited.'
    ],
    'total_reports' => $aiData['total_reports'],
    'ai_subsystem_status' => $aiData['ai_subsystem_status'],
    'ai_detection_totals' => $aiData['ai_detection_totals'],
    'models_active' => $aiData['models_active'],
    'human_officer_concordance_matrix' => $aiData['human_officer_concordance_matrix'],
    'governance_notice' => $aiData['governance_notice']
];

analytics_cache_set('ai_advisory', $role, [], $responsePayload);
analytics_api_success($responsePayload);
