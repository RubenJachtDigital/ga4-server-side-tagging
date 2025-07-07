<?php

namespace GA4ServerSideTagging\API;

use GA4ServerSideTagging\Core\GA4_Server_Side_Tagging_Logger;
use GA4ServerSideTagging\Utilities\GA4_Encryption_Util;

/**
 * REST API endpoint for GA4 Server-Side Tagging.
 *
 * @since      1.0.0
 * @package    GA4_Server_Side_Tagging
 */

if (!defined('WPINC')) {
    die;
}

/**
 * REST API endpoint for GA4 Server-Side Tagging.
 *
 * This class handles the REST API endpoint for server-side tagging.
 *
 * @since      1.0.0
 */
class GA4_Server_Side_Tagging_Endpoint
{

    /**
     * The logger instance.
     *
     * @since    1.0.0
     * @access   private
     * @var      GA4_Server_Side_Tagging_Logger    $logger    The logger instance.
     */
    private $logger;

    /**
     * Initialize the class.
     *
     * @since    1.0.0
     * @param    GA4_Server_Side_Tagging_Logger    $logger    The logger instance.
     */
    public function __construct(GA4_Server_Side_Tagging_Logger $logger)
    {
        $this->logger = $logger;
    }

    /**
     * Register the REST API routes.
     *
     * @since    1.0.0
     */
    public function register_routes()
    {
        register_rest_route('ga4-server-side-tagging/v1', '/send-event', array(
            'methods' => 'POST',
            'callback' => array($this, 'send_event'),
            'permission_callback' => array($this, 'check_strong_permission'),
        ));
    }

    /**
     * Strong permission check for secure config endpoint (enhanced security).
     *
     * @since    1.0.0
     * @param    \WP_REST_Request    $request    The request object.
     * @return   bool                           Whether the request has permission.
     */
    public function check_strong_permission($request)
    {

        // 1. Enhanced origin validation - STRICT check
        if (!$this->validate_request_origin($request)) {
            $this->log_security_failure($request, 'ORIGIN_VALIDATION_FAILED', 'Request origin validation failed');
            return false;
        }

        // 2. Bot detection - Block automated requests to secure config
        if ($this->is_bot_request($request)) {
            $this->log_security_failure($request, 'BOT_DETECTED', 'Bot or automated request detected');
            return false;
        }

        // 3. Enhanced security checks with stricter validation
        if (!$this->validate_enhanced_security($request)) {
            $this->log_security_failure($request, 'ENHANCED_SECURITY_CHECK_FAILED', 'Enhanced security validation failed');
            return false;
        }

        return true;
    }

    /**
     * Comprehensive bot detection for secure config endpoint.
     * 
     * Implements server-side bot detection similar to client-side patterns
     * to prevent bots from accessing sensitive configuration data.
     *
     * @since    1.0.0
     * @param    \WP_REST_Request    $request    The request object.
     * @return   bool                          True if request is from a bot, false otherwise.
     */
    private function is_bot_request($request)
    {
        // Allow bypassing bot detection in development mode
        if (defined('WP_DEBUG') && WP_DEBUG && get_option('ga4_disable_bot_detection', false)) {
            return false;
        }

        $user_agent = $request->get_header('user-agent');
        $client_ip = $this->get_client_ip($request);
        $referer = $request->get_header('referer');

        // Run multiple bot detection checks
        $bot_checks = array(
            $this->check_user_agent_patterns($user_agent),
            $this->check_known_bot_ips($client_ip),
            $this->check_suspicious_referrers($referer),
        );

        // If any check returns true, it's a bot
        $is_bot = in_array(true, $bot_checks, true);

        if ($is_bot) {
            // Log detailed bot detection information
            $detection_details = array(
                'user_agent_check' => $bot_checks[0],
                'headers_check' => $bot_checks[1],
                'ip_check' => $bot_checks[2],
                'referer_check' => $bot_checks[3],
                'behavior_check' => $bot_checks[4],
                'fingerprint_check' => $bot_checks[5]
            );

            $this->logger->log_data(array(
                'ip' => $client_ip,
                'user_agent' => $user_agent,
                'referer' => $referer,
                'detection_details' => $detection_details
            ), 'Bot detected attempting to access secure config', );
        }

        return $is_bot;
    }

