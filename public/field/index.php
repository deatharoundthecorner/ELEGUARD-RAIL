<?php
/**
 * EleGuard Rail — Guided Field Observation Capture Interface
 * Milestone 9C Stage 4E.4.2-B: Complete Observation Capture Workflow
 *
 * Dedicated mobile-first Progressive Web App interface for field telemetry collection.
 * Features 6-step guided wizard, spatial decoupling, unknown location protocol,
 * gapless diurnal taxonomy, driver lockout directive, and RFC 4122 v4 UUID idempotency.
 */

require_once __DIR__ . '/../../app/config/app.php';
require_once __DIR__ . '/../../app/core/Session.php';
require_once __DIR__ . '/../../app/core/Auth.php';
require_once __DIR__ . '/../../app/core/Router.php';
require_once __DIR__ . '/../../app/core/Database.php';
require_once __DIR__ . '/../../app/includes/functions.php';

// Authentication Guard: Redirect unauthenticated requests to login
if (!Auth::check()) {
    Session::flash('error', 'Please log in to access the Field Reporting PWA.');
    Router::redirect('login.php?redirect=' . urlencode('field/index.php'));
}

$currentUser = Auth::user();
$userRole = $currentUser['role'] ?? 'reporter';
$isDriver = ($userRole === 'driver');
$csrfToken = Session::generateCsrfToken();

