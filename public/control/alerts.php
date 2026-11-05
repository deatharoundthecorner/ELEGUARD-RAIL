<?php
/**
 * Control Desk Active Alerts management
 */
$pageTitle = "Active Alerts";
$allowed_roles = 'control_officer';
require_once __DIR__ . '/../../app/layouts/dashboard_header.php';
require_once __DIR__ . '/../../app/services/AlertService.php';

$errors = [];

// Handle resolve / false status updates
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validate_csrf();
    
    $action = $_POST['action'] ?? '';
    $alertId = $_POST['alert_id'] ?? '';
    
    if ($action === 'resolve') {
        if (AlertService::updateAlertStatus((int)$alertId, 'Resolved', $currentUser['id'])) {
            Session::flash('success', "Alert #{$alertId} resolved. Warning removed from train panels.");
            Router::redirect('control/alerts.php');
        } else {
            $errors['submit'] = "Failed to update alert status.";
        }
    } elseif ($action === 'false_alert') {
        if (AlertService::updateAlertStatus((int)$alertId, 'False Alert', $currentUser['id'])) {
            Session::flash('success', "Alert #{$alertId} marked as False Alert.");
            Router::redirect('control/alerts.php');
        } else {
            $errors['submit'] = "Failed to update alert status.";
        }
    }
}

// Fetch only active alerts for control desk by default
$statusFilter = $_GET['status'] ?? 'Active';
$levelFilter = $_GET['level'] ?? null;

$alerts = AlertService::getAllAlerts($statusFilter, $levelFilter);
?>

<div class="dashboard-title-row">
    <h1>Active Collision Warnings & Alerts</h1>
    <p class="text-secondary">Track live warning sectors. Mark them resolved once track clearance is confirmed by wildlife rangers.</p>
</div>

<!-- Filters -->
<div class="filter-card">
    <form action="<?php echo Router::url('control/alerts.php'); ?>" method="GET" class="filter-form">
        <div class="filter-group">
            <label for="status" class="form-label">Alert Status</label>
            <select name="status" id="status" class="form-control">
                <option value="Active" <?php echo $statusFilter === 'Active' ? 'selected' : ''; ?>>Active (Broadcasted)</option>
                <option value="Resolved" <?php echo $statusFilter === 'Resolved' ? 'selected' : ''; ?>>Resolved (Closed)</option>
                <option value="False Alert" <?php echo $statusFilter === 'False Alert' ? 'selected' : ''; ?>>False Alert</option>
            </select>
        </div>

        <div class="filter-group">
            <label for="level" class="form-label">Danger Level</label>
            <select name="level" id="level" class="form-control">
                <option value="">All Levels</option>
                <option value="Low" <?php echo $levelFilter === 'Low' ? 'selected' : ''; ?>>Low</option>
                <option value="Medium" <?php echo $levelFilter === 'Medium' ? 'selected' : ''; ?>>Medium</option>
                <option value="High" <?php echo $levelFilter === 'High' ? 'selected' : ''; ?>>High</option>
                <option value="Critical" <?php echo $levelFilter === 'Critical' ? 'selected' : ''; ?>>Critical</option>
            </select>
        </div>

        <div class="filter-btn-group">
            <button type="submit" class="btn btn-primary">Filter</button>
            <a href="<?php echo Router::url('control/alerts.php'); ?>" class="btn btn-secondary">Reset</a>
        </div>
    </form>
</div>

<!-- Alert list -->
<div class="card-panel">
    <div class="table-responsive">
        <?php if (empty($alerts)): ?>
            <p class="text-center text-muted" style="padding: 40px 0;">No safety warning alerts logged with the selected filters.</p>
        <?php else: ?>
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Date Broadcast</th>
                        <th>Railway Section</th>
                        <th>Danger Level</th>
                        <th>Broadcasted Message</th>
                        <th>Status</th>
                        <?php if ($statusFilter === 'Active'): ?>
                            <th>Actions</th>
                        <?php else: ?>
                            <th>Resolved Date</th>
                        <?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($alerts as $a): ?>
                        <tr>
                            <td><?php echo format_date($a['created_at']); ?></td>
                            <td><strong><?php echo e($a['section_name']); ?></strong> (<?php echo e($a['district']); ?>)</td>
                            <td>
                                <span class="badge <?php echo get_risk_badge_class($a['alert_level']); ?>">
                                    <?php echo e($a['alert_level']); ?>
                                </span>
                            </td>
                            <td style="max-width:300px; font-size:0.85rem; color:var(--text-primary);"><?php echo e($a['alert_message']); ?></td>
                            <td>
                                <span class="badge <?php echo get_alert_status_class($a['alert_status']); ?>">
                                    <?php echo e($a['alert_status']); ?>
                                </span>
                            </td>
                            <?php if ($statusFilter === 'Active'): ?>
                                <td>
                                    <div class="table-actions">
                                        <form action="<?php echo Router::url('control/alerts.php'); ?>" method="POST" onsubmit="return confirm('Resolve alert?');">
                                            <?php echo csrf_field(); ?>
                                            <input type="hidden" name="action" value="resolve">
                                            <input type="hidden" name="alert_id" value="<?php echo $a['id']; ?>">
                                            <button type="submit" class="btn btn-primary btn-sm">Resolve</button>
                                        </form>
                                        <form action="<?php echo Router::url('control/alerts.php'); ?>" method="POST" onsubmit="return confirm('Mark alert as false alarm?');">
                                            <?php echo csrf_field(); ?>
                                            <input type="hidden" name="action" value="false_alert">
                                            <input type="hidden" name="alert_id" value="<?php echo $a['id']; ?>">
                                            <button type="submit" class="btn btn-outline btn-sm" style="color:var(--danger); border-color:var(--danger);">False</button>
                                        </form>
                                    </div>
                                </td>
                            <?php else: ?>
                                <td style="font-size:0.85rem; color:var(--text-muted);">
                                    <?php echo $a['resolved_at'] ? format_date($a['resolved_at']) : 'N/A'; ?>
                                </td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>

<style>
.alert-status-active { background-color: rgba(229, 57, 53, 0.15); color: #ef9a9a; }
.alert-status-resolved { background-color: rgba(76, 175, 80, 0.15); color: #81c784; }
.alert-status-false { background-color: rgba(255, 255, 255, 0.05); color: var(--text-muted); }
</style>

<?php require_once __DIR__ . '/../../app/layouts/dashboard_footer.php'; ?>
