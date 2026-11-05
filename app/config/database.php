<?php
/**
 * Database Configuration for EleGuard Rail
 * Attempts to load environment variables from the root .env file
 * and falls back to default XAMPP credentials.
 */

$envPath = __DIR__ . '/../../.env';
$host = 'localhost';
$db   = 'eleguard_rail';
$user = 'root';
$pass = '';

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
                case 'DB_NAME':
                    $db = $value;
                    break;
                case 'DB_USER':
                    $user = $value;
                    break;
                case 'DB_PASS':
                    $pass = $value;
                    break;
            }
        }
    }
}

// Override with system environment variables if available
if (getenv('DB_HOST') !== false) {
    $host = getenv('DB_HOST');
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

define('DB_HOST', $host);
define('DB_NAME', $db);
define('DB_USER', $user);
define('DB_PASS', $pass);
define('DB_DSN', "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4");
