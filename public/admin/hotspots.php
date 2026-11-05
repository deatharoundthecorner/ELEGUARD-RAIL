<?php
/**
 * Admin Elephant Hotspot Management panel
 */
$pageTitle = "Elephant Hotspots";
$allowed_roles = 'admin';
require_once __DIR__ . '/../../app/layouts/dashboard_header.php';
require_once __DIR__ . '/../../app/services/AlertService.php';
require_once __DIR__ . '/../../app/includes/validation.php';

$errors = [];
$action = $_GET['action'] ?? 'list';
$hotspotIdSelected = $_GET['id'] ?? null;
$hotspotSelected = null;

if ($hotspotIdSelected) {
    $hotspotSelected = AlertService::getHotspotById((int)$hotspotIdSelected);
}

// Handle Form Submissions & Deletions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validate_csrf();

    $submitAction = $_POST['submit_action'] ?? '';

    if ($submitAction === 'add') {
        $sectionId = $_POST['section_id'] ?? '';
        $name = trim($_POST['hotspot_name'] ?? '');
        $lat = trim($_POST['latitude'] ?? '');
        $lon = trim($_POST['longitude'] ?? '');
        $risk = $_POST['risk_level'] ?? 'Low';
        $reason = trim($_POST['reason'] ?? '');

        $reqErrors = Validation::required($_POST, ['section_id', 'hotspot_name', 'risk_level']);
        $errors = array_merge($errors, $reqErrors);

        if (empty($errors)) {
            if (AlertService::addHotspot((int)$sectionId, $name, $lat, $lon, $risk, $reason)) {
                Auth::logActivity($currentUser['id'], 'Hotspot Created', "Admin created hotspot: {$name}.");
                Session::flash('success', "Hotspot crossing '{$name}' created successfully.");
                Router::redirect('admin/hotspots.php');
            } else {
                $errors['submit'] = 'Failed to create hotspot.';
            }
        }
    } elseif ($submitAction === 'edit') {
        $id = (int)$_POST['id'];
        $sectionId = $_POST['section_id'] ?? '';
        $name = trim($_POST['hotspot_name'] ?? '');
        $lat = trim($_POST['latitude'] ?? '');
        $lon = trim($_POST['longitude'] ?? '');
        $risk = $_POST['risk_level'] ?? 'Low';
        $reason = trim($_POST['reason'] ?? '');

        $reqErrors = Validation::required($_POST, ['section_id', 'hotspot_name', 'risk_level']);
        $errors = array_merge($errors, $reqErrors);

        if (empty($errors)) {
            if (AlertService::updateHotspot($id, (int)$sectionId, $name, $lat, $lon, $risk, $reason)) {
                Auth::logActivity($currentUser['id'], 'Hotspot Updated', "Admin updated hotspot #{$id}: {$name}.");
                Session::flash('success', "Hotspot '{$name}' updated successfully.");
                Router::redirect('admin/hotspots.php');
            } else {
                $errors['submit'] = 'Failed to update hotspot.';
            }
        }
    } elseif ($submitAction === 'delete') {
        $id = (int)$_POST['id'];
        if (AlertService::deleteHotspot($id)) {
            Auth::logActivity($currentUser['id'], 'Hotspot Deleted', "Admin deleted hotspot #{$id}.");
            Session::flash('success', "Hotspot deleted successfully.");
            Router::redirect('admin/hotspots.php');
        } else {
            $errors['submit'] = 'Failed to delete hotspot.';
        }
    }
}

$hotspots = AlertService::getAllHotspots();
$sections = AlertService::getAllSections();
?>

<div class="dashboard-title-row">
    <h1>Elephant Hotspots & Crossings</h1>
    <p class="text-secondary">Map known wildlife sanctuaries borders, water holes intersections, and high-frequency crossing locations.</p>
</div>

