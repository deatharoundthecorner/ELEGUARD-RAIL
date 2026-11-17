<?php
/**
 * EleGuard Rail — Analytics API: Railway Sections Reporting Statistics Endpoint
 * 
 * Route: GET /api/analytics/sections.php
 * Milestone 9C Stage 4D.1
 * 
 * Access Control:
 * - Authorized: admin, control_officer, wildlife_officer
 * - Denied: driver (403), reporter (403), unauthenticated (401)
 * 
 * Query Parameters:
 * - section_id (optional): 1-4
 * 
 * Architectural Notice:
 * - Reports are tallied strictly by registered administrative track sections.
 * - Automatic geometric track reassignments are prohibited.
 */

require_once __DIR__ . '/../../../app/helpers/analytics_api.php';

// 1. Enforce GET, authentication, and role authorization
$auth = analytics_api_init(['admin', 'control_officer', 'wildlife_officer']);
$role = $auth['role'];

// 2. Validate request parameters
analytics_validate_allowed_params(['section_id']);
$sectionId = analytics_validate_section_id($_GET['section_id'] ?? null);

// 3. Role-isolated caching (30s)
$cacheParams = ['section_id' => $sectionId];
$cached = analytics_cache_get('sections', $role, $cacheParams, 30);
if ($cached !== null) {
    analytics_api_success($cached);
}

// 4. Retrieve data using dedicated read-only connection
$pdo = analytics_get_readonly_pdo();
$options = [
    'user_role' => $role,
    'section_id' => $sectionId
];

$sectionData = AnalyticsService::getSectionAnalytics($pdo, $options);

$responsePayload = [
    'metadata' => [
        'generated_at' => date('Y-m-d H:i:s'),
        'role' => $role,
        'filter_section_id' => $sectionId,
        'governance_notice' => 'Corridor risk tallies reflect registered railway section definitions. Automatic geometric track reassignments are prohibited.'
    ],
    'total_sections' => $sectionData['total_sections'],
    'sections' => $sectionData['sections'],
    'unassigned_reports_count' => $sectionData['unassigned_reports_count']
];

analytics_cache_set('sections', $role, $cacheParams, $responsePayload);
analytics_api_success($responsePayload);
