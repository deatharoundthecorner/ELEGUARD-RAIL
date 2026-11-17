<?php
/**
 * Admin Dashboard Statistics
 */
$pageTitle = "Admin Dashboard";
$allowed_roles = 'admin';
require_once __DIR__ . '/../../app/layouts/dashboard_header.php';
require_once __DIR__ . '/../../app/services/UserService.php';
require_once __DIR__ . '/../../app/services/ReportService.php';
require_once __DIR__ . '/../../app/services/AlertService.php';

// Stats indicators
$usersCount = UserService::countAll();
$sectionsCount = AlertService::countSections();
$hotspotsCount = AlertService::countHotspots();
$pendingReportsCount = ReportService::countByStatus('Pending');
$verifiedReportsCount = ReportService::countByStatus('Verified');
$activeAlertsCount = AlertService::countAlertsByStatus('Active');
$criticalAlertsCount = AlertService::countAlertsByLevel('Critical');
$acknowledgementsCount = AlertService::countAcknowledgements();

// Lists
$recentReports = array_slice(ReportService::getAll(), 0, 5);
$recentAlerts = array_slice(AlertService::getAllAlerts(), 0, 5);
$recentIncidents = array_slice(AlertService::getAllIncidents(), 0, 5);
?>

<div class="dashboard-title-row">
    <h1>Administrative Central Console <span class="badge badge-sample" style="font-size:0.75rem; vertical-align:middle; margin-left:8px;">Demonstration Data</span></h1>
    <p class="text-secondary">System-wide monitoring dashboard for users, railway lines, hotspots, safety warnings, and logs.</p>
</div>

<div class="stats-grid">
    <div class="stat-card">
        <span class="stat-title">System Users</span>
        <span class="stat-value"><?php echo $usersCount; ?></span>
        <span class="stat-desc">Active accounts registered</span>
    </div>
    <div class="stat-card">
        <span class="stat-title font-danger">Active Danger Alerts</span>
        <span class="stat-value" style="color:var(--danger);"><?php echo $activeAlertsCount; ?></span>
        <span class="stat-desc">Critical alerts: <?php echo $criticalAlertsCount; ?></span>
    </div>
    <div class="stat-card">
        <span class="stat-title">Pending Reports</span>
        <span class="stat-value" style="color:var(--accent-color);"><?php echo $pendingReportsCount; ?></span>
        <span class="stat-desc">Awaiting ranger confirmation</span>
    </div>
    <div class="stat-card">
        <span class="stat-title">Confirmed Sightings</span>
        <span class="stat-value" style="color:var(--success);"><?php echo $verifiedReportsCount; ?></span>
        <span class="stat-desc">Verified by rangers</span>
    </div>
    <div class="stat-card">
        <span class="stat-title">Driver Acknowledges</span>
        <span class="stat-value"><?php echo $acknowledgementsCount; ?></span>
        <span class="stat-desc">Total responses logged</span>
    </div>
    <div class="stat-card">
        <span class="stat-title">Track Sections</span>
        <span class="stat-value"><?php echo $sectionsCount; ?></span>
        <span class="stat-desc">Hotspots mapped: <?php echo $hotspotsCount; ?></span>
    </div>
</div>

<div class="card-panel" style="margin-top: 25px; margin-bottom: 25px; border-left: 4px solid var(--info); display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:15px; padding:18px 24px;">
    <div>
        <h3 style="margin-bottom:4px; font-size:1.1rem; color:#fff; display:flex; align-items:center; gap:8px;">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0;"><path d="M3 3v18h18"></path><path d="m19 9-5 5-4-4-3 3"></path></svg>
            Platform Analytics, Provenance & Data Quality Console
        </h3>
        <p class="text-secondary" style="font-size:0.85rem; margin:0;">Audit dataset provenance segregation, review data completeness indices, and inspect real-time Analytics REST API health.</p>
    </div>
    <div>
        <a href="<?php echo Router::url('admin/analytics.php'); ?>" class="btn btn-outline btn-md">Open Platform Analytics ↗</a>
    </div>
</div>

<div class="card-panel" style="margin-top: 30px; margin-bottom: 30px;">
    <div class="panel-header">
        <h2 class="panel-title" style="display:flex; align-items:center; gap:8px;">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0;"><polygon points="3 6 9 3 15 6 21 3 21 18 15 21 9 18 3 21"></polygon><line x1="9" y1="3" x2="9" y2="18"></line><line x1="15" y1="6" x2="15" y2="21"></line></svg>
            Central Railway GIS & Wildlife Tracking Map
        </h2>
        <a href="<?php echo Router::url('admin/map.php'); ?>" class="btn btn-outline btn-sm">Full Screen Map ↗</a>
    </div>
    <?php 
    $mapContainerId = 'admin-dash-map';
    $mapHeight = '480px';
    $mapRole = 'admin';
    $showFilters = true;
    $showStatsBar = false;
    include __DIR__ . '/../../app/layouts/map_view_partial.php'; 
    ?>
