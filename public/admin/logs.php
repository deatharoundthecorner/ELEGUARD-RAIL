<?php
/**
 * Admin System Activity Logs audit panel
 */
$pageTitle = "System Activity Logs";
$allowed_roles = 'admin';
require_once __DIR__ . '/../../app/layouts/dashboard_header.php';
require_once __DIR__ . '/../../app/services/AlertService.php';

$logs = AlertService::getSystemLogs();
?>

<div class="dashboard-title-row">
    <h1>System Activity Logs</h1>
    <p class="text-secondary">Security and operational audit history. Tracks logins, sighting submissions, ranger verifications, and alert resolutions.</p>
</div>

<div class="card-panel">
    <div class="table-responsive">
        <?php if (empty($logs)): ?>
            <p class="text-center text-muted" style="padding: 40px 0;">No system activity logs found.</p>
        <?php else: ?>
            <table class="data-table" style="font-size: 0.85rem;">
                <thead>
                    <tr>
                        <th>Timestamp</th>
                        <th>User Account</th>
                        <th>User Role</th>
                        <th>Audit Action</th>
                        <th>Action Description</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($logs as $log): ?>
                        <tr>
                            <td><?php echo format_date($log['created_at']); ?></td>
                            <td>
                                <?php if ($log['user_id']): ?>
                                    <strong><?php echo e($log['user_name']); ?></strong> (ID: #<?php echo $log['user_id']; ?>)
                                <?php else: ?>
                                    <span class="text-muted">Anonymous/System</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($log['user_id']): ?>
                                    <span class="badge badge-outline">
                                        <?php echo e(ucwords(str_replace('_', ' ', $log['user_role']))); ?>
                                    </span>
                                <?php else: ?>
                                    <span class="text-muted">N/A</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <strong style="color:var(--accent-color);"><?php echo e($log['action']); ?></strong>
                            </td>
                            <td style="color:var(--text-secondary);"><?php echo e($log['description']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../../app/layouts/dashboard_footer.php'; ?>
