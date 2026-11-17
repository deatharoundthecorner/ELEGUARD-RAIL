<?php
/**
 * Application Settings
 */

define('APP_NAME', 'EleGuard Rail');
define('APP_VERSION', '1.0.0');

// Development Mode Settings
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Date and Time Zone
date_default_timezone_set('Asia/Colombo');

// Session Settings - Enforce security via Session core helper (TSK-J02)
require_once __DIR__ . '/../core/Session.php';
Session::start();
