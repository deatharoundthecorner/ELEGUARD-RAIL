<?php
/**
 * Admin Audit: Driver Acknowledgements Log
 */
$pageTitle = "Driver Responses";
$allowed_roles = 'admin';
require_once __DIR__ . '/../../app/layouts/dashboard_header.php';
require_once __DIR__ . '/../../app/services/AlertService.php';

$acknowledgements = AlertService::getAcknowledgements();
?>

<div class="dashboard-title-row">
    <h1>Driver Cab Acknowledgements Log</h1>
    <p class="text-secondary">Audit driver confirmations, response times, advisory receipts, and comments recorded on safety warnings.</p>
</div>

<div class="card-panel">
    <div class="table-responsive">
        <?php if (empty($acknowledgements)): ?>
            <p class="text-center text-muted" style="padding: 40px 0;">No driver acknowledgements logged in the database yet.</p>
        <?php else: ?>
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Time Acknowledged</th>
                        <th>Train Driver</th>
                        <th>Railway Section</th>
                        <th>Driver Response / Status</th>
                        <th>Driver Remarks</th>
                        <th>Original message</th>
                        <th>Alert level</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($acknowledgements as $ack): ?>
                        <tr>
                            <td><?php echo format_date($ack['acknowledged_at']); ?></td>
                            <td><strong><?php echo e($ack['driver_name']); ?></strong></td>
                            <td><strong><?php echo e($ack['section_name']); ?></strong></td>
                            <td>
                                <?php 
                                    $displayStatus = $ack['action_status_display'] ?? $ack['action_status'];
                                    $st = $ack['action_status'];
                                    $badgeClass = 'badge-outline';
                                    if ($st === 'Stopped') $badgeClass = 'badge-danger';
                                    elseif ($st === 'Slowing Down') $badgeClass = 'badge-warning-dark';
                                    elseif ($st === 'Acknowledged') $badgeClass = 'badge-success';
                                ?>
                                <span class="badge <?php echo $badgeClass; ?>">
                                    <?php echo e($displayStatus); ?>
                                </span>
                            </td>
                            <td style="font-size:0.85rem; color:#fff; max-width: 200px;"><?php echo e($ack['note'] ? $ack['note'] : 'N/A'); ?></td>
                            <td style="font-size:0.8rem; color:var(--text-secondary); max-width: 250px;"><?php echo e($ack['alert_message']); ?></td>
                            <td>
                                <span class="badge <?php echo get_risk_badge_class($ack['alert_level']); ?>">
                                    <?php echo e($ack['alert_level']); ?>
                                </span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../../app/layouts/dashboard_footer.php'; ?>
