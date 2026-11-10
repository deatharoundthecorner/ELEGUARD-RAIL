<?php
/**
 * Database Core class (Singleton)
 * Provides a unified connection instance using PDO.
 */

require_once __DIR__ . '/../config/database.php';

class Database {
    private static $instance = null;
    private $pdo;

    private function __construct() {
        // Enforce database safety if running in a test context
        $script = isset($_SERVER['SCRIPT_FILENAME']) ? str_replace('\\', '/', realpath($_SERVER['SCRIPT_FILENAME']) ?: $_SERVER['SCRIPT_FILENAME']) : '';
        $isTestContext = defined('ELEGUARD_TEST_ENVIRONMENT') || 
                         (PHP_SAPI === 'cli' && strpos($script, '/tests/') !== false);

        if ($isTestContext) {
            $dbName = getenv('DB_NAME') ?: ($_ENV['DB_NAME'] ?? (defined('DB_NAME') ? DB_NAME : null));
            if (empty($dbName)) {
                throw new RuntimeException("DATABASE SAFETY VIOLATION: DB_NAME is missing or empty in test execution. Tests must explicitly target 'eleguard_rail_test'.");
            }
            if (strtolower(trim($dbName)) === 'eleguard_rail') {
                throw new RuntimeException("FATAL DATABASE SAFETY VIOLATION: Automated test attempted to connect to operational database 'eleguard_rail'. Execution halted.");
            }
            if (strtolower(trim($dbName)) !== 'eleguard_rail_test') {
                throw new RuntimeException("DATABASE SAFETY VIOLATION: Database '{$dbName}' is not an approved test database. Tests must target 'eleguard_rail_test'.");
            }
        }

        $dsn = DB_DSN;
        if ($isTestContext && !empty($dbName)) {
            $h = getenv('DB_HOST') ?: (defined('DB_HOST') ? DB_HOST : '127.0.0.1');
            $p = getenv('DB_PORT') ?: (defined('DB_PORT') ? DB_PORT : 3308);
            $dsn = "mysql:host={$h};port={$p};dbname={$dbName};charset=utf8mb4";
        }

        try {
            $this->pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
        } catch (PDOException $e) {
            if ($isTestContext || PHP_SAPI === 'cli') {
                throw new RuntimeException("Database connection failed: " . $e->getMessage(), 0, $e);
            }
            // Render a friendly error screen if database connection fails
            $appName = 'EleGuard Rail';
            echo "<!DOCTYPE html>
            <html lang='en'>
            <head>
                <meta charset='UTF-8'>
                <meta name='viewport' content='width=device-width, initial-scale=1.0'>
                <title>Database Connection Error - {$appName}</title>
                <style>
                    body {
                        font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
                        background: #141a14;
                        color: #e0e6e0;
                        display: flex;
                        justify-content: center;
                        align-items: center;
                        height: 100vh;
                        margin: 0;
                    }
                    .error-container {
                        background: #1c261c;
                        border: 1px solid #2e3d2e;
                        border-radius: 8px;
                        padding: 30px;
                        max-width: 500px;
                        text-align: center;
                        box-shadow: 0 4px 15px rgba(0, 0, 0, 0.5);
                    }
                    h1 { color: #f44336; margin-top: 0; }
                    p { line-height: 1.6; }
                    .code-block {
                        background: #0f140f;
                        color: #8bc34a;
                        padding: 10px;
                        font-family: monospace;
                        border-radius: 4px;
                        text-align: left;
                        overflow-x: auto;
                        margin: 15px 0;
                    }
                </style>
            </head>
            <body>
                <div class='error-container'>
                    <h1>Database Connection Error</h1>
                    <p>EleGuard Rail could not establish a connection to MySQL. Please verify your local XAMPP/MySQL services are running and configuration is correct.</p>
                    <div class='code-block'>
                        Error: " . htmlspecialchars($e->getMessage()) . "<br>
                        DSN: " . htmlspecialchars(DB_DSN) . "<br>
                        User: " . htmlspecialchars(DB_USER) . "
                    </div>
                    <p>Refer to <code>docs/setup-guide.md</code> to initialize database tables and seed credentials.</p>
                </div>
            </body>
            </html>";
            exit;
        }
    }

    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new Database();
        }
        return self::$instance;
    }

    public static function resetInstance() {
        self::$instance = null;
    }

    public function getConnection() {
        return $this->pdo;
    }
}