// Fetch railway sections for section dropdown
$sections = [];
try {
    $pdo = Database::getInstance()->getConnection();
    $stmt = $pdo->query("SELECT id, section_name, district, risk_level FROM railway_sections ORDER BY id ASC");
    $sections = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // Graceful fallback if database read fails
    $sections = [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <meta name="theme-color" content="#166534">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="description" content="EleGuard Rail Decoupled Field Telemetry PWA Client">
    <meta name="csrf-token" content="<?php echo e($csrfToken); ?>">
    
    <title>Field Telemetry PWA | <?php echo e(APP_NAME); ?></title>

    <!-- Web App Manifest -->
    <link rel="manifest" href="../manifest.webmanifest">

    <!-- Application Icons -->
    <link rel="icon" type="image/svg+xml" href="../assets/images/icons/icon.svg">
    <link rel="apple-touch-icon" href="../assets/images/icons/icon-192.png">

    <!-- Google Fonts: Inter and Outfit -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Outfit:wght@600;700;800&display=swap" rel="stylesheet">

    <!-- PWA Stylesheet -->
    <link rel="stylesheet" href="../assets/css/field_pwa.css">

    <!-- Global API Configuration -->
    <script>
        window.ELEGUARD_CSRF_TOKEN = "<?php echo e($csrfToken); ?>";
        window.ELEGUARD_API_SUBMIT = "<?php echo Router::url('api/v2/reports/submit.php'); ?>";
        window.ELEGUARD_API_SYNC_BATCH = "<?php echo Router::url('api/v2/reports/sync_batch.php'); ?>";
        window.ELEGUARD_API_MY_REPORTS = "<?php echo Router::url('api/v2/reports/my.php'); ?>";
        window.ELEGUARD_API_REPORTS_BASE = "<?php echo Router::url('api/v2/reports'); ?>";
    </script>
</head>
<body>

    <!-- Sticky App Header -->
    <header class="field-header">
        <div class="header-inner">
            <a href="index.php" class="brand-group">
                <span class="brand-icon">🐘</span>
                <span class="brand-title">EleGuard <span class="brand-accent">Field</span></span>
            </a>

            <div class="header-status-area">
                <!-- Dynamic Online / Offline Pill -->
                <div class="status-badge online" id="connectionStatusBadge">
                    <span class="status-dot" id="connectionStatusDot"></span>
                    <span id="connectionStatusText">Online</span>
                </div>

                <!-- Encrypted Storage Status Pill / Button -->
                <button type="button" class="storage-status-pill uninitialized" id="storageStatusBtn" title="Encrypted Drafts Storage" onclick="window.EleGuardStorageUI.openStorageModal()">
                    <span id="storageStatusIcon">🔒</span>
                    <span id="storageStatusText">Storage Setup</span>
                    <span class="draft-count-bubble" id="storageDraftCountBubble" style="display: none;">0</span>
                </button>

                <!-- User Session Pill -->
                <div class="user-pill">
                    <span>👤 <?php echo e($currentUser['username'] ?? 'User'); ?></span>
                    <span class="user-role-badge"><?php echo e($userRole); ?></span>
                </div>

                <a href="<?php echo Router::url(Router::getDashboardPath($userRole)); ?>" class="btn btn-secondary" style="min-height: 32px; padding: 4px 10px; font-size: 12px;" title="Exit to Dashboard">
                    Dashboard
                </a>
            </div>
        </div>
    </header>

    <?php if ($isDriver): ?>
        <!-- ================================================================= -->
        <!-- LOCOMOTIVE DRIVER SAFETY RESTRICTION (MANDATORY DIRECTIVE)        -->
        <!-- ================================================================= -->
        <main class="field-main">
            <div class="driver-lockout-card">
                <div class="driver-lockout-icon">🚫🚂</div>
                <h1 class="driver-lockout-title">Driver Safety Directive Active</h1>
                <p class="driver-lockout-text">
                    In strict accordance with <strong>Milestone 9C Stage 4E.2-B</strong> railway safety regulations, locomotive drivers are <strong>prohibited</strong> from operating mobile devices, touchscreens, or field apps inside the locomotive cab while on duty.
                </p>
                <div class="radio-channel-box">
                    ⚠️ Do not operate this application while driving.<br>
                    Communicate observations through the authorized railway VHF communication procedure.
                </div>
                <p style="font-size: 13px; color: #94a3b8; margin-bottom: 20px;">
                    Station staff and wildlife field officers will log the telemetry report on your behalf.
                </p>
                <a href="<?php echo Router::url('driver/dashboard.php'); ?>" class="btn btn-primary" style="display: inline-flex;">
                    ← Return to Driver Terminal
                </a>
            </div>
        </main>
    <?php else: ?>
        <!-- ================================================================= -->
        <!-- GUIDED FIELD OBSERVATION WORKFLOW                                 -->
        <!-- ================================================================= -->

        <!-- Navigation Tabs -->
        <nav class="field-nav-tabs">
            <div class="nav-tabs-inner">
                <button type="button" class="tab-btn active" data-tab="tabObservation">
                    <span>📝 Observation Capture</span>
                </button>
                <button type="button" class="tab-btn" data-tab="tabDrafts">
                    <span>📦 Drafts &amp; Outbox</span>
                    <span class="tab-badge" id="draftCountBadge">0</span>
                </button>
                <button type="button" class="tab-btn" data-tab="tabSync">
                    <span>🔄 Sync Health</span>
                    <span class="tab-badge" style="color: #10b981;">Ready</span>
                </button>
                <button type="button" class="tab-btn" data-tab="tabMyReports" id="tabBtnMyReports">
                    <span>📋 My Sightings</span>
                    <span class="tab-badge" id="myReportsActionBadge" style="display:none; background:#f59e0b; color:#000; font-weight:700;">!</span>
                </button>
            </div>
        </nav>

        <main class="field-main">

            <!-- TAB 1: GUIDED OBSERVATION WORKFLOW -->
            <section id="tabObservation" class="tab-content active">

                <!-- 6-Step Stepper Progress Bar -->
                <div class="stepper-progress-container">
                    <div class="stepper-header">
                        <span class="stepper-step-title" id="stepperStepTitle">Step 1: Observation Details</span>
                        <span class="stepper-counter" id="stepperCounter">1 of 6</span>
                    </div>
                    <div class="stepper-bar-track">
                        <div class="stepper-bar-fill" id="stepperBarFill" style="width: 16.66%;"></div>
                    </div>
                    <div class="stepper-pills">
                        <div class="step-indicator-pill active" data-step="1" id="pillStep1">
                            <span class="step-circle">1</span>
                            <span>Details</span>
                        </div>
                        <div class="step-indicator-pill" data-step="2" id="pillStep2">
                            <span class="step-circle">2</span>
                            <span>Observer</span>
                        </div>
                        <div class="step-indicator-pill" data-step="3" id="pillStep3">
                            <span class="step-circle">3</span>
                            <span>Elephant</span>
                        </div>
                        <div class="step-indicator-pill" data-step="4" id="pillStep4">
                            <span class="step-circle">4</span>
                            <span>Group</span>
                        </div>
                        <div class="step-indicator-pill" data-step="5" id="pillStep5">
                            <span class="step-circle">5</span>
                            <span>Railway</span>
                        </div>
                        <div class="step-indicator-pill" data-step="6" id="pillStep6">
                            <span class="step-circle">6</span>
                            <span>Review</span>
                        </div>
                    </div>
                </div>

                <!-- Form Shell -->
                <form id="guidedReportForm" autocomplete="off" onsubmit="return false;">

                    <!-- Global Validation Message Banner -->
                    <div id="stepErrorBanner" class="notice-box notice-danger" style="display: none;"></div>

                    <!-- ========================================================= -->
                    <!-- STEP 1: OBSERVATION DETAILS                               -->
                    <!-- ========================================================= -->
                    <div class="wizard-step active" id="wizardStep1" data-step="1">
                        <div class="form-card">
                            <div class="card-header">
                                <div class="card-title-group">
                                    <div class="card-step-num">1</div>
                                    <div>
                                        <h2 class="card-title">Observation Details &amp; Timestamp</h2>
                                        <p class="card-subtitle">Record temporal continuum and primary sighting notes</p>
                                    </div>
                                </div>
                            </div>

                            <div class="form-grid-3">
                                <div class="form-group">
                                    <label class="form-label" for="observation_date">Observation Date <span class="required">*</span></label>
                                    <input type="date" class="form-input" id="observation_date" name="observation_date" required>
                                </div>
                                <div class="form-group">
                                    <label class="form-label" for="observation_time">Observation Time (HH:MM)</label>
                                    <input type="time" class="form-input" id="observation_time" name="observation_time">
                                </div>
                                <div class="form-group" style="justify-content: flex-end;">
                                    <label class="checkbox-card" style="margin-top: 22px;">
                                        <input type="checkbox" id="time_unknown" name="time_unknown" value="1">
                                        <span class="checkbox-label" style="font-size: 13px;">Observation time unknown</span>
                                    </label>
                                </div>
                            </div>

                            <!-- Diurnal Continuum (Authoritative 7 periods) -->
                            <div style="margin-top: 14px;">
                                <label class="form-label">Diurnal Period (Auto-detected or tap to choose):</label>
                                <input type="hidden" id="diurnal_window" name="diurnal_window" value="midday">
                                
                                <div class="diurnal-group">
                                    <button type="button" class="diurnal-btn" data-period="dawn">
                                        <span class="d-title">🌅 Dawn</span>
                                        <span class="d-time">04:00 – 07:00</span>
                                    </button>
                                    <button type="button" class="diurnal-btn" data-period="morning">
                                        <span class="d-title">☀️ Morning</span>
                                        <span class="d-time">07:00 – 11:00</span>
                                    </button>
                                    <button type="button" class="diurnal-btn" data-period="midday">
                                        <span class="d-title">🌤️ Midday</span>
                                        <span class="d-time">11:00 – 14:00</span>
                                    </button>
                                    <button type="button" class="diurnal-btn" data-period="afternoon">
                                        <span class="d-title">⛅ Afternoon</span>
                                        <span class="d-time">14:00 – 17:00</span>
                                    </button>
                                    <button type="button" class="diurnal-btn" data-period="dusk">
                                        <span class="d-title">🌇 Dusk</span>
                                        <span class="d-time">17:00 – 19:30</span>
                                    </button>
                                    <button type="button" class="diurnal-btn" data-period="night">
                                        <span class="d-title">🌙 Night</span>
                                        <span class="d-time">19:30 – 23:30</span>
                                    </button>
                                    <button type="button" class="diurnal-btn" data-period="deep_night" style="grid-column: 1 / -1;">
                                        <span class="d-title">🌌 Deep Night</span>
                                        <span class="d-time">23:30 – 04:00 (Spans Midnight)</span>
                                    </button>
                                </div>
                            </div>

                            <div class="form-grid-2" style="margin-top: 16px;">
                                <div class="form-group">
                                    <label class="form-label" for="observation_category">Observation Category <span class="required">*</span></label>
                                    <select class="form-select" id="observation_category" name="observation_category">
                                        <option value="live_sighting">Live Elephant Sighting</option>
                                        <option value="tracks_or_dung">Tracks, Footprints or Dung</option>
                                        <option value="crop_damage">Crop or Fence Damage</option>
                                        <option value="carcass">Elephant Carcass / Injury</option>
                                        <option value="fence_breach">Railway Wildlife Fence Breach</option>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label class="form-label" for="urgency_level">Estimated Urgency Level <span class="required">*</span></label>
                                    <select class="form-select" id="urgency_level" name="urgency_level">
                                        <option value="Low">Low (Distant forest observation)</option>
                                        <option value="Medium" selected>Medium (Herd in corridor vicinity)</option>
                                        <option value="High">High (Elephants approaching railway line)</option>
                                        <option value="Critical">Critical (Elephants directly on tracks)</option>
                                    </select>
                                </div>
                            </div>

                            <div class="form-group" style="margin-top: 14px;">
                                <label class="form-label" for="location_description">Location Description <span class="required">*</span></label>
                                <input type="text" class="form-input" id="location_description" name="location_description" placeholder="e.g. Near culvert 412, Habarana-Gal Oya forest reserve" required maxlength="255">
                                <span style="font-size: 11px; color: var(--text-muted);">A descriptive landmark name or corridor area is mandatory.</span>
                            </div>

                            <div class="form-group" style="margin-top: 14px;">
                                <label class="form-label" for="observer_notes">Additional Field Remarks (Optional)</label>
                                <textarea class="form-textarea" id="observer_notes" name="observer_notes" placeholder="Observer movement, weather, foliage density, behavior..."></textarea>
                            </div>

                            <div class="step-nav-controls">
                                <button type="button" class="btn btn-save-draft" onclick="window.EleGuardStorageUI.saveCurrentDraft()">💾 Save Draft</button>
                                <button type="button" class="btn btn-primary" onclick="window.EleGuardWizard.nextStep()">
                                    Next: Observer Location →
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- ========================================================= -->
                    <!-- STEP 2: OBSERVER GPS LOCATION                             -->
                    <!-- ========================================================= -->
                    <div class="wizard-step" id="wizardStep2" data-step="2">
                        <div class="form-card">
                            <div class="card-header">
                                <div class="card-title-group">
                                    <div class="card-step-num">2</div>
                                    <div>
                                        <h2 class="card-title">Observer GNSS Location</h2>
                                        <p class="card-subtitle">Exact position of the field officer / reporting person</p>
                                    </div>
                                </div>
                            </div>

                            <div class="notice-box notice-warning">
                                <span>⚠️</span>
                                <div>
                                    <strong>OBSERVER LOCATION — NOT THE ANIMAL'S VERIFIED LOCATION:</strong><br>
                                    Never assume the elephant is where you are standing. GPS background tracking is strictly disabled.
                                </div>
                            </div>

                            <button type="button" class="btn btn-gps" id="acquireGpsBtn">
                                <span>📍 Acquire Observer GNSS Satellite Fix</span>
                            </button>
                            <div id="gpsStatusMsg" style="font-size: 13px; margin-bottom: 14px; font-weight: 500;"></div>

                            <div class="form-grid-2">
                                <div class="form-group">
                                    <label class="form-label" for="observer_lat">Observer Latitude (°N)</label>
                                    <input type="number" step="0.000001" class="form-input" id="observer_lat" name="observer_lat" placeholder="e.g. 8.032145">
                                </div>
                                <div class="form-group">
                                    <label class="form-label" for="observer_lon">Observer Longitude (°E)</label>
                                    <input type="number" step="0.000001" class="form-input" id="observer_lon" name="observer_lon" placeholder="e.g. 80.754321">
                                </div>
                            </div>

                            <div class="form-grid-3" style="margin-top: 14px;">
                                <div class="form-group">
                                    <label class="form-label" for="observer_accuracy">GPS Accuracy (±m)</label>
                                    <input type="number" step="0.1" class="form-input" id="observer_accuracy" name="observer_accuracy" placeholder="e.g. 6.5" value="10.0">
                                </div>
                                <div class="form-group">
                                    <label class="form-label" for="acquisition_method">Acquisition Method</label>
                                    <select class="form-select" id="acquisition_method" name="acquisition_method">
                                        <option value="gps_device">Hardware GNSS (GPS/Galileo)</option>
                                        <option value="network_coarse">Cellular / Network Triangulation</option>
                                        <option value="manual_map_pinpoint">Manual Map Selection</option>
                                        <option value="landmark_only">Descriptive Landmark Only</option>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label class="form-label" for="acquisition_time">Acquisition Timestamp</label>
                                    <input type="text" class="form-input" id="acquisition_time" name="acquisition_time" readonly placeholder="Auto-captured on fix">
                                </div>
                            </div>

                            <div class="step-nav-controls">
                                <button type="button" class="btn btn-secondary" onclick="window.EleGuardWizard.prevStep()">
                                    ← Back: Details
                                </button>
                                <button type="button" class="btn btn-save-draft" onclick="window.EleGuardStorageUI.saveCurrentDraft()">💾 Save Draft</button>
                                <button type="button" class="btn btn-primary" onclick="window.EleGuardWizard.nextStep()">
                                    Next: Elephant Location →
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- ========================================================= -->
                    <!-- STEP 3: ELEPHANT LOCATION                                 -->
                    <!-- ========================================================= -->
                    <div class="wizard-step" id="wizardStep3" data-step="3">
                        <div class="form-card">
                            <div class="card-header">
                                <div class="card-title-group">
                                    <div class="card-step-num">3</div>
                                    <div>
                                        <h2 class="card-title">Elephant Position Estimation</h2>
                                        <p class="card-subtitle">Method A: Geodetic Offset vs. Method B: Unknown Location</p>
                                    </div>
                                </div>
                            </div>

                            <div class="notice-box notice-info">
                                <span>ℹ️</span>
                                <div>
                                    <strong>Scientific Protocol:</strong> Reporting <em>"Animal location unknown"</em> is scientifically preferable to inaccurate or guessed coordinates. The server recalculates and validates all offsets upon ingestion.
                                </div>
                            </div>

                            <!-- Option B: Unknown animal location toggle -->
                            <label class="checkbox-card" style="margin-bottom: 16px;">
                                <input type="checkbox" id="animal_coords_unknown" name="animal_coords_unknown" value="1">
                                <span class="checkbox-label">
                                    ⚠️ Animal location unknown (Leaves animal coordinates strictly NULL)
                                </span>
                            </label>

                            <!-- Option A: Distance & Bearing Offset -->
                            <div id="animal_offset_section">
                                <div class="form-grid-3">
                                    <div class="form-group">
                                        <label class="form-label" for="observation_distance">Distance from Observer (metres)</label>
                                        <input type="number" min="0" max="5000" class="form-input" id="observation_distance" name="observation_distance" placeholder="e.g. 75">
                                    </div>
                                    <div class="form-group">
                                        <label class="form-label" for="observation_bearing">Compass Azimuth (0°–359°)</label>
                                        <input type="number" min="0" max="359" class="form-input" id="observation_bearing" name="observation_bearing" placeholder="e.g. 45">
                                    </div>
                                    <div class="form-group">
                                        <label class="form-label" for="animal_uncertainty">Estimated Uncertainty (±m)</label>
                                        <input type="number" min="1" max="1000" class="form-input" id="animal_uncertainty" name="animal_uncertainty" placeholder="e.g. 25" value="25">
                                    </div>
                                </div>

                                <!-- Visual Compass Cardinal Helper -->
                                <div style="margin-top: 10px;">
                                    <span style="font-size: 12px; color: var(--text-secondary); font-weight: 600;">Compass Azimuth Helper:</span>
                                    <div class="direction-grid">
                                        <button type="button" class="dir-btn" data-bearing="0">N (0°)</button>
                                        <button type="button" class="dir-btn" data-bearing="45">NE (45°)</button>
                                        <button type="button" class="dir-btn" data-bearing="90">E (90°)</button>
                                        <button type="button" class="dir-btn" data-bearing="135">SE (135°)</button>
                                        <button type="button" class="dir-btn" data-bearing="180">S (180°)</button>
                                        <button type="button" class="dir-btn" data-bearing="225">SW (225°)</button>
                                        <button type="button" class="dir-btn" data-bearing="270">W (270°)</button>
                                        <button type="button" class="dir-btn" data-bearing="315">NW (315°)</button>
                                    </div>
                                </div>

                                <!-- Live Great Circle Projection Preview -->
                                <div class="calc-preview-box" id="calcPreviewBox">
                                    <div class="calc-preview-title">📐 Geodesic Projection Preview (Client Preview Only)</div>
                                    <div class="calc-preview-coords" id="calcCoordsDisplay">
                                        Waiting for Observer GPS fix...
                                    </div>
                                    <div class="calc-uncertainty-pill" id="calcUncertaintyDisplay">
                                        Please enter distance and bearing offset.
                                    </div>
                                </div>
                            </div>

                            <div class="step-nav-controls">
                                <button type="button" class="btn btn-secondary" onclick="window.EleGuardWizard.prevStep()">
                                    ← Back: Observer
                                </button>
                                <button type="button" class="btn btn-save-draft" onclick="window.EleGuardStorageUI.saveCurrentDraft()">💾 Save Draft</button>
                                <button type="button" class="btn btn-primary" onclick="window.EleGuardWizard.nextStep()">
                                    Next: Group Info →
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- ========================================================= -->
                    <!-- STEP 4: ELEPHANT GROUP INFORMATION                       -->
                    <!-- ========================================================= -->
                    <div class="wizard-step" id="wizardStep4" data-step="4">
                        <div class="form-card">
                            <div class="card-header">
                                <div class="card-title-group">
                                    <div class="card-step-num">4</div>
                                    <div>
                                        <h2 class="card-title">Elephant Group Information &amp; Demographics</h2>
                                        <p class="card-subtitle">All counts are estimates. Do not guess individual categories if uncertain.</p>
                                    </div>
                                </div>
                            </div>

                            <div class="form-grid-2">
                                <div class="form-group">
                                    <label class="form-label" for="elephant_count">Total Estimated Group Size <span class="required">*</span></label>
                                    <input type="number" min="1" max="150" class="form-input" id="elephant_count" name="elephant_count" value="1" required style="font-size: 18px; font-weight: 700; color: #10b981;">
                                    <span style="font-size: 11px; color: var(--text-muted);" id="autoSumMsg">Set directly or summed from demographic breakdown below.</span>
                                </div>
                                <div class="form-group">
                                    <label class="form-label" for="sighting_evidence_type">Sighting Evidence Type <span class="required">*</span></label>
                                    <select class="form-select" id="sighting_evidence_type" name="sighting_evidence_type">
                                        <option value="direct_sighting">Direct Visual Sighting</option>
                                        <option value="tracks_footprints">Indirect: Footprints / Tracks</option>
                                        <option value="dung_fresh">Indirect: Fresh Dung</option>
                                        <option value="dung_old">Indirect: Old Dung</option>
                                        <option value="vegetation_damage">Indirect: Broken Trees / Crop Damage</option>
                                        <option value="auditory">Indirect: Auditory / Vocalization</option>
                                    </select>
                                </div>
                            </div>

                            <!-- Demographic Breakdown Fields -->
                            <div style="margin-top: 14px; background: rgba(15,23,42,0.5); padding: 14px; border-radius: var(--radius-md); border: 1px solid var(--border-subtle);">
                                <span style="font-size: 12px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; color: var(--brand-sky);">
                                    Demographic Breakdown (Leave blank if unclassified):
                                </span>
                                
                                <div class="form-grid-2" style="margin-top: 10px;">
                                    <div class="form-group">
                                        <label class="form-label" for="adult_males">Adult Males</label>
                                        <input type="number" min="0" max="100" class="form-input count-calc-input" id="adult_males" name="adult_males" placeholder="0">
                                    </div>
                                    <div class="form-group">
                                        <label class="form-label" for="adult_females">Adult Females</label>
                                        <input type="number" min="0" max="100" class="form-input count-calc-input" id="adult_females" name="adult_females" placeholder="0">
                                    </div>
                                </div>

                                <div class="form-grid-2" style="margin-top: 10px;">
                                    <div class="form-group">
                                        <label class="form-label" for="calves">Calves / Juveniles</label>
                                        <input type="number" min="0" max="50" class="form-input count-calc-input" id="calves" name="calves" placeholder="0">
                                    </div>
                                    <div class="form-group">
                                        <label class="form-label" for="unclassified_count">Unclassified / Unknown Individuals</label>
                                        <input type="number" min="0" max="100" class="form-input count-calc-input" id="unclassified_count" name="unclassified_count" placeholder="0">
                                    </div>
                                </div>
                            </div>

                            <div class="form-grid-2" style="margin-top: 14px;">
                                <div class="form-group">
                                    <label class="form-label" for="tusker_present">Tusker Observed?</label>
                                    <select class="form-select" id="tusker_present" name="tusker_present">
                                        <option value="no">No Tusker Observed</option>
                                        <option value="single_tusker">Single Tusker</option>
                                        <option value="multiple_tuskers">Multiple Tuskers</option>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label class="form-label" for="elephant_behavior">Observed Elephant Behavior</label>
                                    <select class="form-select" id="elephant_behavior" name="elephant_behavior">
                                        <option value="crossing">Crossing Railway Track</option>
                                        <option value="grazing_verge">Grazing Along Verge / Cutting</option>
                                        <option value="moving_parallel">Moving Parallel to Track</option>
                                        <option value="resting_brush">Resting in Adjacent Forest</option>
                                    </select>
                                </div>
                            </div>

                            <div class="step-nav-controls">
                                <button type="button" class="btn btn-secondary" onclick="window.EleGuardWizard.prevStep()">
                                    ← Back: Elephant Location
                                </button>
                                <button type="button" class="btn btn-save-draft" onclick="window.EleGuardStorageUI.saveCurrentDraft()">💾 Save Draft</button>
                                <button type="button" class="btn btn-primary" onclick="window.EleGuardWizard.nextStep()">
                                    Next: Railway Association →
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- ========================================================= -->
                    <!-- STEP 5: RAILWAY ASSOCIATION                               -->
                    <!-- ========================================================= -->
                    <div class="wizard-step" id="wizardStep5" data-step="5">
                        <div class="form-card">
                            <div class="card-header">
                                <div class="card-title-group">
                                    <div class="card-step-num">5</div>
                                    <div>
                                        <h2 class="card-title">Railway Association &amp; Physical Proximity</h2>
                                        <p class="card-subtitle">Corridor section, chainage milestone, and photographic evidence</p>
                                    </div>
                                </div>
                            </div>

                            <div class="form-grid-2">
                                <div class="form-group">
                                    <label class="form-label" for="section_id">Railway Section</label>
                                    <select class="form-select" id="section_id" name="section_id">
                                        <option value="">-- Auto-resolve via GIS or Select --</option>
                                        <?php foreach ($sections as $s): ?>
                                            <option value="<?php echo (int)$s['id']; ?>">
                                                <?php echo e($s['section_name'] . ' (' . $s['district'] . ')'); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <span style="font-size: 11px; color: var(--text-muted);">
                                        Uncertain section attributions are preserved as provisional and verified by CTC Control Officers.
                                    </span>
                                </div>
                                <div class="form-group">
                                    <label class="form-label" for="nearest_milestone_ref">Milestone / Kilometer Reference</label>
                                    <input type="text" class="form-input" id="nearest_milestone_ref" name="nearest_milestone_ref" placeholder="e.g. KM 142/6 (Optional)">
                                    <span style="font-size: 11px; color: var(--text-muted);">Do not fabricate milestone posts if unobserved.</span>
                                </div>
                            </div>

                            <div class="form-grid-2" style="margin-top: 14px;">
                                <div class="form-group">
                                    <label class="form-label" for="track_proximity">Proximity to Railway Track</label>
                                    <select class="form-select" id="track_proximity" name="track_proximity">
                                        <option value="on_track">On Track / Ballast (&lt; 3m, Zone 0)</option>
                                        <option value="verge">Track Verge / Cutting (3m – 15m, Zone 1)</option>
                                        <option value="visible_corridor" selected>Visible Corridor (15m – 50m, Zone 2)</option>
                                        <option value="distant_forest">Distant Forest (&gt; 50m, Zone 3)</option>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label class="form-label" for="evidence_photo">Photographic Evidence (Optional)</label>
                                    <input type="file" id="evidence_photo" name="evidence_photo" accept="image/*" capture="environment" class="form-input" style="padding: 10px;">
                                </div>
                            </div>

                            <div class="step-nav-controls">
                                <button type="button" class="btn btn-secondary" onclick="window.EleGuardWizard.prevStep()">
                                    ← Back: Group Info
                                </button>
                                <button type="button" class="btn btn-save-draft" onclick="window.EleGuardStorageUI.saveCurrentDraft()">💾 Save Draft</button>
                                <button type="button" class="btn btn-primary" onclick="window.EleGuardWizard.nextStep()">
                                    Next: Review &amp; Submit →
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- ========================================================= -->
                    <!-- STEP 6: REVIEW AND SUBMIT                                 -->
                    <!-- ========================================================= -->
                    <div class="wizard-step" id="wizardStep6" data-step="6">
                        <div class="form-card">
                            <div class="card-header">
                                <div class="card-title-group">
                                    <div class="card-step-num">6</div>
                                    <div>
                                        <h2 class="card-title">Review &amp; Confirm Field Submission</h2>
                                        <p class="card-subtitle">Verify all telemetry data before transmission to railway control</p>
                                    </div>
                                </div>
                            </div>

                            <!-- Unique Idempotency Key Pill -->
                            <div class="review-uuid-pill">
                                <span>🔑 Idempotency Key:</span>
                                <strong id="reviewUuidDisplay">Generating...</strong>
                            </div>

                            <!-- Submission Feedback / Result Container -->
                            <div id="submissionResultArea"></div>

                            <!-- Review Card 1: Observation Details -->
                            <div class="review-group-card">
                                <div class="review-card-header">
                                    <span class="review-card-title">📅 1. Observation Details</span>
                                    <button type="button" class="review-edit-btn" onclick="window.EleGuardWizard.goToStep(1)">✏️ Edit</button>
                                </div>
                                <div class="review-data-grid">
                                    <div class="review-row">
                                        <span class="review-label">Date &amp; Time</span>
                                        <span class="review-value" id="revDateTime">—</span>
                                    </div>
                                    <div class="review-row">
                                        <span class="review-label">Diurnal Period</span>
                                        <span class="review-value" id="revDiurnal">—</span>
                                    </div>
                                    <div class="review-row">
                                        <span class="review-label">Category &amp; Urgency</span>
                                        <span class="review-value" id="revCategoryUrgency">—</span>
                                    </div>
                                    <div class="review-row">
                                        <span class="review-label">Location Description</span>
                                        <span class="review-value" id="revLocationDesc">—</span>
                                    </div>
                                </div>
                            </div>

                            <!-- Review Card 2: Observer GNSS -->
                            <div class="review-group-card">
                                <div class="review-card-header">
                                    <span class="review-card-title">📍 2. Observer Location (Where Observer Is)</span>
                                    <button type="button" class="review-edit-btn" onclick="window.EleGuardWizard.goToStep(2)">✏️ Edit</button>
                                </div>
                                <div class="review-data-grid">
                                    <div class="review-row">
                                        <span class="review-label">Observer Coordinates</span>
                                        <span class="review-value" id="revObserverCoords">—</span>
                                    </div>
                                    <div class="review-row">
                                        <span class="review-label">GPS Accuracy &amp; Method</span>
                                        <span class="review-value" id="revObserverAccuracy">—</span>
                                    </div>
                                </div>
                            </div>

                            <!-- Review Card 3: Elephant Location -->
                            <div class="review-group-card">
                                <div class="review-card-header">
                                    <span class="review-card-title">🐘 3. Elephant Location</span>
                                    <button type="button" class="review-edit-btn" onclick="window.EleGuardWizard.goToStep(3)">✏️ Edit</button>
                                </div>
                                <div class="review-data-grid">
                                    <div class="review-row">
                                        <span class="review-label">Location Status</span>
                                        <span class="review-value" id="revAnimalStatus">—</span>
                                    </div>
                                    <div class="review-row">
                                        <span class="review-label">Offset Range &amp; Azimuth</span>
                                        <span class="review-value" id="revAnimalOffset">—</span>
                                    </div>
                                </div>
                            </div>

                            <!-- Review Card 4: Elephant Group Demographics -->
                            <div class="review-group-card">
                                <div class="review-card-header">
                                    <span class="review-card-title">👥 4. Group Demographics &amp; Evidence</span>
                                    <button type="button" class="review-edit-btn" onclick="window.EleGuardWizard.goToStep(4)">✏️ Edit</button>
                                </div>
                                <div class="review-data-grid">
                                    <div class="review-row">
                                        <span class="review-label">Total Estimated Herd Size</span>
                                        <span class="review-value" id="revTotalCount">—</span>
                                    </div>
                                    <div class="review-row">
                                        <span class="review-label">Demographic Breakdown</span>
                                        <span class="review-value" id="revDemographics">—</span>
                                    </div>
                                </div>
                            </div>

                            <!-- Review Card 5: Railway Association -->
                            <div class="review-group-card">
                                <div class="review-card-header">
                                    <span class="review-card-title">🛤️ 5. Railway Association</span>
                                    <button type="button" class="review-edit-btn" onclick="window.EleGuardWizard.goToStep(5)">✏️ Edit</button>
                                </div>
                                <div class="review-data-grid">
                                    <div class="review-row">
                                        <span class="review-label">Railway Corridor Section</span>
                                        <span class="review-value" id="revRailwaySection">—</span>
                                    </div>
                                    <div class="review-row">
                                        <span class="review-label">Milestone &amp; Proximity</span>
                                        <span class="review-value" id="revMilestoneProximity">—</span>
                                    </div>
                                </div>
                            </div>

                            <!-- Submission Action Area -->
                            <div class="step-nav-controls" id="submissionActionRow">
                                <button type="button" class="btn btn-secondary" onclick="window.EleGuardWizard.prevStep()">
                                    ← Back: Edit Railway
                                </button>
                                <button type="button" class="btn btn-save-draft" onclick="window.EleGuardStorageUI.saveCurrentDraft()">💾 Save Draft</button>
                                <button type="button" class="btn btn-primary" id="confirmSubmitBtn" onclick="window.EleGuardWizard.submitObservation()">
                                    🚀 Confirm &amp; Submit Observation
                                </button>
                            </div>
                        </div>
                    </div>

                </form>

            </section>

            <!-- TAB 2: DRAFTS & OUTBOX (STAGE 4E.4.2-C ENCRYPTED STORAGE) -->
            <section id="tabDrafts" class="tab-content">
                <div class="form-card">
                    <div class="card-header">
                        <h2 class="card-title">📦 Encrypted Offline Outbox</h2>
                        <span class="card-subtitle">AES-GCM-256 Web Crypto SubtleCrypto Storage &bull; 72-Hour Expiry</span>
                    </div>

                    <!-- Uninitialized State -->
                    <div id="tabDraftsUninitialized" style="display: none; padding: 20px 0;">
                        <div class="notice-box notice-warning" style="margin-bottom: 20px;">
                            <span>⚠️</span>
                            <div>
                                <strong>Encrypted Storage Not Initialized:</strong> Set a master passphrase to enable secure browser-local storage for field drafts.
                            </div>
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="tabSetupPassphrase">Create Master Passphrase (min 6 characters)</label>
                            <input type="password" id="tabSetupPassphrase" class="form-input" placeholder="Enter secure passphrase" autocomplete="new-password">
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="tabSetupPassphraseConfirm">Confirm Master Passphrase</label>
                            <input type="password" id="tabSetupPassphraseConfirm" class="form-input" placeholder="Confirm passphrase" autocomplete="new-password">
                        </div>
                        <div id="tabSetupErrorMsg" style="color: #ef4444; font-size: 13px; margin-bottom: 12px; display: none;"></div>
                        <button type="button" class="btn btn-primary" onclick="window.EleGuardStorageUI.handleTabSetupSubmit()">
                            🔐 Initialize Encrypted Storage
                        </button>
                    </div>

                    <!-- Locked State -->
                    <div id="tabDraftsLocked" style="display: none; padding: 20px 0;">
                        <div class="notice-box notice-info" style="margin-bottom: 20px;">
                            <span>🔒</span>
                            <div>
                                <strong>Storage Locked:</strong> Enter your master passphrase to unlock offline drafts.
                            </div>
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="tabUnlockPassphrase">Master Passphrase</label>
                            <input type="password" id="tabUnlockPassphrase" class="form-input" placeholder="Enter master passphrase" autocomplete="current-password">
                        </div>
                        <div id="tabUnlockErrorMsg" style="color: #ef4444; font-size: 13px; margin-bottom: 12px; display: none;"></div>
                        <div style="display: flex; justify-content: space-between; align-items: center;">
                            <button type="button" style="background: none; border: none; color: #ef4444; font-size: 13px; text-decoration: underline; cursor: pointer; padding: 4px;" onclick="window.EleGuardStorageUI.confirmResetStorage()">
                                Forgot Passphrase / Reset Storage
                            </button>
                            <button type="button" class="btn btn-primary" onclick="window.EleGuardStorageUI.handleTabUnlockSubmit()">
                                🔓 Unlock Storage
                            </button>
                        </div>
                    </div>

                    <!-- Unlocked State -->
                    <div id="tabDraftsUnlocked" style="display: none;">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; padding: 12px 16px; background: rgba(16, 185, 129, 0.1); border: 1px solid rgba(16, 185, 129, 0.3); border-radius: 8px;">
                            <span style="font-size: 13px; color: #10b981; font-weight: 600;">
                                🟢 Storage Unlocked &bull; In-Memory Key Active
                            </span>
                            <div style="display: flex; gap: 8px;">
                                <button type="button" class="btn btn-secondary" style="padding: 4px 10px; font-size: 12px; min-height: 32px;" onclick="window.EleGuardStorageUI.lockStorage()">
                                    🔒 Lock
                                </button>
                                <button type="button" class="btn btn-danger" style="padding: 4px 10px; font-size: 12px; min-height: 32px;" onclick="window.EleGuardStorageUI.confirmClearAllDrafts()">
                                    Clear All
                                </button>
                            </div>
                        </div>

                        <!-- Draft Items List -->
                        <div id="tabDraftsListContainer" class="drafts-list-container" style="max-height: 480px;">
                            <!-- Dynamically rendered -->
                        </div>
                    </div>

                    <div class="notice-box notice-info" style="margin-top: 24px; text-align: left;">
                        <span>🛡️</span>
                        <div>
                            <strong>Anti-Poaching Protection:</strong> All draft records are encrypted client-side using AES-GCM-256 before writing to IndexedDB. No sensitive GPS coordinates are stored in plaintext. Drafts older than 72 hours are automatically purged to prevent stale telemetry.
                        </div>
                    </div>
                </div>
            </section>

            <!-- TAB 3: CONTROLLED OFFLINE SYNCHRONIZATION DASHBOARD -->
            <section id="tabSync" class="tab-content">
                <div class="form-card">
                    <div class="card-header" style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
                        <div>
                            <h2 class="card-title">Telemetry Synchronization Dashboard</h2>
                            <p class="card-subtitle">Controlled, user-initiated synchronization of encrypted offline drafts to central railway control.</p>
                        </div>
                        <button type="button" class="btn btn-secondary" onclick="window.EleGuardSyncController && window.EleGuardSyncController.renderDashboard()" title="Refresh Status">
                            🔄 Refresh
                        </button>
                    </div>

                    <!-- Metrics Grid -->
                    <div class="sync-metrics-grid">
                        <div class="sync-metric-tile">
                            <span class="sync-metric-label">Pending Sync</span>
                            <span class="sync-metric-val pending" id="syncMetricPending">0</span>
                        </div>
                        <div class="sync-metric-tile">
                            <span class="sync-metric-label">Synced / Replayed</span>
                            <span class="sync-metric-val synced" id="syncMetricSynced">0</span>
                        </div>
                        <div class="sync-metric-tile">
                            <span class="sync-metric-label">Validation Errors</span>
                            <span class="sync-metric-val rejected" id="syncMetricRejected">0</span>
                        </div>
                        <div class="sync-metric-tile">
                            <span class="sync-metric-label">Total Outbox</span>
                            <span class="sync-metric-val total" id="syncMetricTotal">0</span>
                        </div>
                    </div>

                    <!-- Sync Progress Bar (Visible during sync) -->
                    <div id="syncProgressContainer" style="display: none; margin: 16px 0;">
                        <div class="sync-progress-header" style="display: flex; justify-content: space-between; font-size: 13px; margin-bottom: 6px;">
                            <span id="syncProgressText">Preparing micro-batch transmission...</span>
                        </div>
                        <div class="stepper-bar-track">
                            <div class="stepper-bar-fill" id="syncProgressBarFill" style="width: 0%; transition: width 0.3s ease;"></div>
                        </div>
                    </div>

                    <!-- Action Banner & Primary Sync Button -->
                    <div class="sync-action-banner" style="margin: 20px 0; padding: 16px; background: rgba(30, 41, 59, 0.7); border-radius: 8px; border: 1px solid rgba(255, 255, 255, 0.1); display: flex; flex-direction: column; gap: 12px;">
                        <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
                            <div>
                                <strong style="font-size: 14px; color: #f8fafc;">User-Controlled Synchronization Engine</strong>
                                <div style="font-size: 12px; color: #94a3b8; margin-top: 2px;">
                                    Transfers encrypted drafts in micro-batches (10 per batch) with server-authoritative idempotency.
                                </div>
                            </div>
                            <button type="button" class="btn btn-primary" id="syncNowBtn" style="min-width: 200px;" onclick="window.EleGuardSyncController && window.EleGuardSyncController.synchronizeAll()">
                                <span>🚀 Synchronize Now</span>
                            </button>
                        </div>
                    </div>

                    <!-- Outbox Items List Container -->
                    <div style="margin-top: 20px;">
                        <h3 style="font-size: 15px; font-weight: 600; color: #e2e8f0; margin-bottom: 12px; display: flex; justify-content: space-between; align-items: center;">
                            <span>📦 Outbox Queue &amp; Per-Item Status</span>
                        </h3>
                        <div id="syncDraftsListContainer">
                            <!-- Populated dynamically by EleGuardSyncController.renderDashboard() -->
                        </div>
                    </div>

                    <!-- Diagnostic & Architecture Metadata -->
                    <div style="margin-top: 24px; border-top: 1px solid rgba(255,255,255,0.08); padding-top: 16px; display: flex; flex-direction: column; gap: 10px; font-size: 12px;">
                        <div style="display: flex; justify-content: space-between; align-items: center; padding: 8px 12px; background: rgba(15,23,42,0.6); border-radius: 6px;">
                            <span>Network Gateway:</span>
                            <strong id="syncNetworkState" style="color: #10b981;">Online &bull; Connected</strong>
                        </div>
                        <div style="display: flex; justify-content: space-between; align-items: center; padding: 8px 12px; background: rgba(15,23,42,0.6); border-radius: 6px;">
                            <span>Last Synchronization:</span>
                            <span id="syncLastSyncTime" style="color: #cbd5e1;">Never (Session Start)</span>
                        </div>
                        <div style="display: flex; justify-content: space-between; align-items: center; padding: 8px 12px; background: rgba(15,23,42,0.6); border-radius: 6px;">
                            <span>Active Draft UUID:</span>
                            <code id="syncActiveUuid" style="font-size: 11px;">—</code>
                        </div>
                        <div style="display: flex; justify-content: space-between; align-items: center; padding: 8px 12px; background: rgba(15,23,42,0.6); border-radius: 6px;">
                            <span>Batch Ingestion Target:</span>
                            <code>/api/v2/reports/sync-batch</code>
                        </div>
                        <div style="display: flex; justify-content: space-between; align-items: center; padding: 8px 12px; background: rgba(15,23,42,0.6); border-radius: 6px;">
                            <span>API CSRF Binding:</span>
                            <strong style="color: #38bdf8;">Session Protected (Active)</strong>
                        </div>
                    </div>
                </div>
            </section>

            <!-- TAB 4: MY SIGHTINGS & CLARIFICATION WORKSPACE -->
            <section id="tabMyReports" class="tab-content">
                <div class="form-card">
                    <div class="card-header" style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
                        <div>
                            <h2 class="card-title">My Submitted Sightings</h2>
                            <p class="card-subtitle">Track biological verification status and reply to wildlife officer inquiries.</p>
                        </div>
                        <button type="button" class="btn btn-secondary" onclick="window.EleGuardReporterWorkspace && window.EleGuardReporterWorkspace.loadReports()" title="Refresh Sightings">
                            🔄 Refresh
                        </button>
                    </div>

                    <!-- Offline Advisory Banner -->
                    <div id="myReportsOfflineBanner" class="offline-advisory-banner" style="display: none;">
                        <span>⚠️</span>
                        <div>
                            <strong>Offline Mode Active:</strong> You can view previously loaded report summaries, but submitting clarification replies requires an active cellular connection.
                        </div>
                    </div>

                    <!-- Status Filter Pills -->
                    <div class="status-pills-bar" id="myReportsFilterPills">
                        <button type="button" class="status-pill active" data-filter="all">
                            All <span class="pill-count" id="pillCountAll">0</span>
                        </button>
                        <button type="button" class="status-pill pill-needs-info" data-filter="Needs Information">
                            💬 Needs Info <span class="pill-count" id="pillCountNeedsInfo">0</span>
                        </button>
                        <button type="button" class="status-pill pill-pending" data-filter="Pending">
                            ⏳ Pending <span class="pill-count" id="pillCountPending">0</span>
                        </button>
                        <button type="button" class="status-pill pill-clarified" data-filter="Clarification Submitted">
                            📨 Clarified <span class="pill-count" id="pillCountClarified">0</span>
                        </button>
                        <button type="button" class="status-pill pill-verified" data-filter="Verified">
                            ✔️ Verified <span class="pill-count" id="pillCountVerified">0</span>
                        </button>
                        <button type="button" class="status-pill pill-rejected" data-filter="Rejected">
                            ❌ Rejected <span class="pill-count" id="pillCountRejected">0</span>
                        </button>
                    </div>

                    <!-- Reports List / Cards Container -->
                    <div id="myReportsListContainer" class="sighting-cards-container">
                        <div class="sighting-loading-state">
                            <span class="loading-spinner"></span>
                            <p>Loading your sighting reports...</p>
                        </div>
                    </div>

                    <!-- Pagination Controls -->
                    <div id="myReportsPagination" class="pagination-bar" style="display: none;">
                        <button type="button" class="btn btn-secondary" id="myReportsPrevPageBtn" disabled>← Previous</button>
                        <span id="myReportsPageInfo" class="page-info">Page 1 of 1</span>
                        <button type="button" class="btn btn-secondary" id="myReportsNextPageBtn" disabled>Next →</button>
                    </div>
                </div>
            </section>

        </main>

    <?php endif; ?>

    <!-- Storage Setup Modal -->
    <div id="storageSetupModal" class="storage-modal-backdrop">
        <div class="storage-modal-card">
            <div class="storage-modal-header">
                <h3 class="storage-modal-title"><span>🔒</span> Setup Encrypted Storage</h3>
                <button type="button" class="storage-close-btn" onclick="window.EleGuardStorageUI.closeModals()">✕</button>
            </div>
            <p style="font-size: 13px; color: var(--text-secondary); line-height: 1.5; margin: 0;">
                Set a personal master passphrase to encrypt field drafts locally in IndexedDB using AES-GCM-256 and PBKDF2 (250,000 rounds). Your passphrase is never transmitted to the server.
            </p>
            <div class="form-group" style="margin-top: 4px;">
                <label class="form-label" for="setupPassphraseInput">Master Passphrase (min 6 characters)</label>
                <input type="password" id="setupPassphraseInput" class="form-input" placeholder="Enter secure passphrase" autocomplete="new-password">
            </div>
            <div class="form-group">
                <label class="form-label" for="setupPassphraseConfirmInput">Confirm Passphrase</label>
                <input type="password" id="setupPassphraseConfirmInput" class="form-input" placeholder="Confirm passphrase" autocomplete="new-password">
            </div>
            <div id="setupErrorMsg" style="color: #ef4444; font-size: 12px; display: none;"></div>
            <div style="display: flex; justify-content: flex-end; gap: 10px; margin-top: 8px;">
                <button type="button" class="btn btn-secondary" onclick="window.EleGuardStorageUI.closeModals()">Cancel</button>
                <button type="button" class="btn btn-primary" onclick="window.EleGuardStorageUI.handleSetupSubmit()">Set Passphrase &amp; Initialize</button>
            </div>
        </div>
    </div>

    <!-- Storage Unlock Modal -->
    <div id="storageUnlockModal" class="storage-modal-backdrop">
        <div class="storage-modal-card">
            <div class="storage-modal-header">
                <h3 class="storage-modal-title"><span>🔓</span> Unlock Offline Drafts</h3>
                <button type="button" class="storage-close-btn" onclick="window.EleGuardStorageUI.closeModals()">✕</button>
            </div>
            <p style="font-size: 13px; color: var(--text-secondary); line-height: 1.5; margin: 0;">
                Enter your master passphrase to unlock offline drafts. The decryption key will reside only in volatile memory during your session.
            </p>
            <div class="form-group" style="margin-top: 4px;">
                <label class="form-label" for="unlockPassphraseInput">Master Passphrase</label>
                <input type="password" id="unlockPassphraseInput" class="form-input" placeholder="Enter master passphrase" autocomplete="current-password">
            </div>
            <div id="unlockErrorMsg" style="color: #ef4444; font-size: 12px; display: none;"></div>
            <div style="display: flex; justify-content: space-between; align-items: center; margin-top: 8px;">
                <button type="button" style="background: none; border: none; color: #ef4444; font-size: 12px; text-decoration: underline; cursor: pointer; padding: 4px;" onclick="window.EleGuardStorageUI.confirmResetStorage()">Forgot Passphrase / Reset Storage</button>
                <div style="display: flex; gap: 10px;">
                    <button type="button" class="btn btn-secondary" onclick="window.EleGuardStorageUI.closeModals()">Cancel</button>
                    <button type="button" class="btn btn-primary" onclick="window.EleGuardStorageUI.handleUnlockSubmit()">Unlock Storage</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Storage Drafts Modal Drawer -->
    <div id="storageDraftsModal" class="storage-modal-backdrop">
        <div class="storage-modal-card" style="max-width: 640px;">
            <div class="storage-modal-header">
                <h3 class="storage-modal-title"><span>📦</span> Saved Offline Drafts</h3>
                <button type="button" class="storage-close-btn" onclick="window.EleGuardStorageUI.closeModals()">✕</button>
            </div>
            <div style="display: flex; justify-content: space-between; align-items: center;">
                <span style="font-size: 12px; color: var(--brand-emerald); font-weight: 600;">● Decryption Key Active</span>
                <div style="display: flex; gap: 8px;">
                    <button type="button" class="btn btn-secondary" style="padding: 4px 10px; font-size: 12px; min-height: 30px;" onclick="window.EleGuardStorageUI.lockStorage()">🔒 Lock Storage</button>
                    <button type="button" class="btn btn-danger" style="padding: 4px 10px; font-size: 12px; min-height: 30px;" onclick="window.EleGuardStorageUI.confirmClearAllDrafts()">Clear All</button>
                </div>
            </div>
            <div id="modalDraftsListContainer" class="drafts-list-container">
                <!-- Populated dynamically -->
            </div>
            <div style="display: flex; justify-content: flex-end; margin-top: 8px;">
                <button type="button" class="btn btn-secondary" onclick="window.EleGuardStorageUI.closeModals()">Close</button>
            </div>
        </div>
    </div>

    <!-- Detailed Sighting & Clarification Modal -->
    <div id="sightingDetailModal" class="storage-modal-backdrop" style="display: none;">
        <div class="storage-modal-card" style="max-width: 720px; max-height: 90vh; overflow-y: auto;">
            <div class="storage-modal-header" style="position: sticky; top: -20px; background: var(--bg-surface, #1e293b); z-index: 10; padding: 12px 0; border-bottom: 1px solid rgba(255,255,255,0.1); margin-top: -10px;">
                <div style="display: flex; align-items: center; gap: 10px; flex-wrap: wrap;">
                    <h3 class="storage-modal-title" id="sightingModalTitle">Sighting Details</h3>
                    <span id="sightingModalStatusBadge" class="status-pill active" style="font-size: 11px; padding: 2px 10px;">Pending</span>
                </div>
                <button type="button" class="storage-close-btn" onclick="window.EleGuardReporterWorkspace.closeModal()">✕</button>
            </div>

            <!-- Modal Content (Dynamically Populated) -->
            <div id="sightingModalBody" style="margin-top: 15px;">
                <div class="sighting-loading-state">
                    <span class="loading-spinner"></span>
                    <p>Loading observation and verification history...</p>
                </div>
            </div>
        </div>
    </div>

    <!-- Storage Notification Toast -->
    <div id="storageToast" class="storage-toast"></div>

    <!-- Client-Side Encrypted Storage Engine -->
    <script src="../assets/js/field_crypto_storage.js"></script>
    <!-- Controlled Offline Synchronization Engine -->
    <script src="../assets/js/field_sync_controller.js"></script>
    <!-- PWA Client Engine -->
    <script src="../assets/js/field_pwa.js"></script>
</body>
</html>
