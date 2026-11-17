<?php
/**
 * EleGuard Rail — Analytics API: Data Quality & Provenance Audit Endpoint
 * 
 * Route: GET /api/analytics/quality.php
 * Milestone 9C Stage 4D.1
 * 
 * Access Control:
 * - Authorized: admin, wildlife_officer, control_officer
 * - Denied: driver (403), reporter (403), unauthenticated (401)
 * 
 * Query Parameters: None
 * 
 * Audit Focus:
 * - Field completeness (dates, times, GPS, accuracy telemetry).
 * - Provenance breakdown (genuine field, dev test, AI test, unknown).
 * - Data quality warnings for operational governance.
 */

require_once __DIR__ . '/../../../app/helpers/analytics_api.php';

// 1. Enforce GET, authentication, and role authorization
$auth = analytics_api_init(['admin', 'wildlife_officer', 'control_officer']);
$role = $auth['role'];

// 2. Validate request parameters
analytics_validate_allowed_params([]);

// 3. Role-isolated caching (30s)
$cached = analytics_cache_get('quality', $role, [], 30);
if ($cached !== null) {
    analytics_api_success($cached);
}

// 4. Retrieve data using dedicated read-only connection
$pdo = analytics_get_readonly_pdo();
$options = [
    'user_role' => $role
];

$qualityData = AnalyticsService::getDataQualityAnalytics($pdo, $options);
$generalStats = AnalyticsService::getGeneralStatistics($pdo, $options);

$responsePayload = [
    'metadata' => [
        'generated_at' => date('Y-m-d H:i:s'),
        'role' => $role,
        'audit_scope' => 'Operational database completeness, timestamp accuracy, and provenance segregation audit'
    ],
    'completeness_metrics' => [
        'total_reports' => $qualityData['total_reports'],
        'missing_observation_dates' => $qualityData['missing_observation_dates'],
        'missing_observation_times' => $qualityData['missing_observation_times'],
        'missing_gps' => $qualityData['missing_gps'],
        'gps_accuracy_telemetry' => $qualityData['gps_accuracy_telemetry'],
        'invalid_coordinates_count' => $qualityData['invalid_coordinates_count'],
        'unknown_provenance_count' => $qualityData['unknown_provenance_count'],
        'test_demo_records_count' => $qualityData['test_demo_records_count'],
        'potential_section_assignment_discrepancies' => $qualityData['potential_section_assignment_discrepancies'],
        'overall_data_completeness_percentage' => $qualityData['overall_data_completeness_percentage'],
        'audit_recommendation' => $qualityData['audit_recommendation']
    ],
    'provenance_breakdown' => $generalStats['by_provenance'],
    'provenance_summary' => $generalStats['provenance_summary'],
    'warnings' => [
        'Missing observation times: 100% of historical reports lack recorded observation times.',
        'Data contamination: Development test triads (IDs 4-40) and AI test records exist in the operational database and must remain segregated from genuine wildlife statistics.',
        'Genuine field records are currently limited to N=3.'
    ],
    'recommendations' => [
        'Enforce mobile GPS lock and hardware clock capture at public/ranger report submission time.',
        'Migrate historical development test records to dedicated testing environments prior to multi-year empirical model training.'
    ]
];

analytics_cache_set('quality', $role, [], $responsePayload);
analytics_api_success($responsePayload);
