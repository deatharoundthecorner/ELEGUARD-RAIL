<?php
/**
 * Reporter Dashboard
 */
$pageTitle = "Reporter Dashboard";
$allowed_roles = 'reporter';
require_once __DIR__ . '/../../app/layouts/dashboard_header.php';
require_once __DIR__ . '/../../app/services/ReportService.php';

$userId = $currentUser['id'];
$myReports = ReportService::getAll(null, null, null, $userId);

// Calculate status counts for statistics cards
$pendingCount = 0;
$verifiedCount = 0;
$alertCount = 0;

foreach ($myReports as $rep) {
    if ($rep['report_status'] === 'Pending') {
        $pendingCount++;
    } elseif ($rep['report_status'] === 'Verified') {
        $verifiedCount++;
    } elseif ($rep['report_status'] === 'Converted to Alert') {
        $alertCount++;
    }
}

// Slice to show only recent 5 reports
$recentReports = array_slice($myReports, 0, 5);
?>

<div class="dashboard-title-row">
    <h1>Welcome, <?php echo e($currentUser['name']); ?></h1>
    <p class="text-secondary">Community early warning sighting desk. Use this panel to file reports and track their status.</p>
</div>

<div class="stats-grid">
    <div class="stat-card">
        <span class="stat-title">Submitted Sightings</span>
        <span class="stat-value"><?php echo count($myReports); ?></span>
        <span class="stat-desc">Total reports filed by you</span>
    </div>
    <div class="stat-card">
        <span class="stat-title">Pending Verification</span>
        <span class="stat-value" style="color:var(--accent-color);"><?php echo $pendingCount; ?></span>
        <span class="stat-desc">Awaiting wildlife ranger review</span>
    </div>
    <div class="stat-card">
        <span class="stat-title">Verified Sightings</span>
        <span class="stat-value" style="color:var(--success);"><?php echo $verifiedCount; ?></span>
        <span class="stat-desc">Confirmed by rangers</span>
    </div>
    <div class="stat-card">
        <span class="stat-title">Active Safety Alerts</span>
        <span class="stat-value" style="color:var(--danger);"><?php echo $alertCount; ?></span>
        <span class="stat-desc">Upgraded to active train warnings</span>
    </div>
</div>

<div class="card-panel" style="text-align: center; padding: 40px 20px; border-top: 4px solid var(--accent-color);">
    <h2>Reported an Elephant Near the Tracks?</h2>
    <p class="text-secondary" style="margin-bottom: 20px; max-width: 600px; margin-left: auto; margin-right: auto;">Help prevent collisions. File the location, urgency, and description immediately. Wildlife rangers and train controls will receive notifications instantly.</p>
    <a href="<?php echo Router::url('reporter/submit_report.php'); ?>" class="btn btn-accent btn-lg">➕ File Sighting Report</a>
</div>

<div class="card-panel">
    <div class="panel-header">
        <h2 class="panel-title">Your Recent Sighting Submissions</h2>
        <a href="<?php echo Router::url('reporter/my_reports.php'); ?>" class="btn btn-outline btn-sm">View All Reports</a>
    </div>

    <div class="table-responsive">
        <?php if (empty($recentReports)): ?>
            <p class="text-muted text-center" style="padding: 20px 0;">You have not submitted any elephant reports yet.</p>
        <?php else: ?>
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Date/Time</th>
                        <th>Railway Section</th>
                        <th>Location Description</th>
                        <th>Elephant Count</th>
                        <th>Sighting Urgency</th>
                        <th>Computed Risk Score</th>
                        <th>Report Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recentReports as $rep): ?>
                        <tr>
                            <td><?php echo format_date($rep['created_at']); ?></td>
                            <td><strong><?php echo e($rep['section_name']); ?></strong></td>
                            <td><?php echo e($rep['location_description']); ?></td>
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

<?php require_once __DIR__ . '/../../app/layouts/dashboard_footer.php'; ?>
