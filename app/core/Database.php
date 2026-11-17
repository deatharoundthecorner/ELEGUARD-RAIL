<?php
/**
 * Database Core class (Singleton)
 * Provides a unified connection instance using PDO.
 */

require_once __DIR__ . '/../config/database.php';

class Database {
    private static $instance = null;
    private static $analyticsInstance = null;
    private $pdo;
    private $analyticsPdo = null;

    private function __construct() {
        // Enforce Windows-compatible database safety if running in a test context
        $rawScript = $_SERVER['SCRIPT_FILENAME'] ?? ($_SERVER['argv'][0] ?? '');
        $normalizedScript = str_replace('\\', '/', realpath($rawScript) ?: $rawScript);
        $isTestContext = (defined('ELEGUARD_TEST_ENVIRONMENT') && ELEGUARD_TEST_ENVIRONMENT === true) ||
                         (getenv('ELEGUARD_TEST_CONTEXT') === '1') ||
                         (($_ENV['ELEGUARD_TEST_CONTEXT'] ?? '') === '1') ||
                         (PHP_SAPI === 'cli' && (
                             strpos($normalizedScript, '/tests/') !== false ||
                             strpos(strtolower($normalizedScript), 'run_all_tests.php') !== false ||
                             strpos(strtolower($normalizedScript), 'concurrent_submit_worker.php') !== false
                         ));

        if ($isTestContext) {
            $dbName = getenv('DB_NAME') ?: ($_ENV['DB_NAME'] ?? (defined('DB_NAME') ? DB_NAME : null));
            if (empty($dbName)) {
                throw new RuntimeException("DATABASE SAFETY VIOLATION: DB_NAME is missing or empty in test execution. Tests must explicitly target an approved disposable test database (e.g. 'eleguard_test_tmp_*').");
            }
            $trimmedDb = strtolower(trim($dbName));
            if ($trimmedDb === 'eleguard_rail') {
                throw new RuntimeException("FATAL DATABASE SAFETY VIOLATION: Automated test attempted to connect to operational database 'eleguard_rail'. Execution halted.");
            }
            if ($trimmedDb === 'eleguard_rail_test') {
                throw new RuntimeException("FATAL DATABASE SAFETY VIOLATION: Automated test attempted to connect to protected persistent test database 'eleguard_rail_test'. Tests must target a unique disposable test database.");
            }
            $isApprovedDisposable = preg_match('/^eleguard_test_tmp_[a-f0-9]{6,16}$/i', $trimmedDb) ||
                                   preg_match('/^eleguard_disposable_[a-z0-9_]+$/i', $trimmedDb) ||
                                   $trimmedDb === 'eleguard_migration_test' ||
                                   $trimmedDb === 'eleguard_rail_test_disposable';
            if (!$isApprovedDisposable) {
                throw new RuntimeException("DATABASE SAFETY VIOLATION: Database '{$dbName}' is not an approved test database. Tests must target an approved disposable database.");
            }

            // Enforce restricted test writer credentials: strictly require DB_TEST_USER
            $testUser = getenv('DB_TEST_USER') ?: (defined('DB_TEST_USER') && DB_TEST_USER ? DB_TEST_USER : null);
            $testPass = getenv('DB_TEST_PASS') !== false ? getenv('DB_TEST_PASS') : (defined('DB_TEST_PASS') ? DB_TEST_PASS : '');

            if (empty($testUser)) {
                throw new RuntimeException("DATABASE CONFIGURATION ERROR: Dedicated test writer user (DB_TEST_USER) is not configured. Silently falling back to administrative credentials is strictly prohibited.");
            }
            if (strtolower(trim($testUser)) === 'root') {
                throw new RuntimeException("DATABASE SECURITY VIOLATION: Application test execution using 'root' is strictly prohibited.");
            }

            $activeUser = $testUser;
            $activePass = $testPass;

            $h = getenv('DB_HOST') ?: (defined('DB_HOST') ? DB_HOST : '127.0.0.1');
            $p = getenv('DB_PORT') ?: (defined('DB_PORT') ? DB_PORT : 3308);
            $dsn = "mysql:host={$h};port={$p};dbname={$dbName};charset=utf8mb4";
        } else {
            // Production Context
            $dbName = defined('DB_NAME') ? DB_NAME : 'eleguard_rail';
            if (strtolower(trim($dbName)) === 'eleguard_rail_test' || preg_match('/^eleguard_test_tmp_/i', strtolower(trim($dbName)))) {
                throw new RuntimeException("DATABASE SAFETY VIOLATION: Production connection cannot target test database '{$dbName}'.");
            }
            $activeUser = DB_USER;
            $activePass = DB_PASS;
            $dsn = DB_DSN;
        }

        try {
            $this->pdo = new PDO($dsn, $activeUser, $activePass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
        } catch (PDOException $e) {
            if ($isTestContext || PHP_SAPI === 'cli') {
                throw new RuntimeException("Database connection failed: " . $e->getMessage(), 0, $e);
            }

            // Log technical failure details securely without disclosing to client
            try {
                error_log("Database connection failed: " . $e->getMessage() . " | DSN: " . $dsn);
            } catch (Throwable $t) {
                // Fail-safe: ensure logging error does not bubble unhandled
            }

            if (!headers_sent()) {
                http_response_code(500);
            }

            // Render a generic, friendly error screen if database connection fails
            $appName = 'EleGuard Rail';
            echo "<!DOCTYPE html>
            <html lang='en'>
            <head>
                <meta charset='UTF-8'>
                <meta name='viewport' content='width=device-width, initial-scale=1.0'>
                <title>Database Service Unavailable - {$appName}</title>
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
                </style>
            </head>
            <body>
                <div class='error-container'>
                    <h1>Database Service Unavailable</h1>
                    <p>EleGuard Rail could not establish a connection to the database service. Technical details have been logged for administrative investigation.</p>
                    <p>Please verify system services are operational, or contact technical support if the issue persists.</p>
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
        self::$analyticsInstance = null;
    }

    public function getConnection() {
        return $this->pdo;
    }

    /**
     * Obtains a dedicated read-only database connection for analytics queries.
     * Enforces engine-level read-only safety via the DB_ANALYTICS_USER account.
     * 
     * In test environments, strictly connects to the isolated test database.
     * In operational web contexts, strictly connects using the dedicated read-only account.
     * Fails closed if the read-only user is unconfigured; silent fallback is prohibited.
     * 
     * @return PDO
     * @throws RuntimeException
     */
    public static function getAnalyticsConnection() {
        if (self::$analyticsInstance !== null && self::$analyticsInstance->analyticsPdo !== null) {
            return self::$analyticsInstance->analyticsPdo;
        }

        $script = isset($_SERVER['SCRIPT_FILENAME']) ? str_replace('\\', '/', realpath($_SERVER['SCRIPT_FILENAME']) ?: $_SERVER['SCRIPT_FILENAME']) : '';
        $isTestContext = defined('ELEGUARD_TEST_ENVIRONMENT') || 
                         (PHP_SAPI === 'cli' && strpos($script, '/tests/') !== false);

        $dbName = defined('DB_NAME') ? DB_NAME : 'eleguard_rail';
        if ($isTestContext) {
            $dbName = getenv('DB_NAME') ?: ($_ENV['DB_NAME'] ?? (defined('DB_NAME') ? DB_NAME : null));
            if (empty($dbName)) {
                throw new RuntimeException("DATABASE SAFETY VIOLATION: DB_NAME is missing or empty in test execution. Tests must explicitly target an approved disposable test database (e.g. 'eleguard_test_tmp_*').");
            }
            $trimmedDb = strtolower(trim($dbName));
            if ($trimmedDb === 'eleguard_rail') {
                throw new RuntimeException("FATAL DATABASE SAFETY VIOLATION: Automated test attempted to connect to operational database 'eleguard_rail'. Execution halted.");
            }
            if ($trimmedDb === 'eleguard_rail_test') {
                throw new RuntimeException("FATAL DATABASE SAFETY VIOLATION: Automated test attempted to connect to protected persistent test database 'eleguard_rail_test'. Tests must target a unique disposable test database.");
            }
            $isApprovedDisposable = preg_match('/^eleguard_test_tmp_[a-f0-9]{6,16}$/i', $trimmedDb) ||
                                   preg_match('/^eleguard_disposable_[a-z0-9_]+$/i', $trimmedDb) ||
                                   $trimmedDb === 'eleguard_migration_test' ||
                                   $trimmedDb === 'eleguard_rail_test_disposable';
            if (!$isApprovedDisposable) {
                throw new RuntimeException("DATABASE SAFETY VIOLATION: Database '{$dbName}' is not an approved test database. Tests must target an approved disposable database.");
            }

            $analyticsUser = getenv('DB_TEST_ANALYTICS_USER') ?: (defined('DB_TEST_ANALYTICS_USER') && DB_TEST_ANALYTICS_USER ? DB_TEST_ANALYTICS_USER : null);
            $analyticsPass = getenv('DB_TEST_ANALYTICS_PASS') !== false ? getenv('DB_TEST_ANALYTICS_PASS') : (defined('DB_TEST_ANALYTICS_PASS') ? DB_TEST_ANALYTICS_PASS : '');

            if (empty($analyticsUser)) {
                throw new RuntimeException("DATABASE CONFIGURATION ERROR: Dedicated test read-only database user (DB_TEST_ANALYTICS_USER) is not configured. Silently falling back to write-capable credentials is prohibited.");
            }
        } else {
            // Production Web Context
            if (strtolower(trim($dbName)) === 'eleguard_rail_test' || preg_match('/^eleguard_test_tmp_/i', strtolower(trim($dbName)))) {
                throw new RuntimeException("DATABASE SAFETY VIOLATION: Production analytics connection cannot target test database '{$dbName}'.");
            }

            $analyticsUser = getenv('DB_ANALYTICS_USER') ?: (defined('DB_ANALYTICS_USER') && DB_ANALYTICS_USER ? DB_ANALYTICS_USER : null);
            $analyticsPass = getenv('DB_ANALYTICS_PASS') !== false ? getenv('DB_ANALYTICS_PASS') : (defined('DB_ANALYTICS_PASS') ? DB_ANALYTICS_PASS : '');

            if (empty($analyticsUser)) {
                throw new RuntimeException("DATABASE CONFIGURATION ERROR: Dedicated read-only database user (DB_ANALYTICS_USER) is not configured. Silently falling back to write-capable credentials is prohibited.");
            }
        }

        $h = getenv('DB_HOST') ?: (defined('DB_HOST') ? DB_HOST : '127.0.0.1');
        $p = getenv('DB_PORT') ?: (defined('DB_PORT') ? DB_PORT : 3308);
        $dsn = "mysql:host={$h};port={$p};dbname={$dbName};charset=utf8mb4";

        try {
            $pdo = new PDO($dsn, $analyticsUser, $analyticsPass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
            if (self::$analyticsInstance === null) {
                self::$analyticsInstance = new self();
            }
            self::$analyticsInstance->analyticsPdo = $pdo;
            return $pdo;
        } catch (PDOException $e) {
            throw new RuntimeException("Read-only analytics database connection failed for user '{$analyticsUser}': " . $e->getMessage(), 0, $e);
        }
    }
}
