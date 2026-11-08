<?php
/**
 * Wildlife Officer Dashboard
 */
$pageTitle = "Ranger Dashboard";
$allowed_roles = 'wildlife_officer';
require_once __DIR__ . '/../../app/layouts/dashboard_header.php';
require_once __DIR__ . '/../../app/services/ReportService.php';
require_once __DIR__ . '/../../app/services/AlertService.php';

$pendingReports = ReportService::getAll('Pending');
$verifiedCount = ReportService::countByStatus('Verified');
$rejectedCount = ReportService::countByStatus('Rejected');
$hotspotsCount = AlertService::countHotspots();
$activeAlerts = AlertService::countAlertsByStatus('Active');

// Get recent 5 pending reports
$recentPending = array_slice($pendingReports, 0, 5);
?>

<div class="dashboard-title-row">
    <h1>Wildlife Rangers Sighting Center</h1>
    <p class="text-secondary">Review public elephant reports, verify threats, or register official ranger sightings.</p>
</div>

<div class="stats-grid">
    <div class="stat-card">
        <span class="stat-title">Pending Verification</span>
        <span class="stat-value" style="color:var(--accent-color);"><?php echo count($pendingReports); ?></span>
        <span class="stat-desc">Reports needing validation</span>
    </div>
    <div class="stat-card">
        <span class="stat-title">Verified Sightings</span>
        <span class="stat-value" style="color:var(--success);"><?php echo $verifiedCount; ?></span>
        <span class="stat-desc">Confirmed elephant movements</span>
    </div>
    <div class="stat-card">
        <span class="stat-title">Rejected Reports</span>
        <span class="stat-value" style="color:var(--text-muted);"><?php echo $rejectedCount; ?></span>
        <span class="stat-desc">Identified as false alerts</span>
    </div>
    <div class="stat-card">
        <span class="stat-title">Hotspot Areas</span>
        <span class="stat-value"><?php echo $hotspotsCount; ?></span>
        <span class="stat-desc">Known movement corridors</span>
    </div>
</div>

<div class="card-panel" style="margin-top: 30px;">
    <div class="panel-header">
        <h2 class="panel-title">🗺️ Live Wildlife & Railway Corridor GIS Map</h2>
        <a href="<?php echo Router::url('wildlife/map.php'); ?>" class="btn btn-outline btn-sm">Full Screen Map ↗</a>
    </div>
    <?php 
    $mapContainerId = 'wildlife-dash-map';
    $mapHeight = '460px';
    $mapRole = 'wildlife_officer';
    $showFilters = true;
    $showStatsBar = false;
    include __DIR__ . '/../../app/layouts/map_view_partial.php'; 
    ?>
</div>

<div class="grid grid-2col" style="margin-top: 30px;">
    <div class="card-panel">
        <div class="panel-header">
            <h2 class="panel-title">Pending Reports Awaiting Verification</h2>
            <a href="<?php echo Router::url('wildlife/reports.php?status=Pending'); ?>" class="btn btn-outline btn-sm">Manage All</a>
        </div>

        <div class="table-responsive">
            <?php if (empty($recentPending)): ?>
                <p class="text-muted text-center" style="padding: 20px 0;">🎉 No pending elephant reports to verify.</p>
            <?php else: ?>
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Section</th>
                            <th>Count</th>
                            <th>Urgency</th>
                            <th>Risk Score</th>
                            <th>AI Advisory</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recentPending as $rep): ?>
                            <tr>
                                <td><?php echo date('m-d h:i A', strtotime($rep['created_at'])); ?></td>
                                <td><strong><?php echo e($rep['section_name']); ?></strong></td>
                                <td><?php echo e($rep['elephant_count']); ?></td>
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
                                    <?php
                                    $dAiSt = $rep['ai_status'] ?? 'pending';
                                    $dDetected = !empty($rep['ai_detected_elephant']);
                                    $dConf = isset($rep['ai_confidence']) ? (float)$rep['ai_confidence'] : null;

                                    if ($dAiSt === 'processed') {
                                        if ($dDetected) {
                                            $confPct = round($dConf * 100);
                                            echo "<span class=\"badge badge-success\" style=\"font-size:0.75rem;\" title=\"" . e($rep['ai_detection_notes']) . "\">🟢 Detected ({$confPct}%)</span>";
                                        } elseif ($dConf !== null && $dConf >= 0.20) {
                                            $confPct = round($dConf * 100);
                                            echo "<span class=\"badge badge-warning\" style=\"font-size:0.75rem;\" title=\"" . e($rep['ai_detection_notes']) . "\">🟡 Uncertain ({$confPct}%)</span>";
                                        } else {
                                            echo "<span class=\"badge\" style=\"background:#4b5563; color:#fff; font-size:0.75rem;\" title=\"" . e($rep['ai_detection_notes']) . "\">⚪ None</span>";
                                        }
                                    } elseif ($dAiSt === 'pending') {
                                        echo "<span class=\"badge badge-warning\" style=\"font-size:0.75rem;\">⏳ Pending</span>";
                                    } elseif ($dAiSt === 'failed') {
                                        echo "<span class=\"badge badge-danger\" style=\"font-size:0.75rem;\">⚠️ Error</span>";
                                    } else {
                                        echo "<span class=\"text-muted\" style=\"font-size:0.75rem;\">&mdash;</span>";
                                    }
                                    ?>
                                </td>
                                <td>
                                    <a href="<?php echo Router::url('wildlife/reports.php?id=' . $rep['id']); ?>" class="btn btn-primary btn-sm">Review</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>

    <div class="card-panel" style="display: flex; flex-direction: column; justify-content: center; text-align: center; border-left: 4px solid var(--success);">
        <h2>Official Ranger Sighting?</h2>
        <p class="text-secondary" style="margin-bottom: 20px;">Ranger sightings are submitted directly as <strong>Verified</strong> reports. Use the submission form to create immediate warnings.</p>
        <a href="<?php echo Router::url('wildlife/submit_report.php'); ?>" class="btn btn-accent btn-lg">➕ File Ranger Sighting</a>
    </div>
</div>

<?php require_once __DIR__ . '/../../app/layouts/dashboard_footer.php'; ?>
