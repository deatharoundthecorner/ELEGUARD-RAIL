<?php
/**
 * Reporter Registration Page
 */
$pageTitle = "Register Reporter Account";
require_once __DIR__ . '/../app/layouts/public_header.php';
require_once __DIR__ . '/../app/services/UserService.php';
require_once __DIR__ . '/../app/includes/validation.php';
require_once __DIR__ . '/../app/core/Router.php';

// Redirect if already logged in
if (Auth::check()) {
    Router::redirectToDashboard();
}

$errors = [];
$name = '';
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validate_csrf();

    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    // Validate required fields
    $reqErrors = Validation::required($_POST, ['name', 'email', 'password', 'confirm_password']);
    $errors = array_merge($errors, $reqErrors);

    // Validate email
    if (empty($errors['email'])) {
        $emailError = Validation::email($email);
        if ($emailError) {
            $errors['email'] = $emailError;
        }
    }

    // Validate password length
    if (empty($errors['password'])) {
        $passError = Validation::minLength($password, 6, 'Password');
        if ($passError) {
            $errors['password'] = $passError;
        }
    }

    // Match passwords
    if (empty($errors['confirm_password']) && $password !== $confirmPassword) {
        $errors['confirm_password'] = 'Passwords do not match.';
    }

    if (empty($errors)) {
        // Attempt registration (role is fixed to 'reporter')
        if (UserService::add($name, $email, $password, 'reporter', 'active')) {
            // Log successful registration
            try {
                $db = Database::getInstance()->getConnection();
                // Find created user id
                $usrStmt = $db->prepare("SELECT id FROM users WHERE email = :email LIMIT 1");
                $usrStmt->execute([':email' => $email]);
                $newUsr = $usrStmt->fetch();
                if ($newUsr) {
                    Auth::logActivity($newUsr['id'], 'User Registered', "New reporter account registered for {$email}.");
                }
            } catch (Exception $e) {}

            Session::flash('success', 'Account created successfully! You can now log in to submit sightings.');
            Router::redirect('login.php');
        } else {
            $errors['email'] = 'An account with this email address already exists.';
        }
    }
}
?>

<div class="auth-wrapper">
    <div class="auth-card">
        <h2 class="auth-title text-center">Reporter Registration</h2>
        <p class="auth-subtitle text-center">Create a reporter account to report elephant movements near tracks.</p>
        
        <form action="<?php echo Router::url('register.php'); ?>" method="POST" novalidate>
            <?php echo csrf_field(); ?>
            
            <div class="form-group">
                <label for="name" class="form-label">Full Name</label>
                <input type="text" name="name" id="name" class="form-control" value="<?php echo e($name); ?>" placeholder="e.g. Priyantha Perera" required>
                <?php if (isset($errors['name'])): ?>
                    <span class="field-error"><?php echo e($errors['name']); ?></span>
                <?php endif; ?>
            </div>
            
            <div class="form-group">
                <label for="email" class="form-label">Email Address</label>
                <input type="email" name="email" id="email" class="form-control" value="<?php echo e($email); ?>" placeholder="e.g. reporter@eleguard.lk" required>
                <?php if (isset($errors['email'])): ?>
                    <span class="field-error"><?php echo e($errors['email']); ?></span>
                <?php endif; ?>
            </div>
            
            <div class="form-group">
                <label for="password" class="form-label">Password (Min. 6 chars)</label>
                <input type="password" name="password" id="password" class="form-control" placeholder="••••••" required>
                <?php if (isset($errors['password'])): ?>
                    <span class="field-error"><?php echo e($errors['password']); ?></span>
                <?php endif; ?>
            </div>
            
            <div class="form-group">
                <label for="confirm_password" class="form-label">Confirm Password</label>
                <input type="password" name="confirm_password" id="confirm_password" class="form-control" placeholder="••••••" required>
                <?php if (isset($errors['confirm_password'])): ?>
                    <span class="field-error"><?php echo e($errors['confirm_password']); ?></span>
                <?php endif; ?>
            </div>
            
            <div class="form-actions">
                <button type="submit" class="btn btn-primary btn-block">Register Account</button>
            </div>
        </form>
        
        <div class="auth-footer text-center">
            <p>Already have an account? <a href="<?php echo Router::url('login.php'); ?>">Sign In here</a></p>
        </div>
    </div>
</div>

<style>
.auth-wrapper {
    display: flex;
    justify-content: center;
    align-items: center;
    padding: 40px 0;
}

.auth-card {
    background-color: var(--bg-card);
    border: 1px solid var(--border-color);
    border-radius: 8px;
    padding: 40px;
    width: 100%;
    max-width: 480px;
    box-shadow: 0 8px 24px rgba(0, 0, 0, 0.4);
}

.auth-title {
    font-size: 1.8rem;
    margin-bottom: 8px;
}

.auth-subtitle {
    color: var(--text-secondary);
    font-size: 0.9rem;
    margin-bottom: 30px;
}

.btn-block {
    width: 100%;
}

.field-error {
    color: var(--danger);
    font-size: 0.8rem;
    display: block;
    margin-top: 5px;
}

.auth-footer {
    margin-top: 30px;
    border-top: 1px solid var(--border-color);
    padding-top: 20px;
    font-size: 0.9rem;
}
</style>

<?php require_once __DIR__ . '/../app/layouts/public_footer.php'; ?>
