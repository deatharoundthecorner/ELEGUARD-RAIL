<?php
/**
 * Authentication Required Middleware
 */

require_once __DIR__ . '/../core/Auth.php';
require_once __DIR__ . '/../core/Router.php';
require_once __DIR__ . '/../core/Session.php';

if (!Auth::check()) {
    Session::flash('error', 'You must be logged in to access that page.');
    Router::redirect('login.php');
}
