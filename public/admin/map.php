<?php
/**
 * Administrator - Full Network GIS & Wildlife Safety Map
 */
$pageTitle = "Central GIS Map";
$allowed_roles = 'admin';
require_once __DIR__ . '/../../app/layouts/dashboard_header.php';
?>

<div class="dashboard-title-row">
    <div>
        <h1>🗺️ Central Railway GIS & Wildlife Threat Map</h1>
        <p class="text-secondary">Administrative geospatial overview of Sri Lanka railway routes, wildlife crossing corridors, verified sightings, and pending reports.</p>
    </div>
    <div style="display: flex; gap: 10px;">
        <a href="<?php echo Router::url('admin/hotspots.php'); ?>" class="btn btn-outline btn-sm">📍 Hotspots</a>
        <a href="<?php echo Router::url('admin/dashboard.php'); ?>" class="btn btn-outline btn-sm">📊 Overview</a>
    </div>
</div>

<?php 
$mapContainerId = 'admin-gis-map';
$mapHeight = '650px';
$mapRole = 'admin';
$showFilters = true;
$showStatsBar = true;
include __DIR__ . '/../../app/layouts/map_view_partial.php'; 
?>

<?php require_once __DIR__ . '/../../app/layouts/dashboard_footer.php'; ?>
