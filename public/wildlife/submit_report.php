<?php
/**
 * Ranger Sighting Submission Page
 */
$pageTitle = "File Ranger Sighting";
$allowed_roles = 'wildlife_officer';
require_once __DIR__ . '/../../app/layouts/dashboard_header.php';
require_once __DIR__ . '/../../app/services/AlertService.php';
require_once __DIR__ . '/../../app/services/ReportService.php';
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

    // Validate fields
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
        // 1. Submit Sighting (saves as Pending initially)
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
            $gpsAccuracy
        );

        if ($success) {
            // Get the last inserted ID to update status to 'Verified' immediately
            $db = Database::getInstance()->getConnection();
            $newReportId = $db->lastInsertId();
            
            // 2. Elevate to 'Verified' because submitter is a Wildlife Officer
            ReportService::updateStatus((int)$newReportId, 'Verified', $currentUser['id']);
            
            // Log ranger submit action
            Auth::logActivity($currentUser['id'], 'Ranger Sighting Filed', "Ranger filed sighting #{$newReportId} in section #{$sectionId} (Confirmed).");

            Session::flash('success', 'Official ranger sighting registered successfully as a confirmed (Verified) report.');
            Router::redirect('wildlife/reports.php');
        } else {
            $errors['submit'] = 'Failed to register report. Please check database connection.';
        }
    }
}
?>

<div class="dashboard-title-row">
    <h1>File Official Ranger Sighting</h1>
    <p class="text-secondary">Official ranger reports are automatically marked as <strong>Verified</strong> for immediate action by Railway Control.</p>
</div>

<div class="card-panel" style="max-width: 700px;">
    <?php if (isset($errors['submit'])): ?>
        <div class="alert alert-danger">
            <p><?php echo e($errors['submit']); ?></p>
        </div>
    <?php endif; ?>

    <form action="<?php echo Router::url('wildlife/submit_report.php'); ?>" method="POST" enctype="multipart/form-data">
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
                <label for="urgency_level" class="form-label">Proximity Urgency *</label>
                <select name="urgency_level" id="urgency_level" class="form-control" required>
                    <option value="Low" <?php echo $urgencyLevel === 'Low' ? 'selected' : ''; ?>>Low (Far from track lines)</option>
                    <option value="Medium" <?php echo $urgencyLevel === 'Medium' ? 'selected' : ''; ?>>Medium (Approaching track buffer)</option>
                    <option value="High" <?php echo $urgencyLevel === 'High' ? 'selected' : ''; ?>>High (Close / Trackside corridor)</option>
                    <option value="Critical" <?php echo $urgencyLevel === 'Critical' ? 'selected' : ''; ?>>Critical (On tracks / Crossing corridor)</option>
                </select>
                <?php if (isset($errors['urgency_level'])): ?>
                    <span class="field-error"><?php echo e($errors['urgency_level']); ?></span>
                <?php endif; ?>
            </div>
        </div>

        <div class="form-group">
            <label for="location_description" class="form-label">Location Description *</label>
            <input type="text" name="location_description" id="location_description" class="form-control" placeholder="e.g. Near Gal Oya kilometer post 124/5 bend" value="<?php echo e($locationDescription); ?>" required>
            <?php if (isset($errors['location_description'])): ?>
                <span class="field-error"><?php echo e($errors['location_description']); ?></span>
            <?php endif; ?>
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
            <div id="gpsAccuracyDisplay" class="gps-accuracy-info" style="<?php echo !empty($gpsAccuracy) ? '' : 'display:none;'; ?>">
                Estimated Accuracy: &plusmn;<span id="accuracyVal"><?php echo e($gpsAccuracy); ?></span> meters
            </div>
        </div>

        <div class="form-group">
            <label for="description" class="form-label">Ranger Sighting Logs / Field Notes</label>
            <textarea name="description" id="description" class="form-control" placeholder="e.g. A family herd of 4 elephants detected feeding on scrub vegetation. Active movement towards tracks observed. Tracks monitored."><?php echo e($description); ?></textarea>
        </div>

        <div class="form-group">
            <label for="image" class="form-label">Attach Field Photo (Optional, JPEG/PNG max 2MB)</label>
            <input type="file" name="image" id="image" class="form-control" accept="image/png, image/jpeg, image/jpg">
            <?php if (isset($errors['image'])): ?>
                <span class="field-error"><?php echo e($errors['image']); ?></span>
            <?php endif; ?>
        </div>

        <div class="form-actions">
            <button type="submit" class="btn btn-accent">Submit Verified Sighting</button>
            <a href="<?php echo Router::url('wildlife/dashboard.php'); ?>" class="btn btn-secondary">Cancel</a>
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
.gps-location-card {
    background-color: rgba(255, 255, 255, 0.02);
    border: 1px solid var(--border-color);
    border-radius: 8px;
    padding: 16px;
    margin-bottom: 20px;
}
.gps-card-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 6px;
}
.gps-hint {
    font-size: 0.85rem;
    color: var(--text-secondary);
    line-height: 1.4;
    margin-bottom: 12px;
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
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const btnGet = document.getElementById('btnGetLocation');
    const btnClear = document.getElementById('btnClearLocation');
    const latInput = document.getElementById('latitude');
    const lngInput = document.getElementById('longitude');
    const accInput = document.getElementById('gps_accuracy');
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
        accInput.value = '';
        accDisplay.style.display = 'none';
        updateClearButton();
    });
    lngInput.addEventListener('input', function() {
        accInput.value = '';
        accDisplay.style.display = 'none';
        updateClearButton();
    });
    updateClearButton();

    if (btnClear) {
        btnClear.addEventListener('click', function() {
            latInput.value = '';
            lngInput.value = '';
            accInput.value = '';
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
