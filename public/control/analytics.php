<?php
/**
 * Railway Control Officer Historical Analytics & Corridor Intelligence
 * Milestone 9C Stage 4D.2
 */
$pageTitle = "Corridor Historical Analytics";
$allowed_roles = ['control_officer', 'admin'];
require_once __DIR__ . '/../../app/layouts/dashboard_header.php';
?>

<link rel="stylesheet" href="<?php echo Router::url('assets/css/analytics.css'); ?>">

<div class="dashboard-title-row">
    <div>
        <h1>🚦 Railway Corridor Historical Intelligence</h1>
        <p class="text-secondary">Historical spatial-temporal analytics, reporting delays, corridor risk tiers, and duplicate hazard mitigation.</p>
    </div>
    <div>
        <a href="<?php echo Router::url('control/dashboard.php'); ?>" class="btn btn-outline btn-sm">← Back to Control Desk</a>
    </div>
</div>

<!-- Mandatory Data Scarcity & Transparency Notice -->
<div class="analytics-notice">
    <div class="notice-icon">ℹ️</div>
    <div>
        <div class="notice-title">Scientific Transparency & Historical Scarcity Notice</div>
        <div class="notice-body">
            The historical database contains <strong>N=3 genuine field sightings</strong> (IDs 1–3). 100% of historical records lack recorded observation times. Ingestion timestamp (<code>created_at</code>) is strictly <strong>never assumed to be observation time</strong>. Diurnal and temporal distributions reflect verified sighting times only.
        </div>
    </div>
</div>

<!-- Interactive Filter Toolbar -->
<div class="analytics-toolbar">
    <div class="toolbar-left">
        <label for="control-section-filter" style="font-size:0.85rem; font-weight:600; color:var(--text-secondary);">Railway Section:</label>
        <select id="control-section-filter" class="analytics-select">
            <option value="">All Corridor Sections</option>
            <option value="1">Section 1: Gal Oya - Habarana</option>
            <option value="2">Section 2: Habarana - Palugaswewa</option>
            <option value="3">Section 3: Palugaswewa - Hingurakgoda</option>
            <option value="4">Section 4: Hingurakgoda - Polonnaruwa</option>
        </select>

        <label for="control-provenance-filter" style="font-size:0.85rem; font-weight:600; color:var(--text-secondary);">Data Provenance:</label>
        <select id="control-provenance-filter" class="analytics-select">
            <option value="all">All Records (Field + Test + Audit)</option>
            <option value="genuine_only">Genuine Field Sightings Only</option>
        </select>
    </div>
    <div class="toolbar-right">
        <button id="control-refresh-btn" class="btn btn-outline btn-sm">🔄 Refresh Analytics</button>
    </div>
</div>

<!-- KPI Summary Cards -->
<div class="analytics-kpi-grid">
    <div class="kpi-card accent-gold">
        <div class="kpi-title">Total Sighting Reports</div>
        <div class="kpi-value" id="kpi-total-reports">--</div>
        <div class="kpi-desc">Across all monitored railway sections</div>
    </div>
    <div class="kpi-card accent-success">
        <div class="kpi-title">Verified Sightings</div>
        <div class="kpi-value" id="kpi-verified-reports" style="color:var(--success);">--</div>
        <div class="kpi-desc">Confirmed by field wildlife officers</div>
    </div>
    <div class="kpi-card accent-danger">
        <div class="kpi-title">Active Danger Alerts</div>
        <div class="kpi-value" id="kpi-active-alerts" style="color:var(--danger);">--</div>
        <div class="kpi-desc">Current train speed restriction zones</div>
    </div>
    <div class="kpi-card accent-warning">
        <div class="kpi-title">Duplicate Candidate Pairs</div>
        <div class="kpi-value" id="kpi-duplicate-candidates" style="color:var(--warning);">--</div>
        <div class="kpi-desc">Within 250m & overlapping time window</div>
    </div>
</div>

