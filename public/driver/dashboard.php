<?php
/**
 * Train Driver Live Cab Warning Panel
 */
$pageTitle = "CAB ALERT DESK";
$allowed_roles = 'driver';
require_once __DIR__ . '/../../app/layouts/dashboard_header.php';
require_once __DIR__ . '/../../app/services/AlertService.php';

// Fetch all broadcasted warnings
$activeAlerts = AlertService::getAllAlerts('Active');
?>

<div class="driver-dashboard-wrap">
    
    <div class="dashboard-title-row text-center">
        <h1 style="font-size:2rem; font-family:var(--font-heading); margin-bottom:5px;">🚂 CAB ACTIVE WARNING PANEL</h1>
        <p class="text-secondary">Safety alerts are updated live. Tap hazard card to acknowledge action taken.</p>
    </div>

    <!-- Web Audio API Controller Overlay -->
    <div class="driver-audio-alert-control">
        <div class="audio-status-label">
            <span>Sound Monitoring:</span>
            <strong id="audioStatusText">🔊 MONITORING ACTIVE</strong>
        </div>
        <button id="toggleAudioBtn" class="audio-toggle-btn">Mute Warning Alarms</button>
    </div>

    <!-- Active Warning Alerts list -->
    <div class="driver-alerts-list">
        <?php if (empty($activeAlerts)): ?>
            <div class="driver-no-alerts-card">
                <span class="driver-no-alerts-icon">🌳</span>
                <h2 style="font-size:1.8rem; color:var(--success); margin-bottom:10px;">TRACKS CLEAR</h2>
                <p>No active elephant warnings reported on any railway sections. Maintain standard speed limits and stay vigilant.</p>
            </div>
        <?php else: ?>
            <?php foreach ($activeAlerts as $a): ?>
                <?php
                $level = strtolower($a['alert_level']);
                $cardClass = 'driver-alert-' . $level;
                ?>
                <div class="driver-alert-card <?php echo $cardClass; ?>">
                    <div class="driver-alert-header">
                        <span class="driver-alert-level-badge"><?php echo e($a['alert_level']); ?> HAZARD</span>
                        <span class="driver-alert-time"><?php echo date('h:i A', strtotime($a['created_at'])); ?></span>
                    </div>
                    <div class="driver-alert-body">
                        <div class="driver-section-name">📍 <?php echo e($a['section_name']); ?></div>
                        <div class="driver-alert-msg"><?php echo e($a['alert_message']); ?></div>
                        
                        <div class="driver-alert-actions">
                            <a href="<?php echo Router::url('driver/alert_details.php?id=' . $a['id']); ?>" class="btn driver-btn-ack">Acknowledge Speed / Action</a>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../../app/layouts/dashboard_footer.php'; ?>
