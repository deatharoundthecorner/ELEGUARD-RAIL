<?php
/**
 * Wildlife Hotspot Corridors List
 */
$pageTitle = "Elephant Hotspots";
$allowed_roles = 'wildlife_officer';
require_once __DIR__ . '/../../app/layouts/dashboard_header.php';
require_once __DIR__ . '/../../app/services/AlertService.php';

$riskFilter = $_GET['risk_level'] ?? null;
$districtFilter = $_GET['district'] ?? null;

$hotspots = AlertService::getAllHotspots($districtFilter, $riskFilter);
?>

<div class="dashboard-title-row">
    <h1>Elephant Hotspots & Crossings</h1>
    <p class="text-secondary">Identify zones and track segments in Sri Lanka with historically high elephant activity.</p>
</div>

<!-- Filters Panel -->
<div class="filter-card">
    <form action="<?php echo Router::url('wildlife/hotspots.php'); ?>" method="GET" class="filter-form">
        <div class="filter-group">
            <label for="risk_level" class="form-label">Risk Rating</label>
            <select name="risk_level" id="risk_level" class="form-control">
                <option value="">All Risks</option>
                <option value="Low" <?php echo $riskFilter === 'Low' ? 'selected' : ''; ?>>Low</option>
                <option value="Medium" <?php echo $riskFilter === 'Medium' ? 'selected' : ''; ?>>Medium</option>
                <option value="High" <?php echo $riskFilter === 'High' ? 'selected' : ''; ?>>High</option>
                <option value="Critical" <?php echo $riskFilter === 'Critical' ? 'selected' : ''; ?>>Critical</option>
            </select>
        </div>

        <div class="filter-group">
            <label for="district" class="form-label">District Name</label>
            <input type="text" name="district" id="district" class="form-control" placeholder="e.g. Polonnaruwa" value="<?php echo e($districtFilter); ?>">
        </div>

        <div class="filter-btn-group">
            <button type="submit" class="btn btn-primary">Filter</button>
            <a href="<?php echo Router::url('wildlife/hotspots.php'); ?>" class="btn btn-secondary">Reset</a>
        </div>
    </form>
</div>

<!-- Hotspot List Panel -->
<div class="card-panel">
    <div class="table-responsive">
        <?php if (empty($hotspots)): ?>
            <p class="text-center text-muted" style="padding: 30px 0;">No hotspot areas match the filters.</p>
        <?php else: ?>
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Hotspot Crossing Name</th>
                        <th>Railway Section</th>
                        <th>District Location</th>
                        <th>Latitude / Longitude</th>
                        <th>Risk Level</th>
                        <th>Reason / Historical Activity</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($hotspots as $hot): ?>
                        <tr>
                            <td><strong><?php echo e($hot['hotspot_name']); ?></strong></td>
                            <td><?php echo e($hot['section_name']); ?></td>
                            <td><?php echo e($hot['district']); ?></td>
                            <td>
                                <?php if ($hot['latitude'] && $hot['longitude']): ?>
                                    <code><?php echo number_format($hot['latitude'], 6); ?>, <?php echo number_format($hot['longitude'], 6); ?></code>
                                <?php else: ?>
                                    <span class="text-muted">Not Mapped</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="badge <?php echo get_risk_badge_class($hot['risk_level']); ?>">
                                    <?php echo e($hot['risk_level']); ?>
                                </span>
                            </td>
                            <td style="font-size:0.85rem; color:var(--text-secondary); max-width:300px;"><?php echo e($hot['reason']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../../app/layouts/dashboard_footer.php'; ?>
