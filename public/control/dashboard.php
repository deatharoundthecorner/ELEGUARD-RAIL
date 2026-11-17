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
    <h1>Railway Control Sighting Desk <span class="badge badge-sample" style="font-size:0.75rem; vertical-align:middle; margin-left:8px;">Demonstration Environment</span></h1>
    <p class="text-secondary">Track active sightings, broadcast safety advisory alerts, and monitor driver receipts.</p>
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
        <span class="stat-desc">Critical hazards requiring authorised railway review</span>
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

<div class="card-panel" style="margin-top: 25px; margin-bottom: 25px; border-left: 4px solid var(--accent-color); display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:15px; padding:18px 24px;">
    <div>
        <h3 style="margin-bottom:4px; font-size:1.1rem; color:#fff; display:flex; align-items:center; gap:8px;">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0;"><path d="M3 3v18h18"></path><path d="m19 9-5 5-4-4-3 3"></path></svg>
            Railway Corridor Historical Intelligence & Analytics
        </h3>
        <p class="text-secondary" style="font-size:0.85rem; margin:0;">Access 24-hour diurnal patterns, dispatch latency, duplicate hazard alerts, and corridor risk classifications.</p>
    </div>
    <div>
        <a href="<?php echo Router::url('control/analytics.php'); ?>" class="btn btn-outline btn-md">Open Corridor Analytics ↗</a>
    </div>
</div>

<div class="card-panel" style="margin-top: 30px; margin-bottom: 30px;">
    <div class="panel-header">
        <h2 class="panel-title" style="display:flex; align-items:center; gap:8px;">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0;"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg>
            Live Railway Corridor Hazard Map
        </h2>
        <a href="<?php echo Router::url('control/map.php'); ?>" class="btn btn-outline btn-sm">Full Screen Map ↗</a>
    </div>
    <?php 
    $mapContainerId = 'control-dash-map';
    $mapHeight = '460px';
    $mapRole = 'control_officer';
    $showFilters = true;
    $showStatsBar = false;
    include __DIR__ . '/../../app/layouts/map_view_partial.php'; 
    ?>
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
                <p class="text-muted text-center" style="padding: 20px 0;">No verified elephant reports awaiting alert conversion.</p>
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
                                    <?php 
                                        $displayStatus = $ack['action_status_display'] ?? $ack['action_status'];
                                        $ac = $ack['action_status'];
                                        $badgeClass = 'badge-outline';
                                        if ($ac === 'Stopped') $badgeClass = 'badge-danger';
                                        elseif ($ac === 'Slowing Down') $badgeClass = 'badge-warning-dark';
                                        elseif ($ac === 'Acknowledged') $badgeClass = 'badge-success';
                                    ?>
                                    <span class="badge <?php echo $badgeClass; ?>">
                                        <?php echo e($displayStatus); ?>
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
    <a href="<?php echo Router::url('control/create_alert.php'); ?>" class="btn btn-danger btn-lg"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:middle; margin-right:6px;"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"></path><line x1="12" y1="9" x2="12" y2="13"></line><line x1="12" y1="17" x2="12.01" y2="17"></line></svg>Create Safety Warning Alert</a>
</div>

<?php require_once __DIR__ . '/../../app/layouts/dashboard_footer.php'; ?>
