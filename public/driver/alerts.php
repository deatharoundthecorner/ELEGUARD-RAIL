<?php
/**
 * Driver Active Alerts Archive
 */
$pageTitle = "Active Alerts";
$allowed_roles = 'driver';
require_once __DIR__ . '/../../app/layouts/dashboard_header.php';
require_once __DIR__ . '/../../app/services/AlertService.php';

$alerts = AlertService::getAllAlerts('Active');
?>

<div class="dashboard-title-row">
    <h1>All Active Warning Sectors</h1>
    <p class="text-secondary">View active warnings currently broadcasted across the Sri Lanka Railway network.</p>
</div>

<div class="card-panel">
    <div class="table-responsive">
        <?php if (empty($alerts)): ?>
            <p class="text-center text-muted" style="padding: 40px 0;">🎉 No active safety warnings on the tracks.</p>
        <?php else: ?>
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Time Issued</th>
                        <th>Railway Section</th>
                        <th>District Location</th>
                        <th>Alert Danger Level</th>
                        <th>Alert Description</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($alerts as $a): ?>
                        <tr>
                            <td><?php echo date('m-d h:i A', strtotime($a['created_at'])); ?></td>
                            <td><strong><?php echo e($a['section_name']); ?></strong></td>
                            <td><?php echo e($a['district']); ?></td>
                            <td>
                                <span class="badge <?php echo get_risk_badge_class($a['alert_level']); ?>">
                                    <?php echo e($a['alert_level']); ?>
                                </span>
                            </td>
                            <td style="font-size:0.85rem; max-width:300px;"><?php echo e($a['alert_message']); ?></td>
                            <td>
                                <a href="<?php echo Router::url('driver/alert_details.php?id=' . $a['id']); ?>" class="btn btn-primary btn-sm">Acknowledge</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../../app/layouts/dashboard_footer.php'; ?>
