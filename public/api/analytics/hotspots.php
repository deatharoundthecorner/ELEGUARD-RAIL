<?php
/**
 * EleGuard Rail — Analytics API: Hotspot Corridor Inventory Endpoint
 * 
 * Route: GET /api/analytics/hotspots.php
 * Milestone 9C Stage 4D.1
 * 
 * Access Control:
 * - Authorized: wildlife_officer, control_officer, admin
 * - Denied: driver (403), reporter (403), unauthenticated (401)
 * 
 * Query Parameters:
 * - section_id (optional): 1-4
 * 
 * Wildlife Coordinate Protection:
 * - Only authorized wildlife and railway control officers may view operational hotspot coordinates.
 * - Unauthorized callers are strictly blocked with 403 Forbidden.
 * - Validation status is transparently exposed (all existing hotspots are manually configured).
 */

require_once __DIR__ . '/../../../app/helpers/analytics_api.php';

// 1. Enforce GET, authentication, and role authorization
$auth = analytics_api_init(['admin', 'wildlife_officer', 'control_officer']);
$role = $auth['role'];

// 2. Validate request parameters
analytics_validate_allowed_params(['section_id']);
$sectionId = analytics_validate_section_id($_GET['section_id'] ?? null);

// 3. Role-isolated caching (30s)
$cacheParams = ['section_id' => $sectionId];
$cached = analytics_cache_get('hotspots', $role, $cacheParams, 30);
if ($cached !== null) {
    analytics_api_success($cached);
}

// 4. Retrieve data using dedicated read-only connection
$pdo = analytics_get_readonly_pdo();
$options = [
    'user_role' => $role,
    'section_id' => $sectionId
];

$hotspotData = AnalyticsService::getHotspotAnalytics($pdo, $options);

$responsePayload = [
    'metadata' => [
        'generated_at' => date('Y-m-d H:i:s'),
        'role' => $role,
        'filter_section_id' => $sectionId,
        'coordinate_protection_notice' => 'Hotspot coordinates delivered under authenticated official access tier. Redistribution or unauthenticated exposure is strictly prohibited under Sri Lanka wildlife protection policy.'
    ],
    'total_hotspots' => $hotspotData['total_hotspots'],
    'by_risk_level' => $hotspotData['by_risk_level'],
    'by_section' => $hotspotData['by_section'],
    'hotspots' => $hotspotData['hotspots']
];

analytics_cache_set('hotspots', $role, $cacheParams, $responsePayload);
analytics_api_success($responsePayload);
