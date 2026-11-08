<?php
/**
 * Login Page
 */
$pageTitle = "Login";
require_once __DIR__ . '/../app/layouts/public_header.php';
require_once __DIR__ . '/../app/core/Auth.php';
require_once __DIR__ . '/../app/core/Router.php';
require_once __DIR__ . '/../app/includes/validation.php';

// Redirect if already logged in
if (Auth::check()) {
    Router::redirectToDashboard();
}

$errors = [];
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validate_csrf();
    
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    
    // Validate inputs
    $reqErrors = Validation::required($_POST, ['email', 'password']);
    $errors = array_merge($errors, $reqErrors);
    
    if (empty($errors['email'])) {
        $emailError = Validation::email($email);
        if ($emailError) {
            $errors['email'] = $emailError;
        }
    }
    
    if (empty($errors)) {
        if (Auth::login($email, $password)) {
            Session::flash('success', 'Logged in successfully. Welcome to EleGuard Rail!');
            Router::redirectToDashboard();
        } else {
            $errors['auth'] = 'Invalid email or password. Please try again.';
        }
    }
}
?>

<div class="auth-wrapper">
    <div class="auth-card">
        <h2 class="auth-title text-center">System Login</h2>
        <p class="auth-subtitle text-center">Sign in to report crossings, manage alerts, or view cab warnings.</p>
        
        <?php if (isset($errors['auth'])): ?>
            <div class="alert alert-danger">
                <p><?php echo e($errors['auth']); ?></p>
            </div>
        <?php endif; ?>
        
        <form action="<?php echo Router::url('login.php'); ?>" method="POST" novalidate>
            <?php echo csrf_field(); ?>
            
            <div class="form-group">
                <label for="email" class="form-label">Email Address</label>
                <input type="email" name="email" id="email" class="form-control" value="<?php echo e($email); ?>" required autofocus>
                <?php if (isset($errors['email'])): ?>
                    <span class="field-error"><?php echo e($errors['email']); ?></span>
                <?php endif; ?>
            </div>
            
            <div class="form-group">
                <label for="password" class="form-label">Password</label>
                <input type="password" name="password" id="password" class="form-control" required>
                <?php if (isset($errors['password'])): ?>
                    <span class="field-error"><?php echo e($errors['password']); ?></span>
                <?php endif; ?>
            </div>
            
            <div class="form-actions">
                <button type="submit" class="btn btn-primary btn-block">Sign In</button>
            </div>
        </form>
        
        <div class="auth-footer text-center">
            <p>Are you a villager or local railway worker? <a href="<?php echo Router::url('register.php'); ?>">Register here</a></p>
            <p style="margin-top:15px; font-size:0.8rem; color:var(--text-muted);">
                Test accounts: <code>admin@eleguard.lk</code> / <code>driver@eleguard.lk</code> / <code>wildlife@eleguard.lk</code> / <code>control@eleguard.lk</code> / <code>reporter@eleguard.lk</code> (password: username minus domain + 123, e.g. <code>admin123</code>, <code>driver123</code>, etc.)
            </p>
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
