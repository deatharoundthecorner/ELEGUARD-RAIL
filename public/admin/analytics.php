<?php
/**
 * System Administrator Historical Analytics & Data Quality Console
 * Milestone 9C Stage 4D.2
 */
$pageTitle = "Platform Analytics & Data Quality";
$allowed_roles = 'admin';
require_once __DIR__ . '/../../app/layouts/dashboard_header.php';
?>

<link rel="stylesheet" href="<?php echo Router::url('assets/css/analytics.css'); ?>">

<div class="dashboard-title-row">
    <div>
        <h1>📊 Platform Analytics, Provenance & Data Quality Console</h1>
        <p class="text-secondary">Administrative audit of dataset provenance, data completeness indices, AI model throughput, and API endpoint health.</p>
    </div>
    <div>
        <a href="<?php echo Router::url('admin/dashboard.php'); ?>" class="btn btn-outline btn-sm">← Back to Admin Console</a>
    </div>
</div>

<!-- Mandatory Administrative & Database Safety Notice -->
<div class="analytics-notice">
    <div class="notice-icon">🔒</div>
    <div>
        <div class="notice-title">Administrative Database Safety & Provenance Integrity</div>
        <div class="notice-body">
            <strong>Operational Immutability Enforced:</strong> Operational database <code>eleguard_rail</code> (Port 3308) is guarded by storage engine read-only accounts (<code>eleguard_readonly</code>). Historical reports are strictly segregated by provenance to prevent development fixtures and automated audit triads from polluting ecological intelligence.
        </div>
    </div>
</div>

<!-- Interactive Toolbar -->
<div class="analytics-toolbar">
    <div class="toolbar-left">
        <span style="font-size:0.85rem; color:var(--text-secondary);">System Scope: <strong>Global Telemetry & Service Health</strong></span>
    </div>
    <div class="toolbar-right">
        <button id="admin-refresh-btn" class="btn btn-outline btn-sm">🔄 Refresh Analytics & Probe APIs</button>
    </div>
</div>

<!-- KPI Summary Cards -->
<div class="analytics-kpi-grid">
    <div class="kpi-card accent-gold">
        <div class="kpi-title">Total Database Reports</div>
        <div class="kpi-value" id="kpi-admin-total-reports">--</div>
        <div class="kpi-desc">Total rows in elephant_reports</div>
    </div>
    <div class="kpi-card accent-success">
        <div class="kpi-title">Genuine Field Sightings</div>
        <div class="kpi-value" id="kpi-admin-genuine-reports" style="color:var(--success);">--</div>
        <div class="kpi-desc">Verified operational wildlife records</div>
    </div>
    <div class="kpi-card accent-warning">
        <div class="kpi-title">Development Test Records</div>
        <div class="kpi-value" id="kpi-admin-test-reports" style="color:var(--warning);">--</div>
        <div class="kpi-desc">Segregated dev & seed fixtures</div>
    </div>
    <div class="kpi-card accent-info">
        <div class="kpi-title">Data Completeness Score</div>
        <div class="kpi-value" id="kpi-admin-completeness" style="color:var(--info);">--</div>
        <div class="kpi-desc">Composite quality benchmark</div>
    </div>
</div>

<!-- Provenance & Quality Grid -->
<div class="grid grid-2col">
    <!-- Dataset Provenance Donut Chart -->
    <div class="chart-card">
        <div class="chart-header">
            <div>
                <h2 class="chart-title">🏷️ Dataset Provenance Segregation</h2>
                <div class="chart-subtitle">Audit breakdown distinguishing genuine field records from test fixtures</div>
            </div>
        </div>
        <div class="chart-body" id="admin-provenance-chart">
            <!-- Dynamic Donut Chart rendered by JS -->
        </div>
    </div>

    <!-- Data Quality & Field Completeness Audit -->
    <div class="chart-card">
        <div class="chart-header">
            <div>
                <h2 class="chart-title">📋 Data Quality & Schema Integrity Audit</h2>
                <div class="chart-subtitle">Evaluation of coordinate boundaries, timestamp completeness, and section alignment</div>
            </div>
        </div>
        <div class="chart-body" style="justify-content: flex-start;">
            <div id="admin-quality-table" style="width:100%;">
                <!-- Dynamic Quality Table rendered by JS -->
            </div>
        </div>
    </div>
</div>

<!-- AI Subsystem & System Health Grid -->
<div class="grid grid-2col">
    <!-- AI Camera Subsystem Telemetry -->
    <div class="card-panel">
        <div class="panel-header">
            <h2 class="panel-title">🤖 AI Camera Subsystem Throughput</h2>
            <span class="badge badge-outline">YOLOv8 Advisory Inference</span>
        </div>
        <div id="admin-ai-telemetry" style="padding:15px 0;">
            <!-- Rendered by JS -->
        </div>
        <div style="font-size:0.78rem; color:var(--text-muted); padding:0 15px 15px 15px; border-top:1px solid rgba(255,255,255,0.05); padding-top:10px;">
            AI outputs remain purely advisory. Inference runs asynchronously and cannot block report ingestion or train alerts.
        </div>
    </div>

    <!-- Database Safety & System Isolation Monitor -->
    <div class="card-panel">
        <div class="panel-header">
            <h2 class="panel-title">🛡️ Database Safety & Environment Isolation</h2>
        </div>
        <div style="padding:15px; font-size:0.85rem; line-height:1.6;">
            <div style="display:flex; justify-content:space-between; padding:8px 0; border-bottom:1px solid rgba(255,255,255,0.04);">
                <span>Operational Database:</span>
                <strong style="color:var(--success);">eleguard_rail (3308) — IMMUTABLE</strong>
            </div>
            <div style="display:flex; justify-content:space-between; padding:8px 0; border-bottom:1px solid rgba(255,255,255,0.04);">
                <span>Read-Only Account:</span>
                <code>eleguard_readonly (SELECT, SHOW VIEW)</code>
            </div>
            <div style="display:flex; justify-content:space-between; padding:8px 0; border-bottom:1px solid rgba(255,255,255,0.04);">
                <span>Automated Test Database:</span>
                <code>eleguard_rail_test (Isolated)</code>
            </div>
            <div style="display:flex; justify-content:space-between; padding:8px 0;">
                <span>Concurrency Protection:</span>
                <span class="badge badge-success">MariaDB Advisory Mutex</span>
            </div>
        </div>
    </div>
</div>

<!-- Real-Time Analytics REST API Health & Probe Matrix -->
<div class="card-panel" style="margin-bottom: 24px;">
    <div class="panel-header">
        <div>
            <h2 class="panel-title">⚡ Real-Time Analytics REST API Health Matrix</h2>
            <p class="text-secondary" style="font-size:0.8rem; margin-top:2px;">Live probes to the 7 dedicated read-only analytics endpoints.</p>
        </div>
        <a href="<?php echo Router::url('admin/logs.php'); ?>" class="btn btn-outline btn-sm">Inspect System Activity Logs</a>
    </div>
    <div class="table-responsive" id="admin-api-health-table">
        <!-- Rendered by JS -->
    </div>
</div>

<script src="<?php echo Router::url('assets/js/analytics_dashboard.js'); ?>"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    if (window.EleGuardAnalytics) {
        window.EleGuardAnalytics.initAdminAnalytics();
    }
});
</script>

<?php require_once __DIR__ . '/../../app/layouts/dashboard_footer.php'; ?>
