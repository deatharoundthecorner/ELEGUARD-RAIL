<?php
/**
 * Sanity CMS Configuration
 */

$envPath = __DIR__ . '/../../.env';
$projectId = '';
$dataset = 'production';
$apiVersion = '2025-01-01';
$useCdn = 'true';

if (file_exists($envPath)) {
    $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        if (strpos($line, '=') !== false) {
            list($key, $value) = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value, " '\"");
            switch ($key) {
                case 'SANITY_PROJECT_ID':
                    $projectId = $value;
                    break;
                case 'SANITY_DATASET':
                    $dataset = $value;
                    break;
                case 'SANITY_API_VERSION':
                    $apiVersion = $value;
                    break;
                case 'SANITY_USE_CDN':
                    $useCdn = $value;
                    break;
            }
        }
    }
}

// Override with system environment variables if available
if (getenv('SANITY_PROJECT_ID') !== false) {
    $projectId = getenv('SANITY_PROJECT_ID');
}
if (getenv('SANITY_DATASET') !== false) {
    $dataset = getenv('SANITY_DATASET');
}
if (getenv('SANITY_API_VERSION') !== false) {
    $apiVersion = getenv('SANITY_API_VERSION');
}
if (getenv('SANITY_USE_CDN') !== false) {
    $useCdn = getenv('SANITY_USE_CDN');
}

define('SANITY_PROJECT_ID', $projectId);
define('SANITY_DATASET', $dataset);
define('SANITY_API_VERSION', $apiVersion);
define('SANITY_USE_CDN', filter_var($useCdn, FILTER_VALIDATE_BOOLEAN));