    /**
     * Check user agent for bot patterns.
     *
     * @since    1.0.0
     * @param    string    $user_agent    The user agent string.
     * @return   bool                    True if bot detected.
     */
    private function check_user_agent_patterns($user_agent)
    {
        if (empty($user_agent) || strlen($user_agent) < 10) {
            return true; // Missing or suspiciously short user agent
        }

        // Comprehensive bot patterns (synchronized with Cloudflare Worker)
        $bot_patterns = array(
            // Common bots
            '/bot\b/i',
            '/crawl/i',
            '/spider/i',
            '/scraper/i',

            // Search engine bots - ENHANCED
            '/googlebot/i',
            '/bingbot/i',
            '/yahoo/i',
            '/duckduckbot/i',
            '/baiduspider/i',
            '/yandexbot/i',
            '/sogou/i',
            '/applebot/i', // Added - Apple's web crawler

            // Social media bots
            '/facebookexternalhit/i',
            '/twitterbot/i',
            '/linkedinbot/i',
            '/whatsapp/i',
            '/telegrambot/i',
            '/discordbot/i', // Added

            // SEO/monitoring tools
            '/semrushbot/i',
            '/ahrefsbot/i',
            '/mj12bot/i',
            '/dotbot/i',
            '/screaming frog/i',
            '/seobility/i',
            '/serpstatbot/i', // Added
            '/ubersuggest/i', // Added
            '/sistrix/i', // Added

            // Headless browsers and automation
            '/headlesschrome/i',
            '/phantomjs/i',
            '/slimerjs/i',
            '/htmlunit/i',
            '/selenium/i',
            '/webdriver/i', // Added
            '/puppeteer/i', // Added
            '/playwright/i', // Added
            '/cypress/i', // Added

            // Monitoring services
            '/pingdom/i',
            '/uptimerobot/i',
            '/statuscake/i',
            '/site24x7/i',
            '/newrelic/i',
            '/gtmetrix/i', // Added
            '/pagespeed/i',
            '/lighthouse/i', // Added
            '/chrome-lighthouse/i',

            // Generic automation and tools
            '/python/i',
            '/requests/i',
            '/curl/i',
            '/wget/i',
            '/apache-httpclient/i',
            '/java\//i',
            '/okhttp/i',
            '/node\.js/i',
            '/go-http-client/i',
            '/http_request/i', // Added
            '/ruby/i',
            '/perl/i',
            '/libwww/i',

            // AI/ML crawlers
            '/gptbot/i', // Added
            '/chatgpt/i', // Added
            '/claudebot/i', // Added
            '/anthropic/i', // Added
            '/openai/i', // Added
            '/perplexity/i', // Added
            '/cohere/i', // Added

            // Academic and research bots
            '/researchbot/i', // Added
            '/academicbot/i', // Added
            '/university/i', // Added

            // Suspicious patterns
            '/^mozilla\/5\.0$/i', // Just "Mozilla/5.0"
            '/compatible;?\s*$/i', // Ends with just "compatible"
            '/^\s*$/i', // Empty or whitespace only
            '/prerender/i'
        );

        foreach ($bot_patterns as $pattern) {
            if (preg_match($pattern, $user_agent)) {
                return true;
            }
        }

        // Check for suspicious user agent patterns
        if (preg_match('/^[a-z\s]+$/i', $user_agent)) {
            return true; // Too simple user agent
        }

        return false;
    }

