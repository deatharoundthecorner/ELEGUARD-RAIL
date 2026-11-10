<?php
/**
 * Control Desk Reports & Alert Conversion
 */
$pageTitle = "Convert Reports";
$allowed_roles = 'control_officer';
require_once __DIR__ . '/../../app/layouts/dashboard_header.php';
require_once __DIR__ . '/../../app/services/ReportService.php';
require_once __DIR__ . '/../../app/services/AlertService.php';
require_once __DIR__ . '/../../app/services/TemporalService.php';
require_once __DIR__ . '/../../app/services/RiskService.php';
require_once __DIR__ . '/../../app/includes/validation.php';

$errors = [];

// Handle report verification or alert conversion POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validate_csrf();
    
    $action = $_POST['action'] ?? '';
    
    if ($action === 'convert_alert') {
        $reportId = $_POST['report_id'] ?? null;
        $sectionId = $_POST['section_id'] ?? '';
        $alertLevel = $_POST['alert_level'] ?? 'Medium';
        $alertMessage = trim($_POST['alert_message'] ?? '');
        
        $reqErrors = Validation::required($_POST, ['section_id', 'alert_level', 'alert_message']);
        $errors = array_merge($errors, $reqErrors);
        
        if (empty($errors)) {
            $alertId = AlertService::createAlert(
                $reportId ? (int)$reportId : null,
                (int)$sectionId,
                $alertLevel,
                $alertMessage,
                $currentUser['id']
            );
            
            if ($alertId) {
                Session::flash('success', "Safety warning alert broadcasted successfully (Alert #{$alertId}).");
                Router::redirect('control/alerts.php');
            } else {
                $errors['submit'] = "Failed to broadcast warning alert.";
            }
        }
    } elseif ($action === 'verify') {
        $reportId = $_POST['report_id'] ?? '';
        if (ReportService::updateStatus((int)$reportId, 'Verified', $currentUser['id'])) {
            Session::flash('success', "Report #{$reportId} marked as Verified. Ready for alert broadcast.");
            Router::redirect("control/reports.php?id={$reportId}");
        } else {
            $errors['submit'] = "Failed to update report status.";
        }
    } elseif ($action === 'reject') {
        $reportId = $_POST['report_id'] ?? '';
        if (ReportService::updateStatus((int)$reportId, 'Rejected', $currentUser['id'])) {
            Session::flash('success', "Report #{$reportId} rejected.");
            Router::redirect('control/reports.php');
        } else {
            $errors['submit'] = "Failed to update report status.";
        }
    }
}

// Fetch single report details if ID is requested
$selectedReport = null;
$selectedId = $_GET['id'] ?? null;
$shadowRisk = null;
$delayInfo = null;
if ($selectedId) {
    $selectedReport = ReportService::getById((int)$selectedId);
    if ($selectedReport) {
        $db = Database::getInstance()->getConnection();
        $alertStmt = $db->prepare("SELECT COUNT(*) FROM alerts WHERE section_id = :sec_id AND alert_status = 'Active'");
        $alertStmt->execute([':sec_id' => $selectedReport['section_id']]);
        $hasActiveAlert = ((int)$alertStmt->fetchColumn()) > 0;

        $reportContext = array_merge($selectedReport, [
            'section_risk' => $selectedReport['section_risk_level'] ?? 'Low',
            'active_alerts' => $hasActiveAlert
        ]);

        $shadowRisk = RiskService::calculateObservationAwareRisk($reportContext, $selectedReport, ['enable_decay' => false]);
        $delayInfo = TemporalService::calculateReportingDelay($selectedReport);
    }
}

// Fetch all reports with filters
$statusFilter = $_GET['status'] ?? null;
$sectionFilter = $_GET['section_id'] ?? null;
$urgencyFilter = $_GET['urgency_level'] ?? null;

$reports = ReportService::getAll($statusFilter, $sectionFilter, $urgencyFilter);
$sections = AlertService::getAllSections();
?>

<div class="dashboard-title-row">
    <h1>Process Sighting Reports</h1>
    <p class="text-secondary">Review ranger-verified reports and convert them into live safety alerts broadcasted to train dashboards.</p>
</div>

