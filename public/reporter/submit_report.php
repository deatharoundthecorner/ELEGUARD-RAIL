<?php
/**
 * Submit Sighting Report
 */
$pageTitle = "File Elephant Sighting";
$allowed_roles = 'reporter';
require_once __DIR__ . '/../../app/layouts/dashboard_header.php';
require_once __DIR__ . '/../../app/services/AlertService.php';
require_once __DIR__ . '/../../app/services/ReportService.php';
require_once __DIR__ . '/../../app/services/TemporalService.php';
require_once __DIR__ . '/../../app/includes/validation.php';
require_once __DIR__ . '/../../app/includes/upload.php';

$sections = AlertService::getAllSections();
$errors = [];

$sectionId = '';
$elephantCount = '';
$urgencyLevel = 'Medium';
$locationDescription = '';
$latitude = '';
$longitude = '';
$gpsAccuracy = '';
$description = '';

// Temporal fields default state (anchored to Asia/Colombo)
$sightingDate = date('Y-m-d');
$sightingDateUnknown = false;
$sightingPrecision = TemporalService::PRECISION_EXACT;
$sightingTime = date('H:i');
$sightingWindow = '';
$customTime = '';
$customUncertainty = 30;
$gpsTimestamp = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validate_csrf();

    $sectionId = $_POST['section_id'] ?? '';
    $elephantCount = $_POST['elephant_count'] ?? '';
    $urgencyLevel = $_POST['urgency_level'] ?? 'Medium';
    $locationDescription = trim($_POST['location_description'] ?? '');
    $latitude = trim($_POST['latitude'] ?? '');
    $longitude = trim($_POST['longitude'] ?? '');
    $gpsAccuracy = trim($_POST['gps_accuracy'] ?? '');
    $description = trim($_POST['description'] ?? '');

    // Read temporal input fields
    $sightingDateUnknown = !empty($_POST['sighting_date_unknown']);
    $sightingPrecision = $_POST['sighting_time_precision'] ?? TemporalService::PRECISION_EXACT;
    $sightingDate = $sightingDateUnknown ? null : trim($_POST['sighting_date'] ?? '');
    $sightingTime = trim($_POST['sighting_time'] ?? '');
    $sightingWindow = trim($_POST['sighting_time_window'] ?? '');
    $customTime = trim($_POST['custom_time'] ?? '');
    $customUncertainty = isset($_POST['custom_uncertainty']) && $_POST['custom_uncertainty'] !== '' ? (int)$_POST['custom_uncertainty'] : null;
    $gpsTimestamp = trim($_POST['gps_timestamp'] ?? '');

    // Validate standard fields
    $reqErrors = Validation::required($_POST, ['section_id', 'elephant_count', 'urgency_level', 'location_description']);
    $errors = array_merge($errors, $reqErrors);

    if (empty($errors['elephant_count'])) {
        $numErr = Validation::numeric($elephantCount, 'Elephant Count');
        if ($numErr) {
            $errors['elephant_count'] = $numErr;
        } elseif ((int)$elephantCount <= 0) {
            $errors['elephant_count'] = 'Elephant count must be at least 1.';
        }
    }

    // Optional GPS coordinate validation
    $coordErrors = Validation::coordinate($latitude, $longitude);
    if (!empty($coordErrors)) {
        $errors = array_merge($errors, $coordErrors);
    }

    // Assemble and pre-validate temporal payload
    $temporalPayload = [
        'sighting_date' => $sightingDate,
        'sighting_time_precision' => $sightingDateUnknown ? TemporalService::PRECISION_UNKNOWN : $sightingPrecision,
        'sighting_time' => ($sightingPrecision === TemporalService::PRECISION_EXACT) ? $sightingTime : (($sightingWindow === 'custom') ? $customTime : null),
        'sighting_time_window' => ($sightingPrecision === TemporalService::PRECISION_APPROXIMATE) ? $sightingWindow : null,
        'sighting_time_uncertainty_minutes' => ($sightingPrecision === TemporalService::PRECISION_APPROXIMATE && $sightingWindow === 'custom') ? $customUncertainty : null,
        'gps_timestamp' => !empty($gpsTimestamp) ? $gpsTimestamp : null
    ];

    $tempNorm = TemporalService::validateAndNormalizeTemporalInput($temporalPayload);
    if (!$tempNorm['valid']) {
        foreach ($tempNorm['errors'] as $errKey => $errMsg) {
            $errors[$errKey] = $errMsg;
        }
    }

    $imagePath = null;
    if (empty($errors) && isset($_FILES['image']) && $_FILES['image']['error'] !== UPLOAD_ERR_NO_FILE) {
        $uploadResult = Upload::saveReportImage($_FILES['image']);
        if ($uploadResult['success']) {
            $imagePath = $uploadResult['path'];
        } else {
            $errors['image'] = $uploadResult['error'];
        }
    }

    if (empty($errors)) {
        $success = ReportService::submitReport(
            (int)$sectionId,
            $currentUser['id'],
            (int)$elephantCount,
            $locationDescription,
            $urgencyLevel,
            $description,
            $imagePath,
            $latitude,
            $longitude,
            $gpsAccuracy,
            $tempNorm['data']
        );

        if ($success) {
            // Log submit action
            Auth::logActivity($currentUser['id'], 'Sighting Submitted', "Sighting reported in section #{$sectionId} ({$elephantCount} elephants).");
            
            Session::flash('success', 'Elephant sighting report submitted successfully. A risk score has been computed and wildlife rangers are notified.');
            Router::redirect('reporter/my_reports.php');
        } else {
            $errors['submit'] = 'Failed to submit report. Please check database connectivity.';
        }
    }
}
?>

