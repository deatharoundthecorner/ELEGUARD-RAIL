<?php
/**
 * EleGuard Rail - Reusable GIS Map View Partial
 * 
 * Milestone 9C — Stage 4E.4.3-H3: Interactive Railway Map & Section Visualization
 * 
 * Embeddable map component with interactive filter toolbar, layer controls,
 * loading overlay, summary counters, and mandatory engineering disclaimer.
 * 
 * Required variables (optional defaults provided):
 * - $mapContainerId (string, default: 'gis-map')
 * - $mapHeight (string, default: '520px')
 * - $mapRole (string, default: Auth::role())
 * - $showFilters (bool, default: true)
 * - $showStatsBar (bool, default: true)
 */

require_once __DIR__ . '/../core/Auth.php';
require_once __DIR__ . '/../core/Database.php';
require_once __DIR__ . '/../core/Router.php';
require_once __DIR__ . '/../includes/functions.php';

$mapContainerId = $mapContainerId ?? 'gis-map';
$mapHeight = $mapHeight ?? '520px';
$mapRole = $mapRole ?? Auth::role();
$showFilters = $showFilters ?? true;
$showStatsBar = $showStatsBar ?? true;

// Fetch dynamic railway sections for filter dropdown
$db = Database::getInstance()->getConnection();
$sectionsStmt = $db->query("SELECT id, section_name, district, risk_level FROM railway_sections ORDER BY id ASC");
$mapSections = $sectionsStmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!-- GIS Stylesheet & Local Leaflet Vendor Assets -->
<link rel="stylesheet" href="<?php echo Router::url('assets/vendor/leaflet/leaflet.css'); ?>">
<link rel="stylesheet" href="<?php echo Router::url('assets/css/map.css'); ?>">

<div class="gis-map-wrapper">
    <?php if ($showFilters): ?>
        <!-- Filter & Control Toolbar -->
        <div class="gis-filter-toolbar" role="toolbar" aria-label="GIS Map Controls and Filters">
            <div class="gis-filter-group">
                <span class="gis-filter-label" id="gis-filter-heading">Filters:</span>

                <!-- Railway Section Filter -->
                <label for="gis-filter-section" class="sr-only" style="display:none;">Railway Section</label>
                <select id="gis-filter-section" class="gis-select" title="Filter by Railway Section" aria-labelledby="gis-filter-heading">
                    <option value="">🛣️ All Railway Sections</option>
                    <?php foreach ($mapSections as $sec): ?>
                        <option value="<?php echo $sec['id']; ?>">
                            Sec <?php echo $sec['id']; ?>: <?php echo e($sec['section_name']); ?> (<?php echo e($sec['risk_level']); ?>)
                        </option>
                    <?php endforeach; ?>
                </select>

                <!-- Threat / Risk Level Filter -->
                <label for="gis-filter-risk" class="sr-only" style="display:none;">Threat Risk Level</label>
                <select id="gis-filter-risk" class="gis-select" title="Filter by Threat Risk Level">
                    <option value="">⚠️ All Threat Risks</option>
                    <option value="Critical">🔴 Critical</option>
                    <option value="High">🟠 High</option>
                    <option value="Medium">🟡 Medium</option>
                    <option value="Low">🟢 Low</option>
                </select>

                <!-- Sighting Verification Status -->
                <label for="gis-filter-status" class="sr-only" style="display:none;">Verification Status</label>
                <select id="gis-filter-status" class="gis-select" title="Filter by Verification Status">
                    <option value="">📋 All Sighting Statuses</option>
                    <option value="Verified">✓ Verified Only</option>
                    <option value="Pending">⏳ Pending Ranger Verification</option>
                </select>

                <!-- Date Range Filters -->
                <label for="gis-filter-date-from" class="sr-only" style="display:none;">Date From</label>
                <input type="date" id="gis-filter-date-from" class="gis-input" title="Date From" placeholder="From" aria-label="Date From">
                <label for="gis-filter-date-to" class="sr-only" style="display:none;">Date To</label>
                <input type="date" id="gis-filter-date-to" class="gis-input" title="Date To" placeholder="To" aria-label="Date To">
            </div>

            <div class="gis-filter-group">
                <button type="button" id="gis-filter-reset" class="gis-btn" title="Reset all filters" aria-label="Reset Map Filters">
                    🔄 Reset
                </button>
                <button type="button" id="gis-filter-refresh" class="gis-btn gis-btn-primary" title="Refresh live spatial feed" aria-label="Refresh Spatial Feed">
                    ⚡ Refresh Map
                </button>
            </div>
        </div>
    <?php endif; ?>

    <!-- Mandatory OpenStreetMap Engineering Disclaimer & Advisory Banner -->
    <div class="gis-disclaimer-banner" role="note" aria-label="GIS Data Source Disclaimer">
        <span class="gis-disclaimer-badge">⚠️ PROVISIONAL — NOT OFFICIALLY VERIFIED (OSM/HOT-HDX)</span>
        <span class="gis-disclaimer-text">
            Railway track centerlines derived from OpenStreetMap / HOT-HDX for operational spatial awareness; not official Sri Lanka Railways engineering as-built survey alignment. Dual observer and animal markers distinguish human positions from estimated wildlife locations.
        </span>
    </div>

    <!-- Map Canvas Container -->
    <div id="<?php echo e($mapContainerId); ?>" class="gis-map-container" style="height: <?php echo e($mapHeight); ?>;" role="region" aria-label="Interactive Railway and Wildlife Map"></div>

    <!-- Loading Overlay -->
    <div id="<?php echo e($mapContainerId); ?>-loading" class="gis-loading-overlay active" aria-live="polite">
        <div class="gis-spinner"></div>
        <span>Loading OpenStreetMap & Railway Spatial Layers...</span>
    </div>

    <!-- Error Banner -->
    <div id="<?php echo e($mapContainerId); ?>-error" class="gis-error-banner" role="alert"></div>
