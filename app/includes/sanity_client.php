<?php
/**
 * Sanity CMS API Client
 * Sends queries to the Sanity HTTP API and handles errors gracefully.
 */

require_once __DIR__ . '/../config/sanity.php';

class SanityClient {

    /**
     * Executes a GROQ query on Sanity CDN or API
     * @param string $groqQuery The GROQ query string
     * @return array|null The result array on success, or null on failure/missing settings
     */
    public static function query($groqQuery) {
        // If Project ID is not configured, trigger fallback immediately
        if (empty(SANITY_PROJECT_ID)) {
            return null;
        }

        if (!function_exists('curl_init')) {
            return null;
        }

        $subdomain = SANITY_USE_CDN ? 'apicdn' : 'api';
        $encodedQuery = urlencode($groqQuery);
        $url = "https://" . SANITY_PROJECT_ID . ".{$subdomain}.sanity.io/v" . SANITY_API_VERSION . "/data/query/" . SANITY_DATASET . "?query=" . $encodedQuery;

        try {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 3); // 3 seconds timeout to avoid slowing page load
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // compatibility for local environments
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode === 200 && $response) {
                $decoded = json_decode($response, true);
                if (isset($decoded['result'])) {
                    return $decoded['result'];
                }
            }
        } catch (Throwable $e) {
            // Silently catch exceptions and errors to trigger fallback public content
        }
        
        return null;
    }
}
