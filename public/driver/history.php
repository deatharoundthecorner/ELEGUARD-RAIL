<?php
/**
 * Driver Acknowledgement History
 */
$pageTitle = "My Acknowledgements";
$allowed_roles = 'driver';
require_once __DIR__ . '/../../app/layouts/dashboard_header.php';
require_once __DIR__ . '/../../app/services/AlertService.php';

$myAcks = AlertService::getAcknowledgements($currentUser['id']);
?>

<div class="dashboard-title-row">
    <h1>Cab Response History</h1>
    <p class="text-secondary">Track the safety logs and speed actions you have submitted on active warning alerts.</p>
</div>

<div class="card-panel">
    <div class="table-responsive">
        <?php if (empty($myAcks)): ?>
            <p class="text-center text-muted" style="padding: 40px 0;">No past acknowledgements logged for your driver account yet.</p>
        <?php else: ?>
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Date / Time</th>
                        <th>Railway Section</th>
                        <th>Original message</th>
                        <th>Safety Action Taken</th>
                        <th>Your Remark Notes</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($myAcks as $ack): ?>
                        <tr>
                            <td><?php echo format_date($ack['acknowledged_at']); ?></td>
                            <td><strong><?php echo e($ack['section_name']); ?></strong></td>
                            <td style="font-size:0.8rem; color:var(--text-secondary); max-width:300px;"><?php echo e($ack['alert_message']); ?></td>
                            <td>
                                <span class="badge <?php 
                                    $st = $ack['action_status'];
                                    if ($st === 'Stopped') echo 'badge-danger';
                                    elseif ($st === 'Slowing Down') echo 'badge-warning-dark';
                                    elseif ($st === 'Acknowledged') echo 'badge-success';
                                    else echo 'badge-outline';
                                ?>">
                                    <?php echo e($ack['action_status']); ?>
                                </span>
                            </td>
                            <td style="font-size:0.85rem; max-width:200px;"><?php echo e($ack['note'] ? $ack['note'] : 'N/A'); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../../app/layouts/dashboard_footer.php'; ?>
