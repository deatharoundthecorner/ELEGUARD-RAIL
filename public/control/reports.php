<?php
/**
 * Control Desk Reports & Alert Conversion
 */
$pageTitle = "Convert Reports";
$allowed_roles = 'control_officer';
require_once __DIR__ . '/../../app/layouts/dashboard_header.php';
require_once __DIR__ . '/../../app/services/ReportService.php';
require_once __DIR__ . '/../../app/services/AlertService.php';
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
if ($selectedId) {
    $selectedReport = ReportService::getById((int)$selectedId);
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
    <div class="card-panel" style="border-top: 4px solid var(--danger); max-width: 850px; margin-bottom: 40px;">
        <div class="panel-header">
            <h2 class="panel-title">Actioning Sighting Report #<?php echo $selectedReport['id']; ?></h2>
            <a href="<?php echo Router::url('control/reports.php'); ?>" class="btn btn-outline btn-sm">Close Review</a>
        </div>
        
        <div class="grid grid-2col" style="gap:30px;">
            <div class="report-meta-summary">
                <table class="data-table" style="font-size:0.9rem;">
                    <tr>
                        <td style="color:var(--text-secondary); width:40%;">Date/Time:</td>
                        <td><?php echo format_date($selectedReport['created_at']); ?></td>
                    </tr>
                    <tr>
                        <td style="color:var(--text-secondary);">Section:</td>
                        <td><strong><?php echo e($selectedReport['section_name']); ?></strong></td>
                    </tr>
                    <tr>
                        <td style="color:var(--text-secondary);">Urgency:</td>
                        <td><span class="badge <?php echo get_risk_badge_class($selectedReport['urgency_level']); ?>"><?php echo e($selectedReport['urgency_level']); ?></span></td>
                    </tr>
                    <tr>
                        <td style="color:var(--text-secondary);">Computed Risk:</td>
                        <td><span class="badge <?php echo get_risk_badge_class($selectedReport['risk_score']); ?>"><?php echo e($selectedReport['risk_score']); ?></span></td>
                    </tr>
                    <tr>
                        <td style="color:var(--text-secondary);">Report Status:</td>
                        <td><span class="badge badge-outline"><?php echo e($selectedReport['report_status']); ?></span></td>
                    </tr>
                    <tr>
                        <td style="color:var(--text-secondary);">Location:</td>
                        <td><code><?php echo e($selectedReport['location_description']); ?></code></td>
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
                            <td><strong><?php echo e($rep['section_name']); ?></strong></td>
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
