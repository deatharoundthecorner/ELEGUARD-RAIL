<?php
/**
 * Admin Railway Section Management panel
 */
$pageTitle = "Railway Sections";
$allowed_roles = 'admin';
require_once __DIR__ . '/../../app/layouts/dashboard_header.php';
require_once __DIR__ . '/../../app/services/AlertService.php';
require_once __DIR__ . '/../../app/includes/validation.php';

$errors = [];
$action = $_GET['action'] ?? 'list';
$sectionIdSelected = $_GET['id'] ?? null;
$sectionSelected = null;

if ($sectionIdSelected) {
    $sectionSelected = AlertService::getSectionById((int)$sectionIdSelected);
}

// Handle Form Submissions & Deletions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validate_csrf();

    $submitAction = $_POST['submit_action'] ?? '';

    if ($submitAction === 'add') {
        $name = trim($_POST['section_name'] ?? '');
        $district = trim($_POST['district'] ?? '');
        $start = trim($_POST['start_location'] ?? '');
        $end = trim($_POST['end_location'] ?? '');
        $risk = $_POST['risk_level'] ?? 'Low';
        $desc = trim($_POST['description'] ?? '');

        $reqErrors = Validation::required($_POST, ['section_name', 'district', 'start_location', 'end_location', 'risk_level']);
        $errors = array_merge($errors, $reqErrors);

        if (empty($errors)) {
            if (AlertService::addSection($name, $district, $start, $end, $risk, $desc)) {
                Auth::logActivity($currentUser['id'], 'Section Created', "Admin created railway section: {$name}.");
                Session::flash('success', "Railway section '{$name}' created successfully.");
                Router::redirect('admin/railway_sections.php');
            } else {
                $errors['submit'] = 'Failed to create section. Please try again.';
            }
        }
    } elseif ($submitAction === 'edit') {
        $id = (int)$_POST['id'];
        $name = trim($_POST['section_name'] ?? '');
        $district = trim($_POST['district'] ?? '');
        $start = trim($_POST['start_location'] ?? '');
        $end = trim($_POST['end_location'] ?? '');
        $risk = $_POST['risk_level'] ?? 'Low';
        $desc = trim($_POST['description'] ?? '');

        $reqErrors = Validation::required($_POST, ['section_name', 'district', 'start_location', 'end_location', 'risk_level']);
        $errors = array_merge($errors, $reqErrors);

        if (empty($errors)) {
            if (AlertService::updateSection($id, $name, $district, $start, $end, $risk, $desc)) {
                Auth::logActivity($currentUser['id'], 'Section Updated', "Admin updated railway section #{$id}: {$name}.");
                Session::flash('success', "Railway section '{$name}' updated successfully.");
                Router::redirect('admin/railway_sections.php');
            } else {
                $errors['submit'] = 'Failed to update section.';
            }
        }
    } elseif ($submitAction === 'delete') {
        $id = (int)$_POST['id'];
        if (AlertService::deleteSection($id)) {
            Auth::logActivity($currentUser['id'], 'Section Deleted', "Admin deleted railway section #{$id}.");
            Session::flash('success', "Railway section deleted successfully.");
            Router::redirect('admin/railway_sections.php');
        } else {
            $errors['submit'] = 'Failed to delete section.';
        }
    }
}

$sections = AlertService::getAllSections();
?>

<div class="dashboard-title-row">
    <h1>Railway Track Sections</h1>
    <p class="text-secondary">Define track limits, regional districts, and baseline risk levels across the train network.</p>
</div>

<!-- Add Section Panel -->
<?php if ($action === 'add'): ?>
    <div class="card-panel" style="max-width: 650px; border-top: 4px solid var(--primary-light);">
        <div class="panel-header">
            <h2 class="panel-title">Add Railway Section</h2>
            <a href="<?php echo Router::url('admin/railway_sections.php'); ?>" class="btn btn-outline btn-sm">Back to List</a>
        </div>

        <form action="<?php echo Router::url('admin/railway_sections.php?action=add'); ?>" method="POST">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="submit_action" value="add">

            <div class="form-group">
                <label for="section_name" class="form-label">Section Name *</label>
                <input type="text" name="section_name" id="section_name" class="form-control" placeholder="e.g. Maho - Anuradhapura" required>
            </div>

            <div class="form-group">
                <label for="district" class="form-label">District Jurisdiction *</label>
                <input type="text" name="district" id="district" class="form-control" placeholder="e.g. Kurunegala/Anuradhapura" required>
            </div>

            <div class="grid grid-2col" style="gap:20px; margin-bottom: 0;">
                <div class="form-group">
                    <label for="start_location" class="form-label">Start Location Station *</label>
                    <input type="text" name="start_location" id="start_location" class="form-control" placeholder="e.g. Maho Junction" required>
                </div>

                <div class="form-group">
                    <label for="end_location" class="form-label">End Location Station *</label>
                    <input type="text" name="end_location" id="end_location" class="form-control" placeholder="e.g. Anuradhapura Station" required>
                </div>
            </div>

            <div class="form-group">
                <label for="risk_level" class="form-label">Baseline Risk level *</label>
                <select name="risk_level" id="risk_level" class="form-control" required>
                    <option value="Low">Low</option>
                    <option value="Medium">Medium</option>
                    <option value="High">High</option>
                    <option value="Critical">Critical</option>
                </select>
            </div>

            <div class="form-group">
                <label for="description" class="form-label">Section Description / Details</label>
                <textarea name="description" id="description" class="form-control" placeholder="Describe track markers, bends, and general elephant presence notes..."></textarea>
            </div>

            <div class="form-actions">
                <button type="submit" class="btn btn-primary">Create Section</button>
                <a href="<?php echo Router::url('admin/railway_sections.php'); ?>" class="btn btn-secondary">Cancel</a>
            </div>
        </form>
    </div>

