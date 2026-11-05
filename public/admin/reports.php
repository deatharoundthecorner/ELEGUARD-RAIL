<?php
/**
 * Admin Sighting Reports Management panel
 */
$pageTitle = "Elephant Reports";
$allowed_roles = 'admin';
require_once __DIR__ . '/../../app/layouts/dashboard_header.php';
require_once __DIR__ . '/../../app/services/ReportService.php';
require_once __DIR__ . '/../../app/services/AlertService.php';

$errors = [];
$selectedId = $_GET['id'] ?? null;
$selectedReport = null;

if ($selectedId) {
    $selectedReport = ReportService::getById((int)$selectedId);
}

// Handle status updates
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validate_csrf();
    
    $reportId = $_POST['report_id'] ?? '';
    $status = $_POST['report_status'] ?? '';
    
    if (ReportService::updateStatus((int)$reportId, $status, $currentUser['id'])) {
        Session::flash('success', "Report #{$reportId} status successfully updated to '{$status}'.");
        Router::redirect('admin/reports.php');
    } else {
        $errors['submit'] = "Failed to update report status.";
    }
}

$statusFilter = $_GET['status'] ?? null;
$sectionFilter = $_GET['section_id'] ?? null;
$urgencyFilter = $_GET['urgency_level'] ?? null;

$reports = ReportService::getAll($statusFilter, $sectionFilter, $urgencyFilter);
$sections = AlertService::getAllSections();
?>

<div class="dashboard-title-row">
    <h1>Manage Elephant Sightings</h1>
    <p class="text-secondary">View all public and ranger sighting submissions, review computed threat risk scores, and check details.</p>
</div>

<?php if ($selectedReport): ?>
    <!-- Review card -->
    <div class="card-panel" style="border-top: 4px solid var(--accent-color); max-width: 800px; margin-bottom: 40px;">
        <div class="panel-header">
            <h2 class="panel-title">Reviewing Report #<?php echo $selectedReport['id']; ?></h2>
            <a href="<?php echo Router::url('admin/reports.php'); ?>" class="btn btn-outline btn-sm">Close Review</a>
        </div>

        <div class="grid grid-2col" style="gap:30px;">
            <div>
                <table class="data-table" style="font-size:0.95rem;">
                    <tr>
                        <td style="color:var(--text-secondary); width:40%;">Submitted By:</td>
                        <td><strong><?php echo e($selectedReport['reporter_name']); ?></strong></td>
                    </tr>
                    <tr>
                        <td style="color:var(--text-secondary);">Date Filed:</td>
                        <td><?php echo format_date($selectedReport['created_at']); ?></td>
                    </tr>
                    <tr>
                        <td style="color:var(--text-secondary);">Railway Section:</td>
                        <td><strong><?php echo e($selectedReport['section_name']); ?></strong></td>
                    </tr>
                    <tr>
                        <td style="color:var(--text-secondary);">Exact Location:</td>
                        <td><code><?php echo e($selectedReport['location_description']); ?></code></td>
                    </tr>
                    <tr>
                        <td style="color:var(--text-secondary);">Elephant Count:</td>
                        <td><strong style="color:var(--accent-color);"><?php echo e($selectedReport['elephant_count']); ?></strong></td>
                    </tr>
                    <tr>
                        <td style="color:var(--text-secondary);">Urgency level:</td>
                        <td><span class="badge <?php echo get_risk_badge_class($selectedReport['urgency_level']); ?>"><?php echo e($selectedReport['urgency_level']); ?></span></td>
                    </tr>
                    <tr>
                        <td style="color:var(--text-secondary);">Computed Risk:</td>
                        <td><span class="badge <?php echo get_risk_badge_class($selectedReport['risk_score']); ?>"><?php echo e($selectedReport['risk_score']); ?></span></td>
                    </tr>
                </table>
                
                <div style="margin-top:20px; background:rgba(255,255,255,0.02); padding:15px; border-radius:6px; border:1px solid var(--border-color);">
                    <h4 style="color:var(--text-secondary); margin-bottom:5px; font-size:0.85rem;">Observation Description:</h4>
                    <p style="font-size:0.9rem;"><?php echo nl2br(e($selectedReport['description'] ? $selectedReport['description'] : 'No comments provided.')); ?></p>
                </div>
            </div>
            
            <div style="text-align:center; display:flex; flex-direction:column; justify-content:center; align-items:center; gap:20px;">
                <?php if (!empty($selectedReport['image_path'])): ?>
                    <a href="<?php echo Router::url($selectedReport['image_path']); ?>" target="_blank">
                        <img src="<?php echo Router::url($selectedReport['image_path']); ?>" alt="Attachment" style="max-width:100%; max-height:220px; border-radius:6px; border:1px solid var(--border-color); object-fit:contain;">
                    </a>
                <?php else: ?>
                    <div style="width:100%; height:180px; background:rgba(0,0,0,0.2); border:1px dashed var(--border-color); display:flex; justify-content:center; align-items:center; color:var(--text-muted); border-radius:6px;">No Image Sighted</div>
                <?php endif; ?>

                <form action="<?php echo Router::url('admin/reports.php'); ?>" method="POST" style="width:100%;">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="report_id" value="<?php echo $selectedReport['id']; ?>">
                    
                    <div class="form-group" style="text-align:left;">
                        <label for="report_status" class="form-label">Override/Update Report Status</label>
                        <select name="report_status" id="report_status" class="form-control" required>
                            <option value="Pending" <?php echo $selectedReport['report_status'] === 'Pending' ? 'selected' : ''; ?>>Pending</option>
                            <option value="Verified" <?php echo $selectedReport['report_status'] === 'Verified' ? 'selected' : ''; ?>>Verified</option>
                            <option value="Rejected" <?php echo $selectedReport['report_status'] === 'Rejected' ? 'selected' : ''; ?>>Rejected</option>
                            <option value="Converted to Alert" <?php echo $selectedReport['report_status'] === 'Converted to Alert' ? 'selected' : ''; ?>>Converted to Alert</option>
                            <option value="Resolved" <?php echo $selectedReport['report_status'] === 'Resolved' ? 'selected' : ''; ?>>Resolved</option>
                        </select>
                    </div>
                    
                    <button type="submit" class="btn btn-accent" style="width:100%;">Update Status</button>
                </form>
            </div>
        </div>
    </div>
