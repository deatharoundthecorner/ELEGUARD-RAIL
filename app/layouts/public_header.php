<?php
/**
 * Public Layout Header
 */
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../core/Auth.php';
require_once __DIR__ . '/../core/Router.php';
require_once __DIR__ . '/../includes/functions.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo isset($pageTitle) ? e($pageTitle) . ' | ' . APP_NAME : APP_NAME; ?></title>
    
    <!-- Google Fonts: Inter and Outfit -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Outfit:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    
    <!-- Stylesheets -->
    <link rel="stylesheet" href="<?php echo Router::url('assets/css/style.css'); ?>">
</head>
<body>
    <header class="public-header">
        <div class="container navbar-container">
            <a href="<?php echo Router::url('index.php'); ?>" class="brand">
                <span class="logo-icon">🐘</span>
                <span class="brand-text">EleGuard <span class="brand-accent">Rail</span></span>
            </a>
            
            <nav class="public-nav" id="publicNav">
                <a href="<?php echo Router::url('index.php'); ?>" class="nav-link">Home</a>
                <a href="<?php echo Router::url('safety.php'); ?>" class="nav-link">Safety Instructions</a>
                <a href="<?php echo Router::url('faq.php'); ?>" class="nav-link">FAQ</a>
                <a href="<?php echo Router::url('awareness.php'); ?>" class="nav-link">Awareness</a>
                <a href="<?php echo Router::url('emergency_contacts.php'); ?>" class="nav-link">Emergency Contacts</a>
                
                <?php if (Auth::check()): ?>
                    <?php
                    $role = Auth::role();
                    $dashboardPath = "{$role}/dashboard.php";
                    ?>
                    <a href="<?php echo Router::url($dashboardPath); ?>" class="nav-btn btn-primary">Dashboard</a>
                    <a href="<?php echo Router::url('logout.php'); ?>" class="nav-link logout-link">Logout</a>
                <?php else: ?>
                    <a href="<?php echo Router::url('login.php'); ?>" class="nav-link">Login</a>
                    <a href="<?php echo Router::url('register.php'); ?>" class="nav-btn btn-primary">Register</a>
                <?php endif; ?>
            </nav>
            
            <button class="nav-toggle" aria-label="Toggle menu" onclick="toggleNav()">
                <span></span>
                <span></span>
                <span></span>
            </button>
        </div>
    </header>

    <main class="main-content container">
        <?php if ($flashError = Session::flash('error')): ?>
            <div class="alert alert-danger dismissible-alert">
                <p><?php echo e($flashError); ?></p>
                <button class="close-alert-btn" onclick="this.parentElement.remove()">&times;</button>
            </div>
        <?php endif; ?>

        <?php if ($flashSuccess = Session::flash('success')): ?>
            <div class="alert alert-success dismissible-alert">
                <p><?php echo e($flashSuccess); ?></p>
                <button class="close-alert-btn" onclick="this.parentElement.remove()">&times;</button>
            </div>
        <?php endif; ?>