<div class="dashboard-title-row">
    <h1>File Elephant Sighting Report</h1>
    <p class="text-secondary">Provide precise details about elephant proximity to railway tracks to safeguard lives.</p>
</div>

<div class="card-panel" style="max-width: 700px;">
    <?php if (isset($errors['submit'])): ?>
        <div class="alert alert-danger">
            <p><?php echo e($errors['submit']); ?></p>
        </div>
    <?php endif; ?>

    <form action="<?php echo Router::url('reporter/submit_report.php'); ?>" method="POST" enctype="multipart/form-data">
        <?php echo csrf_field(); ?>

        <div class="form-group">
            <label for="section_id" class="form-label">Railway Section *</label>
            <select name="section_id" id="section_id" class="form-control" required>
                <option value="">-- Choose Railway Section --</option>
                <?php foreach ($sections as $sec): ?>
                    <option value="<?php echo $sec['id']; ?>" <?php echo $sectionId == $sec['id'] ? 'selected' : ''; ?>>
                        <?php echo e($sec['section_name']); ?> (<?php echo e($sec['district']); ?> - Risk: <?php echo e($sec['risk_level']); ?>)
                    </option>
                <?php endforeach; ?>
            </select>
            <?php if (isset($errors['section_id'])): ?>
                <span class="field-error"><?php echo e($errors['section_id']); ?></span>
            <?php endif; ?>
        </div>

        <div class="grid grid-2col" style="gap:20px; margin-bottom: 0;">
            <div class="form-group">
                <label for="elephant_count" class="form-label">Elephant Count *</label>
                <input type="number" name="elephant_count" id="elephant_count" class="form-control" min="1" max="100" value="<?php echo e($elephantCount); ?>" required>
                <?php if (isset($errors['elephant_count'])): ?>
                    <span class="field-error"><?php echo e($errors['elephant_count']); ?></span>
                <?php endif; ?>
            </div>

            <div class="form-group">
                <label for="urgency_level" class="form-label">Sighting Urgency *</label>
                <select name="urgency_level" id="urgency_level" class="form-control" required>
                    <option value="Low" <?php echo $urgencyLevel === 'Low' ? 'selected' : ''; ?>>Low (Animal far from track)</option>
                    <option value="Medium" <?php echo $urgencyLevel === 'Medium' ? 'selected' : ''; ?>>Medium (Moving towards track)</option>
                    <option value="High" <?php echo $urgencyLevel === 'High' ? 'selected' : ''; ?>>High (Close to track line)</option>
                    <option value="Critical" <?php echo $urgencyLevel === 'Critical' ? 'selected' : ''; ?>>Critical (Directly on track/crossing)</option>
                </select>
                <?php if (isset($errors['urgency_level'])): ?>
                    <span class="field-error"><?php echo e($errors['urgency_level']); ?></span>
                <?php endif; ?>
            </div>
        </div>

        <div class="form-group">
            <label for="location_description" class="form-label">Exact Location Description *</label>
            <input type="text" name="location_description" id="location_description" class="form-control" placeholder="e.g. Near Mile Post 82 curve, 200m past forest temple" value="<?php echo e($locationDescription); ?>" required>
            <?php if (isset($errors['location_description'])): ?>
                <span class="field-error"><?php echo e($errors['location_description']); ?></span>
            <?php endif; ?>
        </div>

        <!-- Temporal Observation Module (Observation Date & Time vs Ingestion Time) -->
        <div class="form-group temporal-observation-card">
            <div class="card-header-row" style="margin-bottom:6px;">
                <label class="form-label" style="margin-bottom:0; font-size:1rem; font-weight:600;">
                    <span class="section-icon">🕒</span> Elephant Observation Time <small class="text-secondary">(When was the elephant spotted?)</small>
                </label>
            </div>
            <p class="temporal-hint">
                Record the actual date and time when the elephant was observed. This information is critical for diurnal movement analysis and is tracked separately from when you submit this report.
            </p>

            <!-- Observation Date & Date Unknown Checkbox -->
            <div class="grid grid-2col" style="gap:15px; margin-bottom:12px; align-items:flex-end;">
                <div class="form-group" style="margin-bottom:0;">
                    <label for="sighting_date" class="form-sublabel">Observation Date *</label>
                    <input type="date" name="sighting_date" id="sighting_date" class="form-control" max="<?php echo date('Y-m-d'); ?>" value="<?php echo e($sightingDate ?? date('Y-m-d')); ?>">
                    <?php if (isset($errors['sighting_date'])): ?>
                        <span class="field-error"><?php echo e($errors['sighting_date']); ?></span>
                    <?php endif; ?>
                </div>
                <div class="form-group" style="margin-bottom:0; padding-bottom:8px;">
                    <label class="checkbox-label" style="display:flex; align-items:center; gap:8px; cursor:pointer; font-size:0.85rem; color:var(--text-secondary);">
                        <input type="checkbox" name="sighting_date_unknown" id="sighting_date_unknown" value="1" <?php echo !empty($sightingDateUnknown) ? 'checked' : ''; ?>>
                        <span>Date Unknown / Historical Record</span>
                    </label>
                </div>
            </div>

            <!-- Precision Classification Radio Group -->
            <div class="form-group" style="margin-bottom:12px;">
                <label class="form-sublabel">Time Precision *</label>
                <div class="precision-radio-group">
                    <label class="precision-radio-pill">
                        <input type="radio" name="sighting_time_precision" value="exact" <?php echo ($sightingPrecision === 'exact') ? 'checked' : ''; ?>>
                        <span>Exact Clock Time (&plusmn;5 min)</span>
                    </label>
                    <label class="precision-radio-pill">
                        <input type="radio" name="sighting_time_precision" value="approximate" <?php echo ($sightingPrecision === 'approximate') ? 'checked' : ''; ?>>
                        <span>Approximate Window</span>
                    </label>
                    <label class="precision-radio-pill">
                        <input type="radio" name="sighting_time_precision" value="unknown" <?php echo ($sightingPrecision === 'unknown') ? 'checked' : ''; ?>>
                        <span>Time Unknown</span>
                    </label>
                </div>
                <?php if (isset($errors['sighting_time_precision'])): ?>
                    <span class="field-error"><?php echo e($errors['sighting_time_precision']); ?></span>
                <?php endif; ?>
            </div>

            <!-- Exact Time Container -->
            <div id="exact_time_container" class="temporal-subpanel" style="margin-bottom:8px;">
                <label for="sighting_time" class="form-sublabel">Exact Observation Time *</label>
                <div style="max-width:240px;">
                    <input type="time" name="sighting_time" id="sighting_time" class="form-control" value="<?php echo e($sightingTime); ?>">
                </div>
                <small class="text-secondary" style="font-size:0.8rem; display:block; margin-top:4px;">
                    Sri Lanka Standard Time (Asia/Colombo). Time cannot be in the future if date is today.
                </small>
                <?php if (isset($errors['sighting_time'])): ?>
                    <span class="field-error"><?php echo e($errors['sighting_time']); ?></span>
                <?php endif; ?>
            </div>

            <!-- Approximate Window Container -->
            <div id="approx_window_container" class="temporal-subpanel" style="margin-bottom:8px; display:none;">
                <label for="sighting_time_window" class="form-sublabel">Diurnal Observation Window *</label>
                <select name="sighting_time_window" id="sighting_time_window" class="form-control">
                    <option value="">-- Choose Diurnal Period --</option>
                    <option value="dawn" <?php echo ($sightingWindow === 'dawn') ? 'selected' : ''; ?>>Dawn (04:30 – 06:30, ref 05:30 &plusmn;60m)</option>
                    <option value="morning" <?php echo ($sightingWindow === 'morning') ? 'selected' : ''; ?>>Morning (07:30 – 10:30, ref 09:00 &plusmn;90m)</option>
                    <option value="afternoon" <?php echo ($sightingWindow === 'afternoon') ? 'selected' : ''; ?>>Afternoon (13:00 – 16:00, ref 14:30 &plusmn;90m)</option>
                    <option value="dusk" <?php echo ($sightingWindow === 'dusk') ? 'selected' : ''; ?>>Dusk (17:30 – 19:00, ref 18:15 &plusmn;45m)</option>
                    <option value="night" <?php echo ($sightingWindow === 'night') ? 'selected' : ''; ?>>Night (20:00 – 00:00, ref 22:00 &plusmn;120m)</option>
                    <option value="custom" <?php echo ($sightingWindow === 'custom') ? 'selected' : ''; ?>>Custom Reference Time & Uncertainty...</option>
                </select>
                <?php if (isset($errors['sighting_time_window'])): ?>
                    <span class="field-error"><?php echo e($errors['sighting_time_window']); ?></span>
                <?php endif; ?>

                <!-- Custom Window Subfields -->
                <div id="custom_window_fields" style="margin-top:12px; display:none;">
                    <div class="grid grid-2col" style="gap:15px; margin-bottom:0;">
                        <div class="form-group" style="margin-bottom:0;">
                            <label for="custom_time" class="form-sublabel">Approximate Reference Time *</label>
                            <input type="time" name="custom_time" id="custom_time" class="form-control" value="<?php echo e($customTime); ?>">
                        </div>
                        <div class="form-group" style="margin-bottom:0;">
                            <label for="custom_uncertainty" class="form-sublabel">Uncertainty Range (&plusmn; minutes) *</label>
                            <select name="custom_uncertainty" id="custom_uncertainty" class="form-control">
                                <option value="15" <?php echo ($customUncertainty === 15) ? 'selected' : ''; ?>>&plusmn;15 minutes</option>
                                <option value="30" <?php echo ($customUncertainty === 30 || $customUncertainty === null) ? 'selected' : ''; ?>>&plusmn;30 minutes</option>
                                <option value="45" <?php echo ($customUncertainty === 45) ? 'selected' : ''; ?>>&plusmn;45 minutes</option>
                                <option value="60" <?php echo ($customUncertainty === 60) ? 'selected' : ''; ?>>&plusmn;60 minutes (1 hour)</option>
                                <option value="90" <?php echo ($customUncertainty === 90) ? 'selected' : ''; ?>>&plusmn;90 minutes (1.5 hours)</option>
                                <option value="120" <?php echo ($customUncertainty === 120) ? 'selected' : ''; ?>>&plusmn;120 minutes (2 hours)</option>
                                <option value="180" <?php echo ($customUncertainty === 180) ? 'selected' : ''; ?>>&plusmn;180 minutes (3 hours)</option>
                            </select>
                        </div>
                    </div>
                    <?php if (isset($errors['sighting_time_uncertainty_minutes'])): ?>
                        <span class="field-error"><?php echo e($errors['sighting_time_uncertainty_minutes']); ?></span>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Unknown Time Container -->
            <div id="unknown_time_container" class="temporal-subpanel" style="margin-bottom:8px; display:none;">
                <div class="alert" style="background:rgba(255,255,255,0.04); border:1px solid var(--border-color); padding:10px 14px; font-size:0.85rem; color:var(--text-secondary); margin-bottom:0;">
                    ℹ️ Sighting time will be recorded as <strong>Unrecorded / Unknown</strong>. System submission time (<code>created_at</code>) will be tracked separately without fabricating midnight or false observation times.
                </div>
            </div>
        </div>

        <!-- GPS Coordinates Module (HTML5 Geolocation + Manual Fallback) -->
        <div class="form-group gps-location-card">
            <div class="gps-card-header">
                <label class="form-label" style="margin-bottom:0;">
                    <span class="gps-icon">📍</span> GPS Location Coordinates <small class="text-secondary">(Optional)</small>
                </label>
            </div>
            
            <p class="gps-hint">
                Provide GPS coordinates to enable railway proximity calculations and alert nearby trains. You can capture them automatically with your phone/browser or type them manually.
            </p>

            <div class="gps-action-row">
                <button type="button" id="btnGetLocation" class="btn btn-secondary btn-sm">
                    <span class="btn-gps-icon">📡</span> Use Current GPS Location
                </button>
                <button type="button" id="btnClearLocation" class="btn btn-outline btn-sm" style="display:none;">
                    Clear Coordinates
                </button>
                <span id="gpsStatusMessage" class="gps-status-text" aria-live="polite"></span>
            </div>

            <div class="grid grid-2col gps-coords-grid" style="gap:15px; margin-top:14px; margin-bottom:0;">
                <div class="form-group" style="margin-bottom:0;">
                    <label for="latitude" class="form-sublabel">Latitude (e.g. 8.035412)</label>
                    <input type="number" step="any" min="-90" max="90" name="latitude" id="latitude" class="form-control" placeholder="e.g. 8.035412" value="<?php echo e($latitude); ?>">
                    <?php if (isset($errors['latitude'])): ?>
                        <span class="field-error"><?php echo e($errors['latitude']); ?></span>
                    <?php endif; ?>
                </div>

                <div class="form-group" style="margin-bottom:0;">
                    <label for="longitude" class="form-sublabel">Longitude (e.g. 80.751104)</label>
                    <input type="number" step="any" min="-180" max="180" name="longitude" id="longitude" class="form-control" placeholder="e.g. 80.751104" value="<?php echo e($longitude); ?>">
                    <?php if (isset($errors['longitude'])): ?>
                        <span class="field-error"><?php echo e($errors['longitude']); ?></span>
                    <?php endif; ?>
                </div>
            </div>

            <input type="hidden" name="gps_accuracy" id="gps_accuracy" value="<?php echo e($gpsAccuracy); ?>">
            <input type="hidden" name="gps_timestamp" id="gps_timestamp" value="<?php echo e($gpsTimestamp); ?>">
            <div id="gpsAccuracyDisplay" class="gps-accuracy-info" style="<?php echo !empty($gpsAccuracy) ? '' : 'display:none;'; ?>">
                Estimated Accuracy: &plusmn;<span id="accuracyVal"><?php echo e($gpsAccuracy); ?></span> meters
            </div>
        </div>

        <div class="form-group">
            <label for="description" class="form-label">Additional Observation Details</label>
            <textarea name="description" id="description" class="form-control" placeholder="e.g. Elephant seems to be alone and searching for water. Sighted walking north along forest corridor."><?php echo e($description); ?></textarea>
        </div>

        <div class="form-group">
            <label for="image" class="form-label">Upload Photo (Optional, JPEG/PNG max 2MB)</label>
            <input type="file" name="image" id="image" class="form-control" accept="image/png, image/jpeg, image/jpg">
            <?php if (isset($errors['image'])): ?>
                <span class="field-error"><?php echo e($errors['image']); ?></span>
            <?php endif; ?>
        </div>

        <div class="form-actions">
            <button type="submit" class="btn btn-accent">Submit Sighting Report</button>
            <a href="<?php echo Router::url('reporter/dashboard.php'); ?>" class="btn btn-secondary">Cancel</a>
        </div>
    </form>
