<?php
/**
 * EleGuard Rail — Analytics API: Duplicate Candidate Statistics Endpoint
 * 
 * Route: GET /api/analytics/duplicates.php
 * Milestone 9C Stage 4D.1
 * 
 * Access Control:
 * - Authorized: wildlife_officer, admin, control_officer
 * - Denied: driver (403), reporter (403), unauthenticated (401)
 * 
 * Query Parameters: None
 * 
 * Critical Safety & Governance Constraints:
 * - Duplicate candidate evaluation is explainable and advisory only.
 * - Zero confirmed duplicates policy enforced.
 * - Reports are NEVER automatically merged, deleted, or suppressed.
 * - Duplicate confirmation requires authenticated Wildlife Officer action.
 */

require_once __DIR__ . '/../../../app/helpers/analytics_api.php';

// 1. Enforce GET, authentication, and role authorization
$auth = analytics_api_init(['admin', 'wildlife_officer', 'control_officer']);
$role = $auth['role'];

// 2. Validate request parameters
analytics_validate_allowed_params([]);

// 3. Role-isolated caching (15s)
$cached = analytics_cache_get('duplicates', $role, [], 15);
if ($cached !== null) {
    analytics_api_success($cached);
}

// 4. Retrieve data using dedicated read-only connection
$pdo = analytics_get_readonly_pdo();
$options = [
    'user_role' => $role
];

$duplicateData = AnalyticsService::getDuplicateAnalytics($pdo, $options);

$responsePayload = [
    'metadata' => [
        'generated_at' => date('Y-m-d H:i:s'),
        'role' => $role,
        'governance_policy' => 'Zero confirmed duplicates. Duplicate identification surfaces candidate pairs for human Wildlife Officer validation. Automated merging, deletion, or report suppression is strictly prohibited.'
    ],
    'stored_duplicate_metadata' => $duplicateData['stored_duplicate_metadata'],
    'pairwise_candidate_evaluation' => $duplicateData['pairwise_candidate_evaluation'],
    'candidate_criteria' => [
        'strong_candidate' => 'Spatial proximity <= 500m AND observation time delta <= 30 mins AND same date',
        'possible_candidate' => 'Spatial proximity <= 2000m AND observation time delta <= 120 mins AND same date',
        'spatial_only' => 'Spatial proximity <= 2000m BUT observation time or date unrecorded or mismatched',
        'excluded_test' => 'Pair includes one or more development test or AI synthetic records'
    ]
];

analytics_cache_set('duplicates', $role, [], $responsePayload);
analytics_api_success($responsePayload);
