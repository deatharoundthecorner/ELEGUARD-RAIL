<?php
/**
 * Logout Page
 */

require_once __DIR__ . '/../app/core/Auth.php';
require_once __DIR__ . '/../app/core/Router.php';
require_once __DIR__ . '/../app/core/Session.php';

Auth::logout();

// Start a new session just to flash the success message
Session::start();
Session::flash('success', 'You have been successfully logged out.');
Router::redirect('login.php');