    /**
     * Check if IP is from known bot/hosting providers.
     *
     * @since    1.0.0
     * @param    string    $ip    The client IP address.
     * @return   bool            True if IP is suspicious.
     */
    private function check_known_bot_ips($ip)
    {
        if (empty($ip)) {
            return false;
        }

        // Known bot IP ranges (simplified - in production you'd use a more comprehensive list)
        $bot_ip_ranges = array(
            '66.249.64.0/19',     // Googlebot
            '157.55.32.0/20',     // Bingbot
            '40.77.167.0/24',     // Bingbot
            '207.46.0.0/16',      // Bingbot
            '72.30.0.0/16',       // Yahoo
            '98.137.149.56/29',   // Yahoo
            '74.6.136.0/26',      // Yahoo
        );

        foreach ($bot_ip_ranges as $range) {
            if ($this->ip_in_range($ip, $range)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if IP is within a CIDR range.
     *
     * @since    1.0.0
     * @param    string    $ip      The IP to check.
     * @param    string    $range   The CIDR range.
     * @return   bool              True if IP is in range.
     */
    private function ip_in_range($ip, $range)
    {
        if (strpos($range, '/') === false) {
            return $ip === $range;
        }

        list($range_ip, $netmask) = explode('/', $range, 2);
        $range_decimal = ip2long($range_ip);
        $ip_decimal = ip2long($ip);
        $wildcard_decimal = pow(2, (32 - $netmask)) - 1;
        $netmask_decimal = ~$wildcard_decimal;

        return (($ip_decimal & $netmask_decimal) === ($range_decimal & $netmask_decimal));
    }

    /**
     * Check for suspicious referrer patterns.
     *
     * @since    1.0.0
     * @param    string    $referer    The referer header.
     * @return   bool                 True if suspicious referrer detected.
     */
    private function check_suspicious_referrers($referer)
    {
        if (empty($referer)) {
            return false; // Missing referer is not necessarily suspicious for API calls
        }

        $suspicious_patterns = array(
            '/google\.com\/search/i',
            '/bing\.com\/search/i',
            '/yahoo\.com\/search/i',
            '/bot/i',
            '/crawl/i',
            '/spider/i'
        );

        foreach ($suspicious_patterns as $pattern) {
            if (preg_match($pattern, $referer)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Send event to Cloudflare Worker with embedded config.
     *
     * @since    1.0.0
     * @param    \WP_REST_Request    $request    The request object.
     * @return   \WP_REST_Response               The response object.
     */
    public function send_event($request)
    {
        try {
            // Rate limiting check - 100 requests per minute per IP
            $rate_limit_check = $this->check_rate_limit($request);
            if (!$rate_limit_check['allowed']) {
                return new \WP_REST_Response(
                    array(
                        'error' => 'Rate limit exceeded',
                        'details' => 'Maximum 100 requests per minute allowed',
                        'retry_after' => $rate_limit_check['retry_after']
                    ),
                    429
                );
            }

            // Handle encrypted request if present (now supports temporary key encryption)
            $request_data = $this->handle_encrypted_request($request);

            // Validate required fields without modifying the payload
            if (empty($request_data)) {
                return new \WP_REST_Response(array('error' => 'Invalid request data'), 400);
            }

            // Check for event name (accept both 'name' and 'event_name' fields)
            if (empty($request_data['name']) || empty($request_data['event_name'])) {
                return new \WP_REST_Response(array('error' => 'Missing required field: event name'), 400);
            }

            // Check for required client_id and session_id in params
            $params = $request_data['params'] ?? array();
            if (empty($params['client_id'])) {
                return new \WP_REST_Response(array('error' => 'Missing required field: client_id'), 400);
            }

            if (empty($params['session_id'])) {
                return new \WP_REST_Response(array('error' => 'Missing required field: session_id'), 400);
            }

            // Get configuration
            $cloudflare_url = get_option('ga4_cloudflare_worker_url', '');
            $worker_api_key = \GA4ServerSideTagging\Utilities\GA4_Encryption_Util::retrieve_encrypted_key('ga4_worker_api_key') ?: get_option('ga4_worker_api_key', '');
            $encryption_enabled = (bool) get_option('ga4_jwt_encryption_enabled', false);
            $encryption_key = \GA4ServerSideTagging\Utilities\GA4_Encryption_Util::retrieve_encrypted_key('ga4_jwt_encryption_key') ?: '';
            
            // Debug logging: Check if keys from get_option are in encrypted format
            $raw_encryption_key_from_option = get_option('ga4_jwt_encryption_key', '');
            if (!empty($raw_encryption_key_from_option)) {
                // Check if the raw value looks like encrypted data (base64 encoded JSON)
                $is_encrypted_format = $this->is_key_in_encrypted_format($raw_encryption_key_from_option);
                if ($is_encrypted_format) {
                    $this->logger->info('Encryption key from get_option is in encrypted format (good - using retrieve_encrypted_key)');
                } else {
                    // Check if it's a valid hex key (plain text)
                    if (strlen($raw_encryption_key_from_option) === 64 && ctype_xdigit($raw_encryption_key_from_option)) {
                        $this->logger->warning('Encryption key from get_option is in plain text format (should be encrypted)');
                    } else {
                        $this->logger->error('Encryption key from get_option has invalid format: length=' . strlen($raw_encryption_key_from_option));
                    }
                }
            } else {
                $this->logger->info('No encryption key found in get_option (ga4_jwt_encryption_key is empty)');
            }
            
            // Debug logging: Check API key format too
            $raw_api_key_from_option = get_option('ga4_worker_api_key', '');
            if (!empty($raw_api_key_from_option)) {
                $is_api_key_encrypted = $this->is_key_in_encrypted_format($raw_api_key_from_option);
                if ($is_api_key_encrypted) {
                    $this->logger->info('Worker API key from get_option is in encrypted format (good - using retrieve_encrypted_key)');
                } else {
                    // Check if it looks like a valid API key (32+ chars, alphanumeric/dashes)
                    if (strlen($raw_api_key_from_option) >= 32 && preg_match('/^[a-zA-Z0-9\-_]+$/', $raw_api_key_from_option)) {
                        $this->logger->warning('Worker API key from get_option is in plain text format (should be encrypted)');
                    } else {
                        $this->logger->error('Worker API key from get_option has invalid format: length=' . strlen($raw_api_key_from_option));
                    }
                }
            } else {
                $this->logger->info('No worker API key found in get_option (ga4_worker_api_key is empty)');
            }

            if (empty($cloudflare_url) || empty($worker_api_key)) {
                return new \WP_REST_Response(array('error' => 'Configuration incomplete'), 500);
            }

            // Forward the complete original payload to Cloudflare (no modifications)
            $event_payload = $request_data;

            // Send to Cloudflare Worker
            $result = $this->forward_to_cloudflare($event_payload, $cloudflare_url, $worker_api_key, $encryption_enabled, $encryption_key, $request);

            if ($result['success']) {
                // Include worker response in the response back to client
                $response_data = array('success' => true);
                if (isset($result['worker_response'])) {
                    $response_data['worker_response'] = $result['worker_response'];
                }
                return new \WP_REST_Response($response_data, 200);
            } else {
                $this->logger->error('Failed to send event to Cloudflare: ' . $result['error']);
                return new \WP_REST_Response(array('error' => 'Event sending failed', 'details' => $result['error']), 500);
            }

        } catch (\Exception $e) {
            $this->logger->error('Failed to process send-event request: ' . $e->getMessage());
            return new \WP_REST_Response(array('error' => 'Processing error'), 500);
        }
    }


    /**
     * Log security failures to the main log file.
     *
     * @since    1.0.0
     * @param    \WP_REST_Request    $request      The request object.
     * @param    string            $failure_type Type of security failure.
     * @param    string            $message      Failure message.
     */
    private function log_security_failure($request, $failure_type, $message)
    {
        $client_ip = $this->get_client_ip($request);
        $user_agent = $request->get_header('user-agent');
        $endpoint = $request->get_route();

        $failure_data = array(
            'type' => 'SECURITY_FAILURE',
            'failure_type' => $failure_type,
            'message' => $message,
            'endpoint' => $endpoint,
            'ip' => $client_ip,
            'user_agent' => $user_agent,
            'referer' => $request->get_header('referer'),
            'origin' => $request->get_header('origin'),
            'timestamp' => current_time('mysql')
        );

        $this->logger->log_data($failure_data, 'Security Failure');
    }












    /**
     * Validate request origin with multiple checks.
     *
     * @since    1.0.0
     * @param    \WP_REST_Request    $request    The request object.
     * @return   bool                          Whether the origin is valid.
     */
    private function validate_request_origin($request)
    {
        $site_url = site_url();
        $site_host = wp_parse_url($site_url, PHP_URL_HOST);

        // Check Referer header
        $referer = $request->get_header('referer');
        if ($referer) {
            $referer_host = wp_parse_url($referer, PHP_URL_HOST);
            if ($referer_host === $site_host) {
                return true;
            }
        }

        // Check Origin header
        $origin = $request->get_header('origin');
        if ($origin) {
            $origin_host = wp_parse_url($origin, PHP_URL_HOST);
            if ($origin_host === $site_host) {
                return true;
            }
        }

        // Check payload origin as last resort (less secure)
        $params = $request->get_json_params();
        if (isset($params['page_origin'])) {
            $payload_origin = $params['page_origin'];
            if (wp_http_validate_url($payload_origin)) {
                $payload_host = wp_parse_url($payload_origin, PHP_URL_HOST);
                if ($payload_host === $site_host) {
                    // Only log failures, not fallback usage
                    return true;
                }
            }
        }

        $this->logger->error('Origin validation failed. Expected: ' . $site_host);
        return false;
    }


    /**
     * Get client IP address.
     *
     * @since    1.0.0
     * @param    \WP_REST_Request    $request    The request object.
     * @return   string                        The client IP address.
     */
    private function get_client_ip($request)
    {
        // Check for IP from various headers (in order of preference)
        $ip_headers = array(
            'HTTP_CF_CONNECTING_IP',     // Cloudflare
            'HTTP_X_REAL_IP',            // Nginx proxy
            'HTTP_X_FORWARDED_FOR',      // Load balancer
            'HTTP_X_FORWARDED',          // Proxy
            'HTTP_X_CLUSTER_CLIENT_IP',  // Cluster
            'HTTP_FORWARDED_FOR',        // Proxy
            'HTTP_FORWARDED',            // Proxy
            'REMOTE_ADDR'                // Standard
        );

        foreach ($ip_headers as $header) {
            if (!empty($_SERVER[$header])) {
                $ip = sanitize_text_field(wp_unslash($_SERVER[$header]));
                // Handle comma-separated IPs (take first one)
                if (strpos($ip, ',') !== false) {
                    $ip = trim(explode(',', $ip)[0]);
                }
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    return $ip;
                }
            }
        }

        return isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : '0.0.0.0';
    }

    /**
     * Check if User-Agent appears suspicious.
     *
     * @since    1.0.0
     * @param    string    $user_agent    The User-Agent string.
     * @return   bool                     Whether the User-Agent is suspicious.
     */
    private function is_suspicious_user_agent($user_agent)
    {
        $suspicious_patterns = array(
            '/bot/i',
            '/crawler/i',
            '/spider/i',
            '/scraper/i',
            '/curl/i',
            '/wget/i',
            '/python/i',
            '/postman/i',
            '/insomnia/i',
            '/automated/i'
        );

        foreach ($suspicious_patterns as $pattern) {
            if (preg_match($pattern, $user_agent)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Handle encrypted request data
     * 
     * @param \WP_REST_Request $request Request object
     * @return array|null Decrypted request data or original data
     */
    private function handle_encrypted_request($request)
    {
        $request_body = $request->get_json_params();


        // Check for time-based JWT encryption first
        if (isset($request_body['time_jwt']) && !empty($request_body['time_jwt'])) {
            // This is a time-based JWT encrypted request
            try {
                $decrypted_data = \GA4ServerSideTagging\Utilities\GA4_Encryption_Util::verify_time_based_jwt($request_body['time_jwt']);
                if ($decrypted_data === false) {
                    throw new \Exception('Time-based JWT verification failed');
                }

                return $decrypted_data;
            } catch (\Exception $e) {
                $this->logger->error('Failed to verify time-based JWT request: ' . $e->getMessage(), array(
                    'ip' => $this->get_client_ip($request)
                ));
                throw new \Exception('Failed to verify time-based JWT request: ' . $e->getMessage());
            }
        }

        // Fall back to legacy JWT encryption
        $encryption_enabled = get_option('ga4_jwt_encryption_enabled', false);
        $is_encrypted = GA4_Encryption_Util::is_encrypted_request($request);

        if (!$encryption_enabled || !$is_encrypted) {
            // Return original request body if not encrypted
            return $request_body;
        }

        // Use the configured encryption key from backend settings
        $encryption_key = \GA4ServerSideTagging\Utilities\GA4_Encryption_Util::retrieve_encrypted_key('ga4_jwt_encryption_key') ?: '';

        if (empty($encryption_key)) {
            throw new \Exception('Encryption is enabled but no encryption key is configured');
        }

        try {
            $decrypted_data = GA4_Encryption_Util::parse_encrypted_request($request_body, $encryption_key);

            $this->logger->info('JWT request verified successfully', array(
                'ip' => $this->get_client_ip($request)
            ));

            return $decrypted_data;

        } catch (\Exception $e) {
            $this->logger->error('Failed to verify JWT request: ' . $e->getMessage(), array(
                'ip' => $this->get_client_ip($request)
            ));

            throw new \Exception('JWT request verification failed: ' . $e->getMessage());
        }
    }


    /**
     * Enhanced security validation with stricter checks.
     *
     * @since    1.0.0
     * @param    \WP_REST_Request    $request    The request object.
     * @return   bool                          Whether the request passes enhanced security.
     */
    private function validate_enhanced_security($request)
    {
        // 1. Require HTTPS for secure config (except in development)
        if (!is_ssl() && wp_get_environment_type() !== 'development') {
            $this->logger->error('Enhanced security failed: Non-HTTPS connection');
            return false;
        }

        // 2. Check for suspicious User-Agent patterns (stricter)
        $user_agent = $request->get_header('user-agent');
        if (empty($user_agent)) {
            $this->logger->error('Enhanced security failed: Missing User-Agent header');
            return false;
        }
        if ($this->is_suspicious_user_agent($user_agent)) {
            $this->logger->error('Enhanced security failed: Suspicious User-Agent pattern');
            return false;
        }

        // 3. Require essential browser headers (relaxed for now)
        $essential_headers = array('accept');
        foreach ($essential_headers as $header) {
            if (empty($request->get_header($header))) {
                $this->logger->error("Enhanced security failed: Missing essential header: {$header}");
                return false;
            }
        }

        // Log other headers for debugging (only in development)
        if (wp_get_environment_type() === 'development') {
            $accept_language = $request->get_header('accept-language');
            $accept_encoding = $request->get_header('accept-encoding');
            $this->logger->error("Debug headers - Accept-Language: '{$accept_language}', Accept-Encoding: '{$accept_encoding}'");
        }

        // 4. Check request method is POST only
        if ($request->get_method() !== 'POST') {
            $this->logger->error('Enhanced security failed: Invalid request method: ' . $request->get_method());
            return false;
        }

        // 5. Validate no suspicious query parameters
        $query_params = $request->get_query_params();
        $suspicious_params = array('cmd', 'exec', 'system', 'eval', 'base64', 'shell');
        foreach ($suspicious_params as $param) {
            if (isset($query_params[$param])) {
                $this->logger->error("Enhanced security failed: Suspicious query parameter: {$param}");
                return false;
            }
        }

        return true;
    }








    /**
     * Forward event to Cloudflare Worker.
     *
     * @since    1.0.0
     * @param    array     $event_payload      The event data.
     * @param    string    $cloudflare_url     The Cloudflare Worker URL.
     * @param    string    $worker_api_key     The Worker API key.
     * @param    bool      $encryption_enabled Whether encryption is enabled.
     * @param    string    $encryption_key     The encryption key.
     * @param    \WP_REST_Request $original_request The original client request.
     * @return   array                         Success/failure result.
     */
    private function forward_to_cloudflare($event_payload, $cloudflare_url, $worker_api_key, $encryption_enabled, $encryption_key, $original_request = null)
    {
        try {
            // Ensure Cloudflare Worker URL uses HTTPS
            if (!empty($cloudflare_url) && strpos($cloudflare_url, 'https://') !== 0) {
                return array('success' => false, 'error' => 'Cloudflare Worker URL must use HTTPS protocol for security');
            }
            // Use the API key as-is for Authorization header (already decrypted from database)
            $auth_header_value = $worker_api_key;
            
            // Debug logging: Log the exact API key being sent to Cloudflare (first 20 characters for security)
            $this->logger->info('Sending API key to Cloudflare - Full length: ' . strlen($auth_header_value) . ', Preview: ' . substr($auth_header_value, 0, 20) . '...');
            
            // Check if the API key looks like it might still be encrypted (base64 JSON format)
            if (strlen($auth_header_value) > 100 || strpos($auth_header_value, 'eyJ') === 0) {
                $this->logger->warning('API key appears to still be in encrypted/JWT format when sending to Cloudflare');
                $this->logger->warning('This suggests the key was not properly decrypted from database storage');
            }
            
            // Also log what we got from the database before decryption
            $raw_api_key_from_db = get_option('ga4_worker_api_key', '');
            $this->logger->info('Raw API key from database - Length: ' . strlen($raw_api_key_from_db) . ', Preview: ' . substr($raw_api_key_from_db, 0, 20) . '...');

            $headers = array(
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $auth_header_value
            );

            // Forward Origin, Referer, and User-Agent headers from the original request to Cloudflare Worker
            if ($original_request) {
                $origin = $original_request->get_header('origin');
                $referer = $original_request->get_header('referer');
                $user_agent = $original_request->get_header('user-agent');

                if ($origin) {
                    $headers['Origin'] = $origin;
                }

                if ($referer) {
                    $headers['Referer'] = $referer;
                }

                if ($user_agent) {
                    $headers['User-Agent'] = $user_agent;
                }
            }

            $body = $event_payload;

            // Encrypt payload if encryption is enabled
            if ($encryption_enabled && !empty($encryption_key)) {
                try {
                    // Use the configured encryption key from backend settings
                    $encrypted_payload = GA4_Encryption_Util::create_encrypted_response($body, $encryption_key);
                    $body = $encrypted_payload;

                    // Add encryption header for Cloudflare Worker
                    $headers['X-Encrypted'] = 'true';
                } catch (\Exception $e) {
                    $this->logger->error('Event encryption failed: ' . $e->getMessage());
                    // Continue with unencrypted payload as fallback
                }
            }

            // Send to Cloudflare Worker
            $response = wp_remote_post($cloudflare_url, array(
                'timeout' => 30,
                'headers' => $headers,
                'body' => wp_json_encode($body)
            ));

            if (is_wp_error($response)) {
                return array('success' => false, 'error' => $response->get_error_message());
            }

            $response_code = wp_remote_retrieve_response_code($response);
            $response_body = wp_remote_retrieve_body($response);

            if ($response_code >= 200 && $response_code < 300) {
                // Parse response body if it's JSON
                $parsed_response = json_decode($response_body, true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    // Check if response is encrypted (JWT format)
                    if (isset($parsed_response['jwt']) && $encryption_enabled && !empty($encryption_key)) {
                        try {
                            // Decrypt the JWT response from Cloudflare Worker
                            $decrypted_response = GA4_Encryption_Util::decrypt($parsed_response['jwt'], $encryption_key);
                            if ($decrypted_response !== false) {
                                // Parse the decrypted JSON
                                $decrypted_data = json_decode($decrypted_response, true);
                                if (json_last_error() === JSON_ERROR_NONE) {
                                    return array('success' => true, 'worker_response' => $decrypted_data);
                                } else {
                                    return array('success' => true, 'worker_response' => $decrypted_response);
                                }
                            } else {
                                // Decryption failed, return original encrypted response
                                $this->logger->warning('Failed to decrypt response from Cloudflare Worker');
                                return array('success' => true, 'worker_response' => $parsed_response);
                            }
                        } catch (\Exception $e) {
                            // Decryption error, return original response
                            $this->logger->error('Error decrypting Cloudflare response: ' . $e->getMessage());
                            return array('success' => true, 'worker_response' => $parsed_response);
                        }
                    } else {
                        // Response is not encrypted or encryption not enabled
                        return array('success' => true, 'worker_response' => $parsed_response);
                    }
                } else {
                    return array('success' => true, 'worker_response' => $response_body);
                }
            } else {
                return array('success' => false, 'error' => 'HTTP ' . $response_code . ': ' . $response_body);
            }

        } catch (\Exception $e) {
            return array('success' => false, 'error' => $e->getMessage());
        }
    }

    /**
     * Check rate limit for send-event endpoint (100 requests per minute per IP)
     *
     * @since    1.0.0
     * @param    \WP_REST_Request    $request    The request object.
     * @return   array                         Rate limit check result.
     */
    private function check_rate_limit($request)
    {
        $client_ip = $this->get_client_ip($request);
        $current_time = time();
        $rate_limit_window = 60; // 1 minute in seconds
        $max_requests = 100; // Maximum requests per minute

        // Get rate limit data from transients (temporary storage)
        $transient_key = 'ga4_rate_limit_' . md5($client_ip);
        $rate_data = get_transient($transient_key);

        if ($rate_data === false) {
            // First request from this IP in the current window
            $rate_data = array(
                'count' => 1,
                'window_start' => $current_time,
                'requests' => array($current_time)
            );
            set_transient($transient_key, $rate_data, $rate_limit_window);
            return array('allowed' => true, 'remaining' => $max_requests - 1, 'retry_after' => 0);
        }

        // Clean up old requests outside the current window
        $window_start = $current_time - $rate_limit_window;
        $rate_data['requests'] = array_filter($rate_data['requests'], function ($timestamp) use ($window_start) {
            return $timestamp > $window_start;
        });

        // Update count based on cleaned requests
        $rate_data['count'] = count($rate_data['requests']);

        // Check if rate limit exceeded
        if ($rate_data['count'] >= $max_requests) {
            // Calculate retry after time (seconds until oldest request expires)
            $oldest_request = min($rate_data['requests']);
            $retry_after = max(0, $oldest_request + $rate_limit_window - $current_time);

            // Log rate limit violation
            $this->logger->warning('Rate limit exceeded for IP: ' . $client_ip . ' (' . $rate_data['count'] . ' requests in last minute)');

            return array(
                'allowed' => false,
                'remaining' => 0,
                'retry_after' => $retry_after
            );
        }

        // Add current request to the list
        $rate_data['requests'][] = $current_time;
        $rate_data['count'] = count($rate_data['requests']);

        // Update transient with new data
        set_transient($transient_key, $rate_data, $rate_limit_window);

        return array(
            'allowed' => true,
            'remaining' => $max_requests - $rate_data['count'],
            'retry_after' => 0
        );
    }

    /**
     * Check if a key is in encrypted format (base64 encoded JSON)
     *
     * @since    1.0.0
     * @param    string    $key    The key to check.
     * @return   bool             True if key appears to be encrypted.
     */
    private function is_key_in_encrypted_format( $key ) {
        // Encrypted keys are base64 encoded JSON with specific structure
        try {
            // Try to decode as base64
            $decoded = base64_decode( $key, true );
            if ( $decoded === false ) {
                return false;
            }
            
            // Try to parse as JSON
            $json_data = json_decode( $decoded, true );
            if ( json_last_error() !== JSON_ERROR_NONE ) {
                return false;
            }
            
            // Check if it has the expected encrypted key structure
            if ( is_array( $json_data ) && isset( $json_data['data'], $json_data['iv'], $json_data['tag'] ) ) {
                return true;
            }
            
            return false;
        } catch ( \Exception $e ) {
            return false;
        }
    }

}