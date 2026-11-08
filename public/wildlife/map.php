<?php
/**
 * Wildlife Officer - Interactive Wildlife Tracking & Railway GIS Map
 */
$pageTitle = "Wildlife Tracking Map";
$allowed_roles = 'wildlife_officer';
require_once __DIR__ . '/../../app/layouts/dashboard_header.php';
?>

<div class="dashboard-title-row">
    <div>
        <h1>🐘 Wildlife Tracking & Railway Corridor Map</h1>
        <p class="text-secondary">Spatial monitoring of elephant sighting reports, track proximity metrics, and known crossing hotspots.</p>
    </div>
    <div style="display: flex; gap: 10px;">
        <a href="<?php echo Router::url('wildlife/submit_report.php'); ?>" class="btn btn-accent btn-sm">➕ File Ranger Sighting</a>
        <a href="<?php echo Router::url('wildlife/dashboard.php'); ?>" class="btn btn-outline btn-sm">📊 Dashboard</a>
    </div>
</div>

<?php 
$mapContainerId = 'wildlife-gis-map';
$mapHeight = '650px';
$mapRole = 'wildlife_officer';
$showFilters = true;
$showStatsBar = true;
include __DIR__ . '/../../app/layouts/map_view_partial.php'; 
?>

<?php require_once __DIR__ . '/../../app/layouts/dashboard_footer.php'; ?>
