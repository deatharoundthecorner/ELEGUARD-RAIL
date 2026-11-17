<?php
/**
 * Driver Acknowledgement History
 * Milestone 9C Stage 4E.4.3-I: Auditable Driver Advisory Receipts
 */
$pageTitle = "CAB RESPONSE AUDIT LOG";
$allowed_roles = 'driver';
require_once __DIR__ . '/../../app/layouts/dashboard_header.php';
require_once __DIR__ . '/../../app/services/AlertService.php';

$myAcks = AlertService::getAcknowledgements($currentUser['id']);
?>

<div class="driver-dashboard-wrap" style="max-width:1000px;">

    <!-- MANDATORY STATIONARY-REVIEW SAFETY BANNER -->
    <div class="driver-safety-banner" role="alert">
        <div class="driver-safety-banner-header">
            <span>⚠️</span>
            <span>STATIONARY REVIEW USE ONLY — DO NOT INTERACT WHILE IN MOTION</span>
        </div>
        <div class="driver-safety-banner-body">
            <strong>Operating Safety Regulation:</strong> History logs and past advisory receipts must be reviewed exclusively while locomotive is stationary at a scheduled stop, siding, or stop signal.
        </div>
    </div>

    <div class="dashboard-title-row">
        <h1 style="font-size:1.75rem; font-family:var(--font-heading);">Cab Response &amp; Advisory Receipt History</h1>
        <p class="text-secondary">Official audit log of safety advisory receipts and operational responses submitted by your driver account.</p>
    </div>

    <div class="card-panel">
        <div class="table-responsive">
            <?php if (empty($myAcks)): ?>
                <p class="text-center text-muted" style="padding: 40px 0;">No past advisory receipts logged for your driver account yet.</p>
            <?php else: ?>
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Receipt Date / Time</th>
                            <th>Railway Section</th>
                            <th>Original Broadcast Message</th>
                            <th>Safety Action Status</th>
                            <th>Driver Remarks</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($myAcks as $ack): ?>
                            <tr>
                                <td style="white-space:nowrap; font-weight:600;">
                                    <?php echo date('d-M-Y h:i A', strtotime($ack['acknowledged_at'])); ?>
                                </td>
                                <td><strong><?php echo e($ack['section_name']); ?></strong></td>
                                <td style="font-size:0.85rem; color:var(--text-secondary); max-width:280px; line-height:1.4;">
                                    <?php echo e($ack['alert_message']); ?>
                                </td>
                                <td>
                                    <?php
                                    $displayStatus = $ack['action_status_display'] ?? $ack['action_status'];
                                    $st = $ack['action_status'];
                                    $badgeClass = 'badge-outline';
                                    if ($st === 'Stopped') $badgeClass = 'badge-danger';
                                    elseif ($st === 'Slowing Down') $badgeClass = 'badge-warning-dark';
                                    elseif ($st === 'Acknowledged') $badgeClass = 'badge-success';
                                    ?>
                                    <span class="badge <?php echo $badgeClass; ?>" style="font-size:0.8rem; padding:5px 10px;">
                                        <?php echo e($displayStatus); ?>
                                    </span>
                                </td>
                                <td style="font-size:0.85rem; max-width:220px; color:#d8e1e8;">
                                    <?php echo e($ack['note'] ? $ack['note'] : '—'); ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>

        <div style="margin-top:20px; padding:12px 16px; background:rgba(0,0,0,0.25); border:1px solid #30404d; border-radius:6px; font-size:0.82rem; color:#8a9ba8;">
            ℹ️ <strong>Audit Immutability Notice:</strong> Driver acknowledgements constitute permanent, tamper-evident audit records transmitted to Railway Control. Acknowledgements confirm receipt and operational planning only; they do not alter network hazard status or clear track sectors.
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../../app/layouts/dashboard_footer.php'; ?>