<?php endif; ?>

<!-- Filters -->
<div class="filter-card">
    <form action="<?php echo Router::url('admin/reports.php'); ?>" method="GET" class="filter-form">
        <div class="filter-group">
            <label for="status" class="form-label">Sighting Status</label>
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

        <div class="filter-btn-group">
            <button type="submit" class="btn btn-primary">Filter</button>
            <a href="<?php echo Router::url('admin/reports.php'); ?>" class="btn btn-secondary">Reset</a>
        </div>
    </form>
</div>

<!-- List panel -->
<div class="card-panel">
    <div class="table-responsive">
        <?php if (empty($reports)): ?>
            <p class="text-center text-muted" style="padding: 40px 0;">No elephant sighting reports matches standard filters.</p>
        <?php else: ?>
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Reporter</th>
                        <th>Railway Section</th>
                        <th>Location Details</th>
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
                            <td><?php echo e($rep['location_description']); ?></td>
                            <td><?php echo e($rep['elephant_count']); ?></td>
                            <td><span class="badge <?php echo get_risk_badge_class($rep['urgency_level']); ?>"><?php echo e($rep['urgency_level']); ?></span></td>
                            <td><span class="badge <?php echo get_risk_badge_class($rep['risk_score']); ?>"><?php echo e($rep['risk_score']); ?></span></td>
                            <td>
                                <span class="badge <?php 
                                    $st = $rep['report_status'];
                                    if ($st === 'Pending') echo 'badge-warning';
                                    elseif ($st === 'Verified') echo 'badge-success';
                                    elseif ($st === 'Converted to Alert') echo 'badge-danger';
                                    else echo 'badge-outline';
                                ?>">
                                    <?php echo e($rep['report_status']); ?>
                                </span>
                            </td>
                            <td>
                                <a href="<?php echo Router::url('admin/reports.php?id=' . $rep['id']); ?>" class="btn btn-outline btn-sm">Review</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../../app/layouts/dashboard_footer.php'; ?>