<!-- Corridor Risk & Latency Grid -->
<div class="grid grid-2col">
    <!-- Diurnal Windows Temporal Chart -->
    <div class="chart-card">
        <div class="chart-header">
            <div>
                <h2 class="chart-title">🕒 Diurnal Observation-Time Distribution</h2>
                <div class="chart-subtitle">Sightings mapped by verified observation hour (excludes unrecorded historical clock times)</div>
            </div>
        </div>
        <div class="chart-body" id="control-temporal-chart">
            <!-- Dynamic SVG Chart rendered by JS -->
        </div>
    </div>

    <!-- Dispatch & Reporting Latency Telemetry -->
    <div class="chart-card">
        <div class="chart-header">
            <div>
                <h2 class="chart-title">⏱️ Reporting Delay & Verification Latency</h2>
                <div class="chart-subtitle">Time elapsed between elephant sighting, filing, and ranger verification</div>
            </div>
        </div>
        <div class="chart-body" style="justify-content: flex-start;">
            <div id="control-latency-stats" style="width:100%; margin-bottom:16px;"></div>
            <div style="background-color:rgba(0,0,0,0.2); border:1px solid var(--border-color); border-radius:6px; padding:12px; font-size:0.8rem; color:var(--text-secondary); line-height:1.45;">
                <strong style="color:var(--accent-color);">Operational Safety Rule:</strong> High reporting lag (&gt; 2 hours) indicates wildlife may have migrated across railway milestones. Controller caution is advised before broadcasting restrictive emergency speed reductions.
            </div>
        </div>
    </div>
</div>

<!-- Railway Corridor Sections Inventory -->
<div class="card-panel" style="margin-bottom: 24px;">
    <div class="panel-header">
        <h2 class="panel-title">🛣️ Railway Corridor Sections & Operational Risk Status</h2>
        <span class="badge badge-outline">Read-Only Analytics Query Layer</span>
    </div>
    <div class="table-responsive" id="control-sections-table">
        <!-- Rendered by JS -->
    </div>
</div>

<!-- Hotspots & Duplicate Mitigation Grid -->
<div class="grid grid-2col">
    <!-- Hotspots -->
    <div class="card-panel">
        <div class="panel-header">
            <h2 class="panel-title">📍 Monitored Elephant Hotspot Locations</h2>
        </div>
        <div class="table-responsive" id="control-hotspots-panel">
            <!-- Rendered by JS -->
        </div>
    </div>

    <!-- Duplicate Hazard Warning Panel -->
    <div class="card-panel">
        <div class="panel-header">
            <h2 class="panel-title">⚠️ Duplicate Cluster Hazard Mitigation</h2>
        </div>
        <div style="padding:15px; font-size:0.85rem; line-height:1.5; color:var(--text-secondary);">
            <p style="margin-bottom:12px;">Multiple villagers or rangers often report the same elephant herd within minutes. Our spatial-temporal duplicate engine identifies candidates within <strong>250 meters</strong> and <strong>overlapping observation windows</strong>.</p>
            <div style="background-color:rgba(255, 152, 0, 0.1); border-left:3px solid var(--warning); padding:10px 14px; border-radius:4px; margin-bottom:15px;">
                <strong style="color:var(--warning);">Controller Action Directive:</strong> Check for duplicate reports before creating safety warnings to prevent conflicting speed limits on the same track milestone.
            </div>
            <div style="text-align:center;">
                <a href="<?php echo Router::url('control/create_alert.php'); ?>" class="btn btn-danger btn-sm">🚨 Create Speed Warning Alert</a>
                <a href="<?php echo Router::url('control/alerts.php'); ?>" class="btn btn-outline btn-sm" style="margin-left:8px;">View Active Alerts</a>
            </div>
        </div>
    </div>
</div>

<script src="<?php echo Router::url('assets/js/analytics_dashboard.js'); ?>"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    if (window.EleGuardAnalytics) {
        window.EleGuardAnalytics.initControlAnalytics();
    }
});
</script>

<?php require_once __DIR__ . '/../../app/layouts/dashboard_footer.php'; ?>
