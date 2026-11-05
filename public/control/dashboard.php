<?php
/**
 * Railway Control Officer Dashboard
 */
$pageTitle = "Control Desk";
$allowed_roles = 'control_officer';
require_once __DIR__ . '/../../app/layouts/dashboard_header.php';
require_once __DIR__ . '/../../app/services/AlertService.php';
require_once __DIR__ . '/../../app/services/ReportService.php';

// Stats queries
$activeAlertsCount = AlertService::countAlertsByStatus('Active');
$pendingReportsCount = ReportService::countByStatus('Pending');
$verifiedReportsCount = ReportService::countByStatus('Verified');
$criticalAlertsCount = AlertService::countAlertsByLevel('Critical');

// Sighting reports to action (Verified reports are priority)
$verifiedReports = ReportService::getAll('Verified');
$recentReports = array_slice($verifiedReports, 0, 5);

// Recent acknowledgements
$acknowledgements = AlertService::getAcknowledgements();
$recentAcks = array_slice($acknowledgements, 0, 5);
?>

<div class="dashboard-title-row">
    <h1>Railway Control Sighting Desk</h1>
    <p class="text-secondary">Track active sightings, generate safety speed alerts, and monitor driver actions.</p>
</div>

<div class="stats-grid">
    <div class="stat-card">
        <span class="stat-title">Active Danger Alerts</span>
        <span class="stat-value" style="color:var(--danger);"><?php echo $activeAlertsCount; ?></span>
        <span class="stat-desc">Operational warning zones</span>
    </div>
    <div class="stat-card">
        <span class="stat-title">Critical Hazards</span>
        <span class="stat-value critical"><?php echo $criticalAlertsCount; ?></span>
        <span class="stat-desc">Trains must slow down / stop</span>
    </div>
    <div class="stat-card">
        <span class="stat-title">Verified Sightings</span>
        <span class="stat-value" style="color:var(--success);"><?php echo $verifiedReportsCount; ?></span>
        <span class="stat-desc">Confirmed and ready to alert</span>
    </div>
    <div class="stat-card">
        <span class="stat-title">Pending Public Reports</span>
        <span class="stat-value" style="color:var(--accent-color);"><?php echo $pendingReportsCount; ?></span>
        <span class="stat-desc">Awaiting ranger confirmation</span>
    </div>
</div>

<div class="grid grid-2col">
    <!-- Reports Pending Alert Generation -->
    <div class="card-panel">
        <div class="panel-header">
            <h2 class="panel-title">Confirmed Sighting Reports (Needs Warning Alert)</h2>
            <a href="<?php echo Router::url('control/reports.php?status=Verified'); ?>" class="btn btn-outline btn-sm">View All</a>
        </div>
        
        <div class="table-responsive">
            <?php if (empty($recentReports)): ?>
                <p class="text-muted text-center" style="padding: 20px 0;">🎉 No verified elephant reports awaiting alert conversion.</p>
            <?php else: ?>
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Section</th>
                            <th>Count</th>
                            <th>Urgency</th>
                            <th>Risk</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recentReports as $rep): ?>
                            <tr>
                                <td><strong><?php echo e($rep['section_name']); ?></strong></td>
                                <td><?php echo e($rep['elephant_count']); ?></td>
                                <td><span class="badge <?php echo get_risk_badge_class($rep['urgency_level']); ?>"><?php echo e($rep['urgency_level']); ?></span></td>
                                <td><span class="badge <?php echo get_risk_badge_class($rep['risk_score']); ?>"><?php echo e($rep['risk_score']); ?></span></td>
                                <td>
                                    <a href="<?php echo Router::url('control/reports.php?id=' . $rep['id']); ?>" class="btn btn-primary btn-sm">Convert to Alert</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>

    <!-- Recent driver acknowledgements -->
    <div class="card-panel">
        <div class="panel-header">
            <h2 class="panel-title">Recent Driver Cab Acknowledgements</h2>
            <a href="<?php echo Router::url('control/acknowledgements.php'); ?>" class="btn btn-outline btn-sm">All History</a>
        </div>
        
        <div class="table-responsive">
            <?php if (empty($recentAcks)): ?>
                <p class="text-muted text-center" style="padding: 20px 0;">No driver acknowledgements logged yet.</p>
            <?php else: ?>
                <table class="data-table" style="font-size:0.85rem;">
                    <thead>
                        <tr>
                            <th>Driver</th>
                            <th>Section</th>
                            <th>Response Status</th>
                            <th>Ack Time</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recentAcks as $ack): ?>
                            <tr>
                                <td><strong><?php echo e($ack['driver_name']); ?></strong></td>
                                <td><?php echo e($ack['section_name']); ?></td>
                                <td>
                                    <span class="badge <?php 
                                        $ac = $ack['action_status'];
                                        if ($ac === 'Stopped') echo 'badge-danger';
                                        elseif ($ac === 'Slowing Down') echo 'badge-warning-dark';
                                        elseif ($ac === 'Acknowledged') echo 'badge-success';
                                        else echo 'badge-outline';
                                    ?>">
                                        <?php echo e($ack['action_status']); ?>
                                    </span>
                                </td>
                                <td><?php echo date('h:i A', strtotime($ack['acknowledged_at'])); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="card-panel" style="text-align:center; padding: 30px; border-top: 4px solid var(--danger);">
    <h2>Need to Create a Safety Warning Manually?</h2>
    <p class="text-secondary" style="margin-bottom:15px;">If you receive emergency calls directly, bypass the report validation and broadcast alerts immediately.</p>
    <a href="<?php echo Router::url('control/create_alert.php'); ?>" class="btn btn-danger btn-lg">🚨 Create Safety Warning Alert</a>
</div>

<?php require_once __DIR__ . '/../../app/layouts/dashboard_footer.php'; ?>
