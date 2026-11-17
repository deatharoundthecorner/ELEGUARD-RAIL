<?php
/**
 * Driver Active Alerts Archive
 * Milestone 9C Stage 4E.4.3-I: Driver Advisory Overview
 */
$pageTitle = "ACTIVE NETWORK ADVISORIES";
$allowed_roles = 'driver';
require_once __DIR__ . '/../../app/layouts/dashboard_header.php';
require_once __DIR__ . '/../../app/services/AlertService.php';

$selectedSectionId = null;
if (isset($_GET['section_id']) && is_numeric($_GET['section_id'])) {
    $selectedSectionId = (int)$_GET['section_id'];
    $_SESSION['driver_assigned_section'] = $selectedSectionId;
} elseif (isset($_SESSION['driver_assigned_section'])) {
    $selectedSectionId = (int)$_SESSION['driver_assigned_section'];
}

$allSections = AlertService::getAllSections();
$advisories = AlertService::getDriverAdvisories($selectedSectionId);
?>

<div class="driver-dashboard-wrap" style="max-width:1000px;">

    <!-- MANDATORY STATIONARY-REVIEW SAFETY BANNER -->
    <div class="driver-safety-banner" role="alert">
        <div class="driver-safety-banner-header">
            <span>⚠️</span>
            <span>STATIONARY REVIEW USE ONLY — DO NOT INTERACT WHILE IN MOTION</span>
        </div>
        <div class="driver-safety-banner-body">
            <strong>Operating Safety Regulation:</strong> Advisory archives and route warnings must be reviewed exclusively while the locomotive is stationary at a scheduled stop, siding, or stop signal.
        </div>
    </div>

    <div class="dashboard-title-row">
        <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px; width:100%;">
            <div>
                <h1 style="font-size:1.75rem; font-family:var(--font-heading);">Active Railway Warning Advisories</h1>
                <p class="text-secondary">Current wildlife safety advisories broadcasted across the railway network.</p>
            </div>
            
            <form method="GET" action="<?php echo Router::url('driver/alerts.php'); ?>" style="margin:0;">
                <select name="section_id" class="driver-corridor-select" onchange="this.form.submit()">
                    <option value="0" <?php echo empty($selectedSectionId) ? 'selected' : ''; ?>>All Corridors (Full Network)</option>
                    <?php foreach ($allSections as $sec): ?>
                        <option value="<?php echo (int)$sec['id']; ?>" <?php echo ($selectedSectionId === (int)$sec['id']) ? 'selected' : ''; ?>>
                            <?php echo e($sec['section_name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </form>
        </div>
    </div>

    <div class="card-panel">
        <div class="table-responsive">
            <?php if (empty($advisories)): ?>
                <p class="text-center text-muted" style="padding: 40px 0;">🎉 No active safety advisories currently on this railway corridor.</p>
            <?php else: ?>
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Broadcast Issued</th>
                            <th>Railway Section / Corridor</th>
                            <th>Verification Status</th>
                            <th>Hazard Level</th>
                            <th>Hazard Classification</th>
                            <th>Advisory Message</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($advisories as $a): ?>
                            <tr>
                                <td style="white-space:nowrap; font-size:0.85rem;">
                                    <?php echo date('d-M h:i A', strtotime($a['broadcast_at'])); ?>
                                    <?php if ($a['is_stale']): ?>
                                        <div style="font-size:0.75rem; color:#f5a623;">⚠️ &gt; 2h old</div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <strong><?php echo e($a['section_name']); ?></strong>
                                    <div style="font-size:0.78rem; color:var(--text-secondary);"><?php echo e($a['start_location']); ?> ↔ <?php echo e($a['end_location']); ?></div>
                                </td>
                                <td>
                                    <span class="<?php echo ($a['is_dwc_verified']) ? 'badge-dwc-verified' : 'badge-provisional'; ?>" style="font-size:0.75rem;">
                                        <?php echo ($a['is_dwc_verified']) ? '🛡️ DWC Verified' : '👁️ Provisional'; ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="badge <?php echo get_risk_badge_class($a['alert_level']); ?>">
                                        <?php echo e($a['alert_level']); ?>
                                    </span>
                                </td>
                                <td style="font-weight:600; color:#d8e1e8; font-size:0.85rem; white-space:nowrap;">
                                    <?php echo e($a['hazard_classification']); ?>
                                </td>
                                <td style="font-size:0.85rem; max-width:260px; line-height:1.35;"><?php echo e($a['alert_message']); ?></td>
                                <td>
                                    <a href="<?php echo Router::url('driver/alert_details.php?id=' . (int)$a['id']); ?>" class="btn btn-primary btn-sm driver-touch-btn" style="min-height:42px; padding:6px 14px; white-space:nowrap;">
                                        Acknowledge
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>

        <div style="margin-top:16px; padding:10px 14px; background:rgba(217,130,43,0.15); border:1px solid #d9822b; border-radius:6px; font-size:0.82rem; color:#ffe3b3;">
            ⚠️ <strong>Operating Mandate:</strong> Supplementary hazard information only. Follow official Caution Orders and direct Train Controller instructions.
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../../app/layouts/dashboard_footer.php'; ?>