</div>

<?php if ($showStatsBar): ?>
    <!-- Live Map Metrics Bar -->
    <div class="stats-grid" style="margin-bottom: 25px;" role="region" aria-label="GIS Spatial Metrics Summary">
        <div class="stat-card" style="padding: 12px 16px;">
            <span class="stat-title">Mapped Sightings</span>
            <span class="stat-value" id="gis-stat-total-reports" style="font-size: 1.5rem; color: #8bc34a;">--</span>
            <span class="stat-desc">Active GIS reports</span>
        </div>
        <div class="stat-card" style="padding: 12px 16px;">
            <span class="stat-title">Verified Sightings</span>
            <span class="stat-value" id="gis-stat-verified-reports" style="font-size: 1.5rem; color: #4caf50;">--</span>
            <span class="stat-desc">Confirmed elephant coordinates</span>
        </div>
        <div class="stat-card" style="padding: 12px 16px;">
            <span class="stat-title">Pending Validation</span>
            <span class="stat-value" id="gis-stat-pending-reports" style="font-size: 1.5rem; color: #fbc02d;">--</span>
            <span class="stat-desc">Awaiting ranger review</span>
        </div>
        <div class="stat-card" style="padding: 12px 16px;">
            <span class="stat-title">Critical Threat Sightings</span>
            <span class="stat-value" id="gis-stat-critical-reports" style="font-size: 1.5rem; color: #d32f2f;">--</span>
            <span class="stat-desc">High risk to train safety</span>
        </div>
        <div class="stat-card" style="padding: 12px 16px;">
            <span class="stat-title">Monitored Hotspots</span>
            <span class="stat-value" id="gis-stat-hotspots" style="font-size: 1.5rem; color: #ff9800;">--</span>
            <span class="stat-desc">Active crossing zones</span>
        </div>
    </div>
<?php endif; ?>

<!-- Vendor Leaflet & Map Component Script -->
<script src="<?php echo Router::url('assets/vendor/leaflet/leaflet.js'); ?>"></script>
<script src="<?php echo Router::url('assets/js/map_component.js'); ?>"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    window.eleguardMapInstance = EleGuardMap.init('<?php echo e($mapContainerId); ?>', {
        role: '<?php echo e($mapRole); ?>',
        apiEndpoint: '<?php echo Router::url('api/gis_data.php'); ?>',
        filterPrefix: 'gis-filter-'
    });
});
</script>
