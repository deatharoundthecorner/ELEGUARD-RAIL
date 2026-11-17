<?php
/**
 * Database Configuration for EleGuard Rail
 * Attempts to load environment variables from the root .env file
 * and falls back to default XAMPP credentials.
 */

$envPath = __DIR__ . '/../../.env';
$host = 'localhost';
$port = '3306';
$db   = 'eleguard_rail';
$user = 'root';
$pass = '';
$analyticsUser = null;
$analyticsPass = null;
$testUser = null;
$testPass = null;
$testAnalyticsUser = null;
$testAnalyticsPass = null;

if (file_exists($envPath)) {
    $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        // Skip comments
        if (strpos(trim($line), '#') === 0) {
            continue;
        }
        // Parse key-value pairs
        if (strpos($line, '=') !== false) {
            list($key, $value) = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);
            
            // Remove optional quotes
            $value = trim($value, '"\'');

            switch ($key) {
                case 'DB_HOST':
                    $host = $value;
                    break;
                case 'DB_PORT':
                    $port = $value;
                    break;
                case 'DB_NAME':
                    $db = $value;
                    break;
                case 'DB_USER':
                    $user = $value;
                    break;
                case 'DB_PASS':
                    $pass = $value;
                    break;
                case 'DB_ANALYTICS_USER':
                    $analyticsUser = $value;
                    break;
                case 'DB_ANALYTICS_PASS':
                    $analyticsPass = $value;
                    break;
                case 'DB_TEST_USER':
                    $testUser = $value;
                    break;
                case 'DB_TEST_PASS':
                    $testPass = $value;
                    break;
                case 'DB_TEST_ANALYTICS_USER':
                    $testAnalyticsUser = $value;
                    break;
                case 'DB_TEST_ANALYTICS_PASS':
                    $testAnalyticsPass = $value;
                    break;
            }
        }
    }
}

// Override with system environment variables if available
if (getenv('DB_HOST') !== false) {
    $host = getenv('DB_HOST');
}
if (getenv('DB_PORT') !== false) {
    $port = getenv('DB_PORT');
}
if (getenv('DB_NAME') !== false) {
    $db = getenv('DB_NAME');
}
if (getenv('DB_USER') !== false) {
    $user = getenv('DB_USER');
}
if (getenv('DB_PASS') !== false) {
    $pass = getenv('DB_PASS');
}
if (getenv('DB_ANALYTICS_USER') !== false) {
    $analyticsUser = getenv('DB_ANALYTICS_USER');
}
if (getenv('DB_ANALYTICS_PASS') !== false) {
    $analyticsPass = getenv('DB_ANALYTICS_PASS');
}
if (getenv('DB_TEST_USER') !== false) {
    $testUser = getenv('DB_TEST_USER');
}
if (getenv('DB_TEST_PASS') !== false) {
    $testPass = getenv('DB_TEST_PASS');
}
if (getenv('DB_TEST_ANALYTICS_USER') !== false) {
    $testAnalyticsUser = getenv('DB_TEST_ANALYTICS_USER');
}
if (getenv('DB_TEST_ANALYTICS_PASS') !== false) {
    $testAnalyticsPass = getenv('DB_TEST_ANALYTICS_PASS');
}

define('DB_HOST', $host);
define('DB_PORT', $port);
define('DB_NAME', $db);
define('DB_USER', $user);
define('DB_PASS', $pass);
define('DB_TEST_USER', $testUser);
define('DB_TEST_PASS', $testPass !== null ? $testPass : '');
define('DB_ANALYTICS_USER', $analyticsUser);
define('DB_ANALYTICS_PASS', $analyticsPass !== null ? $analyticsPass : '');
define('DB_TEST_ANALYTICS_USER', $testAnalyticsUser);
define('DB_TEST_ANALYTICS_PASS', $testAnalyticsPass !== null ? $testAnalyticsPass : '');
define('DB_DSN', "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME . ";charset=utf8mb4");
