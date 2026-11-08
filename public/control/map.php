<?php
/**
 * Railway Control Officer - Operational GIS Corridor & Threat Map
 */
$pageTitle = "Operational Railway Map";
$allowed_roles = 'control_officer';
require_once __DIR__ . '/../../app/layouts/dashboard_header.php';
?>

<div class="dashboard-title-row">
    <div>
        <h1>🚦 Operational Railway Sighting & Hazard Map</h1>
        <p class="text-secondary">Real-time situational awareness for train track elephant proximities, active hazards, and speed warning zones.</p>
    </div>
    <div style="display: flex; gap: 10px;">
        <a href="<?php echo Router::url('control/create_alert.php'); ?>" class="btn btn-primary btn-sm">🚨 Broadcast Warning Alert</a>
        <a href="<?php echo Router::url('control/dashboard.php'); ?>" class="btn btn-outline btn-sm">📊 Control Desk</a>
    </div>
</div>

<?php 
$mapContainerId = 'control-gis-map';
$mapHeight = '650px';
$mapRole = 'control_officer';
$showFilters = true;
$showStatsBar = true;
include __DIR__ . '/../../app/layouts/map_view_partial.php'; 
?>

<?php require_once __DIR__ . '/../../app/layouts/dashboard_footer.php'; ?>
