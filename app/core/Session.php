<?php
/**
 * EleGuard Rail — Session Management Wrapper
 * Milestone 9C — Stage J Phase J.1 (TSK-J02 & RSK-J1-01 Remediation)
 *
 * Enforces session security controls:
 * 1. Explicit HttpOnly cookie flag (mitigating XSS session theft).
 * 2. Explicit SameSite=Lax cookie attribute (mitigating CSRF).
 * 3. Dynamic, environment-aware Secure cookie attribute (enabled over HTTPS, transparent on local HTTP).
 * 4. Configurable Trusted Proxy validation for X-Forwarded-Proto (preventing header spoofing and session DoS).
 * 5. Session ID regeneration capability to prevent session fixation.
 * 6. Full session invalidation and cookie expiration on logout.
 */

declare(strict_types=1);

class Session {
    
    /**
     * Configured trusted reverse proxy IPs and CIDR ranges.
     * @var string[]
     */
    private static array $trustedProxies = [];

    /**
     * Whether trusted proxies have been initialized.
     */
    private static bool $trustedProxiesLoaded = false;

    /**
     * Explicitly configure trusted reverse proxy IP addresses or CIDR blocks.
     * Example: Session::setTrustedProxies(['127.0.0.1', '10.0.0.0/8', '192.168.1.50', '::1']);
     * 
     * @param string[] $proxies
     */
    public static function setTrustedProxies(array $proxies): void {
        self::$trustedProxies = array_values(array_filter(array_map('trim', $proxies)));
        self::$trustedProxiesLoaded = true;
    }

    /**
     * Retrieve currently configured trusted proxies.
     * 
     * @return string[]
     */
    public static function getTrustedProxies(): array {
        self::loadTrustedProxiesFromEnv();
        return self::$trustedProxies;
    }

    /**
     * Reset trusted proxy configuration (primarily for testing and environment switching).
     */
    public static function resetTrustedProxies(): void {
        self::$trustedProxies = [];
        self::$trustedProxiesLoaded = false;
    }

    /**
     * Automatically loads trusted proxies from TRUSTED_PROXIES environment variable if not already set.
     */
    private static function loadTrustedProxiesFromEnv(): void {
        if (self::$trustedProxiesLoaded) {
            return;
        }
        self::$trustedProxiesLoaded = true;

        $envVal = getenv('TRUSTED_PROXIES') ?: ($_ENV['TRUSTED_PROXIES'] ?? (defined('TRUSTED_PROXIES') ? TRUSTED_PROXIES : null));
        if (is_string($envVal) && trim($envVal) !== '') {
            $parts = explode(',', $envVal);
            self::$trustedProxies = array_values(array_filter(array_map('trim', $parts)));
        } elseif (is_array($envVal)) {
            self::$trustedProxies = array_values(array_filter(array_map('trim', $envVal)));
        }
    }

