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

// Session Settings
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}
