<?php
/**
 * Wildlife Sighting Reports Verification & Operational Review Workspace
 * 
 * Milestone 9C — Stage 4E.4.3-F: Wildlife Officer Verification & Operational Review Interface
 * 
 * Capabilities:
 * 1. Verification Dashboard with status tabs (Pending, Needs Information, Clarification Submitted, Verified, Rejected).
 * 2. Detailed Observation Review (demographics, tuskers, behavior/evidence, telemetry, corridor & chainage).
 * 3. Interactive Leaflet Map with railway GeoJSON, separate observer/animal markers, uncertainty circles, and OSM disclaimer.
 * 4. Verification Decision Panel with 4 approved methods, justification notes, rejection reason validation, and anti-self-verification guard.
 * 5. Clarification Panel with chronological conversation thread, parent threading, and inquiry submission.
 * 6. Read-only Verification History audit ledger.
 * 7. Preserved AI Advisory & Observation-Aware Dual Risk Assessment.
 */

$pageTitle = "Verify Reports";
$allowed_roles = ['wildlife_officer', 'admin']; // $allowed_roles = 'wildlife_officer';

require_once __DIR__ . '/../../app/layouts/dashboard_header.php';
require_once __DIR__ . '/../../app/services/ReportService.php';
require_once __DIR__ . '/../../app/services/AlertService.php';
require_once __DIR__ . '/../../app/services/AiDetectionService.php';
require_once __DIR__ . '/../../app/services/TemporalService.php';
require_once __DIR__ . '/../../app/services/RiskService.php';
require_once __DIR__ . '/../../app/services/VerificationService.php';
require_once __DIR__ . '/../../app/services/FieldReportingService.php';

$db = Database::getInstance()->getConnection();
$errors = [];

// =============================================================================
// 1. ACTION PROCESSING (VERIFY, REJECT, REQUEST INFO, RERUN AI)
// =============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validate_csrf();
    
    $action = $_POST['action'] ?? '';
    $reportId = isset($_POST['report_id']) && is_numeric($_POST['report_id']) ? (int)$_POST['report_id'] : 0;
    
    if ($reportId <= 0) {
        $errors['action'] = "Invalid report identifier provided.";
    } elseif ($action === 'verify' || $action === 'reject') {
        $decision = ($action === 'verify') ? 'Verified' : 'Rejected';
        $method = trim($_POST['verification_method'] ?? 'remote_telemetry');
        $notes = trim($_POST['verification_notes'] ?? '');
        
        $res = VerificationService::verifyReport(
            $reportId,
            $decision,
            $method,
            $notes,
            (int)$currentUser['id'],
            $currentUser['role']
        );
        
        if ($res['success']) {
            $msg = ($decision === 'Verified') 
                ? "Report #{$reportId} successfully verified." 
                : "Report #{$reportId} successfully rejected.";
            Session::flash('success', $msg);
            Router::redirect('wildlife/reports.php?id=' . $reportId);
        } else {
            $errors['action'] = $res['error']['detail'] ?? "Action rejected by verification engine.";
        }
    } elseif ($action === 'request_info') {
        $inquiryNotes = trim($_POST['inquiry_notes'] ?? '');
        $parentId = !empty($_POST['parent_clarification_id']) && is_numeric($_POST['parent_clarification_id']) 
            ? (int)$_POST['parent_clarification_id'] 
            : null;
            
        $res = VerificationService::requestAdditionalInfo(
            $reportId,
            $inquiryNotes,
            (int)$currentUser['id'],
            $currentUser['role'],
            null,
            $parentId
        );
        
        if ($res['success']) {
            Session::flash('success', "Clarification inquiry recorded for Report #{$reportId}. Status set to 'Needs Information'.");
            Router::redirect('wildlife/reports.php?id=' . $reportId);
        } else {
            $errors['action'] = $res['error']['detail'] ?? "Failed to issue clarification inquiry.";
        }
    } elseif ($action === 'rerun_ai') {
        $rep = ReportService::getById($reportId);
        if ($rep && !empty($rep['image_path'])) {
            $res = AiDetectionService::processReportAi($reportId, $rep['image_path']);
            Session::flash('success', "AI advisory analysis re-executed (Status: {$res['status']}).");
        } else {
            Session::flash('error', "Cannot run AI analysis: no photograph attached to this report.");
        }
        Router::redirect('wildlife/reports.php?id=' . $reportId);
    }
}

