<?php
/**
 * Admin User Management panel
 */
$pageTitle = "Manage Users";
$allowed_roles = 'admin';
require_once __DIR__ . '/../../app/layouts/dashboard_header.php';
require_once __DIR__ . '/../../app/services/UserService.php';
require_once __DIR__ . '/../../app/includes/validation.php';

$errors = [];
$action = $_GET['action'] ?? 'list';
$userIdSelected = $_GET['id'] ?? null;
$userSelected = null;

if ($userIdSelected) {
    $userSelected = UserService::getById((int)$userIdSelected);
}

// Handle Form Submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validate_csrf();
    
    $submitAction = $_POST['submit_action'] ?? '';
    
    if ($submitAction === 'add') {
        $name = trim($_POST['name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $role = $_POST['role'] ?? 'reporter';
        $status = $_POST['status'] ?? 'active';
        
        $reqErrors = Validation::required($_POST, ['name', 'email', 'password', 'role', 'status']);
        $errors = array_merge($errors, $reqErrors);
        
        if (empty($errors['email'])) {
            $emailErr = Validation::email($email);
            if ($emailErr) $errors['email'] = $emailErr;
        }
        
        if (empty($errors['password'])) {
            $passErr = Validation::minLength($password, 6, 'Password');
            if ($passErr) $errors['password'] = $passErr;
        }

        if (empty($errors)) {
            if (UserService::add($name, $email, $password, $role, $status)) {
                // Log action
                Auth::logActivity($currentUser['id'], 'User Created', "Admin created user account {$email} (Role: {$role}).");
                
                Session::flash('success', "User account {$email} created successfully.");
                Router::redirect('admin/users.php');
            } else {
                $errors['email'] = 'An account with this email address already exists.';
            }
        }
    } elseif ($submitAction === 'edit') {
        $id = (int)$_POST['id'];
        $name = trim($_POST['name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $role = $_POST['role'] ?? 'reporter';
        $status = $_POST['status'] ?? 'active';
        
        $reqErrors = Validation::required($_POST, ['name', 'email', 'role', 'status']);
        $errors = array_merge($errors, $reqErrors);
        
        if (empty($errors['email'])) {
            $emailErr = Validation::email($email);
            if ($emailErr) $errors['email'] = $emailErr;
        }

        if (!empty($password) && empty($errors['password'])) {
            $passErr = Validation::minLength($password, 6, 'Password');
            if ($passErr) $errors['password'] = $passErr;
        }

        if (empty($errors)) {
            if (UserService::update($id, $name, $email, $role, $status, $password)) {
                // Log action
                Auth::logActivity($currentUser['id'], 'User Updated', "Admin updated user #{$id} ({$email}).");
                
                Session::flash('success', "User #{$id} updated successfully.");
                Router::redirect('admin/users.php');
            } else {
                $errors['email'] = 'An account with this email address already exists.';
            }
        }
    }
}

// Fetch all users with search/role filters
$roleFilter = $_GET['role'] ?? null;
$searchQuery = $_GET['search'] ?? null;
$users = UserService::getAll($roleFilter, $searchQuery);
?>

<div class="dashboard-title-row">
    <h1>Manage System Users</h1>
    <p class="text-secondary">Register railway staff, edit roles, and deactivate/activate reporter accounts.</p>
</div>

<!-- Add Form Panel -->
<?php if ($action === 'add'): ?>
    <div class="card-panel" style="max-width: 600px; border-top: 4px solid var(--primary-light);">
        <div class="panel-header">
            <h2 class="panel-title">Add New User</h2>
            <a href="<?php echo Router::url('admin/users.php'); ?>" class="btn btn-outline btn-sm">Back to List</a>
        </div>
        
        <form action="<?php echo Router::url('admin/users.php?action=add'); ?>" method="POST" novalidate>
            <?php echo csrf_field(); ?>
            <input type="hidden" name="submit_action" value="add">
            
            <div class="form-group">
                <label for="name" class="form-label">Full Name *</label>
                <input type="text" name="name" id="name" class="form-control" required>
            </div>
            
            <div class="form-group">
                <label for="email" class="form-label">Email Address *</label>
                <input type="email" name="email" id="email" class="form-control" required>
                <?php if (isset($errors['email'])): ?>
                    <span class="field-error" style="color:var(--danger); font-size:0.8rem;"><?php echo e($errors['email']); ?></span>
                <?php endif; ?>
            </div>
            
            <div class="form-group">
                <label for="password" class="form-label">Password (Min. 6 chars) *</label>
                <input type="password" name="password" id="password" class="form-control" required>
                <?php if (isset($errors['password'])): ?>
                    <span class="field-error" style="color:var(--danger); font-size:0.8rem;"><?php echo e($errors['password']); ?></span>
                <?php endif; ?>
            </div>
            
            <div class="grid grid-2col" style="gap:20px; margin-bottom: 0;">
                <div class="form-group">
                    <label for="role" class="form-label">System Role *</label>
                    <select name="role" id="role" class="form-control" required>
                        <option value="admin">Admin</option>
                        <option value="control_officer">Railway Control Officer</option>
                        <option value="driver">Train Driver</option>
                        <option value="wildlife_officer">Wildlife Ranger</option>
                        <option value="reporter">Villager Reporter</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label for="status" class="form-label">Account Status *</label>
                    <select name="status" id="status" class="form-control" required>
                        <option value="active">Active</option>
                        <option value="inactive">Inactive</option>
                    </select>
                </div>
            </div>
            
            <div class="form-actions">
                <button type="submit" class="btn btn-primary">Create Account</button>
                <a href="<?php echo Router::url('admin/users.php'); ?>" class="btn btn-secondary">Cancel</a>
            </div>
        </form>
    </div>

<!-- Edit Form Panel -->
<?php elseif ($action === 'edit' && $userSelected): ?>
    <div class="card-panel" style="max-width: 600px; border-top: 4px solid var(--accent-color);">
        <div class="panel-header">
            <h2 class="panel-title">Edit User: <?php echo e($userSelected['name']); ?></h2>
            <a href="<?php echo Router::url('admin/users.php'); ?>" class="btn btn-outline btn-sm">Back to List</a>
        </div>
        
        <form action="<?php echo Router::url('admin/users.php?action=edit&id=' . $userSelected['id']); ?>" method="POST" novalidate>
            <?php echo csrf_field(); ?>
            <input type="hidden" name="submit_action" value="edit">
            <input type="hidden" name="id" value="<?php echo $userSelected['id']; ?>">
            
            <div class="form-group">
                <label for="name" class="form-label">Full Name *</label>
                <input type="text" name="name" id="name" class="form-control" value="<?php echo e($userSelected['name']); ?>" required>
            </div>
            
            <div class="form-group">
                <label for="email" class="form-label">Email Address *</label>
                <input type="email" name="email" id="email" class="form-control" value="<?php echo e($userSelected['email']); ?>" required>
                <?php if (isset($errors['email'])): ?>
                    <span class="field-error" style="color:var(--danger); font-size:0.8rem;"><?php echo e($errors['email']); ?></span>
                <?php endif; ?>
            </div>
            
            <div class="form-group">
                <label for="password" class="form-label">Change Password (Leave blank to keep current)</label>
                <input type="password" name="password" id="password" class="form-control" placeholder="••••••">
                <?php if (isset($errors['password'])): ?>
                    <span class="field-error" style="color:var(--danger); font-size:0.8rem;"><?php echo e($errors['password']); ?></span>
                <?php endif; ?>
            </div>
            
            <div class="grid grid-2col" style="gap:20px; margin-bottom: 0;">
                <div class="form-group">
                    <label for="role" class="form-label">System Role *</label>
                    <select name="role" id="role" class="form-control" required>
                        <option value="admin" <?php echo $userSelected['role'] === 'admin' ? 'selected' : ''; ?>>Admin</option>
                        <option value="control_officer" <?php echo $userSelected['role'] === 'control_officer' ? 'selected' : ''; ?>>Railway Control Officer</option>
                        <option value="driver" <?php echo $userSelected['role'] === 'driver' ? 'selected' : ''; ?>>Train Driver</option>
                        <option value="wildlife_officer" <?php echo $userSelected['role'] === 'wildlife_officer' ? 'selected' : ''; ?>>Wildlife Ranger</option>
                        <option value="reporter" <?php echo $userSelected['role'] === 'reporter' ? 'selected' : ''; ?>>Villager Reporter</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label for="status" class="form-label">Account Status *</label>
                    <select name="status" id="status" class="form-control" required>
                        <option value="active" <?php echo $userSelected['status'] === 'active' ? 'selected' : ''; ?>>Active</option>
                        <option value="inactive" <?php echo $userSelected['status'] === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                    </select>
                </div>
            </div>
            
            <div class="form-actions">
                <button type="submit" class="btn btn-accent">Update Account</button>
                <a href="<?php echo Router::url('admin/users.php'); ?>" class="btn btn-secondary">Cancel</a>
            </div>
        </form>
    </div>
<?php endif; ?>

<!-- Users List and Filters -->
<?php if ($action === 'list'): ?>
    <div class="filter-card">
        <form action="<?php echo Router::url('admin/users.php'); ?>" method="GET" class="filter-form">
            <div class="filter-group">
                <label for="role_filter" class="form-label">Filter Role</label>
                <select name="role" id="role_filter" class="form-control">
                    <option value="">All Roles</option>
                    <option value="admin" <?php echo $roleFilter === 'admin' ? 'selected' : ''; ?>>Admin</option>
                    <option value="control_officer" <?php echo $roleFilter === 'control_officer' ? 'selected' : ''; ?>>Railway Control</option>
                    <option value="driver" <?php echo $roleFilter === 'driver' ? 'selected' : ''; ?>>Train Driver</option>
                    <option value="wildlife_officer" <?php echo $roleFilter === 'wildlife_officer' ? 'selected' : ''; ?>>Wildlife Ranger</option>
                    <option value="reporter" <?php echo $roleFilter === 'reporter' ? 'selected' : ''; ?>>Villager Reporter</option>
                </select>
            </div>
            
            <div class="filter-group">
                <label for="search" class="form-label">Search Name / Email</label>
                <input type="text" name="search" id="search" class="form-control" placeholder="e.g. Priyantha" value="<?php echo e($searchQuery); ?>">
            </div>
            
            <div class="filter-btn-group">
                <button type="submit" class="btn btn-primary">Search</button>
                <a href="<?php echo Router::url('admin/users.php'); ?>" class="btn btn-secondary">Reset</a>
                <a href="<?php echo Router::url('admin/users.php?action=add'); ?>" class="btn btn-accent">➕ Add User</a>
            </div>
        </form>
    </div>

    <div class="card-panel">
        <div class="table-responsive">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Account ID</th>
                        <th>Full Name</th>
                        <th>Email Address</th>
                        <th>System Role</th>
                        <th>Status</th>
                        <th>Created Date</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($users as $u): ?>
                        <tr>
                            <td>#<?php echo $u['id']; ?></td>
                            <td><strong><?php echo e($u['name']); ?></strong></td>
                            <td><?php echo e($u['email']); ?></td>
                            <td>
                                <span class="badge badge-outline">
                                    <?php echo e(ucwords(str_replace('_', ' ', $u['role']))); ?>
                                </span>
                            </td>
                            <td>
                                <span class="badge <?php echo $u['status'] === 'active' ? 'badge-success' : 'badge-danger'; ?>">
                                    <?php echo e($u['status']); ?>
                                </span>
                            </td>
                            <td><?php echo format_raw_date($u['created_at']); ?></td>
                            <td>
                                <div class="table-actions">
                                    <a href="<?php echo Router::url('admin/users.php?action=edit&id=' . $u['id']); ?>" class="btn btn-outline btn-sm">Edit</a>
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