<?php if ($selectedReport): ?>
    <!-- Conversion & Review Panel -->
    <div class="card-panel" style="border-top: 4px solid var(--danger); max-width: 950px; margin-bottom: 40px;">
        <div class="panel-header">
            <h2 class="panel-title">Actioning Sighting Report #<?php echo $selectedReport['id']; ?></h2>
            <a href="<?php echo Router::url('control/reports.php'); ?>" class="btn btn-outline btn-sm">Close Review</a>
        </div>
        
        <div class="grid grid-2col" style="gap:30px;">
            <div class="report-meta-summary">
                <table class="data-table" style="font-size:0.9rem;">
                    <tr>
                        <td style="color:var(--text-secondary); width:40%;">Date Filed:</td>
                        <td><code><?php echo format_date($selectedReport['created_at']); ?> SLST</code></td>
                    </tr>
                    <tr>
                        <td style="color:var(--text-secondary);">Observation Time:</td>
                        <td>
                            <strong><?php echo TemporalService::formatSightingSummary($selectedReport); ?></strong>
                            <div style="margin-top:4px;">
                                <?php echo TemporalService::renderPrecisionBadge($selectedReport['sighting_time_precision'], $selectedReport['sighting_time_window'], $selectedReport['sighting_time_uncertainty_minutes']); ?>
                            </div>
                        </td>
                    </tr>
                    <?php if ($delayInfo): ?>
                    <tr>
                        <td style="color:var(--text-secondary);">Reporting Delay:</td>
                        <td>
                            <?php 
                            $badgeColor = !empty($delayInfo['is_chronology_inconsistent']) ? 'var(--danger)' : ($delayInfo['is_delayed'] ? 'var(--warning)' : 'var(--success)');
                            $badgeIcon = !empty($delayInfo['is_chronology_inconsistent']) ? '⚠️ ' : ($delayInfo['is_delayed'] ? '⏳ ' : '⚡ ');
                            ?>
                            <span style="color:<?php echo $badgeColor; ?>; font-weight:500;">
                                <?php echo $badgeIcon; ?><?php echo e($delayInfo['label']); ?>
                            </span>
                        </td>
                    </tr>
                    <?php endif; ?>
                    <tr>
                        <td style="color:var(--text-secondary);">Section:</td>
                        <td><strong><?php echo e($selectedReport['section_name']); ?></strong></td>
                    </tr>
                    <tr>
                        <td style="color:var(--text-secondary);">Urgency:</td>
                        <td><span class="badge <?php echo get_risk_badge_class($selectedReport['urgency_level']); ?>"><?php echo e($selectedReport['urgency_level']); ?></span></td>
                    </tr>
                    <tr>
                        <td style="color:var(--text-secondary);">Operational Risk:</td>
                        <td>
                            <span class="badge <?php echo get_risk_badge_class($selectedReport['risk_score']); ?>"><?php echo e($selectedReport['risk_score']); ?></span>
                            <span class="badge badge-success" style="font-size:0.7rem; margin-left:4px;">Active</span>
                        </td>
                    </tr>
                    <tr>
                        <td style="color:var(--text-secondary);">Report Status:</td>
                        <td><span class="badge badge-outline"><?php echo e($selectedReport['report_status']); ?></span></td>
                    </tr>
                    <tr>
                        <td style="color:var(--text-secondary);">Location:</td>
                        <td>
                            <code><?php echo e($selectedReport['location_description']); ?></code>
                            <?php if (!empty($selectedReport['latitude']) && !empty($selectedReport['longitude'])): ?>
                                <div style="margin-top:4px;">
                                    <a href="https://www.google.com/maps?q=<?php echo urlencode($selectedReport['latitude'] . ',' . $selectedReport['longitude']); ?>" target="_blank" rel="noopener" style="color:var(--accent-color); text-decoration:underline;">
                                        📍 GPS: <?php echo number_format($selectedReport['latitude'], 6) . ', ' . number_format($selectedReport['longitude'], 6); ?>
                                    </a>
                                    <?php if (!empty($selectedReport['gps_accuracy_meters'])): ?>
                                        <span class="text-muted" style="font-size:0.75rem;">(&plusmn;<?php echo round($selectedReport['gps_accuracy_meters']); ?>m)</span>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <td style="color:var(--text-secondary);">Elephants:</td>
                        <td><strong style="color:var(--accent-color);"><?php echo e($selectedReport['elephant_count']); ?> spotted</strong></td>
                    </tr>
                </table>
                
                <?php if (!empty($selectedReport['image_path'])): ?>
                    <div style="margin-top:15px; text-align:center;">
                        <a href="<?php echo Router::url($selectedReport['image_path']); ?>" target="_blank">
                            <img src="<?php echo Router::url($selectedReport['image_path']); ?>" alt="Attachment" style="max-width:100%; max-height:180px; border-radius:4px; border:1px solid var(--border-color); object-fit:contain;">
                        </a>
                    </div>
                <?php endif; ?>
                
                <div style="margin-top:15px; background:rgba(0,0,0,0.1); padding:12px; border-radius:4px; font-size:0.85rem; border:1px solid var(--border-color);">
                    <strong style="color:var(--text-secondary);">Details:</strong>
                    <p style="margin-top:4px;"><?php echo e($selectedReport['description'] ? $selectedReport['description'] : 'No comments.'); ?></p>
                </div>
            </div>
            
            <div class="report-action-form">
                <?php if ($selectedReport['report_status'] === 'Verified'): ?>
                    <h3 style="margin-bottom:15px; font-size:1.1rem; color:#fff;">Convert to Live Train Warning</h3>
                    
                    <?php if (isset($errors['submit'])): ?>
                        <div class="alert alert-danger" style="padding:8px 12px; font-size:0.85rem;"><?php echo e($errors['submit']); ?></div>
                    <?php endif; ?>
                    
                    <form action="<?php echo Router::url('control/reports.php'); ?>" method="POST">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="action" value="convert_alert">
                        <input type="hidden" name="report_id" value="<?php echo $selectedReport['id']; ?>">
                        <input type="hidden" name="section_id" value="<?php echo $selectedReport['section_id']; ?>">
                        
                        <div class="form-group">
                            <label class="form-label">Railway Section</label>
                            <input type="text" class="form-control" value="<?php echo e($selectedReport['section_name']); ?>" disabled>
                        </div>
                        
                        <div class="form-group">
                            <label for="alert_level" class="form-label">Warning Alert Level (Matches Computed Risk)</label>
                            <select name="alert_level" id="alert_level" class="form-control" required>
                                <option value="Low" <?php echo $selectedReport['risk_score'] === 'Low' ? 'selected' : ''; ?>>Low Sighting</option>
                                <option value="Medium" <?php echo $selectedReport['risk_score'] === 'Medium' ? 'selected' : ''; ?>>Medium Warning</option>
                                <option value="High" <?php echo $selectedReport['risk_score'] === 'High' ? 'selected' : ''; ?>>High Danger</option>
                                <option value="Critical" <?php echo $selectedReport['risk_score'] === 'Critical' ? 'selected' : ''; ?>>Critical Collision Hazard</option>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label for="alert_message" class="form-label">Broadcast Warning Message *</label>
                            <?php
                            $defaultMsg = "COLLISION HAZARD: Sighting of " . $selectedReport['elephant_count'] . " elephant(s) verified in " . $selectedReport['section_name'] . " section near " . $selectedReport['location_description'] . ". Reduce speed and watch tracks.";
                            ?>
                            <textarea name="alert_message" id="alert_message" class="form-control" required style="font-size:0.85rem; height:120px;"><?php echo e($defaultMsg); ?></textarea>
                            <?php if (isset($errors['alert_message'])): ?>
                                <span class="field-error" style="color:var(--danger); font-size:0.8rem;"><?php echo e($errors['alert_message']); ?></span>
                            <?php endif; ?>
                        </div>
                        
                        <div class="form-actions" style="margin-top:15px;">
                            <button type="submit" class="btn btn-danger" style="width:100%;">🚨 Broadcast Warning Alert</button>
                        </div>
                    </form>
                <?php elseif ($selectedReport['report_status'] === 'Pending'): ?>
                    <div style="text-align:center; padding:30px 10px;">
                        <h3 style="color:var(--accent-color); margin-bottom:15px;">Awaiting Ranger Verification</h3>
                        <p class="text-secondary" style="font-size:0.85rem; margin-bottom:20px;">This report has not been verified by a wildlife ranger yet. If you have confirmed details from another source, you can verify it now or reject it.</p>
                        
                        <div style="display:flex; gap:10px;">
                            <form action="<?php echo Router::url('control/reports.php'); ?>" method="POST" style="flex:1;">
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="action" value="verify">
                                <input type="hidden" name="report_id" value="<?php echo $selectedReport['id']; ?>">
                                <button type="submit" class="btn btn-primary" style="width:100%;">Verify Sighting</button>
                            </form>
                            <form action="<?php echo Router::url('control/reports.php'); ?>" method="POST" style="flex:1;">
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="action" value="reject">
                                <input type="hidden" name="report_id" value="<?php echo $selectedReport['id']; ?>">
                                <button type="submit" class="btn btn-outline" style="width:100%; border-color:var(--danger); color:var(--danger);">Reject</button>
                            </form>
                        </div>
                    </div>
                <?php else: ?>
                    <div style="text-align:center; padding:50px 20px; background-color:rgba(255,255,255,0.02); border-radius:6px; color:var(--text-muted);">
                        Report processed with status: <strong><?php echo $selectedReport['report_status']; ?></strong>. No control actions required.
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Dual Risk Assessment & Shadow Mode Panel -->
        <div class="dual-risk-panel" style="margin-top:30px; border-top:1px solid var(--border-color); padding-top:20px;">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px; flex-wrap:wrap; gap:10px;">
                <h3 style="font-size:1.1rem; margin:0; display:flex; align-items:center; gap:8px;">
                    <span>🛡️</span>
                    <span>Risk Assessment Comparison</span>
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
                            <span class="badge badge-success" style="font-size:0.72rem; padding:3px 8px;">ACTIVE CONTROLLER</span>
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
<?php endif; ?>