// =============================================================================
// 2. FETCH STATUS METRIC COUNTS
// =============================================================================
$countsStmt = $db->query("
    SELECT report_status, COUNT(*) as cnt 
    FROM elephant_reports 
    GROUP BY report_status
");
$rawCounts = $countsStmt->fetchAll(PDO::FETCH_KEY_PAIR);

$statusCounts = [
    'all'                     => array_sum($rawCounts),
    'Pending'                 => $rawCounts['Pending'] ?? 0,
    'Needs Information'       => $rawCounts['Needs Information'] ?? 0,
    'Clarification Submitted' => $rawCounts['Clarification Submitted'] ?? 0,
    'Verified'                => $rawCounts['Verified'] ?? 0,
    'Rejected'                => $rawCounts['Rejected'] ?? 0
];

// =============================================================================
// 3. FETCH SINGLE REPORT DETAILS IF REQUESTED (?id=X)
// =============================================================================
$selectedReport = null;
$selectedId = isset($_GET['id']) && is_numeric($_GET['id']) ? (int)$_GET['id'] : null;
$shadowRisk = null;
$reportClarifications = [];
$reportVerifications = [];

if ($selectedId) {
    $repStmt = $db->prepare("
        SELECT r.*, s.section_name, s.district, s.risk_level as section_risk_level, 
               u.name as reporter_name, u.role as reporter_role
        FROM elephant_reports r
        LEFT JOIN railway_sections s ON r.section_id = s.id
        LEFT JOIN users u ON r.reported_by = u.id
        WHERE r.id = :id
        LIMIT 1
    ");
    $repStmt->execute([':id' => $selectedId]);
    $selectedReport = $repStmt->fetch(PDO::FETCH_ASSOC);

    if ($selectedReport) {
        // Active alerts check
        $alertStmt = $db->prepare("SELECT COUNT(*) FROM alerts WHERE section_id = :sec_id AND alert_status = 'Active'");
        $alertStmt->execute([':sec_id' => $selectedReport['section_id']]);
        $hasActiveAlert = ((int)$alertStmt->fetchColumn()) > 0;

        $reportContext = array_merge($selectedReport, [
            'section_risk' => $selectedReport['section_risk_level'] ?? 'Low',
            'active_alerts' => $hasActiveAlert
        ]);

        $shadowRisk = RiskService::calculateObservationAwareRisk($reportContext, $selectedReport, ['enable_decay' => false]);

        // Fetch clarification messages in chronological order
        $clarifStmt = $db->prepare("
            SELECT c.*, u.name as sender_name, u.role as sender_role_actual
            FROM report_clarifications c
            JOIN users u ON c.sender_id = u.id
            WHERE c.report_id = :id
            ORDER BY c.created_at ASC, c.id ASC
        ");
        $clarifStmt->execute([':id' => $selectedId]);
        $reportClarifications = $clarifStmt->fetchAll(PDO::FETCH_ASSOC);

        // Fetch verification history in chronological order
        $verifStmt = $db->prepare("
            SELECT v.*, u.name as verified_by_name, u.role as verified_by_role
            FROM report_verifications v
            JOIN users u ON v.verified_by = u.id
            WHERE v.report_id = :id
            ORDER BY v.verified_at ASC, v.id ASC
        ");
        $verifStmt->execute([':id' => $selectedId]);
        $reportVerifications = $verifStmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

// =============================================================================
// 4. LISTING FILTERS & PAGINATION
// =============================================================================
$statusFilter = $_GET['status'] ?? null;
$sectionFilter = isset($_GET['section_id']) && is_numeric($_GET['section_id']) ? (int)$_GET['section_id'] : null;
$urgencyFilter = $_GET['urgency_level'] ?? null;
$searchQuery = trim($_GET['q'] ?? '');

$page = isset($_GET['page']) && is_numeric($_GET['page']) && (int)$_GET['page'] > 0 ? (int)$_GET['page'] : 1;
$perPage = 15;
$offset = max(0, ($page - 1) * $perPage);

// Base query for report list
$listSql = "
    SELECT r.*, s.section_name, s.district, u.name as reporter_name
    FROM elephant_reports r
    LEFT JOIN railway_sections s ON r.section_id = s.id
    LEFT JOIN users u ON r.reported_by = u.id
    WHERE 1=1
";
$countSql = "SELECT COUNT(*) FROM elephant_reports r LEFT JOIN users u ON r.reported_by = u.id WHERE 1=1";
$queryParams = [];

if ($statusFilter && in_array($statusFilter, ['Pending', 'Needs Information', 'Clarification Submitted', 'Verified', 'Rejected'], true)) {
    $listSql .= " AND r.report_status = :status";
    $countSql .= " AND r.report_status = :status";
    $queryParams[':status'] = $statusFilter;
}

if ($sectionFilter) {
    $listSql .= " AND r.section_id = :section_id";
    $countSql .= " AND r.section_id = :section_id";
    $queryParams[':section_id'] = $sectionFilter;
}

if ($urgencyFilter && in_array($urgencyFilter, ['Low', 'Medium', 'High', 'Critical'], true)) {
    $listSql .= " AND r.urgency_level = :urgency";
    $countSql .= " AND r.urgency_level = :urgency";
    $queryParams[':urgency'] = $urgencyFilter;
}

if (!empty($searchQuery)) {
    $escapedSearch = addcslashes($searchQuery, '%_\\');
    $searchCond = " AND (r.id = :search_id OR r.description LIKE :search_desc ESCAPE '\\\\' OR r.location_description LIKE :search_loc ESCAPE '\\\\' OR u.name LIKE :search_user ESCAPE '\\\\')";
    $listSql .= $searchCond;
    $countSql .= $searchCond;
    $queryParams[':search_id'] = is_numeric($searchQuery) ? (int)$searchQuery : 0;
    $queryParams[':search_desc'] = "%{$escapedSearch}%";
    $queryParams[':search_loc'] = "%{$escapedSearch}%";
    $queryParams[':search_user'] = "%{$escapedSearch}%";
}

// Count total matching
$cStmt = $db->prepare($countSql);
$cStmt->execute($queryParams);
$totalMatching = (int)$cStmt->fetchColumn();
$totalPages = max(1, (int)ceil($totalMatching / $perPage));

// Execute paged list query with bound integer LIMIT and OFFSET
$listSql .= " ORDER BY r.created_at DESC LIMIT :limit OFFSET :offset";
$lStmt = $db->prepare($listSql);
foreach ($queryParams as $qpKey => $qpVal) {
    $lStmt->bindValue($qpKey, $qpVal);
}
$lStmt->bindValue(':limit', (int)$perPage, PDO::PARAM_INT);
$lStmt->bindValue(':offset', (int)$offset, PDO::PARAM_INT);
$lStmt->execute();
$reports = $lStmt->fetchAll(PDO::FETCH_ASSOC);

$sections = AlertService::getAllSections();
?>

<!-- Include Verification Workspace Stylesheet & Leaflet Assets -->
<link rel="stylesheet" href="<?php echo Router::url('assets/vendor/leaflet/leaflet.css'); ?>">
<link rel="stylesheet" href="<?php echo Router::url('assets/css/verification_workspace.css'); ?>">

<div class="dashboard-title-row">
    <div>
        <h1>🐘 Wildlife Sighting Verification Workspace</h1>
        <p class="text-secondary">Review ranger and community field observations, coordinate clarification inquiries, and perform official biological verification decisions.</p>
    </div>
    <div style="display:flex; gap:10px; align-items:center;">
        <a href="<?php echo Router::url('wildlife/submit_report.php'); ?>" class="btn btn-accent btn-sm">➕ File Field Sighting</a>
        <a href="<?php echo Router::url('wildlife/analytics.php'); ?>" class="btn btn-outline btn-sm">📈 Analytics</a>
    </div>
</div>

<?php if (!empty($errors['action'])): ?>
    <div class="alert alert-danger" style="margin-bottom:20px;">
        <strong>⚠️ Operation Rejected:</strong> <?php echo e($errors['action']); ?>
    </div>
<?php endif; ?>

<!-- ======================================================================= -->
<!-- SECTION A: VERIFICATION DASHBOARD STATUS TABS                          -->
<!-- ======================================================================= -->
<nav class="verification-tabs-bar" aria-label="Verification Status Filters">
    <a href="<?php echo Router::url('wildlife/reports.php'); ?>" 
       class="verification-tab-item <?php echo empty($statusFilter) ? 'active' : ''; ?>">
        📋 All Reports <span class="tab-count-pill"><?php echo $statusCounts['all']; ?></span>
    </a>
    <a href="<?php echo Router::url('wildlife/reports.php?status=Pending'); ?>" 
       class="verification-tab-item tab-pending <?php echo $statusFilter === 'Pending' ? 'active tab-pending' : ''; ?>">
        ⏳ Pending <span class="tab-count-pill"><?php echo $statusCounts['Pending']; ?></span>
    </a>
    <a href="<?php echo Router::url('wildlife/reports.php?status=Needs+Information'); ?>" 
       class="verification-tab-item tab-needs-info <?php echo $statusFilter === 'Needs Information' ? 'active tab-needs-info' : ''; ?>">
        💬 Needs Information <span class="tab-count-pill"><?php echo $statusCounts['Needs Information']; ?></span>
    </a>
    <a href="<?php echo Router::url('wildlife/reports.php?status=Clarification+Submitted'); ?>" 
       class="verification-tab-item tab-clarification-submitted <?php echo $statusFilter === 'Clarification Submitted' ? 'active tab-clarification-submitted' : ''; ?>">
        📨 Clarification Submitted <span class="tab-count-pill"><?php echo $statusCounts['Clarification Submitted']; ?></span>
    </a>
    <a href="<?php echo Router::url('wildlife/reports.php?status=Verified'); ?>" 
       class="verification-tab-item tab-verified <?php echo $statusFilter === 'Verified' ? 'active tab-verified' : ''; ?>">
        ✔️ Verified <span class="tab-count-pill"><?php echo $statusCounts['Verified']; ?></span>
    </a>
    <a href="<?php echo Router::url('wildlife/reports.php?status=Rejected'); ?>" 
       class="verification-tab-item tab-rejected <?php echo $statusFilter === 'Rejected' ? 'active tab-rejected' : ''; ?>">
        ❌ Rejected <span class="tab-count-pill"><?php echo $statusCounts['Rejected']; ?></span>
    </a>
</nav>

<!-- ======================================================================= -->
<!-- SECTIONS B–F: DETAILED REPORT REVIEW WORKSPACE (?id=X)                 -->
<!-- ======================================================================= -->
<?php if ($selectedReport): ?>
    <?php
    $annotatedWebPath = !empty($selectedReport['image_path']) ? AiDetectionService::getAnnotatedWebPath($selectedReport['image_path']) : null;
    $hasAnnotated = ($annotatedWebPath !== null);
    
    // Status Badge determination
    $st = $selectedReport['report_status'];
    $badgeClass = 'badge-outline';
    if ($st === 'Pending') $badgeClass = 'badge-status-pending';
    elseif ($st === 'Needs Information') $badgeClass = 'badge-status-needs-info';
    elseif ($st === 'Clarification Submitted') $badgeClass = 'badge-status-clarification-submitted';
    elseif ($st === 'Verified') $badgeClass = 'badge-status-verified';
    elseif ($st === 'Rejected') $badgeClass = 'badge-status-rejected';

    // Demographics and tuskers
    $count = (int)$selectedReport['elephant_count'];
    $males = $selectedReport['adult_males'] !== null ? (int)$selectedReport['adult_males'] : '&mdash;';
    $females = $selectedReport['adult_females'] !== null ? (int)$selectedReport['adult_females'] : '&mdash;';
    $calves = $selectedReport['calves'] !== null ? (int)$selectedReport['calves'] : '&mdash;';
    
    $desc = $selectedReport['description'] ?? '';
    $hasTusker = (stripos($desc, 'tusker') !== false || stripos($desc, 'tusk') !== false);
    
    // Geolocation data
    $obsLat = $selectedReport['observer_latitude'] ?? $selectedReport['latitude'];
    $obsLon = $selectedReport['observer_longitude'] ?? $selectedReport['longitude'];
    $obsAcc = $selectedReport['observer_gps_accuracy_meters'] ?? $selectedReport['gps_accuracy_meters'];
    
    $animLat = $selectedReport['estimated_animal_latitude'];
    $animLon = $selectedReport['estimated_animal_longitude'];
    $animUncert = $selectedReport['animal_location_uncertainty_meters'] ?? 50.0;
    
    $hasAnimalCoords = ($animLat !== null && $animLon !== null && (float)$animLat != 0.0);
    $hasObserverCoords = ($obsLat !== null && $obsLon !== null && (float)$obsLat != 0.0);

    // Self-verification check
    $isAuthor = ((int)$selectedReport['reported_by'] === (int)$currentUser['id']);
    ?>

    <div class="review-workspace-card">
        <div class="review-workspace-header">
            <div>
                <h2 class="review-workspace-title">
                    <span>Observation Review: Report #<?php echo $selectedReport['id']; ?></span>
                    <span class="badge <?php echo $badgeClass; ?>"><?php echo e($st); ?></span>
                </h2>
                <span class="text-secondary" style="font-size:0.85rem;">
                    UUID: <code><?php echo e($selectedReport['client_report_uuid'] ?? 'N/A'); ?></code> &bull; Source: <code><?php echo e($selectedReport['submission_source']); ?></code>
                </span>
            </div>
            <a href="<?php echo Router::url('wildlife/reports.php' . (!empty($statusFilter) ? '?status=' . urlencode($statusFilter) : '')); ?>" 
               class="btn btn-outline btn-sm">✖ Close Review</a>
        </div>

        <!-- =================================================================== -->
        <!-- SECTION B: OBSERVATION REVIEW DATA GRID                             -->
        <!-- =================================================================== -->
        <div class="observation-grid">
            <!-- Left Column: Ecological Event & Demographics -->
            <div>
                <div class="observation-data-card" style="margin-bottom:16px;">
                    <h3>🐘 Elephant Demographics & Sighting Event</h3>
                    
                    <div style="margin-bottom:14px;">
                        <span style="font-size:0.85rem; color:var(--text-secondary);">Demographic Composition:</span>
                        <div class="demographics-pill-grid">
                            <div class="demographic-box">
                                <div class="box-value"><?php echo $count; ?></div>
                                <div class="box-label">Total Count</div>
                            </div>
                            <div class="demographic-box">
                                <div class="box-value"><?php echo $males; ?></div>
                                <div class="box-label">Adult Males</div>
                            </div>
                            <div class="demographic-box">
                                <div class="box-value"><?php echo $females; ?></div>
                                <div class="box-label">Adult Females</div>
                            </div>
                            <div class="demographic-box">
                                <div class="box-value"><?php echo $calves; ?></div>
                                <div class="box-label">Calves</div>
                            </div>
                        </div>
                    </div>

                    <table class="data-table" style="font-size:0.88rem; width:100%;">
                        <tr>
                            <td style="color:var(--text-secondary); width:40%;">Observation Category:</td>
                            <td><strong style="text-transform:capitalize;"><?php echo e(str_replace('_', ' ', $selectedReport['observation_category'])); ?></strong></td>
                        </tr>
                        <tr>
                            <td style="color:var(--text-secondary);">Tusker Presence:</td>
                            <td>
                                <?php if ($hasTusker): ?>
                                    <span class="tusker-alert-badge">🦷 Tusker Indicated in Notes</span>
                                <?php else: ?>
                                    <span class="text-secondary" style="font-size:0.85rem;">None recorded</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <td style="color:var(--text-secondary);">Observation Time:</td>
                            <td>
                                <strong><?php echo TemporalService::formatSightingSummary($selectedReport); ?></strong>
                                <div style="margin-top:2px;">
                                    <?php echo TemporalService::renderPrecisionBadge($selectedReport['sighting_time_precision'], $selectedReport['sighting_time_window'], $selectedReport['sighting_time_uncertainty_minutes']); ?>
                                </div>
                            </td>
                        </tr>
                        <?php 
                        $delayInfo = TemporalService::calculateReportingDelay($selectedReport);
                        if ($delayInfo): 
                        ?>
                        <tr>
                            <td style="color:var(--text-secondary);">Reporting Delay:</td>
                            <td>
                                <span style="font-weight:600; color:<?php echo !empty($delayInfo['is_chronology_inconsistent']) ? '#ef4444' : ($delayInfo['is_delayed'] ? '#f59e0b' : '#10b981'); ?>;">
                                    <?php echo !empty($delayInfo['is_chronology_inconsistent']) ? '⚠️ Inconsistent: ' : ($delayInfo['is_delayed'] ? '⏳ Delayed: ' : '⚡ Rapid: '); ?>
                                    <?php echo e($delayInfo['label']); ?>
                                </span>
                            </td>
                        </tr>
                        <?php endif; ?>
                        <tr>
                            <td style="color:var(--text-secondary);">Observer Name:</td>
                            <td>
                                <strong><?php echo e($selectedReport['reporter_name'] ?? 'Authorized Submitter'); ?></strong>
                                <span class="badge badge-outline" style="font-size:0.72rem; margin-left:4px;">
                                    <?php echo e(ucwords(str_replace('_', ' ', $selectedReport['reporter_role'] ?? 'Reporter'))); ?>
                                </span>
                            </td>
                        </tr>
                    </table>

                    <div style="margin-top:14px; background:rgba(255,255,255,0.03); padding:12px; border-radius:6px; border:1px solid rgba(255,255,255,0.06);">
                        <strong style="font-size:0.82rem; color:var(--text-secondary); text-transform:uppercase;">Field Observation Notes:</strong>
                        <p style="margin:6px 0 0 0; font-size:0.9rem; line-height:1.5; color:#f1f5f9;">
                            <?php echo nl2br(e($desc ?: 'No descriptive notes recorded.')); ?>
                        </p>
                    </div>
                </div>

                <!-- Corridor & Railway Section Context -->
                <div class="observation-data-card">
                    <h3>🛤️ Railway Corridor & Topological Chainage</h3>
                    <table class="data-table" style="font-size:0.88rem; width:100%;">
                        <tr>
                            <td style="color:var(--text-secondary); width:40%;">Railway Section:</td>
                            <td><strong><?php echo e($selectedReport['section_name']); ?></strong> (<?php echo e($selectedReport['district']); ?>)</td>
                        </tr>
                        <tr>
                            <td style="color:var(--text-secondary);">Corridor Risk Level:</td>
                            <td><span class="badge <?php echo get_risk_badge_class($selectedReport['section_risk_level']); ?>"><?php echo e($selectedReport['section_risk_level']); ?></span></td>
                        </tr>
                        <tr>
                            <td style="color:var(--text-secondary);">Nearest Milestone / Chainage:</td>
                            <td>
                                <?php if (!empty($selectedReport['nearest_milestone_ref'])): ?>
                                    <code><?php echo e($selectedReport['nearest_milestone_ref']); ?></code>
                                <?php else: ?>
                                    <span class="text-secondary">Not recorded by observer</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <td style="color:var(--text-secondary);">Distance to Track:</td>
                            <td>
                                <?php if (!empty($selectedReport['distance_to_track_meters'])): ?>
                                    <strong><?php echo round($selectedReport['distance_to_track_meters']); ?> meters</strong>
                                    <?php if (!empty($selectedReport['track_proximity_zone'])): ?>
                                        <span class="badge badge-warning" style="font-size:0.75rem; margin-left:4px;"><?php echo e($selectedReport['track_proximity_zone']); ?></span>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span class="text-secondary">&mdash;</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <td style="color:var(--text-secondary);">Section Assignment Status:</td>
                            <td><code><?php echo e($selectedReport['section_assignment_status']); ?></code></td>
                        </tr>
                    </table>
                </div>
            </div>

            <!-- Right Column: Leaflet Map & Telemetry Coordinates -->
            <div>
                <!-- =============================================================== -->
                <!-- SECTION C: LEAFLET MAP VIEW & OSM ENGINEERING DISCLAIMER        -->
                <!-- =============================================================== -->
                <div class="observation-data-card" style="margin-bottom:16px;">
                    <h3>🗺️ Spatial Telemetry & Railway Map View</h3>
                    
                    <?php if ($hasObserverCoords || $hasAnimalCoords): ?>
                        <div id="sighting-review-map" class="sighting-map-container"></div>
                        
                        <div class="map-engineering-disclaimer">
                            ⚠️ <strong>Railway Geometry Disclaimer:</strong> Railway track alignments displayed using OpenStreetMap track centerlines for situational context; not official Sri Lanka Railways engineering alignment geometry.
                        </div>

                        <div class="map-telemetry-legend">
                            <div class="map-telemetry-legend-item">
                                <span style="display:inline-block; width:12px; height:12px; border-radius:50%; background:#3b82f6;"></span>
                                <span>Observer Position (&plusmn;<?php echo $obsAcc ? round($obsAcc) . 'm' : 'GPS'; ?>)</span>
                            </div>
                            <?php if ($hasAnimalCoords): ?>
                                <div class="map-telemetry-legend-item">
                                    <span style="display:inline-block; width:12px; height:12px; border-radius:50%; background:#f59e0b;"></span>
                                    <span>Estimated Elephant Location (&plusmn;<?php echo round($animUncert); ?>m)</span>
                                </div>
                            <?php endif; ?>
                            <div class="map-telemetry-legend-item">
                                <span style="display:inline-block; width:14px; height:3px; background:#10b981;"></span>
                                <span>Railway Corridor</span>
                            </div>
                        </div>
                    <?php else: ?>
                        <div style="padding:40px 20px; text-align:center; background:rgba(255,255,255,0.01); border:1px dashed var(--border-color); border-radius:6px; color:var(--text-secondary);">
                            📍 <strong>No Geographic Coordinates Available</strong><br>
                            <span style="font-size:0.85rem;">This sighting was recorded without hardware GPS or map pinpoint telemetry.</span>
                        </div>
                    <?php endif; ?>

                    <table class="data-table" style="font-size:0.85rem; width:100%; margin-top:14px;">
                        <tr>
                            <td style="color:var(--text-secondary); width:40%;">Observer Telemetry:</td>
                            <td>
                                <?php if ($hasObserverCoords): ?>
                                    <code><?php echo number_format($obsLat, 6) . ', ' . number_format($obsLon, 6); ?></code>
                                    <?php if ($obsAcc): ?>
                                        <span class="text-secondary" style="font-size:0.8rem;">(&plusmn;<?php echo round($obsAcc); ?>m)</span>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span class="text-secondary">Not captured</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <td style="color:var(--text-secondary);">Estimated Animal Telemetry:</td>
                            <td>
                                <?php if ($hasAnimalCoords): ?>
                                    <code><?php echo number_format($animLat, 6) . ', ' . number_format($animLon, 6); ?></code>
                                    <span class="text-secondary" style="font-size:0.8rem;">(&plusmn;<?php echo round($animUncert); ?>m &bull; <?php echo e($selectedReport['animal_location_method']); ?>)</span>
                                <?php else: ?>
                                    <span class="text-secondary">No animal offset calculated; observer standoff unadjusted.</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <td style="color:var(--text-secondary);">Location Description:</td>
                            <td><code><?php echo e($selectedReport['location_description'] ?: 'None provided'); ?></code></td>
                        </tr>
                    </table>
                </div>

                <!-- Photograph Attachment with AI Toggle -->
                <div class="observation-data-card">
                    <h3>📷 Field Photographic Evidence</h3>
                    <?php if (!empty($selectedReport['image_path'])): ?>
                        <div style="text-align:center;">
                            <a id="sightingImgLink" href="<?php echo Router::url($selectedReport['image_path']); ?>" target="_blank">
                                <img id="sightingImg" src="<?php echo Router::url($selectedReport['image_path']); ?>" 
                                     alt="Field observation media" 
                                     style="max-width:100%; max-height:240px; border-radius:6px; border:1px solid var(--border-color); object-fit:contain;">
                            </a>
                        </div>
                    <?php else: ?>
                        <div style="padding:30px; text-align:center; background:rgba(255,255,255,0.01); border:1px dashed var(--border-color); border-radius:6px; color:var(--text-secondary); font-size:0.9rem;">
                            No photographic evidence attached to this observation.
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- =================================================================== -->
        <!-- SECTION D: VERIFICATION PANEL & ANTI-SELF-VERIFICATION GUARD         -->
        <!-- =================================================================== -->
        <div class="verification-decision-panel">
            <h3 style="margin-top:0; font-size:1.15rem; color:#fff; display:flex; align-items:center; gap:8px;">
                <span>⚖️</span> <span>Official Biological Verification Decision</span>
            </h3>
            
            <?php if ($isAuthor): ?>
                <!-- Self-Verification Prohibited Banner -->
                <div class="self-verification-prohibited-banner">
                    <span style="font-size:1.6rem; line-height:1;">🛑</span>
                    <div>
                        <strong>Self-Verification Prohibited:</strong>
                        You submitted this observation report (User ID #<?php echo (int)$currentUser['id']; ?>).
                        Under EleGuard Rail biological verification governance, an author cannot verify or reject their own field report.
                        An independent Wildlife Officer or Administrator must review and verify this observation.
                    </div>
                </div>
            <?php endif; ?>

            <?php if (in_array($selectedReport['report_status'], ['Pending', 'Needs Information', 'Clarification Submitted'], true)): ?>
                <form action="<?php echo Router::url('wildlife/reports.php?id=' . $selectedReport['id']); ?>" method="POST" id="verificationForm">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="report_id" value="<?php echo $selectedReport['id']; ?>">
                    <input type="hidden" name="action" id="verificationActionInput" value="">
                    
                    <div class="grid grid-2col" style="gap:20px;">
                        <div>
                            <label for="verification_method" class="form-label" style="font-weight:600; color:#fff;">
                                Verification Method <span style="color:#ef4444;">*</span>
                            </label>
                            <select name="verification_method" id="verification_method" class="form-control" <?php echo $isAuthor ? 'disabled' : ''; ?> required>
                                <option value="field_inspection">🔍 Field Inspection (Ranger On-Site Patrol)</option>
                                <option value="station_master_vhf">📻 Station Master VHF Voice Corroboration</option>
                                <option value="remote_telemetry" selected>📡 Remote Telemetry / Sensor Stream</option>
                                <option value="resident_interview">🗣️ Resident / Eyewitness Ground Interview</option>
                            </select>
                            <span class="text-secondary" style="font-size:0.78rem;">Select the method used to corroborate or reject this observation.</span>
                        </div>

                        <div>
                            <label for="verification_notes" class="form-label" style="font-weight:600; color:#fff;">
                                Operational Notes & Evidence Justification <span style="font-size:0.8rem; font-weight:normal; color:var(--text-secondary);">(Mandatory for Rejection, min 10 chars)</span>
                            </label>
                            <textarea name="verification_notes" id="verification_notes" class="form-control" rows="3" 
                                      placeholder="Provide corroborating field details, patrol reference, or rationale for rejection..." 
                                      <?php echo $isAuthor ? 'disabled' : ''; ?>></textarea>
                        </div>
                    </div>

                    <div class="verification-actions-row">
                        <button type="button" class="btn btn-primary" id="btnVerifyReport" 
                                <?php echo $isAuthor ? 'disabled' : ''; ?> 
                                onclick="handleVerificationSubmit('verify')">
                            ✔️ Verify Observation
                        </button>
                        
                        <button type="button" class="btn btn-danger" id="btnRejectReport" 
                                <?php echo $isAuthor ? 'disabled' : ''; ?> 
                                onclick="handleVerificationSubmit('reject')">
                            ❌ Reject Observation
                        </button>
                    </div>
                </form>
            <?php else: ?>
                <div style="padding:16px; border-radius:6px; background:rgba(255,255,255,0.03); color:var(--text-secondary); font-size:0.92rem;">
                    This report has reached a terminal status (<strong><?php echo e($selectedReport['report_status']); ?></strong>). No further verification actions can be submitted.
                </div>
            <?php endif; ?>
        </div>

        <!-- =================================================================== -->
        <!-- SECTION E: CLARIFICATION CONVERSATION PANEL                         -->
        <!-- =================================================================== -->
        <div class="clarification-conversation-panel">
            <h3 style="margin-top:0; font-size:1.15rem; color:#fff; display:flex; align-items:center; gap:8px;">
                <span>💬</span> <span>Observer Clarification Thread</span>
            </h3>

            <div class="conversation-thread">
                <?php if (!empty($reportClarifications)): ?>
                    <?php foreach ($reportClarifications as $c): ?>
                        <?php
                        $msgType = $c['message_type'];
                        $bubbleClass = 'bubble-officer-inquiry';
                        $typeLabel = 'Officer Inquiry';
                        $icon = '👮';
                        
                        if ($msgType === 'reporter_response') {
                            $bubbleClass = 'bubble-reporter-response';
                            $typeLabel = 'Observer Clarification Reply';
                            $icon = '👤';
                        } elseif ($msgType === 'officer_followup') {
                            $bubbleClass = 'bubble-officer-followup';
                            $typeLabel = 'Officer Follow-up';
                            $icon = '🔄';
                        }
                        ?>
                        <div class="message-bubble <?php echo $bubbleClass; ?>" id="clarif-msg-<?php echo $c['id']; ?>">
                            <div class="message-header">
                                <div class="message-author">
                                    <span><?php echo $icon; ?></span>
                                    <span><?php echo e($c['sender_name']); ?></span>
                                    <span class="badge badge-outline" style="font-size:0.7rem;"><?php echo e($typeLabel); ?></span>
                                    <?php if (!empty($c['parent_clarification_id'])): ?>
                                        <span class="parent-reference-tag">↩️ In response to #<?php echo (int)$c['parent_clarification_id']; ?></span>
                                    <?php endif; ?>
                                </div>
                                <span class="message-time"><code><?php echo format_date($c['created_at']); ?> SLST</code></span>
                            </div>
                            <div class="message-body">
                                <?php echo nl2br(e($c['message_text'])); ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div style="padding:24px; text-align:center; color:var(--text-secondary); background:rgba(255,255,255,0.01); border:1px dashed var(--border-color); border-radius:6px;">
                        No clarification messages recorded for this report.
                    </div>
                <?php endif; ?>
            </div>

            <!-- Clarification Action Formulation -->
            <?php if (in_array($selectedReport['report_status'], ['Pending', 'Clarification Submitted'], true)): ?>
                <div class="inquiry-form-card">
                    <h4 style="margin-top:0; font-size:0.95rem; color:#fff; display:flex; align-items:center; gap:6px;">
                        <span>❓</span> <span>Request Additional Information from Observer</span>
                    </h4>
                    <form action="<?php echo Router::url('wildlife/reports.php?id=' . $selectedReport['id']); ?>" method="POST" id="inquiryForm">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="report_id" value="<?php echo $selectedReport['id']; ?>">
                        <input type="hidden" name="action" value="request_info">
                        
                        <?php
                        // Default parent to the latest reporter response if one exists
                        $lastReporterMsgId = null;
                        if (!empty($reportClarifications)) {
                            foreach (array_reverse($reportClarifications) as $rc) {
                                if ($rc['message_type'] === 'reporter_response') {
                                    $lastReporterMsgId = (int)$rc['id'];
                                    break;
                                }
                            }
                        }
                        ?>
                        <input type="hidden" name="parent_clarification_id" value="<?php echo $lastReporterMsgId ? $lastReporterMsgId : ''; ?>">

                        <div class="form-group" style="margin-bottom:10px;">
                            <label for="inquiry_notes" class="form-label" style="font-size:0.85rem; color:var(--text-secondary);">
                                Specific Clarification Question <span style="color:#ef4444;">*</span> (min 10, max 5000 chars):
                            </label>
                            <textarea name="inquiry_notes" id="inquiry_notes" class="form-control" rows="3" 
                                      placeholder="Specify what details require clarification from the observer (e.g., herd heading direction, calf count confirmation, landmarks)..." 
                                      required minlength="10" maxlength="5000" oninput="updateCharCounter(this)"></textarea>
                            <div class="char-counter" id="inquiryCharCounter">0 / 5,000 characters</div>
                        </div>

                        <button type="submit" class="btn btn-outline btn-sm" id="btnSubmitInquiry" style="border-color:#3b82f6; color:#60a5fa;">
                            📨 Submit Clarification Inquiry
                        </button>
                    </form>
                </div>
            <?php elseif ($selectedReport['report_status'] === 'Needs Information'): ?>
                <div style="padding:14px; background:rgba(59, 130, 246, 0.08); border-left:4px solid #3b82f6; border-radius:4px; font-size:0.88rem; color:#93c5fd;">
                    ⏳ <strong>Awaiting Observer Response:</strong> An inquiry is currently active. The observer must submit their response before further inquiries can be opened.
                </div>
            <?php else: ?>
                <div style="font-size:0.85rem; color:var(--text-secondary); margin-top:8px;">
                    Clarification communication is closed for finalized reports.
                </div>
            <?php endif; ?>
        </div>

        <!-- =================================================================== -->
        <!-- SECTION F: VERIFICATION HISTORY AUDIT LEDGER                        -->
        <!-- =================================================================== -->
        <div class="verification-history-card">
            <h3 style="margin-top:0; font-size:1.15rem; color:#fff; display:flex; align-items:center; gap:8px;">
                <span>📜</span> <span>Verification & State Transition Ledger</span>
            </h3>

            <?php if (!empty($reportVerifications)): ?>
                <table class="data-table" style="font-size:0.88rem; width:100%;">
                    <thead>
                        <tr>
                            <th style="width:18%;">Timestamp</th>
                            <th style="width:20%;">Authorized Officer</th>
                            <th style="width:25%;">State Transition</th>
                            <th style="width:17%;">Method</th>
                            <th style="width:20%;">Operational Notes</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($reportVerifications as $vh): ?>
                            <tr>
                                <td><code><?php echo format_date($vh['verified_at']); ?> SLST</code></td>
                                <td>
                                    <strong><?php echo e($vh['verified_by_name']); ?></strong>
                                    <span class="badge badge-outline" style="font-size:0.7rem;"><?php echo e(ucwords(str_replace('_', ' ', $vh['verified_by_role']))); ?></span>
                                </td>
                                <td>
                                    <code><?php echo e($vh['previous_status']); ?></code> &rarr; 
                                    <strong style="color:var(--accent-color);"><?php echo e($vh['new_status']); ?></strong>
                                </td>
                                <td><code><?php echo e($vh['verification_method']); ?></code></td>
                                <td style="font-size:0.82rem; line-height:1.4;">
                                    <?php echo e($vh['verification_notes'] ?: 'No notes recorded.'); ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <div style="padding:16px; text-align:center; color:var(--text-secondary); font-size:0.88rem;">
                    No verification ledger records logged yet.
                </div>
            <?php endif; ?>
        </div>

        <!-- =================================================================== -->
        <!-- AI SIGHTING ADVISORY PANEL (PRESERVED)                              -->
        <!-- =================================================================== -->
        <div class="ai-advisory-card" style="margin-top:24px; padding:20px; border-radius:8px; background:rgba(255,255,255,0.02); border:1px solid var(--border-color);">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px; border-bottom:1px solid var(--border-color); padding-bottom:10px; flex-wrap:wrap; gap:10px;">
                <h3 style="font-size:1.05rem; margin:0; display:flex; align-items:center; gap:8px;">
                    <span>🤖</span> <span>AI Vision Sighting Advisory</span>
                    <span style="font-size:0.75rem; font-weight:normal; color:var(--text-muted);">(YOLOX-Tiny ONNX &bull; Advisory Only)</span>
                </h3>
            </div>
            
            <div style="background:rgba(245, 158, 11, 0.08); border-left:4px solid #f59e0b; padding:10px 14px; border-radius:4px; margin-bottom:14px; font-size:0.82rem; color:#fbbf24;">
                <strong>Advisory Notice:</strong> This detection is an automated AI recommendation. Biological verification decisions must be made solely by authorized Wildlife Officers. AI outputs never modify risk scores or broadcast railway alerts.
            </div>

            <div class="grid grid-2col" style="gap:15px; font-size:0.88rem;">
                <div>
                    <table class="data-table" style="width:100%;">
                        <tr>
                            <td style="color:var(--text-secondary); width:45%;">Detection Outcome:</td>
                            <td>
                                <?php 
                                $rDetected = !empty($selectedReport['ai_detected_elephant']);
                                $rConf = isset($selectedReport['ai_confidence']) ? (float)$selectedReport['ai_confidence'] : null;
                                echo $rDetected ? '🟢 Elephant Pattern Identified' : '⚪ Ambiguous / No Features';
                                ?>
                            </td>
                        </tr>
                        <tr>
                            <td style="color:var(--text-secondary);">Highest Confidence:</td>
                            <td><strong><?php echo $rConf !== null ? number_format($rConf * 100, 1) . '%' : '&mdash;'; ?></strong></td>
                        </tr>
                    </table>
                </div>
                <div>
                    <table class="data-table" style="width:100%;">
                        <tr>
                            <td style="color:var(--text-secondary); width:40%;">Engine Model:</td>
                            <td><code><?php echo e($selectedReport['ai_model_version'] ?? 'YOLOX-Tiny-ONNX'); ?></code></td>
                        </tr>
                        <tr>
                            <td style="color:var(--text-secondary);">Advisory Notes:</td>
                            <td><span style="font-size:0.82rem;"><?php echo e($selectedReport['ai_detection_notes'] ?? 'None'); ?></span></td>
                        </tr>
                    </table>
                </div>
            </div>
        </div>

        <!-- =================================================================== -->
        <!-- DUAL RISK ASSESSMENT & SHADOW MODE PANEL (PRESERVED)               -->
        <!-- =================================================================== -->
        <div class="dual-risk-panel" style="margin-top:30px; border-top:1px solid var(--border-color); padding-top:20px;">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px; flex-wrap:wrap; gap:10px;">
                <h3 style="font-size:1.1rem; margin:0; display:flex; align-items:center; gap:8px;">
                    <span>🛡️</span>
                    <span>Risk Assessment Comparison (Observation-Aware Temporal Risk)</span>
                    <span style="font-size:0.75rem; font-weight:normal; color:var(--text-muted);">(Operational Baseline vs Shadow Model)</span>
                </h3>
            </div>

            <!-- Mandatory Shadow Mode Governance Banner -->
            <div style="background:rgba(245, 158, 11, 0.08); border-left:4px solid #f59e0b; padding:10px 14px; border-radius:4px; margin-bottom:18px; font-size:0.85rem; line-height:1.45; color:#fbbf24;">
                <strong>SHADOW MODE NOTICE:</strong> The observation-aware temporal risk calculation is running in shadow mode for analytical evaluation. It does <strong>not</strong> alter operational risk scores, trigger automated alerts, or change driver cab warnings. Operational alert broadcast strictly uses the verified operational risk score.
            </div>

            <div class="grid grid-2col" style="gap:20px;">
                <!-- Card A: Operational Risk (Active) -->
                <div class="card-panel" style="background:rgba(255,255,255,0.02); border:1px solid var(--border-color); padding:16px; border-radius:8px; display:flex; flex-direction:column; justify-content:space-between; margin-bottom:0;">
                    <div>
                        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px; border-bottom:1px solid rgba(255,255,255,0.08); padding-bottom:8px;">
                            <strong style="font-size:0.95rem; color:#fff;">A. Operational Risk (Active)</strong>
                            <span class="badge badge-success" style="font-size:0.72rem; padding:3px 8px;">ACTIVE BASELINE</span>
                        </div>
                        
                        <div style="display:flex; align-items:center; gap:12px; margin-bottom:14px;">
                            <span class="badge <?php echo get_risk_badge_class($selectedReport['risk_score']); ?>" style="font-size:1.1rem; padding:6px 14px;">
                                <?php echo e($selectedReport['risk_score']); ?>
                            </span>
                            <span class="text-secondary" style="font-size:0.85rem;">Controls Alert Broadcast</span>
                        </div>

                        <table class="data-table" style="font-size:0.85rem; width:100%; margin-bottom:10px;">
                            <tr>
                                <td style="color:var(--text-secondary); width:45%;">Score Source:</td>
                                <td><code>Intake Calculation (ReportService)</code></td>
                            </tr>
                            <tr>
                                <td style="color:var(--text-secondary);">Section Risk:</td>
                                <td><span class="badge <?php echo get_risk_badge_class($selectedReport['section_risk_level'] ?? 'Low'); ?>"><?php echo e($selectedReport['section_risk_level'] ?? 'Low'); ?></span></td>
                            </tr>
                            <tr>
                                <td style="color:var(--text-secondary);">Urgency Rating:</td>
                                <td><span class="badge <?php echo get_risk_badge_class($selectedReport['urgency_level']); ?>"><?php echo e($selectedReport['urgency_level']); ?></span></td>
                            </tr>
                            <tr>
                                <td style="color:var(--text-secondary);">Ingestion Time:</td>
                                <td><code><?php echo format_date($selectedReport['created_at']); ?> SLST</code></td>
                            </tr>
                        </table>
                    </div>

                    <div style="margin-top:12px; padding:8px 10px; background:rgba(16, 185, 129, 0.08); border-radius:4px; font-size:0.8rem; color:#10b981;">
                        ✔ Active operational baseline. Alert broadcast dropdown defaults to this rating.
                    </div>
                </div>

                <!-- Card B: Experimental Temporal Risk (Shadow Mode) -->
                <div class="card-panel" style="background:rgba(255,255,255,0.02); border:1px solid rgba(245, 158, 11, 0.3); padding:16px; border-radius:8px; display:flex; flex-direction:column; justify-content:space-between; margin-bottom:0;">
                    <div>
                        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px; border-bottom:1px solid rgba(255,255,255,0.08); padding-bottom:8px;">
                            <strong style="font-size:0.95rem; color:#fbbf24;">B. Observation-Aware Risk</strong>
                            <span class="badge" style="background:#b45309; color:#fff; font-size:0.7rem; font-weight:bold; padding:3px 8px; border-radius:4px;">
                                EXPERIMENTAL — NOT USED FOR OPERATIONAL ALERTING
                            </span>
                        </div>

                        <?php if ($shadowRisk): ?>
                            <div style="display:flex; align-items:baseline; gap:10px; margin-bottom:14px; flex-wrap:wrap;">
                                <span class="badge <?php echo get_risk_badge_class($shadowRisk['final_classification']); ?>" style="font-size:1.1rem; padding:6px 14px;">
                                    <?php echo e($shadowRisk['final_classification']); ?>
                                </span>
                                <span style="font-size:1rem; font-weight:600; color:#fff;">
                                    <?php echo number_format($shadowRisk['unrounded_total_score'], 2); ?> pts
                                </span>
                                <span class="text-secondary" style="font-size:0.8rem;">
                                    (Rounded: <?php echo $shadowRisk['final_score']; ?> / 13)
                                </span>
                            </div>

                            <table class="data-table" style="font-size:0.85rem; width:100%; margin-bottom:10px;">
                                <tr>
                                    <td style="color:var(--text-secondary); width:45%;">Diurnal Factor:</td>
                                    <td>
                                        <strong style="color:<?php echo $shadowRisk['diurnal_adjustment'] > 0 ? '#fbbf24' : '#9ca3af'; ?>;">
                                            +<?php echo number_format($shadowRisk['diurnal_adjustment'], 2); ?> pts
                                        </strong>
                                        <span class="text-secondary" style="font-size:0.75rem;">(Continuous illumination)</span>
                                    </td>
                                </tr>
                                <tr>
                                    <td style="color:var(--text-secondary);">Observation Window:</td>
                                    <td>
                                        <?php if (!empty($shadowRisk['observation_interval']['start']) && !empty($shadowRisk['observation_interval']['end'])): ?>
                                            <code><?php echo e($shadowRisk['observation_interval']['start']); ?> – <?php echo e($shadowRisk['observation_interval']['end']); ?> SLST</code>
                                        <?php else: ?>
                                            <span class="text-muted">Unknown observation interval</span>
                                        <?php endif; ?>
                                        <div style="margin-top:2px;">
                                            <?php echo TemporalService::renderPrecisionBadge($selectedReport['sighting_time_precision'], $selectedReport['sighting_time_window'], $selectedReport['sighting_time_uncertainty_minutes']); ?>
                                        </div>
                                    </td>
                                </tr>
                                <tr>
                                    <td style="color:var(--text-secondary);">Point Breakdown:</td>
                                    <td style="font-size:0.8rem; line-height:1.4;">
                                        Section: <code><?php echo $shadowRisk['base_section_points']; ?></code> | 
                                        Urgency: <code><?php echo $shadowRisk['urgency_points']; ?></code> | 
                                        Count: <code><?php echo $shadowRisk['elephant_count_points']; ?></code> | 
                                        Alerts: <code><?php echo $shadowRisk['active_alert_points']; ?></code> | 
                                        Diurnal: <code>+<?php echo number_format($shadowRisk['diurnal_adjustment'], 2); ?></code>
                                    </td>
                                </tr>
                                <tr>
                                    <td style="color:var(--text-secondary);">Engine Model:</td>
                                    <td><code>v<?php echo e($shadowRisk['model_version']); ?> (Shadow Assessment)</code></td>
                                </tr>
                            </table>

                            <?php if (!empty($shadowRisk['data_quality_warnings'])): ?>
                                <div style="background:rgba(239, 68, 68, 0.08); border:1px solid rgba(239, 68, 68, 0.25); border-radius:4px; padding:8px 10px; margin-top:8px; font-size:0.78rem; color:#fca5a5;">
                                    <strong style="color:#ef4444;">Data Quality Notices:</strong>
                                    <ul style="margin:4px 0 0 16px; padding:0;">
                                        <?php foreach ($shadowRisk['data_quality_warnings'] as $warning): ?>
                                            <li><?php echo e($warning); ?></li>
                                        <?php endforeach; ?>
                                    </ul>
                                </div>
                            <?php endif; ?>
                        <?php else: ?>
                            <div class="text-muted" style="padding:20px; text-align:center;">
                                Unable to calculate shadow temporal risk assessment.
                            </div>
                        <?php endif; ?>
                    </div>

                    <div style="margin-top:12px; padding:8px 10px; background:rgba(245, 158, 11, 0.08); border-radius:4px; font-size:0.8rem; color:#fbbf24;">
                        ⚠️ Informational shadow telemetry only. Does not alter operational alerts.
                    </div>
                </div>
            </div>
        </div>

    </div>

    <!-- Leaflet Review Map Script -->
    <?php if ($hasObserverCoords || $hasAnimalCoords): ?>
        <script src="<?php echo Router::url('assets/vendor/leaflet/leaflet.js'); ?>"></script>
        <script>
            document.addEventListener('DOMContentLoaded', function() {
                const mapEl = document.getElementById('sighting-review-map');
                if (!mapEl) return;

                const obsLat = <?php echo $hasObserverCoords ? (float)$obsLat : 'null'; ?>;
                const obsLon = <?php echo $hasObserverCoords ? (float)$obsLon : 'null'; ?>;
                const obsAcc = <?php echo ($obsAcc && (float)$obsAcc > 0) ? (float)$obsAcc : '30'; ?>;

                const animLat = <?php echo $hasAnimalCoords ? (float)$animLat : 'null'; ?>;
                const animLon = <?php echo $hasAnimalCoords ? (float)$animLon : 'null'; ?>;
                const animUncert = <?php echo ($animUncert && (float)$animUncert > 0) ? (float)$animUncert : '50'; ?>;

                const centerLat = animLat !== null ? animLat : obsLat;
                const centerLon = animLon !== null ? animLon : obsLon;

                const map = L.map('sighting-review-map', {
                    center: [centerLat, centerLon],
                    zoom: 14,
                    zoomControl: true
                });

                L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
                    maxZoom: 19,
                    attribution: '&copy; OpenStreetMap contributors | EleGuard Rail'
                }).addTo(map);

                const boundsGroup = [];

                // 1. Observer Marker & Accuracy Circle
                if (obsLat !== null && obsLon !== null) {
                    const obsMarker = L.circleMarker([obsLat, obsLon], {
                        radius: 8,
                        fillColor: '#3b82f6',
                        color: '#ffffff',
                        weight: 2,
                        opacity: 1,
                        fillOpacity: 0.9
                    }).addTo(map);
                    obsMarker.bindPopup('<strong>📍 Observer Position</strong><br>GPS Accuracy: &plusmn;' + Math.round(obsAcc) + 'm');

                    L.circle([obsLat, obsLon], {
                        radius: obsAcc,
                        color: '#3b82f6',
                        weight: 1,
                        fillColor: '#3b82f6',
                        fillOpacity: 0.12
                    }).addTo(map);

                    boundsGroup.push([obsLat, obsLon]);
                }

                // 2. Animal Marker & Uncertainty Circle (Only if estimated coordinates exist)
                if (animLat !== null && animLon !== null) {
                    const animMarker = L.circleMarker([animLat, animLon], {
                        radius: 10,
                        fillColor: '#f59e0b',
                        color: '#ffffff',
                        weight: 2,
                        opacity: 1,
                        fillOpacity: 0.95
                    }).addTo(map);
                    animMarker.bindPopup('<strong>🐘 Estimated Elephant Sighting</strong><br>Uncertainty Radius: &plusmn;' + Math.round(animUncert) + 'm');

                    L.circle([animLat, animLon], {
                        radius: animUncert,
                        color: '#f59e0b',
                        dashArray: '4, 4',
                        weight: 1.5,
                        fillColor: '#f59e0b',
                        fillOpacity: 0.15
                    }).addTo(map);

                    boundsGroup.push([animLat, animLon]);

                    // Connect observer to animal
                    if (obsLat !== null && obsLon !== null) {
                        L.polyline([[obsLat, obsLon], [animLat, animLon]], {
                            color: '#94a3b8',
                            weight: 2,
                            dashArray: '5, 5'
                        }).addTo(map);
                    }
                }

                // 3. Railway GeoJSON Track Layer
                fetch('<?php echo Router::url("assets/data/railway_routes.geojson"); ?>')
                    .then(res => res.json())
                    .then(geoJsonData => {
                        L.geoJSON(geoJsonData, {
                            style: {
                                color: '#10b981',
                                weight: 3.5,
                                opacity: 0.85
                            }
                        }).addTo(map);
                    })
                    .catch(err => console.warn('Could not load railway GeoJSON overlay', err));

                if (boundsGroup.length > 0) {
                    map.fitBounds(L.latLngBounds(boundsGroup), { padding: [40, 40], maxZoom: 16 });
                }
            });
        </script>
    <?php endif; ?>

    <!-- Verification Decision Confirmation & Client-Side Scripts -->
    <script>
        function handleVerificationSubmit(decision) {
            const notesEl = document.getElementById('verification_notes');
            const notes = notesEl ? notesEl.value.trim() : '';

            if (decision === 'reject') {
                if (notes.length < 10) {
                    alert('Rejection requires mandatory operational notes of at least 10 characters explaining the reason.');
                    if (notesEl) notesEl.focus();
                    return;
                }
                if (!confirm('Are you certain you wish to mark this observation report as REJECTED?')) {
                    return;
                }
            } else {
                if (!confirm('Confirm biological verification for Report #<?php echo $selectedReport["id"]; ?>?')) {
                    return;
                }
            }

            document.getElementById('verificationActionInput').value = decision;
            
            // Prevent duplicate clicks
            const btnV = document.getElementById('btnVerifyReport');
            const btnR = document.getElementById('btnRejectReport');
            if (btnV) btnV.disabled = true;
            if (btnR) btnR.disabled = true;

            document.getElementById('verificationForm').submit();
        }

        function updateCharCounter(el) {
            const counter = document.getElementById('inquiryCharCounter');
            if (counter) {
                counter.textContent = el.value.length + ' / 5,000 characters';
            }
        }
    </script>

<?php endif; ?>

<!-- ======================================================================= -->
<!-- VERIFICATION DASHBOARD REPORT LIST & FILTERS                            -->
<!-- ======================================================================= -->
<div class="card-panel">
    <div class="panel-header" style="flex-wrap:wrap; gap:12px;">
        <h2 class="panel-title">
            Observation Queue 
            <?php if (!empty($statusFilter)): ?>
                &bull; <span style="font-weight:normal; font-size:1rem; color:var(--text-secondary);"><?php echo e($statusFilter); ?> (<?php echo $totalMatching; ?>)</span>
            <?php else: ?>
                &bull; <span style="font-weight:normal; font-size:1rem; color:var(--text-secondary);">All Reports (<?php echo $totalMatching; ?>)</span>
            <?php endif; ?>
        </h2>

        <!-- Search & Filter Form -->
        <form action="<?php echo Router::url('wildlife/reports.php'); ?>" method="GET" style="display:flex; gap:8px; flex-wrap:wrap;">
            <?php if (!empty($statusFilter)): ?>
                <input type="hidden" name="status" value="<?php echo e($statusFilter); ?>">
            <?php endif; ?>

            <input type="text" name="q" class="form-control" style="font-size:0.85rem; padding:6px 12px; width:220px;" 
                   placeholder="Search ID, observer, notes..." value="<?php echo e($searchQuery); ?>">

            <select name="section_id" class="form-control" style="font-size:0.85rem; padding:6px 10px;">
                <option value="">All Railway Sections</option>
                <?php foreach ($sections as $sec): ?>
                    <option value="<?php echo $sec['id']; ?>" <?php echo $sectionFilter === (int)$sec['id'] ? 'selected' : ''; ?>>
                        Sec <?php echo $sec['id']; ?>: <?php echo e($sec['section_name']); ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <select name="urgency_level" class="form-control" style="font-size:0.85rem; padding:6px 10px;">
                <option value="">All Urgency Levels</option>
                <option value="Critical" <?php echo $urgencyFilter === 'Critical' ? 'selected' : ''; ?>>🔴 Critical</option>
                <option value="High" <?php echo $urgencyFilter === 'High' ? 'selected' : ''; ?>>🟠 High</option>
                <option value="Medium" <?php echo $urgencyFilter === 'Medium' ? 'selected' : ''; ?>>🟡 Medium</option>
                <option value="Low" <?php echo $urgencyFilter === 'Low' ? 'selected' : ''; ?>>🟢 Low</option>
            </select>

            <button type="submit" class="btn btn-outline btn-sm">Filter</button>
            <?php if (!empty($searchQuery) || !empty($sectionFilter) || !empty($urgencyFilter)): ?>
                <a href="<?php echo Router::url('wildlife/reports.php' . (!empty($statusFilter) ? '?status=' . urlencode($statusFilter) : '')); ?>" 
                   class="btn btn-outline btn-sm" title="Clear search and filters">Reset</a>
            <?php endif; ?>
        </form>
    </div>

    <?php if (empty($reports)): ?>
        <div style="padding:40px 20px; text-align:center; color:var(--text-secondary);">
            <div style="font-size:2rem; margin-bottom:8px;">🔍</div>
            <strong style="color:#fff; font-size:1.1rem;">No reports match the current filter criteria</strong>
            <p style="margin-top:6px; font-size:0.88rem;">Try clearing the search query or selecting a different status filter.</p>
        </div>
    <?php else: ?>
        <div style="overflow-x:auto;">
            <table class="data-table" style="width:100%;">
                <thead>
                    <tr>
                        <th style="width:6%;">ID</th>
                        <th style="width:16%;">Date / Observation</th>
                        <th style="width:18%;">Corridor & Chainage</th>
                        <th style="width:14%;">Observer</th>
                        <th style="width:8%;">Count</th>
                        <th style="width:10%;">Urgency</th>
                        <th style="width:14%;">Status</th>
                        <th style="width:14%;">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($reports as $rep): ?>
                        <?php
                        $rSt = $rep['report_status'];
                        $rBadge = 'badge-outline';
                        if ($rSt === 'Pending') $rBadge = 'badge-status-pending';
                        elseif ($rSt === 'Needs Information') $rBadge = 'badge-status-needs-info';
                        elseif ($rSt === 'Clarification Submitted') $rBadge = 'badge-status-clarification-submitted';
                        elseif ($rSt === 'Verified') $rBadge = 'badge-status-verified';
                        elseif ($rSt === 'Rejected') $rBadge = 'badge-status-rejected';
                        ?>
                        <tr>
                            <td><strong>#<?php echo $rep['id']; ?></strong></td>
                            <td>
                                <code><?php echo date('Y-m-d H:i', strtotime($rep['created_at'])); ?></code>
                                <div style="font-size:0.75rem; color:var(--text-secondary); margin-top:2px;">
                                    <?php echo TemporalService::formatSightingSummary($rep); ?>
                                </div>
                            </td>
                            <td>
                                <strong><?php echo e($rep['section_name']); ?></strong>
                                <?php if (!empty($rep['nearest_milestone_ref'])): ?>
                                    <div style="font-size:0.78rem; color:var(--text-secondary);">
                                        <code><?php echo e($rep['nearest_milestone_ref']); ?></code>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span><?php echo e($rep['reporter_name'] ?? 'Authorized Submitter'); ?></span>
                            </td>
                            <td>
                                <strong style="font-size:1.05rem; color:var(--accent-color);"><?php echo (int)$rep['elephant_count']; ?></strong>
                            </td>
                            <td>
                                <span class="badge <?php echo get_risk_badge_class($rep['urgency_level']); ?>">
                                    <?php echo e($rep['urgency_level']); ?>
                                </span>
                            </td>
                            <td>
                                <span class="badge <?php echo $rBadge; ?>" style="font-size:0.78rem; padding:4px 8px;">
                                    <?php echo e($rSt); ?>
                                </span>
                            </td>
                            <td>
                                <a href="<?php echo Router::url('wildlife/reports.php?id=' . $rep['id'] . (!empty($statusFilter) ? '&status=' . urlencode($statusFilter) : '')); ?>" 
                                   class="btn btn-outline btn-sm" style="font-size:0.8rem; padding:4px 10px;">
                                    🔍 Review & Verify
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        <?php if ($totalPages > 1): ?>
            <div class="verification-pagination">
                <span style="font-size:0.85rem; color:var(--text-secondary);">
                    Showing page <?php echo $page; ?> of <?php echo $totalPages; ?> (<?php echo $totalMatching; ?> reports total)
                </span>
                <div class="pagination-pages">
                    <?php if ($page > 1): ?>
                        <a href="<?php echo Router::url('wildlife/reports.php?' . http_build_query(array_merge($_GET, ['page' => $page - 1]))); ?>" 
                           class="pagination-link">&larr; Prev</a>
                    <?php endif; ?>
                    
                    <?php for ($p = 1; $p <= $totalPages; $p++): ?>
                        <?php if ($p == 1 || $p == $totalPages || abs($p - $page) <= 2): ?>
                            <a href="<?php echo Router::url('wildlife/reports.php?' . http_build_query(array_merge($_GET, ['page' => $p]))); ?>" 
                               class="pagination-link <?php echo $p === $page ? 'active' : ''; ?>">
                                <?php echo $p; ?>
                            </a>
                        <?php elseif ($p == 2 || $p == $totalPages - 1): ?>
                            <span style="padding:6px; color:var(--text-secondary);">&hellip;</span>
                        <?php endif; ?>
                    <?php endfor; ?>

                    <?php if ($page < $totalPages): ?>
                        <a href="<?php echo Router::url('wildlife/reports.php?' . http_build_query(array_merge($_GET, ['page' => $page + 1]))); ?>" 
                           class="pagination-link">Next &rarr;</a>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../../app/layouts/dashboard_footer.php'; ?>
