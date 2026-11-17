<?php
/**
 * Wildlife Officer Historical Analytics & Sighting Intelligence
 * Milestone 9C Stage 4D.2
 */
$pageTitle = "Wildlife Sighting Analytics";
$allowed_roles = ['wildlife_officer', 'admin'];
require_once __DIR__ . '/../../app/layouts/dashboard_header.php';
?>

<link rel="stylesheet" href="<?php echo Router::url('assets/css/analytics.css'); ?>">

<div class="dashboard-title-row">
    <div>
        <h1>🐘 Wildlife Sighting Intelligence & Ecological Analytics</h1>
        <p class="text-secondary">Spatial-temporal sighting density, diurnal movement windows, duplicate cluster review, and AI advisory concordance.</p>
    </div>
    <div>
        <a href="<?php echo Router::url('wildlife/dashboard.php'); ?>" class="btn btn-outline btn-sm">← Back to Ranger Center</a>
    </div>
</div>

<!-- Mandatory Governance & Data Transparency Notice -->
<div class="analytics-notice">
    <div class="notice-icon">🛡️</div>
    <div>
        <div class="notice-title">Human Wildlife Officer Authority & Scientific Transparency</div>
        <div class="notice-body">
            <strong>AI is strictly advisory:</strong> Automated computer vision suggestions and spatial-temporal duplicate candidates <strong>never trigger automated alerts or verify reports</strong>. Professional field ranger review is legally required. Historical baseline (IDs 1–43) contains 100% missing observation times; ingestion timestamps are never substituted for sighting times.
        </div>
    </div>
</div>

<!-- Interactive Toolbar -->
<div class="analytics-toolbar">
    <div class="toolbar-left">
        <span style="font-size:0.85rem; color:var(--text-secondary);">Operational Focus: <strong>All Wildlife Corridors & Habitats</strong></span>
    </div>
    <div class="toolbar-right">
        <button id="wildlife-refresh-btn" class="btn btn-outline btn-sm">🔄 Refresh Analytics</button>
    </div>
</div>

<!-- KPI Summary Cards -->
<div class="analytics-kpi-grid">
    <div class="kpi-card accent-warning">
        <div class="kpi-title">Pending Ranger Review</div>
        <div class="kpi-value" id="kpi-wl-pending" style="color:var(--accent-color);">--</div>
        <div class="kpi-desc">Awaiting human field verification</div>
    </div>
    <div class="kpi-card accent-success">
        <div class="kpi-title">Verified Sightings</div>
        <div class="kpi-value" id="kpi-wl-verified" style="color:var(--success);">--</div>
        <div class="kpi-desc">Confirmed elephant movements</div>
    </div>
    <div class="kpi-card accent-gold">
        <div class="kpi-title">Duplicate Candidate Pairs</div>
        <div class="kpi-value" id="kpi-wl-duplicates" style="color:var(--accent-color);">--</div>
        <div class="kpi-desc">Candidate clusters within &le; 250m</div>
    </div>
    <div class="kpi-card accent-info">
        <div class="kpi-title">Overall Data Completeness</div>
        <div class="kpi-value" id="kpi-wl-completeness" style="color:var(--info);">--</div>
        <div class="kpi-desc">Date, time & GPS completeness index</div>
    </div>
</div>

<!-- Diurnal Chart & AI Concordance Grid -->
<div class="grid grid-2col">
    <!-- Diurnal Windows Sighting Chart -->
    <div class="chart-card">
        <div class="chart-header">
            <div>
                <h2 class="chart-title">🕒 24-Hour Diurnal Sighting Distribution</h2>
                <div class="chart-subtitle">Sightings mapped to ecological periods (Dawn, Morning, Afternoon, Dusk, Night)</div>
            </div>
        </div>
        <div class="chart-body" id="wildlife-temporal-chart">
            <!-- Dynamic SVG Chart rendered by JS -->
        </div>
    </div>

    <!-- AI Advisory Concordance Matrix -->
    <div class="chart-card">
        <div class="chart-header">
            <div>
                <h2 class="chart-title">🤖 AI Advisory & Ranger Verification Concordance</h2>
                <div class="chart-subtitle">AI model camera suggestions cross-tabulated against human officer decisions</div>
            </div>
        </div>
        <div class="chart-body" style="justify-content: flex-start;">
            <div id="wildlife-ai-concordance" style="width:100%; margin-bottom:14px;"></div>
            <p style="font-size:0.78rem; color:var(--text-muted); line-height:1.4;">
                * Note: EleGuard Rail enforces human-in-the-loop governance. Discrepancies between AI detection and ranger verification reflect officer authority to reject false positives or verify herds missed by automated cameras.
            </p>
        </div>
    </div>
</div>

<!-- Duplicate Candidate Review Queue -->
<div class="card-panel" style="margin-bottom: 24px;">
    <div class="panel-header">
        <div>
            <h2 class="panel-title">👥 Spatial-Temporal Duplicate Candidate Review Queue</h2>
            <p class="text-secondary" style="font-size:0.8rem; margin-top:2px;">Reports occurring within 250 meters and overlapping time windows. <strong>0 automatic merges performed.</strong></p>
        </div>
        <a href="<?php echo Router::url('wildlife/reports.php?status=Pending'); ?>" class="btn btn-outline btn-sm">Manage All Reports</a>
    </div>
    <div class="table-responsive" id="wildlife-duplicates-table">
        <!-- Rendered by JS -->
    </div>
</div>

<!-- GPS Quality & Hotspots Grid -->
<div class="grid grid-2col">
    <!-- GPS Telemetry & Accuracy Health -->
    <div class="card-panel">
        <div class="panel-header">
            <h2 class="panel-title">📡 GPS Telemetry & Coordinate Quality</h2>
        </div>
        <div id="wildlife-quality-metrics" style="padding:15px 0;">
            <!-- Rendered by JS -->
        </div>
        <div style="font-size:0.78rem; color:var(--text-muted); padding:0 15px 15px 15px; border-top:1px solid rgba(255,255,255,0.05); padding-top:10px;">
            High horizontal accuracy (&le; 50 meters) provides precise triangulation for railway milestone proximity calculations.
        </div>
    </div>

    <!-- Action & Verification Center -->
    <div class="card-panel" style="text-align:center; padding:30px; display:flex; flex-direction:column; justify-content:center;">
        <h3>Ready to Action Pending Reports?</h3>
        <p class="text-secondary" style="font-size:0.85rem; margin:12px 0 20px 0;">Review raw photographs, assess AI annotations, verify elephant herd sizes, or register an official field sighting.</p>
        <div>
            <a href="<?php echo Router::url('wildlife/reports.php?status=Pending'); ?>" class="btn btn-primary btn-md">Review Pending Sightings</a>
            <a href="<?php echo Router::url('wildlife/submit_report.php'); ?>" class="btn btn-outline btn-md" style="margin-left:8px;">+ Submit Verified Sighting</a>
        </div>
    </div>
</div>

<script src="<?php echo Router::url('assets/js/analytics_dashboard.js'); ?>"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    if (window.EleGuardAnalytics) {
        window.EleGuardAnalytics.initWildlifeAnalytics();
    }
});
</script>

<?php require_once __DIR__ . '/../../app/layouts/dashboard_footer.php'; ?>