<!-- Edit Section Panel -->
<?php elseif ($action === 'edit' && $sectionSelected): ?>
    <div class="card-panel" style="max-width: 650px; border-top: 4px solid var(--accent-color);">
        <div class="panel-header">
            <h2 class="panel-title">Edit Section: <?php echo e($sectionSelected['section_name']); ?></h2>
            <a href="<?php echo Router::url('admin/railway_sections.php'); ?>" class="btn btn-outline btn-sm">Back to List</a>
        </div>

        <form action="<?php echo Router::url('admin/railway_sections.php?action=edit&id=' . $sectionSelected['id']); ?>" method="POST">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="submit_action" value="edit">
            <input type="hidden" name="id" value="<?php echo $sectionSelected['id']; ?>">

            <div class="form-group">
                <label for="section_name" class="form-label">Section Name *</label>
                <input type="text" name="section_name" id="section_name" class="form-control" value="<?php echo e($sectionSelected['section_name']); ?>" required>
            </div>

            <div class="form-group">
                <label for="district" class="form-label">District Jurisdiction *</label>
                <input type="text" name="district" id="district" class="form-control" value="<?php echo e($sectionSelected['district']); ?>" required>
            </div>

            <div class="grid grid-2col" style="gap:20px; margin-bottom: 0;">
                <div class="form-group">
                    <label for="start_location" class="form-label">Start Location Station *</label>
                    <input type="text" name="start_location" id="start_location" class="form-control" value="<?php echo e($sectionSelected['start_location']); ?>" required>
                </div>

                <div class="form-group">
                    <label for="end_location" class="form-label">End Location Station *</label>
                    <input type="text" name="end_location" id="end_location" class="form-control" value="<?php echo e($sectionSelected['end_location']); ?>" required>
                </div>
            </div>

            <div class="form-group">
                <label for="risk_level" class="form-label">Baseline Risk level *</label>
                <select name="risk_level" id="risk_level" class="form-control" required>
                    <option value="Low" <?php echo $sectionSelected['risk_level'] === 'Low' ? 'selected' : ''; ?>>Low</option>
                    <option value="Medium" <?php echo $sectionSelected['risk_level'] === 'Medium' ? 'selected' : ''; ?>>Medium</option>
                    <option value="High" <?php echo $sectionSelected['risk_level'] === 'High' ? 'selected' : ''; ?>>High</option>
                    <option value="Critical" <?php echo $sectionSelected['risk_level'] === 'Critical' ? 'selected' : ''; ?>>Critical</option>
                </select>
            </div>

            <div class="form-group">
                <label for="description" class="form-label">Section Description / Details</label>
                <textarea name="description" id="description" class="form-control"><?php echo e($sectionSelected['description']); ?></textarea>
            </div>

            <div class="form-actions">
                <button type="submit" class="btn btn-accent">Update Section</button>
                <a href="<?php echo Router::url('admin/railway_sections.php'); ?>" class="btn btn-secondary">Cancel</a>
            </div>
        </form>
    </div>
<?php endif; ?>

<!-- Section List -->
<?php if ($action === 'list'): ?>
    <div class="filter-card" style="text-align: right;">
        <a href="<?php echo Router::url('admin/railway_sections.php?action=add'); ?>" class="btn btn-accent">➕ Create Railway Section</a>
    </div>

    <div class="card-panel">
        <div class="table-responsive">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Section Name</th>
                        <th>District Location</th>
                        <th>Start Station</th>
                        <th>End Station</th>
                        <th>Baseline Risk</th>
                        <th>Details</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($sections as $sec): ?>
                        <tr>
                            <td>#<?php echo $sec['id']; ?></td>
                            <td><strong><?php echo e($sec['section_name']); ?></strong></td>
                            <td><?php echo e($sec['district']); ?></td>
                            <td><?php echo e($sec['start_location']); ?></td>
                            <td><?php echo e($sec['end_location']); ?></td>
                            <td>
                                <span class="badge <?php echo get_risk_badge_class($sec['risk_level']); ?>">
                                    <?php echo e($sec['risk_level']); ?>
                                </span>
                            </td>
                            <td style="font-size:0.85rem; color:var(--text-secondary); max-width:250px;"><?php echo e($sec['description'] ? $sec['description'] : 'N/A'); ?></td>
                            <td>
                                <div class="table-actions">
                                    <a href="<?php echo Router::url('admin/railway_sections.php?action=edit&id=' . $sec['id']); ?>" class="btn btn-outline btn-sm">Edit</a>
                                    <form action="<?php echo Router::url('admin/railway_sections.php'); ?>" method="POST" onsubmit="return confirm('Are you sure you want to delete this section? All associated hotspots and alerts will be removed!');" style="display:inline;">
                                        <?php echo csrf_field(); ?>
                                        <input type="hidden" name="submit_action" value="delete">
                                        <input type="hidden" name="id" value="<?php echo $sec['id']; ?>">
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