<!-- Filters Card -->
<div class="filter-card">
    <form action="<?php echo Router::url('control/reports.php'); ?>" method="GET" class="filter-form">
        <div class="filter-group">
            <label for="status" class="form-label">Sighting Status</label>
            <select name="status" id="status" class="form-control">
                <option value="">All Statuses</option>
                <option value="Pending" <?php echo $statusFilter === 'Pending' ? 'selected' : ''; ?>>Pending (Unverified)</option>
                <option value="Verified" <?php echo $statusFilter === 'Verified' ? 'selected' : ''; ?>>Verified (Confirmed)</option>
                <option value="Rejected" <?php echo $statusFilter === 'Rejected' ? 'selected' : ''; ?>>Rejected</option>
                <option value="Converted to Alert" <?php echo $statusFilter === 'Converted to Alert' ? 'selected' : ''; ?>>Converted to Alert</option>
                <option value="Resolved" <?php echo $statusFilter === 'Resolved' ? 'selected' : ''; ?>>Resolved</option>
            </select>
        </div>

        <div class="filter-group">
            <label for="section_id" class="form-label">Track Section</label>
            <select name="section_id" id="section_id" class="form-control">
                <option value="">All Sections</option>
                <?php foreach ($sections as $sec): ?>
                    <option value="<?php echo $sec['id']; ?>" <?php echo $sectionFilter == $sec['id'] ? 'selected' : ''; ?>>
                        <?php echo e($sec['section_name']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="filter-btn-group">
            <button type="submit" class="btn btn-primary">Filter</button>
            <a href="<?php echo Router::url('control/reports.php'); ?>" class="btn btn-secondary">Reset</a>
        </div>
    </form>
</div>

<!-- Sightings List Panel -->
<div class="card-panel">
    <div class="table-responsive">
        <?php if (empty($reports)): ?>
            <p class="text-center text-muted" style="padding: 40px 0;">No elephant reports matches the selected filters.</p>
        <?php else: ?>
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Date Filed</th>
                        <th>Reporter</th>
                        <th>Railway Section</th>
                        <th>Count</th>
                        <th>Urgency</th>
                        <th>Computed Risk</th>
                        <th>Status</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($reports as $rep): ?>
                        <tr>
                            <td><?php echo format_date($rep['created_at']); ?></td>
                            <td><?php echo e($rep['reporter_name']); ?></td>
                            <td>
                                <strong><?php echo e($rep['section_name']); ?></strong>
                                <?php if (!empty($rep['latitude']) && !empty($rep['longitude'])): ?>
                                    <div style="font-size:0.75rem; margin-top:2px;">
                                        <a href="https://www.google.com/maps?q=<?php echo urlencode($rep['latitude'] . ',' . $rep['longitude']); ?>" target="_blank" rel="noopener" style="color:var(--accent-color);">
                                            📍 <?php echo number_format($rep['latitude'], 4) . ', ' . number_format($rep['longitude'], 4); ?>
                                        </a>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td><?php echo e($rep['elephant_count']); ?></td>
                            <td>
                                <span class="badge <?php echo get_risk_badge_class($rep['urgency_level']); ?>">
                                    <?php echo e($rep['urgency_level']); ?>
                                </span>
                            </td>
                            <td>
                                <span class="badge <?php echo get_risk_badge_class($rep['risk_score']); ?>">
                                    <?php echo e($rep['risk_score']); ?>
                                </span>
                            </td>
                            <td>
                                <span class="badge <?php 
                                    $st = $rep['report_status'];
                                    if ($st === 'Pending') echo 'badge-warning';
                                    elseif ($st === 'Verified') echo 'badge-success';
                                    elseif ($st === 'Converted to Alert') echo 'badge-danger';
                                    elseif ($st === 'Resolved') echo 'badge-success';
                                    else echo 'badge-outline';
                                ?>">
                                    <?php echo e($rep['report_status']); ?>
                                </span>
                            </td>
                            <td>
                                <a href="<?php echo Router::url('control/reports.php?id=' . $rep['id']); ?>" class="btn btn-outline btn-sm">Process</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../../app/layouts/dashboard_footer.php'; ?>
