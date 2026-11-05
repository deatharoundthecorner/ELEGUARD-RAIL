<?php
/**
 * Wildlife Sighting Reports Verification Area
 */
$pageTitle = "Verify Reports";
$allowed_roles = 'wildlife_officer';
require_once __DIR__ . '/../../app/layouts/dashboard_header.php';
require_once __DIR__ . '/../../app/services/ReportService.php';
require_once __DIR__ . '/../../app/services/AlertService.php';

$errors = [];

// Handle verify/reject actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validate_csrf();
    
    $action = $_POST['action'] ?? '';
    $reportId = $_POST['report_id'] ?? '';
    
    if ($action === 'verify') {
        if (ReportService::updateStatus((int)$reportId, 'Verified', $currentUser['id'])) {
            Session::flash('success', "Report #{$reportId} successfully verified.");
            Router::redirect('wildlife/reports.php');
        } else {
            $errors['action'] = "Failed to update report status.";
        }
    } elseif ($action === 'reject') {
        if (ReportService::updateStatus((int)$reportId, 'Rejected', $currentUser['id'])) {
            Session::flash('success', "Report #{$reportId} successfully marked as rejected.");
            Router::redirect('wildlife/reports.php');
        } else {
            $errors['action'] = "Failed to update report status.";
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
    <h1>Manage Elephant Movement Reports</h1>
    <p class="text-secondary">Track community and team submissions, confirm threat accuracy, and coordinate warnings.</p>
</div>

<?php if ($selectedReport): ?>
    <!-- Detailed Report Review Panel -->
    <div class="card-panel" style="border-top: 4px solid var(--accent-color); max-width: 800px; margin-bottom: 40px;">
        <div class="panel-header">
            <h2 class="panel-title">Reviewing Sighting Report #<?php echo $selectedReport['id']; ?></h2>
            <a href="<?php echo Router::url('wildlife/reports.php'); ?>" class="btn btn-outline btn-sm">Close Review</a>
        </div>
        
        <div class="grid grid-2col" style="gap:30px;">
            <div class="report-details-info">
                <table class="data-table" style="font-size:0.95rem;">
                    <tr>
                        <td style="color:var(--text-secondary); width:40%;">Submitted By:</td>
                        <td><strong><?php echo e($selectedReport['reporter_name']); ?></strong></td>
                    </tr>
                    <tr>
                        <td style="color:var(--text-secondary);">Date / Time:</td>
                        <td><?php echo format_date($selectedReport['created_at']); ?></td>
                    </tr>
                    <tr>
                        <td style="color:var(--text-secondary);">Railway Section:</td>
                        <td><strong><?php echo e($selectedReport['section_name']); ?></strong></td>
                    </tr>
                    <tr>
                        <td style="color:var(--text-secondary);">Track Section Risk:</td>
                        <td><span class="badge <?php echo get_risk_badge_class($selectedReport['section_risk_level']); ?>"><?php echo e($selectedReport['section_risk_level']); ?></span></td>
                    </tr>
                    <tr>
                        <td style="color:var(--text-secondary);">Location Details:</td>
                        <td><code><?php echo e($selectedReport['location_description']); ?></code></td>
                    </tr>
                    <tr>
                        <td style="color:var(--text-secondary);">Elephant Count:</td>
                        <td><strong style="font-size:1.1rem; color:var(--accent-color);"><?php echo e($selectedReport['elephant_count']); ?></strong></td>
                    </tr>
                    <tr>
                        <td style="color:var(--text-secondary);">Sighting Urgency:</td>
                        <td><span class="badge <?php echo get_risk_badge_class($selectedReport['urgency_level']); ?>"><?php echo e($selectedReport['urgency_level']); ?></span></td>
                    </tr>
                    <tr>
                        <td style="color:var(--text-secondary);">Computed Risk Category:</td>
                        <td><span class="badge <?php echo get_risk_badge_class($selectedReport['risk_score']); ?>"><?php echo e($selectedReport['risk_score']); ?></span></td>
                    </tr>
                    <tr>
                        <td style="color:var(--text-secondary);">Report Status:</td>
                        <td>
                            <strong style="color:var(--accent-color);"><?php echo e($selectedReport['report_status']); ?></strong>
                        </td>
                    </tr>
                </table>
                
                <div style="margin-top:20px; background-color:rgba(255,255,255,0.02); padding:15px; border-radius:6px; border:1px solid var(--border-color);">
                    <h4 style="margin-bottom:8px; font-size:0.9rem; color:var(--text-secondary);">Observation Description:</h4>
                    <p style="font-size:0.9rem; line-height:1.5; color:#fff;"><?php echo nl2br(e($selectedReport['description'] ? $selectedReport['description'] : 'No description provided.')); ?></p>
                </div>
            </div>
            
            <div class="report-details-media" style="display:flex; flex-direction:column; gap:15px; align-items:center; justify-content:center;">
                <?php if (!empty($selectedReport['image_path'])): ?>
                    <label style="align-self:flex-start; font-size:0.85rem; color:var(--text-secondary);">Sighting Attachment:</label>
                    <a href="<?php echo Router::url($selectedReport['image_path']); ?>" target="_blank">
                        <img src="<?php echo Router::url($selectedReport['image_path']); ?>" alt="Sighting media" style="max-width:100%; max-height:260px; border-radius:6px; border:1px solid var(--border-color); object-fit:contain;">
                    </a>
                <?php else: ?>
                    <div style="width:100%; height:200px; background:rgba(255,255,255,0.01); border:1px dashed var(--border-color); border-radius:6px; display:flex; justify-content:center; align-items:center; color:var(--text-muted);">
                        No Photo Attachment
                    </div>
                <?php endif; ?>
                
                <?php if ($selectedReport['report_status'] === 'Pending'): ?>
                    <div class="review-actions-wrap" style="width:100%; display:flex; gap:10px; margin-top:20px;">
                        <form action="<?php echo Router::url('wildlife/reports.php'); ?>" method="POST" style="flex:1;">
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="report_id" value="<?php echo $selectedReport['id']; ?>">
                            <input type="hidden" name="action" value="verify">
                            <button type="submit" class="btn btn-primary" style="width:100%;">✔️ Verify Report</button>
                        </form>
                        <form action="<?php echo Router::url('wildlife/reports.php'); ?>" method="POST" style="flex:1;">
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="report_id" value="<?php echo $selectedReport['id']; ?>">
                            <input type="hidden" name="action" value="reject">
                            <button type="submit" class="btn btn-danger" style="width:100%;">❌ Reject Sighting</button>
                        </form>
                    </div>
                <?php else: ?>
                    <div style="width:100%; text-align:center; padding:15px; border-radius:6px; background-color:rgba(255,255,255,0.03); color:var(--text-secondary); font-size:0.9rem;">
                        This report has already been processed (<strong><?php echo $selectedReport['report_status']; ?></strong>).
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
<?php endif; ?>

<!-- Filtering Panel -->
<div class="filter-card">
    <form action="<?php echo Router::url('wildlife/reports.php'); ?>" method="GET" class="filter-form">
        <div class="filter-group">
            <label for="status" class="form-label">Filter by Status</label>
            <select name="status" id="status" class="form-control">
                <option value="">All Statuses</option>
                <option value="Pending" <?php echo $statusFilter === 'Pending' ? 'selected' : ''; ?>>Pending</option>
                <option value="Verified" <?php echo $statusFilter === 'Verified' ? 'selected' : ''; ?>>Verified</option>
                <option value="Rejected" <?php echo $statusFilter === 'Rejected' ? 'selected' : ''; ?>>Rejected</option>
                <option value="Converted to Alert" <?php echo $statusFilter === 'Converted to Alert' ? 'selected' : ''; ?>>Converted to Alert</option>
                <option value="Resolved" <?php echo $statusFilter === 'Resolved' ? 'selected' : ''; ?>>Resolved</option>
            </select>
        </div>

        <div class="filter-group">
            <label for="section_id" class="form-label">Railway Section</label>
            <select name="section_id" id="section_id" class="form-control">
                <option value="">All Sections</option>
                <?php foreach ($sections as $sec): ?>
                    <option value="<?php echo $sec['id']; ?>" <?php echo $sectionFilter == $sec['id'] ? 'selected' : ''; ?>>
                        <?php echo e($sec['section_name']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="filter-group">
            <label for="urgency_level" class="form-label">Urgency Level</label>
            <select name="urgency_level" id="urgency_level" class="form-control">
                <option value="">All Urgencies</option>
                <option value="Low" <?php echo $urgencyFilter === 'Low' ? 'selected' : ''; ?>>Low</option>
                <option value="Medium" <?php echo $urgencyFilter === 'Medium' ? 'selected' : ''; ?>>Medium</option>
                <option value="High" <?php echo $urgencyFilter === 'High' ? 'selected' : ''; ?>>High</option>
                <option value="Critical" <?php echo $urgencyFilter === 'Critical' ? 'selected' : ''; ?>>Critical</option>
            </select>
        </div>

        <div class="filter-btn-group">
            <button type="submit" class="btn btn-primary">Filter</button>
            <a href="<?php echo Router::url('wildlife/reports.php'); ?>" class="btn btn-secondary">Reset</a>
        </div>
    </form>
</div>

<!-- Elephant Sightings List Panel -->
<div class="card-panel">
    <div class="table-responsive">
        <?php if (empty($reports)): ?>
            <p class="text-center text-muted" style="padding: 40px 0;">No elephant movement reports match the selected filters.</p>
        <?php else: ?>
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Date Filed</th>
                        <th>Reporter</th>
                        <th>Railway Section</th>
                        <th>Elephant Count</th>
                        <th>Photo</th>
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
                                <?php if (!empty($rep['image_path'])): ?>
                                    <a href="<?php echo Router::url($rep['image_path']); ?>" target="_blank">
                                        <img src="<?php echo Router::url($rep['image_path']); ?>" alt="Thumbnail" class="report-thumbnail">
                                    </a>
                                <?php else: ?>
                                    <span class="text-muted">None</span>
                                <?php endif; ?>
                            </td>
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
                                <a href="<?php echo Router::url('wildlife/reports.php?id=' . $rep['id']); ?>" class="btn btn-outline btn-sm">Review</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../../app/layouts/dashboard_footer.php'; ?>
