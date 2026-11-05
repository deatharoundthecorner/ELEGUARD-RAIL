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
        try {
            $this->pdo = new PDO(DB_DSN, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
        } catch (PDOException $e) {
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

    public function getConnection() {
        return $this->pdo;
    }
}