</div>

<style>
.field-error {
    color: var(--danger);
    font-size: 0.8rem;
    display: block;
    margin-top: 5px;
}
.temporal-observation-card,
.gps-location-card {
    background-color: rgba(255, 255, 255, 0.02);
    border: 1px solid var(--border-color);
    border-radius: 8px;
    padding: 16px;
    margin-bottom: 20px;
}
.temporal-hint,
.gps-hint {
    font-size: 0.85rem;
    color: var(--text-secondary);
    line-height: 1.4;
    margin-bottom: 12px;
}
.precision-radio-group {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
    margin-top: 4px;
}
.precision-radio-pill {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    background: rgba(255, 255, 255, 0.04);
    border: 1px solid var(--border-color);
    padding: 6px 12px;
    border-radius: 6px;
    cursor: pointer;
    font-size: 0.85rem;
    color: var(--text-primary, #fff);
    transition: border-color 0.2s, background 0.2s;
}
.precision-radio-pill:hover {
    border-color: var(--accent-color);
}
.precision-radio-pill input[type="radio"] {
    margin: 0;
    cursor: pointer;
}
.temporal-subpanel {
    background: rgba(0, 0, 0, 0.15);
    border: 1px dashed var(--border-color);
    border-radius: 6px;
    padding: 12px 14px;
}
.gps-card-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 6px;
}
.gps-action-row {
    display: flex;
    align-items: center;
    gap: 12px;
    flex-wrap: wrap;
}
.gps-status-text {
    font-size: 0.85rem;
    font-weight: 500;
}
.gps-status-text.gps-loading {
    color: var(--accent-color);
}
.gps-status-text.gps-success {
    color: var(--success);
}
.gps-status-text.gps-warn {
    color: var(--warning);
}
.form-sublabel {
    font-size: 0.8rem;
    color: var(--text-secondary);
    display: block;
    margin-bottom: 4px;
}
.gps-accuracy-info {
    font-size: 0.8rem;
    color: var(--text-secondary);
    margin-top: 8px;
    padding: 4px 8px;
    background: rgba(0, 0, 0, 0.2);
    border-radius: 4px;
    display: inline-block;
}
@media (max-width: 600px) {
    .gps-coords-grid {
        grid-template-columns: 1fr !important;
    }
    .gps-action-row {
        flex-direction: column;
        align-items: flex-start;
    }
    .precision-radio-group {
        flex-direction: column;
    }
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // --- Temporal Observation Controls ---
    const dateUnknownCheckbox = document.getElementById('sighting_date_unknown');
    const sightingDateInput = document.getElementById('sighting_date');
    const precisionRadios = document.querySelectorAll('input[name="sighting_time_precision"]');
    const exactContainer = document.getElementById('exact_time_container');
    const approxContainer = document.getElementById('approx_window_container');
    const unknownContainer = document.getElementById('unknown_time_container');
    const windowSelect = document.getElementById('sighting_time_window');
    const customFields = document.getElementById('custom_window_fields');

    function syncTemporalUI() {
        const isUnknownDate = dateUnknownCheckbox && dateUnknownCheckbox.checked;

        if (isUnknownDate) {
            if (sightingDateInput) sightingDateInput.disabled = true;
            precisionRadios.forEach(function(radio) {
                radio.disabled = true;
                if (radio.value === 'unknown') radio.checked = true;
            });
            if (exactContainer) exactContainer.style.display = 'none';
            if (approxContainer) approxContainer.style.display = 'none';
            if (unknownContainer) unknownContainer.style.display = 'block';
            return;
        }

        if (sightingDateInput) sightingDateInput.disabled = false;
        precisionRadios.forEach(function(radio) {
            radio.disabled = false;
        });

        let selectedPrecision = 'exact';
        precisionRadios.forEach(function(radio) {
            if (radio.checked) selectedPrecision = radio.value;
        });

        if (selectedPrecision === 'exact') {
            if (exactContainer) exactContainer.style.display = 'block';
            if (approxContainer) approxContainer.style.display = 'none';
            if (unknownContainer) unknownContainer.style.display = 'none';
        } else if (selectedPrecision === 'approximate') {
            if (exactContainer) exactContainer.style.display = 'none';
            if (approxContainer) approxContainer.style.display = 'block';
            if (unknownContainer) unknownContainer.style.display = 'none';
            if (windowSelect && customFields) {
                customFields.style.display = (windowSelect.value === 'custom') ? 'block' : 'none';
            }
        } else {
            if (exactContainer) exactContainer.style.display = 'none';
            if (approxContainer) approxContainer.style.display = 'none';
            if (unknownContainer) unknownContainer.style.display = 'block';
        }
    }

    if (dateUnknownCheckbox) {
        dateUnknownCheckbox.addEventListener('change', syncTemporalUI);
    }
    precisionRadios.forEach(function(radio) {
        radio.addEventListener('change', syncTemporalUI);
    });
    if (windowSelect) {
        windowSelect.addEventListener('change', function() {
            if (customFields) {
                customFields.style.display = (windowSelect.value === 'custom') ? 'block' : 'none';
            }
        });
    }
    syncTemporalUI();

    // --- GPS Geolocation Controls ---
    const btnGet = document.getElementById('btnGetLocation');
    const btnClear = document.getElementById('btnClearLocation');
    const latInput = document.getElementById('latitude');
    const lngInput = document.getElementById('longitude');
    const accInput = document.getElementById('gps_accuracy');
    const gpsTimestampInput = document.getElementById('gps_timestamp');
    const accDisplay = document.getElementById('gpsAccuracyDisplay');
    const accVal = document.getElementById('accuracyVal');
    const statusMsg = document.getElementById('gpsStatusMessage');

    function updateClearButton() {
        if (latInput.value.trim() !== '' || lngInput.value.trim() !== '') {
            btnClear.style.display = 'inline-block';
        } else {
            btnClear.style.display = 'none';
        }
    }

    latInput.addEventListener('input', function() {
        // If user manually modifies, clear stale GPS accuracy and hardware timestamp
        accInput.value = '';
        if (gpsTimestampInput) gpsTimestampInput.value = '';
        accDisplay.style.display = 'none';
        updateClearButton();
    });
    lngInput.addEventListener('input', function() {
        accInput.value = '';
        if (gpsTimestampInput) gpsTimestampInput.value = '';
        accDisplay.style.display = 'none';
        updateClearButton();
    });
    updateClearButton();

    if (btnClear) {
        btnClear.addEventListener('click', function() {
            latInput.value = '';
            lngInput.value = '';
            accInput.value = '';
            if (gpsTimestampInput) gpsTimestampInput.value = '';
            accDisplay.style.display = 'none';
            statusMsg.textContent = 'Coordinates cleared.';
            statusMsg.className = 'gps-status-text';
            updateClearButton();
        });
    }

    if (btnGet) {
        if (!navigator.geolocation) {
            btnGet.disabled = true;
            statusMsg.textContent = 'Geolocation not supported by this browser.';
            statusMsg.className = 'gps-status-text gps-warn';
            return;
        }

        btnGet.addEventListener('click', function() {
            statusMsg.textContent = 'Acquiring GPS location...';
            statusMsg.className = 'gps-status-text gps-loading';
            btnGet.disabled = true;

            navigator.geolocation.getCurrentPosition(
                function(position) {
                    btnGet.disabled = false;
                    const lat = position.coords.latitude.toFixed(6);
                    const lng = position.coords.longitude.toFixed(6);
                    const acc = position.coords.accuracy ? Math.round(position.coords.accuracy) : null;

                    latInput.value = lat;
                    lngInput.value = lng;
                    accInput.value = acc || '';
                    if (gpsTimestampInput && position.timestamp) {
                        gpsTimestampInput.value = position.timestamp;
                    }

                    if (acc !== null) {
                        accVal.textContent = acc;
                        accDisplay.style.display = 'inline-block';
                    } else {
                        accDisplay.style.display = 'none';
                    }

                    statusMsg.textContent = '✓ GPS location captured!';
                    statusMsg.className = 'gps-status-text gps-success';
                    updateClearButton();
                },
                function(error) {
                    btnGet.disabled = false;
                    let message = 'Unable to retrieve location.';
                    switch (error.code) {
                        case error.PERMISSION_DENIED:
                            message = 'Location access denied. You can still type coordinates manually.';
                            break;
                        case error.POSITION_UNAVAILABLE:
                            message = 'Position unavailable. Please enter coordinates manually.';
                            break;
                        case error.TIMEOUT:
                            message = 'Location request timed out. Please try again.';
                            break;
                    }
                    statusMsg.textContent = message;
                    statusMsg.className = 'gps-status-text gps-warn';
                },
                {
                    enableHighAccuracy: true,
                    timeout: 10000,
                    maximumAge: 30000
                }
            );
        });
    }
});
</script>

<?php require_once __DIR__ . '/../../app/layouts/dashboard_footer.php'; ?>