</div>

<div class="grid grid-2col">
    <!-- Left panel: Recent Elephant Reports -->
    <div class="card-panel">
        <div class="panel-header">
            <h2 class="panel-title">Recent Elephant Sightings</h2>
            <a href="<?php echo Router::url('admin/reports.php'); ?>" class="btn btn-outline btn-sm">View All</a>
        </div>
        
        <div class="table-responsive">
            <?php if (empty($recentReports)): ?>
                <p class="text-muted text-center" style="padding:15px 0;">No elephant reports filed yet.</p>
            <?php else: ?>
                <table class="data-table" style="font-size:0.85rem;">
                    <thead>
                        <tr>
                            <th>Section</th>
                            <th>Count</th>
                            <th>Urgency</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recentReports as $rep): ?>
                            <tr>
                                <td><strong><?php echo e($rep['section_name']); ?></strong></td>
                                <td><?php echo e($rep['elephant_count']); ?></td>
                                <td><span class="badge <?php echo get_risk_badge_class($rep['urgency_level']); ?>"><?php echo e($rep['urgency_level']); ?></span></td>
                                <td>
                                    <span class="badge <?php 
                                        $st = $rep['report_status'];
                                        if ($st === 'Pending') echo 'badge-warning';
                                        elseif ($st === 'Verified') echo 'badge-success';
                                        elseif ($st === 'Converted to Alert') echo 'badge-danger';
                                        else echo 'badge-outline';
                                    ?>">
                                        <?php echo e($rep['report_status']); ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>

    <!-- Right panel: Recent Alerts -->
    <div class="card-panel">
        <div class="panel-header">
            <h2 class="panel-title">Recent safety warning broadcasts</h2>
            <a href="<?php echo Router::url('admin/alerts.php'); ?>" class="btn btn-outline btn-sm">View All</a>
        </div>

        <div class="table-responsive">
            <?php if (empty($recentAlerts)): ?>
                <p class="text-muted text-center" style="padding:15px 0;">No safety warning alerts logged.</p>
            <?php else: ?>
                <table class="data-table" style="font-size:0.85rem;">
                    <thead>
                        <tr>
                            <th>Section</th>
                            <th>Level</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recentAlerts as $a): ?>
                            <tr>
                                <td><strong><?php echo e($a['section_name']); ?></strong></td>
                                <td><span class="badge <?php echo get_risk_badge_class($a['alert_level']); ?>"><?php echo e($a['alert_level']); ?></span></td>
                                <td><span class="badge <?php echo get_alert_status_class($a['alert_status']); ?>"><?php echo e($a['alert_status']); ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="card-panel">
    <div class="panel-header">
        <h2 class="panel-title">Recent historical incidents & Near Misses</h2>
        <a href="<?php echo Router::url('admin/incidents.php'); ?>" class="btn btn-outline btn-sm">Manage Incidents</a>
    </div>

    <div class="table-responsive">
        <?php if (empty($recentIncidents)): ?>
            <p class="text-muted text-center" style="padding:15px 0;">No historical collision/near-miss records registered.</p>
        <?php else: ?>
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Type</th>
                        <th>Railway Section</th>
                        <th>Severity</th>
                        <th>Description</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recentIncidents as $inc): ?>
                        <tr>
                            <td><?php echo format_raw_date($inc['incident_date']); ?></td>
                            <td><strong><?php echo e($inc['incident_type']); ?></strong></td>
                            <td><?php echo e($inc['section_name']); ?></td>
                            <td><span class="badge <?php echo get_risk_badge_class($inc['severity']); ?>"><?php echo e($inc['severity']); ?></span></td>
                            <td style="font-size:0.85rem; color:var(--text-secondary);"><?php echo e($inc['description']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>

<style>
.alert-status-active { background-color: rgba(229, 57, 53, 0.15); color: #ef9a9a; }
.alert-status-resolved { background-color: rgba(76, 175, 80, 0.15); color: #81c784; }
.alert-status-false { background-color: rgba(255, 255, 255, 0.05); color: var(--text-muted); }
</style>

<?php require_once __DIR__ . '/../../app/layouts/dashboard_footer.php'; ?>
