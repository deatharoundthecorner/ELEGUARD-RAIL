<?php
/**
 * View Reporter's Submitted Reports
 */
$pageTitle = "My Sighting Reports";
$allowed_roles = 'reporter';
require_once __DIR__ . '/../../app/layouts/dashboard_header.php';
require_once __DIR__ . '/../../app/services/ReportService.php';

$myReports = ReportService::getAll(null, null, null, $currentUser['id']);
?>

<div class="dashboard-title-row">
    <h1>My Sighting Reports</h1>
    <p class="text-secondary">Track the real-time status of sightings you have reported to Sri Lanka Railway Control and Wildlife Rangers.</p>
</div>

<div class="card-panel">
    <div class="table-responsive">
        <?php if (empty($myReports)): ?>
            <div class="text-center text-muted" style="padding: 40px 0;">
                <p style="font-size:1.1rem; margin-bottom:15px;">You have not submitted any elephant movement reports yet.</p>
                <a href="<?php echo Router::url('reporter/submit_report.php'); ?>" class="btn btn-accent">➕ Submit First Sighting</a>
            </div>
        <?php else: ?>
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Date/Time</th>
                        <th>Railway Section</th>
                        <th>Location Details</th>
                        <th>Count</th>
                        <th>Photo</th>
                        <th>Urgency</th>
                        <th>Computed Risk</th>
                        <th>Status</th>
                        <th>Details/Notes</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($myReports as $rep): ?>
                        <tr>
                            <td><?php echo format_date($rep['created_at']); ?></td>
                            <td><strong><?php echo e($rep['section_name']); ?></strong></td>
                            <td><?php echo e($rep['location_description']); ?></td>
                            <td><?php echo e($rep['elephant_count']); ?></td>
                            <td>
                                <?php if (!empty($rep['image_path'])): ?>
                                    <a href="<?php echo Router::url($rep['image_path']); ?>" target="_blank">
                                        <img src="<?php echo Router::url($rep['image_path']); ?>" alt="Sighting photo" class="report-thumbnail">
                                    </a>
                                <?php else: ?>
                                    <span class="text-muted">None</span>
                                <?php endif; ?>
                            </td>
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
                                    elseif ($st === 'Resolved') echo 'badge-success';
                                    else echo 'badge-outline';
                                ?>">
                                    <?php echo e($rep['report_status']); ?>
                                </span>
                            </td>
                            <td style="max-width: 250px; font-size: 0.85rem; color: var(--text-secondary);">
                                <?php echo e($rep['description'] ? $rep['description'] : 'N/A'); ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../../app/layouts/dashboard_footer.php'; ?>