<!-- Add Hotspot Panel -->
<?php if ($action === 'add'): ?>
    <div class="card-panel" style="max-width: 650px; border-top: 4px solid var(--primary-light);">
        <div class="panel-header">
            <h2 class="panel-title">Add Elephant Hotspot</h2>
            <a href="<?php echo Router::url('admin/hotspots.php'); ?>" class="btn btn-outline btn-sm">Back to List</a>
        </div>

        <form action="<?php echo Router::url('admin/hotspots.php?action=add'); ?>" method="POST">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="submit_action" value="add">

            <div class="form-group">
                <label for="section_id" class="form-label">Linked Railway Section *</label>
                <select name="section_id" id="section_id" class="form-control" required>
                    <option value="">-- Choose Railway Section --</option>
                    <?php foreach ($sections as $sec): ?>
                        <option value="<?php echo $sec['id']; ?>">
                            <?php echo e($sec['section_name']); ?> (<?php echo e($sec['district']); ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label for="hotspot_name" class="form-label">Hotspot Crossing Name *</label>
                <input type="text" name="hotspot_name" id="hotspot_name" class="form-control" placeholder="e.g. Minneriya Elephant Crossing" required>
            </div>

            <div class="grid grid-2col" style="gap:20px; margin-bottom: 0;">
                <div class="form-group">
                    <label for="latitude" class="form-label">Latitude (Optional)</label>
                    <input type="number" step="0.00000001" name="latitude" id="latitude" class="form-control" placeholder="e.g. 8.0412">
                </div>

                <div class="form-group">
                    <label for="longitude" class="form-label">Longitude (Optional)</label>
                    <input type="number" step="0.00000001" name="longitude" id="longitude" class="form-control" placeholder="e.g. 80.8256">
                </div>
            </div>

            <div class="form-group">
                <label for="risk_level" class="form-label">Local risk level *</label>
                <select name="risk_level" id="risk_level" class="form-control" required>
                    <option value="Low">Low</option>
                    <option value="Medium">Medium</option>
                    <option value="High">High</option>
                    <option value="Critical">Critical</option>
                </select>
            </div>

            <div class="form-group">
                <label for="reason" class="form-label">Reason for High-Activity / Notes</label>
                <textarea name="reason" id="reason" class="form-control" placeholder="Describe seasonal movements or proximity to water reserves..."></textarea>
            </div>

            <div class="form-actions">
                <button type="submit" class="btn btn-primary">Create Hotspot</button>
                <a href="<?php echo Router::url('admin/hotspots.php'); ?>" class="btn btn-secondary">Cancel</a>
            </div>
        </form>
    </div>

<!-- Edit Hotspot Panel -->
<?php elseif ($action === 'edit' && $hotspotSelected): ?>
    <div class="card-panel" style="max-width: 650px; border-top: 4px solid var(--accent-color);">
        <div class="panel-header">
            <h2 class="panel-title">Edit Hotspot: <?php echo e($hotspotSelected['hotspot_name']); ?></h2>
            <a href="<?php echo Router::url('admin/hotspots.php'); ?>" class="btn btn-outline btn-sm">Back to List</a>
        </div>

        <form action="<?php echo Router::url('admin/hotspots.php?action=edit&id=' . $hotspotSelected['id']); ?>" method="POST">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="submit_action" value="edit">
            <input type="hidden" name="id" value="<?php echo $hotspotSelected['id']; ?>">

            <div class="form-group">
                <label for="section_id" class="form-label">Linked Railway Section *</label>
                <select name="section_id" id="section_id" class="form-control" required>
                    <option value="">-- Choose Railway Section --</option>
                    <?php foreach ($sections as $sec): ?>
                        <option value="<?php echo $sec['id']; ?>" <?php echo $hotspotSelected['section_id'] == $sec['id'] ? 'selected' : ''; ?>>
                            <?php echo e($sec['section_name']); ?> (<?php echo e($sec['district']); ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label for="hotspot_name" class="form-label">Hotspot Crossing Name *</label>
                <input type="text" name="hotspot_name" id="hotspot_name" class="form-control" value="<?php echo e($hotspotSelected['hotspot_name']); ?>" required>
            </div>

            <div class="grid grid-2col" style="gap:20px; margin-bottom: 0;">
                <div class="form-group">
                    <label for="latitude" class="form-label">Latitude (Optional)</label>
                    <input type="number" step="0.00000001" name="latitude" id="latitude" class="form-control" value="<?php echo e($hotspotSelected['latitude']); ?>">
                </div>

                <div class="form-group">
                    <label for="longitude" class="form-label">Longitude (Optional)</label>
                    <input type="number" step="0.00000001" name="longitude" id="longitude" class="form-control" value="<?php echo e($hotspotSelected['longitude']); ?>">
                </div>
            </div>

            <div class="form-group">
                <label for="risk_level" class="form-label">Local risk level *</label>
                <select name="risk_level" id="risk_level" class="form-control" required>
                    <option value="Low" <?php echo $hotspotSelected['risk_level'] === 'Low' ? 'selected' : ''; ?>>Low</option>
                    <option value="Medium" <?php echo $hotspotSelected['risk_level'] === 'Medium' ? 'selected' : ''; ?>>Medium</option>
                    <option value="High" <?php echo $hotspotSelected['risk_level'] === 'High' ? 'selected' : ''; ?>>High</option>
                    <option value="Critical" <?php echo $hotspotSelected['risk_level'] === 'Critical' ? 'selected' : ''; ?>>Critical</option>
                </select>
            </div>

            <div class="form-group">
                <label for="reason" class="form-label">Reason for High-Activity / Notes</label>
                <textarea name="reason" id="reason" class="form-control"><?php echo e($hotspotSelected['reason']); ?></textarea>
            </div>

            <div class="form-actions">
                <button type="submit" class="btn btn-accent">Update Hotspot</button>
                <a href="<?php echo Router::url('admin/hotspots.php'); ?>" class="btn btn-secondary">Cancel</a>
            </div>
        </form>
    </div>
<?php endif; ?>

<!-- Hotspots List -->
<?php if ($action === 'list'): ?>
    <div class="filter-card" style="text-align: right;">
        <a href="<?php echo Router::url('admin/hotspots.php?action=add'); ?>" class="btn btn-accent">➕ Create Elephant Hotspot</a>
    </div>

    <div class="card-panel">
        <div class="table-responsive">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Hotspot Name</th>
                        <th>Railway Section</th>
                        <th>District Location</th>
                        <th>Coordinates</th>
                        <th>Risk Level</th>
                        <th>Activity Notes</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($hotspots as $hot): ?>
                        <tr>
                            <td>#<?php echo $hot['id']; ?></td>
                            <td><strong><?php echo e($hot['hotspot_name']); ?></strong></td>
                            <td><?php echo e($hot['section_name']); ?></td>
                            <td><?php echo e($hot['district']); ?></td>
                            <td>
                                <?php if ($hot['latitude'] && $hot['longitude']): ?>
                                    <code><?php echo number_format($hot['latitude'], 6); ?>, <?php echo number_format($hot['longitude'], 6); ?></code>
                                <?php else: ?>
                                    <span class="text-muted">Not Set</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="badge <?php echo get_risk_badge_class($hot['risk_level']); ?>">
                                    <?php echo e($hot['risk_level']); ?>
                                </span>
                            </td>
                            <td style="font-size:0.85rem; color:var(--text-secondary); max-width:250px;"><?php echo e($hot['reason'] ? $hot['reason'] : 'N/A'); ?></td>
                            <td>
                                <div class="table-actions">
                                    <a href="<?php echo Router::url('admin/hotspots.php?action=edit&id=' . $hot['id']); ?>" class="btn btn-outline btn-sm">Edit</a>
                                    <form action="<?php echo Router::url('admin/hotspots.php'); ?>" method="POST" onsubmit="return confirm('Are you sure you want to delete this hotspot?');" style="display:inline;">
                                        <?php echo csrf_field(); ?>
                                        <input type="hidden" name="submit_action" value="delete">
                                        <input type="hidden" name="id" value="<?php echo $hot['id']; ?>">
                                        <button type="submit" class="btn btn-danger btn-sm">Delete</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>

<?php require_once __DIR__ . '/../../app/layouts/dashboard_footer.php'; ?>