    /**
     * Checks if a given IP matches a trusted proxy definition (exact IP or CIDR subnet).
     * 
     * @param string $ip The client IP to test
     * @param string $trusted The trusted definition (e.g. '127.0.0.1', '10.0.0.0/8', '::1')
     * @return bool
     */
    public static function isIpTrusted(string $ip, string $trusted): bool {
        $ip = trim($ip);
        $trusted = trim($trusted);

        if ($ip === '' || $trusted === '') {
            return false;
        }

        // 1. Exact string match (IPv4 or IPv6)
        if (strcasecmp($ip, $trusted) === 0) {
            return true;
        }

        // 2. Subnet / CIDR match (e.g. 192.168.1.0/24 or 10.0.0.0/8)
        if (strpos($trusted, '/') !== false) {
            [$subnet, $maskStr] = explode('/', $trusted, 2);
            $mask = is_numeric($maskStr) ? (int)$maskStr : -1;

            // IPv4 CIDR matching
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) &&
                filter_var($subnet, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                if ($mask < 0 || $mask > 32) {
                    return false;
                }
                if ($mask === 0) {
                    return true;
                }
                $ipLong = ip2long($ip);
                $subnetLong = ip2long($subnet);
                if ($ipLong === false || $subnetLong === false) {
                    return false;
                }
                $netmask = ~((1 << (32 - $mask)) - 1);
                return ($ipLong & $netmask) === ($subnetLong & $netmask);
            }

            // IPv6 CIDR matching
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) &&
                filter_var($subnet, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
                if ($mask < 0 || $mask > 128) {
                    return false;
                }
                if ($mask === 0) {
                    return true;
                }
                $ipBin = inet_pton($ip);
                $subnetBin = inet_pton($subnet);
                if ($ipBin === false || $subnetBin === false) {
                    return false;
                }
                $fullBytes = intdiv($mask, 8);
                $remainingBits = $mask % 8;

                if ($fullBytes > 0 && substr($ipBin, 0, $fullBytes) !== substr($subnetBin, 0, $fullBytes)) {
                    return false;
                }
                if ($remainingBits > 0) {
                    $byteMask = ~(0xFF >> $remainingBits) & 0xFF;
                    $ipByte = ord($ipBin[$fullBytes]);
                    $subnetByte = ord($subnetBin[$fullBytes]);
                    if (($ipByte & $byteMask) !== ($subnetByte & $byteMask)) {
                        return false;
                    }
                }
                return true;
            }
        }

        return false;
    }

    /**
     * Checks if the immediate request sender ($_SERVER['REMOTE_ADDR']) is an authorized trusted proxy.
     * Fails closed (returns false) if no trusted proxies are configured or REMOTE_ADDR is invalid.
     */
    public static function isRemoteAddrTrusted(): bool {
        self::loadTrustedProxiesFromEnv();
        if (empty(self::$trustedProxies)) {
            return false;
        }

        $remoteAddr = (string)($_SERVER['REMOTE_ADDR'] ?? '');
        if ($remoteAddr === '' || !filter_var($remoteAddr, FILTER_VALIDATE_IP)) {
            return false;
        }

        foreach (self::$trustedProxies as $trusted) {
            if (self::isIpTrusted($remoteAddr, $trusted)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Determines whether the current request is served over HTTPS/TLS.
     * 
     * Security controls:
     * - Native HTTPS flag and port 443 are unconditionally accepted.
     * - X-Forwarded-Proto is ONLY trusted if REMOTE_ADDR is an explicitly configured trusted proxy.
     */
    public static function isHttps(): bool {
        // 1. Native HTTPS server flag
        if (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') {
            return true;
        }

        // 2. Standard HTTPS port
        if (!empty($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443) {
            return true;
        }

        // 3. Forwarded protocol header — strictly conditional upon trusted proxy validation (RSK-J1-01)
        if (self::isRemoteAddrTrusted()) {
            if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string)$_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https') {
                return true;
            }
        }

        return false;
    }

    /**
     * Starts or resumes session with strict security cookie parameters.
     */
    public static function start(): void {
        if (session_status() === PHP_SESSION_NONE) {
            // Environment-aware HTTPS detection
            $isSecure = self::isHttps();

            // Set secure cookie options before session_start()
            session_set_cookie_params([
                'lifetime' => 0,
                'path'     => '/',
                'domain'   => '',
                'secure'   => $isSecure,
                'httponly' => true,
                'samesite' => 'Lax'
            ]);

            session_start();
        }
    }

    /**
     * Regenerates the session ID to mitigate session fixation attacks.
     * 
     * @param bool $deleteOldSession Whether to delete the old associated session data
     * @return bool True on success, false on failure
     */
    public static function regenerateId(bool $deleteOldSession = true): bool {
        self::start();
        if (session_status() === PHP_SESSION_ACTIVE) {
            return session_regenerate_id($deleteOldSession);
        }
        return false;
    }

    public static function set(string $key, $value): void {
        self::start();
        $_SESSION[$key] = $value;
    }

    public static function get(string $key, $default = null) {
        self::start();
        return $_SESSION[$key] ?? $default;
    }

    public static function has(string $key): bool {
        self::start();
        return isset($_SESSION[$key]);
    }

    public static function remove(string $key): void {
        self::start();
        if (isset($_SESSION[$key])) {
            unset($_SESSION[$key]);
        }
    }

    /**
     * Destroys session data and expires session cookie.
     */
    public static function destroy(): void {
        self::start();
        $_SESSION = [];
        if (ini_get("session.use_cookies")) {
            $params = session_get_cookie_params();
            $isSecure = self::isHttps();
            setcookie(session_name(), '', [
                'expires'  => time() - 42000,
                'path'     => $params["path"] ?: '/',
                'domain'   => $params["domain"] ?: '',
                'secure'   => $isSecure,
                'httponly' => true,
                'samesite' => 'Lax'
            ]);
        }
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
    }

    /**
     * Flash messages (retrieve once then delete)
     */
    public static function flash(string $key, ?string $message = null): ?string {
        self::start();
        if ($message !== null) {
            $_SESSION['flash'][$key] = $message;
            return null;
        }

        if (isset($_SESSION['flash'][$key])) {
            $msg = (string)$_SESSION['flash'][$key];
            unset($_SESSION['flash'][$key]);
            return $msg;
        }
        return null;
    }

    /**
     * CSRF Protection Tokens
     */
    public static function generateCsrfToken(): string {
        self::start();
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return (string)$_SESSION['csrf_token'];
    }

    public static function verifyCsrfToken(?string $token): bool {
        self::start();
        if (empty($_SESSION['csrf_token']) || empty($token)) {
            return false;
        }
        return hash_equals((string)$_SESSION['csrf_token'], $token);
    }
}
