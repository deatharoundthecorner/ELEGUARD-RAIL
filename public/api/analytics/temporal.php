<?php
/**
 * EleGuard Rail — Analytics API: Temporal Distribution Endpoint
 * 
 * Route: GET /api/analytics/temporal.php
 * Milestone 9C Stage 4D.1
 * 
 * Access Control:
 * - Authorized: admin, control_officer, wildlife_officer
 * - Denied: driver (403), reporter (403), unauthenticated (401)
 * 
 * Query Parameters:
 * - section_id (optional): 1-4
 * - start_date (optional): YYYY-MM-DD
 * - end_date (optional): YYYY-MM-DD
 * - provenance (optional): 'all' (default) | 'genuine_only'
 * 
 * Strict Scientific Integrity:
 * - Observation times are derived strictly from verified sighting_time.
 * - Ingestion timestamp (created_at) is NEVER substituted for observation times.
 * - Missing observation times are tracked explicitly as unrecorded.
 */

require_once __DIR__ . '/../../../app/helpers/analytics_api.php';

// 1. Enforce GET, authentication, and role authorization
$auth = analytics_api_init(['admin', 'control_officer', 'wildlife_officer']);
$role = $auth['role'];

// 2. Validate request parameters
analytics_validate_allowed_params(['section_id', 'start_date', 'end_date', 'provenance']);
$sectionId = analytics_validate_section_id($_GET['section_id'] ?? null);
$dates = analytics_validate_date_range($_GET['start_date'] ?? null, $_GET['end_date'] ?? null);
$provenance = analytics_validate_provenance($_GET['provenance'] ?? null);
$genuineOnly = ($provenance === 'genuine_only');

// 3. Role-isolated caching (30s)
$cacheParams = [
    'section_id' => $sectionId,
    'start_date' => $dates['start_date'],
    'end_date' => $dates['end_date'],
    'provenance' => $provenance
];
$cached = analytics_cache_get('temporal', $role, $cacheParams, 30);
if ($cached !== null) {
    analytics_api_success($cached);
}

// 4. Retrieve data using dedicated read-only connection
$pdo = analytics_get_readonly_pdo();
$options = [
    'user_role' => $role,
    'genuine_only' => $genuineOnly,
    'section_id' => $sectionId,
    'start_date' => $dates['start_date'],
    'end_date' => $dates['end_date']
];

$temporalData = AnalyticsService::getTemporalAnalytics($pdo, $options);

$responsePayload = [
    'metadata' => [
        'generated_at' => date('Y-m-d H:i:s'),
        'role' => $role,
        'filter_section_id' => $sectionId,
        'filter_start_date' => $dates['start_date'],
        'filter_end_date' => $dates['end_date'],
        'filter_provenance' => $provenance,
        'truth_in_data_notice' => 'Ingestion timestamp created_at is strictly excluded from observation-time distribution metrics. Unrecorded observation times are explicitly categorized as unrecorded.'
    ],
    'total_evaluated' => $temporalData['total_evaluated'],
    'filter_mode' => $temporalData['filter_mode'],
    'hourly_distribution' => $temporalData['hourly_distribution'],
    'date_distribution' => $temporalData['date_distribution'],
    'monthly_distribution' => $temporalData['monthly_distribution'],
    'diurnal_windows' => $temporalData['window_distribution'],
    'window_distribution' => $temporalData['window_distribution'],
    'precision_breakdown' => $temporalData['precision_breakdown'],
    'unrecorded_dates_count' => $temporalData['unrecorded_dates_count'],
    'unrecorded_times_count' => $temporalData['unrecorded_times_count'],
    'data_scarcity_warnings' => $temporalData['data_scarcity_warnings']
];

analytics_cache_set('temporal', $role, $cacheParams, $responsePayload);
analytics_api_success($responsePayload);
