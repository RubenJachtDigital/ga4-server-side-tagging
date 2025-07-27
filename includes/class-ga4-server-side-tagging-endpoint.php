<?php

namespace GA4ServerSideTagging\API;

use GA4ServerSideTagging\Core\GA4_Server_Side_Tagging_Logger;
use GA4ServerSideTagging\Utilities\GA4_Encryption_Util;
use GA4ServerSideTagging\Core\GA4_Cronjob_Manager;
use GA4ServerSideTagging\Core\GA4_Event_Logger;

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
     * Performance mode enabled - skips heavy validation for faster response
     *
     * @since    1.0.0
     * @access   private
     * @var      bool    $performance_mode_enabled    Whether performance mode is enabled.
     */
    private $performance_mode_enabled = false;

    /**
     * Session key for storing validation status
     *
     * @since    1.0.0
     * @access   private
     * @var      string    $validation_session_key    Session key for tracking validation.
     */
    private $validation_session_key = 'ga4_session_validated';

    /**
     * The cronjob manager instance.
     *
     * @since    2.0.0
     * @access   private
     * @var      GA4_Cronjob_Manager    $cronjob_manager    Handles event queuing.
     */
    private $cronjob_manager;

    /**
     * The event logger instance.
     *
     * @since    2.1.0
     * @access   private
     * @var      GA4_Event_Logger    $event_logger    Handles comprehensive event logging.
     */
    private $event_logger;

    /**
     * Initialize the class.
     *
     * @since    1.0.0
     * @param    GA4_Server_Side_Tagging_Logger    $logger    The logger instance.
     */
    public function __construct(GA4_Server_Side_Tagging_Logger $logger)
    {
        $this->logger = $logger;
        $this->cronjob_manager = new GA4_Cronjob_Manager($logger);
        $this->event_logger = new GA4_Event_Logger();
        
        // Ensure session is started for validation tracking
        if (!session_id()) {
            session_start();
        }
    }

    /**
     * Register the REST API routes.
     *
     * @since    1.0.0
     */
    public function register_routes()
    {
        // Events endpoint (supports both single events and batches)
        register_rest_route('ga4-server-side-tagging/v1', '/send-events', array(
            'methods' => 'POST',
            'callback' => array($this, 'send_events'),
            'permission_callback' => array($this, 'check_strong_permission'),
        ));

        // Encrypted events endpoint (for sendBeacon compatibility - no X-Encrypted header needed)
        register_rest_route('ga4-server-side-tagging/v1', '/send-events/encrypted', array(
            'methods' => 'POST',
            'callback' => array($this, 'send_events_encrypted'),
            'permission_callback' => array($this, 'check_strong_permission'),
        ));

    }


    /**
     * Strong permission check for secure config endpoint (enhanced security).
     *
     * @since    1.0.0
     * @param    \WP_REST_Request    $request    The request object.
     * @return   bool|\WP_Error                  Whether the request has permission or error with fresh nonce.
     */
    public function check_strong_permission($request)
    {
        // Check if this session has already passed heavy validation
        $session_validated = isset($_SESSION[$this->validation_session_key]) && $_SESSION[$this->validation_session_key] === true;

        if ($session_validated && $this->performance_mode_enabled) {
            // Session already validated - perform only basic origin check            
            if (!$this->validate_request_origin($request)) {
                $this->log_security_failure($request, 'ORIGIN_VALIDATION_FAILED', 'Request origin validation failed (basic check)');
                return false;
            }
            
            return true; // Fast path for validated sessions
        }

        
        // 1. Enhanced origin validation - STRICT check
        if (!$this->validate_request_origin($request)) {
            $this->log_security_failure($request, 'ORIGIN_VALIDATION_FAILED', 'Request origin validation failed (heavy check)');
            return false;
        }

        // 2. Bot detection - Block automated requests
        if ($this->is_bot_request($request)) {
            $this->log_security_failure($request, 'BOT_DETECTED', 'Bot or automated request detected');
            return false;
        }

        // 3. Enhanced security checks with stricter validation
        if (!$this->validate_enhanced_security($request)) {
            $this->log_security_failure($request, 'ENHANCED_SECURITY_CHECK_FAILED', 'Enhanced security validation failed');
            return false;
        }

        // All heavy validation passed - mark session as validated
        $_SESSION[$this->validation_session_key] = true;

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
            $this->check_missing_headers($request),
            $this->check_suspicious_asn($client_ip),
            $this->check_behavioral_patterns($request)
        );

        // Require at least 2 positive checks to classify as bot (same as Cloudflare worker)
        $positive_checks = array_filter($bot_checks, function($check) { return $check === true; });
        $is_bot = count($positive_checks) >= 2;

        if ($is_bot) {
            // Log detailed bot detection information
            $detection_details = array(
                'user_agent_check' => $bot_checks[0],
                'ip_check' => $bot_checks[1],
                'referer_check' => $bot_checks[2],
                'headers_check' => $bot_checks[3],
                'asn_check' => $bot_checks[4],
                'behavior_check' => $bot_checks[5],
                'positive_checks_count' => count($positive_checks)
            );

            // Log the bot detection with context from the calling method
            $context = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2)[1]['function'] ?? 'unknown';
            $message = ($context === 'send_events') ? 'Bot detected attempting to send events' : 'Bot detected attempting to access secure config';
            
            $this->logger->bot_detected($message, json_encode(array(
                'ip' => $client_ip,
                'user_agent' => $user_agent,
                'referer' => $referer,
                'detection_details' => $detection_details,
                'context' => $context
            )));
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
     * Check for missing essential headers (similar to Cloudflare worker).
     *
     * @since    1.0.0
     * @param    \WP_REST_Request    $request    The request object.
     * @return   bool                          True if suspicious headers detected.
     */
    private function check_missing_headers($request)
    {
        $essential_headers = array(
            'accept',
            'accept-language',
            'accept-encoding'
        );

        $missing_count = 0;
        foreach ($essential_headers as $header) {
            if (empty($request->get_header($header))) {
                $missing_count++;
            }
        }

        // Missing 2 or more essential headers is suspicious
        if ($missing_count >= 2) {
            return true;
        }

        // Check for suspicious header values
        $accept = $request->get_header('accept');
        if ($accept === '*/*') {
            return true; // Too generic
        }

        $connection = $request->get_header('connection');
        if ($connection === 'close') {
            return true; // Suspicious connection type
        }

        // Check for automation indicators in headers
        $x_forwarded_for = $request->get_header('x-forwarded-for');
        if ($x_forwarded_for && stripos($x_forwarded_for, 'crawler') !== false) {
            return true;
        }

        // Check for "from" header (bot indicator)
        if (!empty($request->get_header('from'))) {
            return true;
        }

        return false;
    }

    /**
     * Check for suspicious ASN/hosting providers (similar to Cloudflare worker).
     *
     * @since    1.0.0
     * @param    string    $ip    The client IP address.
     * @return   bool            True if suspicious ASN detected.
     */
    private function check_suspicious_asn($ip)
    {
        if (empty($ip)) {
            return false;
        }

        // Known bot/hosting provider IP ranges (expanded from Cloudflare worker)
        $suspicious_ip_ranges = array(
            // Cloud providers commonly used by bots
            '13.107.42.0/24',     // Microsoft Azure
            '20.36.0.0/14',       // Microsoft Azure
            '40.74.0.0/15',       // Microsoft Azure
            '52.0.0.0/11',        // Amazon AWS
            '54.0.0.0/15',        // Amazon AWS
            '35.0.0.0/8',         // Google Cloud
            '34.0.0.0/9',         // Google Cloud
            '104.16.0.0/12',      // Cloudflare
            '172.64.0.0/13',      // Cloudflare
            '173.245.48.0/20',    // Cloudflare
            '103.21.244.0/22',    // Cloudflare
            '103.22.200.0/22',    // Cloudflare
            '103.31.4.0/22',      // Cloudflare
            '141.101.64.0/18',    // Cloudflare
            '108.162.192.0/18',   // Cloudflare
            '190.93.240.0/20',    // Cloudflare
            '188.114.96.0/20',    // Cloudflare
            '197.234.240.0/22',   // Cloudflare
            '198.41.128.0/17',    // Cloudflare
            '162.158.0.0/15',     // Cloudflare
            '104.24.0.0/14',      // Cloudflare
            '172.67.0.0/16',      // Cloudflare
            '131.0.72.0/22'       // Cloudflare
        );

        foreach ($suspicious_ip_ranges as $range) {
            if ($this->ip_in_range($ip, $range)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check for suspicious behavioral patterns (similar to Cloudflare worker).
     *
     * @since    1.0.0
     * @param    \WP_REST_Request    $request    The request object.
     * @return   bool                          True if suspicious behavior detected.
     */
    private function check_behavioral_patterns($request)
    {
        $user_agent = $request->get_header('user-agent');
        
        // Check for automation tools in user agent
        $automation_patterns = array(
            '/curl/i',
            '/wget/i',
            '/python/i',
            '/node/i',
            '/automation/i',
            '/postman/i',
            '/insomnia/i',
            '/selenium/i',
            '/webdriver/i',
            '/puppeteer/i',
            '/playwright/i',
            '/phantom/i'
        );

        foreach ($automation_patterns as $pattern) {
            if (preg_match($pattern, $user_agent)) {
                return true;
            }
        }

        // Check content type
        $content_type = $request->get_header('content-type');
        if ($content_type && $content_type !== 'application/json') {
            return true; // Expecting JSON for this endpoint
        }

        // Check for missing origin and referer (both missing is suspicious)
        $origin = $request->get_header('origin');
        $referer = $request->get_header('referer');
        if (empty($origin) && empty($referer)) {
            return true;
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
     * Send events to Cloudflare Worker via encrypted endpoint (batch processing only).
     *
     * @since    1.0.0
     * @param    \WP_REST_Request    $request    The request object.
     * @return   \WP_REST_Response               The response object.
     */
    public function send_events_encrypted($request)
    {
        // Force the request to be treated as encrypted for processing
        // This allows sendBeacon to work without X-Encrypted header
        $original_body = $request->get_json_params();
        
        // SECURITY: For encrypted endpoint, we use enhanced session validation instead of URL nonces
        // This is much safer than exposing nonces in URL parameters which can be logged/cached
        
        // The session-based validation happens in check_strong_permission() which is already 
        // applied to this route, providing adequate security for encrypted requests
        
        // Additional validation: Ensure request contains encrypted JWT data
        if (!isset($original_body['time_jwt']) || empty($original_body['time_jwt'])) {
            $client_ip = $this->get_client_ip($request);
            $this->logger->warning('Encrypted endpoint accessed without encrypted payload from IP: ' . $client_ip);
            
            $this->event_logger->create_event_record(
                $request->get_body(),
                'error', // monitor_status
                $this->get_essential_headers($request),
                false,
                array(
                    'event_name' => 'endpoint_access_violation',
                    'reason' => 'Encrypted endpoint accessed without encrypted payload',
                    'ip_address' => $client_ip,
                    'user_agent' => $request->get_header('user-agent'),
                    'url' => $request->get_header('origin'),
                    'referrer' => $request->get_header('referer')
                )
            );
            
            return new \WP_REST_Response(
                array('error' => 'Invalid request format for encrypted endpoint'),
                400
            );
        }
      
        // Delegate to the main send_events method - it already handles encrypted requests
        return $this->send_events($request);
    }

    /**
     * Send events to Cloudflare Worker (batch processing only).
     *
     * @since    1.0.0
     * @param    \WP_REST_Request    $request    The request object.
     * @return   \WP_REST_Response               The response object.
     */
    public function send_events($request)
    {
        $start_time = microtime(true);
        $session_id = session_id();
        $client_ip = $this->get_client_ip($request);
        
        try {
            // Perform bot detection for all requests (no authentication bypass for client requests)
            if ($this->is_bot_request($request)) {
                // Log comprehensive bot detection details (single-row approach)
                $this->event_logger->create_event_record(
                    $request->get_body(),
                    'bot_detected', // monitor_status
                    $this->get_essential_headers($request),
                    false,
                    array(
                        'event_name' => 'unknown_request',
                        'reason' => 'Multi-factor bot detection triggered',
                        'ip_address' => $client_ip,
                        'user_agent' => $request->get_header('user-agent'),
                        'url' => $request->get_header('origin'),
                        'referrer' => $request->get_header('referer'),
                        'session_id' => $session_id,
                        'bot_detection_rules' => $this->get_bot_detection_details($request)
                    )
                );

                $this->logger->warning("Bot detected attempting to send events - blocked from database storage", json_encode(array(
                    'ip' => $client_ip,
                    'user_agent' => $request->get_header('user-agent'),
                    'referer' => $request->get_header('referer')
                )));
                return new \WP_REST_Response(
                    array(
                        'error' => 'Request blocked',
                        'details' => 'Automated requests are not allowed'
                    ),
                    403
                );
            }

            // Rate limiting check - 100 requests per minute per IP
            $rate_limit_check = $this->check_rate_limit($request);
            if (!$rate_limit_check['allowed']) {
                // Log rate limiting event (single-row approach)
                $this->event_logger->create_event_record(
                    substr($request->get_body(), 0, 1000) . '...', // Truncate large payloads
                    'denied', // monitor_status
                    $this->get_essential_headers($request),
                    false,
                    array(
                        'event_name' => 'batch_request',
                        'reason' => 'Rate limit exceeded: ' . $rate_limit_check['retry_after'] . 's retry',
                        'ip_address' => $client_ip,
                        'user_agent' => $request->get_header('user-agent'),
                        'url' => $request->get_header('origin'),
                        'referrer' => $request->get_header('referer'),
                        'session_id' => $session_id
                    )
                );

                $this->logger->warning("Rate limit exceeded for IP: {$client_ip} - rejected batch request");
                return new \WP_REST_Response(
                    array(
                        'error' => 'Rate limit exceeded',
                        'details' => 'Maximum 100 requests per minute allowed',
                        'retry_after' => $rate_limit_check['retry_after']
                    ),
                    429
                );
            }

            // Handle encrypted request if present
            $request_data = $this->handle_encrypted_request($request);

        

            // Validate batch request structure
            if (empty($request_data)) {
                $this->logger->error("Empty request data received");
                
                $this->event_logger->create_event_record(
                    $request->get_body(),
                    'error', // monitor_status
                    $this->get_essential_headers($request),
                    false,
                    array(
                        'event_name' => 'data_validation_error',
                        'reason' => 'Empty request data received',
                        'ip_address' => $client_ip,
                        'user_agent' => $request->get_header('user-agent'),
                        'url' => $request->get_header('origin'),
                        'referrer' => $request->get_header('referer'),
                        'session_id' => $session_id
                    )
                );
                
                return new \WP_REST_Response(array('error' => 'Invalid request data'), 400);
            }

            // Support unified batch structure for both single events and batch events
            if (isset($request_data['events']) && is_array($request_data['events'])) {
                // Unified batch structure - validate it
                if (empty($request_data['events'])) {
                    $this->event_logger->create_event_record(
                        $request->get_body(),
                        'error', // monitor_status
                        $this->get_essential_headers($request),
                        false,
                        array(
                            'event_name' => 'data_validation_error',
                            'reason' => 'Empty events array received',
                            'ip_address' => $client_ip,
                            'user_agent' => $request->get_header('user-agent'),
                            'url' => $request->get_header('origin'),
                            'referrer' => $request->get_header('referer'),
                            'session_id' => $session_id
                        )
                    );
                    
                    return new \WP_REST_Response(array('error' => 'Empty events array'), 400);
                }
                
                // Validate each event in the batch
                foreach ($request_data['events'] as $index => $event) {
                    if (!is_array($event)) {
                        $this->event_logger->create_event_record(
                            $request->get_body(),
                            'error', // monitor_status
                            $this->get_essential_headers($request),
                            false,
                            array(
                                'event_name' => 'data_validation_error',
                                'reason' => "Event at index {$index} is not an array",
                                'ip_address' => $client_ip,
                                'user_agent' => $request->get_header('user-agent'),
                                'url' => $request->get_header('origin'),
                                'referrer' => $request->get_header('referer'),
                                'session_id' => $session_id
                            )
                        );
                        
                        return new \WP_REST_Response(array('error' => "Event at index {$index} is not an array"), 400);
                    }
                    
                    if (!isset($event['name']) || empty($event['name'])) {
                        $this->event_logger->create_event_record(
                            $request->get_body(),
                            'error', // monitor_status
                            $this->get_essential_headers($request),
                            false,
                            array(
                                'event_name' => 'data_validation_error',
                                'reason' => "Missing event name at index {$index}",
                                'ip_address' => $client_ip,
                                'user_agent' => $request->get_header('user-agent'),
                                'url' => $request->get_header('origin'),
                                'referrer' => $request->get_header('referer'),
                                'session_id' => $session_id
                            )
                        );
                        
                        return new \WP_REST_Response(array('error' => "Missing event name at index {$index}"), 400);
                    }
                    
                    if (!isset($event['params']) || !is_array($event['params'])) {
                        // If no params, create empty array
                        $request_data['events'][$index]['params'] = array();
                    }
                }
                
            } elseif (isset($request_data['event_name']) || isset($request_data['name'])) {
                // Legacy single event format - convert to unified batch structure
                $event_name = $request_data['event_name'] ?? $request_data['name'];
                $event_params = $request_data['params'] ?? $request_data;
                
                // Preserve consent data from root level or params level
                $consent_data = null;
                if (isset($request_data['consent'])) {
                    $consent_data = $request_data['consent'];
                } elseif (isset($event_params['consent'])) {
                    $consent_data = $event_params['consent'];
                }
                
                // Remove event-level fields from params to avoid duplication
                unset($event_params['event_name'], $event_params['name'], $event_params['consent'], $event_params['timestamp']);
                
                $single_event = array(
                    'name' => $event_name,
                    'params' => $event_params,
                    'isCompleteData' => true,
                    'timestamp' => $request_data['timestamp'] ?? time() * 1000
                );
                
                // Convert to unified batch structure
                $request_data['events'] = array($single_event);
                $request_data['batch'] = false;
                
                // Preserve consent data at request level
                if ($consent_data) {
                    $request_data['consent'] = $consent_data;
                }
                
                
            } else {
                $this->event_logger->create_event_record(
                    $request->get_body(),
                    'error', // monitor_status
                    $this->get_essential_headers($request),
                    false,
                    array(
                        'event_name' => 'data_validation_error',
                        'reason' => 'Missing events array or single event data',
                        'ip_address' => $client_ip,
                        'user_agent' => $request->get_header('user-agent'),
                        'url' => $request->get_header('origin'),
                        'referrer' => $request->get_header('referer'),
                        'session_id' => $session_id
                    )
                );
                
                return new \WP_REST_Response(array('error' => 'Missing events array or single event data'), 400);
            }

            // Validate consent data (optional for debugging)
            if (!isset($request_data['consent'])) {
                $this->logger->warning("Missing consent data - using default DENIED consent - IP: {$client_ip} - Session: {$session_id} - Events: " . count($request_data['events']) . " - First: " . ($request_data['events'][0]['name'] ?? 'unknown') . " - Keys: " . implode(',', array_keys($request_data)) . " - Has params consent: " . (isset($request_data['events'][0]['params']['consent']) ? 'yes' : 'no'));
                $request_data['consent'] = array(
                    'consent_mode' => 'DENIED',
                    'consent_reason' => 'missing_data'
                );
            }

            // WordPress-side bot detection (mirrors Cloudflare Worker logic)
            $bot_detection_result = $this->detect_bot_from_event_data($request, $request_data);
            if ($bot_detection_result['is_bot']) {
                    // Log each event as bot detected
                    foreach ($request_data['events'] as $event) {
                        $this->event_logger->create_event_record(
                            json_encode($request_data, JSON_PRETTY_PRINT),
                            'bot_detected', // monitor_status
                            $this->get_essential_headers($request),
                            false,
                            array(
                                'event_name' => $event['name'] ?? 'unknown',
                                'reason' => 'WordPress-side bot detection: Score ' . $bot_detection_result['score'] . '/100',
                                'ip_address' => $client_ip,
                                'user_agent' => $request->get_header('user-agent'),
                                'url' => $request->get_header('origin'),
                                'referrer' => $request->get_header('referer'),
                                'session_id' => $session_id,
                                'consent_given' => $this->extract_consent_status($request_data),
                                'bot_detection_rules' => array_merge($this->get_bot_detection_details($request), $bot_detection_result)
                            )
                        );
                    }

                    $this->logger->warning("Bot detected via WordPress endpoint - blocked from processing", array(
                        'ip' => $client_ip,
                        'user_agent' => $request->get_header('user-agent'),
                        'bot_score' => $bot_detection_result['score'],
                        'reasons' => $bot_detection_result['reasons'],
                        'event_count' => count($request_data['events'])
                    ));
                    
                    // Return success response but don't process the events (same as Cloudflare Worker)
                    return new \WP_REST_Response(array(
                        'success' => true,
                        'events_processed' => count($request_data['events']),
                        'filtered' => true,
                        'message' => 'Events processed successfully'
                    ), 200);
                }
                
            // Clean botData from all events after bot detection (before database storage)
            foreach ($request_data['events'] as $index => $event) {
                if (isset($event['params']['botData'])) {
                    unset($request_data['events'][$index]['params']['botData']);
                }
            }

            // Log batch info with type distinction
            $event_count = count($request_data['events']);            

            // Check if cronjob batching is enabled and WP-Cron is available
            $cronjob_enabled = true;
            $wp_cron_disabled = defined('DISABLE_WP_CRON') && DISABLE_WP_CRON;
            
            if (!$cronjob_enabled || $wp_cron_disabled) {
                // Fall back to direct sending if cronjobs disabled or WP-Cron is disabled
                if ($wp_cron_disabled) {
                    $this->logger->info('WP-Cron is disabled (DISABLE_WP_CRON=true), using direct sending instead of queue');
                }
                return $this->send_events_directly($request_data, $start_time, $session_id, $request);
            }
            
            // Queue events for batch processing instead of sending directly
            $queued_events = 0;
            $failed_events = 0;
            
            // Check if encryption is enabled for storing in database
            $encryption_enabled = (bool) get_option('ga4_jwt_encryption_enabled', false);
            
            // Determine if the original request was encrypted (time-based JWT or regular JWT)
            $original_request_body = $request->get_json_params();
            $was_originally_encrypted = (isset($original_request_body['time_jwt']) && !empty($original_request_body['time_jwt'])) ||
                                       (isset($original_request_body['encrypted']) && $original_request_body['encrypted'] === true);
            
            // Process each event for queuing
            foreach ($request_data['events'] as $event) {
                // Prepare event data with context
                $event_data = array(
                    'event' => $event,
                    'consent' => $request_data['consent'] ?? null,
                    'batch' => $request_data['batch'] ?? false,
                    'timestamp' => time()
                );
                
                // Encrypt payload for database storage if encryption is enabled
                $should_encrypt = false;
                if ($encryption_enabled) {
                    $encryption_key = GA4_Encryption_Util::retrieve_encrypted_key('ga4_jwt_encryption_key');
                    if (!empty($encryption_key)) {
                        try {
                            // Use permanent JWT encryption for database storage (no expiry)
                            $encrypted_data = GA4_Encryption_Util::create_permanent_jwt_token(wp_json_encode($event_data), $encryption_key);
                            $should_encrypt = true;
                            $event_data = $encrypted_data;
                            
                        } catch (\Exception $e) {
                            $this->logger->warning("Failed to encrypt event for queuing with permanent key: " . $e->getMessage());
                            // Continue with unencrypted data
                        }
                    } else {
                        $this->logger->warning("Permanent encryption key not available - storing event unencrypted");
                    }
                }
                
                // Get original request headers - filter only essential ones for lightweight storage
                $original_headers = $this->get_essential_headers($request);
                
                // Create single-row entry with both monitoring and queue status (unified approach)
                $event_id = $this->event_logger->create_event_record(
                    $event_data,
                    'allowed', // monitor_status
                    $original_headers,
                    $was_originally_encrypted,
                    array(
                        'event_name' => $event['name'] ?? 'unknown',
                        'reason' => 'Successfully queued for batch processing',
                        'original_payload' => $should_encrypt ? $event_data : json_encode($event_data),
                        'ip_address' => $client_ip,
                        'user_agent' => $request->get_header('user-agent'),
                        'url' => $request->get_header('origin'),
                        'referrer' => $request->get_header('referer'),
                        'session_id' => $session_id,
                        'consent_given' => $this->extract_consent_status($request_data),
                        'batch_size' => count($request_data['events']),
                        'is_encrypted' => $should_encrypt
                    )
                );
                
                if ($event_id) {
                    $queued_events++;
                } else {
                    $failed_events++;
                }
            }
            
            $processing_time = round((microtime(true) - $start_time) * 1000, 2);
            
            if ($failed_events > 0) {
                $this->logger->warning("Some events failed to queue - Queued: $queued_events, Failed: $failed_events");
            }
            
            
            return new \WP_REST_Response(array(
                'success' => true,
                'events_queued' => $queued_events,
                'events_failed' => $failed_events,
                'message' => 'Events queued for batch processing every 5 minutes'
            ), 200);

        } catch (\Exception $e) {
            $processing_time = round((microtime(true) - $start_time) * 1000, 2);
            $this->logger->error("Failed to process batch events request for session: {$session_id} after {$processing_time}ms: " . $e->getMessage());
            
            $this->event_logger->create_event_record(
                $request->get_body(),
                'error', // monitor_status
                $this->get_essential_headers($request),
                false,
                array(
                    'event_name' => 'system_error',
                    'reason' => 'Processing exception: ' . $e->getMessage(),
                    'ip_address' => $client_ip,
                    'user_agent' => $request->get_header('user-agent'),
                    'url' => $request->get_header('origin'),
                    'referrer' => $request->get_header('referer'),
                    'session_id' => $session_id
                )
            );
            
            return new \WP_REST_Response(array('error' => 'Processing error'), 500);
        }
    }

    /**
     * Send events directly to Cloudflare (legacy behavior when cronjob is disabled)
     *
     * @since    2.0.0
     * @param    array  $request_data  The request data
     * @param    float  $start_time    The start time for performance measurement
     * @param    string $session_id    The session ID
     * @param    \WP_REST_Request $request The original request object for logging
     * @return   \WP_REST_Response     The response
     */
    private function send_events_directly($request_data, $start_time, $session_id, $request)
    {
        $event_count = count($request_data['events']);

        // Get configuration from database
        $cloudflare_url = get_option('ga4_cloudflare_worker_url', '');
        $encryption_enabled = (bool) get_option('ga4_jwt_encryption_enabled', false);
        $encryption_key = GA4_Encryption_Util::retrieve_encrypted_key('ga4_jwt_encryption_key') ?: '';
        
        if (empty($cloudflare_url)) {
            $client_ip = $this->get_client_ip($request);
            
            $this->event_logger->create_event_record(
                $request->get_body(),
                'error', // monitor_status
                $this->get_essential_headers($request),
                false,
                array(
                    'event_name' => 'configuration_error',
                    'reason' => 'Cloudflare Worker URL not configured',
                    'ip_address' => $client_ip,
                    'user_agent' => $request->get_header('user-agent'),
                    'url' => $request->get_header('origin'),
                    'referrer' => $request->get_header('referer'),
                    'session_id' => $session_id
                )
            );
            
            return new \WP_REST_Response(array('error' => 'Cloudflare Worker URL not configured'), 500);
        }

        // Forward the complete batch payload to Cloudflare
        $batch_payload = $request_data;
        
        // Clean botData from all events before sending to Cloudflare (direct mode)
        foreach ($batch_payload['events'] as $index => $event) {
            if (isset($event['params']['botData'])) {
                unset($batch_payload['events'][$index]['params']['botData']);
            }
        }
        
        // Check if debug mode is enabled
        $debug_mode = get_option('ga4_server_side_tagging_debug_mode', false);
        
        if ($debug_mode) {
            // Debug mode: Wait for response from Cloudflare for debugging
            $result = $this->forward_to_cloudflare($batch_payload, $cloudflare_url, $encryption_enabled, $encryption_key, null);
            $processing_time = round((microtime(true) - $start_time) * 1000, 2);

            if ($result['success']) {
                // Events successfully sent - logging handled by main flow to prevent duplicate entries

                $response_data = array(
                    'success' => true, 
                    'events_processed' => $event_count,
                        'mode' => 'direct_sending'
                );
                if (isset($result['worker_response'])) {
                    $response_data['worker_response'] = $result['worker_response'];
                }
                return new \WP_REST_Response($response_data, 200);
            } else {
                // Events failed to send - logging handled by main flow to prevent duplicate entries

                $this->logger->error("Failed to send batch events to Cloudflare for session: {$session_id} after {$processing_time}ms: " . $result['error']);
                return new \WP_REST_Response(array(
                    'error' => 'Batch event sending failed', 
                    'details' => $result['error'],
                    'events_failed' => $event_count,
                    'mode' => 'direct_sending'
                ), 500);
            }
        } else {
            // Production mode: Fire and forget for maximum performance
            $this->forward_to_cloudflare_async($batch_payload, $cloudflare_url, $encryption_enabled, $encryption_key, null);
            $processing_time = round((microtime(true) - $start_time) * 1000, 2);
            
            // Async events sent - logging handled by main flow to prevent duplicate entries
            
            return new \WP_REST_Response(array(
                'success' => true, 
                'events_processed' => $event_count,
                'mode' => 'direct_sending'
            ), 200);
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

        // Check for time-based JWT encryption first (for JS client requests)
        if (isset($request_body['time_jwt']) && !empty($request_body['time_jwt'])) {
            return $this->handle_time_based_jwt($request_body['time_jwt'], $request);
        }

        // Check for permanent JWT encryption (for backend/stored requests)
        $encryption_enabled = get_option('ga4_jwt_encryption_enabled', false);
        $is_encrypted = GA4_Encryption_Util::is_encrypted_request($request);

        if ($encryption_enabled && $is_encrypted) {
            return $this->handle_permanent_jwt($request_body, $request);
        }

        // Return original request body if not encrypted
        return $request_body;
    }

    /**
     * Handle time-based JWT requests from JavaScript clients
     * Uses 5-minute rotating encryption keys
     * 
     * @param string $time_jwt Time-based JWT token
     * @param \WP_REST_Request $request Request object for logging
     * @return array Decrypted request data
     * @throws \Exception If decryption fails
     */
    private function handle_time_based_jwt($time_jwt, $request)
    {
        try {
            $decrypted_data = GA4_Encryption_Util::verify_time_based_jwt($time_jwt);
            if ($decrypted_data === false) {
                throw new \Exception('Time-based JWT verification failed');
            }

            return $decrypted_data;
        } catch (\Exception $e) {
            $this->logger->error('Failed to verify time-based JWT request from IP ' . $this->get_client_ip($request) . ': ' . $e->getMessage());
            throw new \Exception('Failed to verify time-based JWT request: ' . $e->getMessage());
        }
    }

    /**
     * Handle permanent JWT requests (backend/stored data)
     * Uses permanent encryption keys that don't expire
     * 
     * @param array $request_body Request body data
     * @param \WP_REST_Request $request Request object for logging
     * @return array Decrypted request data
     * @throws \Exception If decryption fails
     */
    private function handle_permanent_jwt($request_body, $request)
    {
        // Use the configured permanent encryption key from backend settings
        $encryption_key = GA4_Encryption_Util::retrieve_encrypted_key('ga4_jwt_encryption_key') ?: '';

        if (empty($encryption_key)) {
            throw new \Exception('Encryption is enabled but no permanent encryption key is configured');
        }

        try {
            $decrypted_data = GA4_Encryption_Util::parse_encrypted_request($request_body, $encryption_key);
            return $decrypted_data;
        } catch (\Exception $e) {
            $this->logger->error('Failed to verify permanent JWT request from IP ' . $this->get_client_ip($request) . ': ' . $e->getMessage());
            throw new \Exception('Permanent JWT request verification failed: ' . $e->getMessage());
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
            $this->logger->info("Debug headers - Accept-Language: '{$accept_language}', Accept-Encoding: '{$accept_encoding}'");
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
     * Forward the event to Cloudflare Worker asynchronously (fire and forget).
     *
     * @since    1.0.0
     * @param    array                  $event_payload      The event payload.
     * @param    string                 $cloudflare_url     The Cloudflare Worker URL.
     * @param    bool                   $encryption_enabled Whether encryption is enabled.
     * @param    string                 $encryption_key     The encryption key.
     * @param    \WP_REST_Request|null  $original_request   The original request object for header forwarding.
     * @return   void
     */
    private function forward_to_cloudflare_async($event_payload, $cloudflare_url, $encryption_enabled, $encryption_key, $original_request = null)
    {
        try {
            // Ensure Cloudflare Worker URL uses HTTPS
            if (!empty($cloudflare_url) && strpos($cloudflare_url, 'https://') !== 0) {
                $this->logger->error('Cloudflare Worker URL must use HTTPS protocol for security');
                return;
            }

            $headers = array(
                'Content-Type' => 'application/json'
            );

            // Add Worker API key authentication header
            $worker_api_key = GA4_Encryption_Util::retrieve_encrypted_key('ga4_worker_api_key');
            if (!empty($worker_api_key)) {
                $headers['Authorization'] = 'Bearer ' . $worker_api_key;
            }

            // Forward Origin, Referer, and User-Agent headers from the original request
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
                    $encrypted_payload = GA4_Encryption_Util::create_encrypted_response($body, $encryption_key);
                    $body = $encrypted_payload;
                    $headers['X-Encrypted'] = 'true';
                } catch (\Exception $e) {
                    $this->logger->error('Event encryption failed: ' . $e->getMessage());
                    // Continue with unencrypted payload as fallback
                }
            }

            // Fire and forget - no blocking, no response waiting
            wp_remote_post($cloudflare_url, array(
                'timeout' => 10, // 10 second timeout for async
                'blocking' => false, // Non-blocking request
                'headers' => $headers,
                'body' => wp_json_encode($body)
            ));

        } catch (\Exception $e) {
            $this->logger->error('Async event forwarding failed: ' . $e->getMessage());
        }
    }

    /**
     * Forward event to Cloudflare Worker.
     *
     * @since    1.0.0
     * @param    array     $event_payload      The event data.
     * @param    string    $cloudflare_url     The Cloudflare Worker URL.
     * @param    bool      $encryption_enabled Whether encryption is enabled.
     * @param    string    $encryption_key     The encryption key.
     * @param    \WP_REST_Request $original_request The original client request.
     * @return   array                         Success/failure result.
     */
    private function forward_to_cloudflare($event_payload, $cloudflare_url, $encryption_enabled, $encryption_key, $original_request = null)
    {
        try {
            // Ensure Cloudflare Worker URL uses HTTPS
            if (!empty($cloudflare_url) && strpos($cloudflare_url, 'https://') !== 0) {
                return array('success' => false, 'error' => 'Cloudflare Worker URL must use HTTPS protocol for security');
            }

            $headers = array(
                'Content-Type' => 'application/json'
            );

            // Add Worker API key authentication header
            $worker_api_key = GA4_Encryption_Util::retrieve_encrypted_key('ga4_worker_api_key');
            if (!empty($worker_api_key)) {
                $headers['Authorization'] = 'Bearer ' . $worker_api_key;
            }

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

            // Send to Cloudflare Worker - 10 second timeout for debug mode
            $response = wp_remote_post($cloudflare_url, array(
                'timeout' => 10, // 10 second timeout for debug mode
                'headers' => $headers,
                'body' => wp_json_encode($body)
            ));

            if (is_wp_error($response)) {
                $this->logger->error("HTTP request failed in debug mode: " . $response->get_error_message());
                
                // HTTP error logged by main system error handler to prevent duplicate entries
                
                return array('success' => false, 'error' => $response->get_error_message());
            }

            $response_code = wp_remote_retrieve_response_code($response);
            $response_body = wp_remote_retrieve_body($response);

            if ($response_code >= 200 && $response_code < 300) {
                // Try to parse response body as JSON first
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
                        // Response is JSON but not encrypted (valid JSON response)
                        return array('success' => true, 'worker_response' => $parsed_response);
                    }
                } else {
                    // Response is not JSON - handle as plain text
                    // If it's a simple success message, treat it as successful
                    $response_body_trimmed = trim($response_body);
                    if (empty($response_body_trimmed) || 
                        stripos($response_body_trimmed, 'success') !== false ||
                        stripos($response_body_trimmed, 'ok') !== false ||
                        stripos($response_body_trimmed, 'processed') !== false ||
                        strlen($response_body_trimmed) < 100) {
                        // Treat plain text success responses as successful
                        return array('success' => true, 'worker_response' => array(
                            'message' => $response_body_trimmed,
                            'status' => 'success',
                            'format' => 'plain_text'
                        ));
                    } else {
                        // Unknown plain text response, return as-is
                        return array('success' => true, 'worker_response' => $response_body);
                    }
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

    /**
     * Detect bot from event data (mirrors Cloudflare Worker bot detection)
     *
     * @since    1.0.0
     * @param    \WP_REST_Request    $request      The request object.
     * @param    array              $request_data  The event request data.
     * @return   array                           Bot detection result.
     */
    private function detect_bot_from_event_data($request, $request_data)
    {
        $bot_checks = array();
        $reasons = array();
        $total_score = 0;
        
        // Extract botData from the first event (if available)
        $bot_data = null;
        $event_params = null;
        if (!empty($request_data['events']) && isset($request_data['events'][0]['params'])) {
            $event_params = $request_data['events'][0]['params'];
            $bot_data = isset($event_params['botData']) ? $event_params['botData'] : null;
        }
        
        // 1. Check existing WordPress bot detection patterns (existing method)
        if ($this->is_bot_request($request)) {
            $bot_checks[] = true;
            $reasons[] = 'wordpress_bot_patterns';
            $total_score += 50;
        } else {
            $bot_checks[] = false;
        }
        
        // 2. WordPress botData analysis (mirrors Cloudflare checkWordPressBotData)
        if ($bot_data) {
            $botdata_result = $this->check_wordpress_bot_data($bot_data);
            $bot_checks[] = $botdata_result['is_bot'];
            if ($botdata_result['is_bot']) {
                $reasons[] = 'botdata_analysis';
                $total_score += $botdata_result['score'];
            }
        } else {
            $bot_checks[] = false;
        }
        
        // 3. Behavior patterns analysis (mirrors Cloudflare checkBehaviorPatterns)
        if ($bot_data && $event_params) {
            $behavior_result = $this->check_behavior_patterns($bot_data, $event_params);
            $bot_checks[] = $behavior_result['is_bot'];
            if ($behavior_result['is_bot']) {
                $reasons[] = 'behavior_patterns';
                $total_score += $behavior_result['score'];
            }
        } else {
            $bot_checks[] = false;
        }
        
        // 4. User agent analysis (enhanced)
        $user_agent = $request->get_header('user-agent');
        $ua_result = $this->check_enhanced_user_agent($user_agent);
        $bot_checks[] = $ua_result['is_bot'];
        if ($ua_result['is_bot']) {
            $reasons[] = 'user_agent_patterns';
            $total_score += $ua_result['score'];
        }
        
        // Mirror Cloudflare Worker logic: require at least 2 positive checks to classify as bot
        $positive_checks = array_filter($bot_checks);
        $is_bot = count($positive_checks) >= 2;
        
        return array(
            'is_bot' => $is_bot,
            'score' => $total_score,
            'reasons' => $reasons,
            'positive_checks' => count($positive_checks),
            'check_details' => array(
                'wordpress_patterns' => $bot_checks[0],
                'botdata_analysis' => $bot_checks[1],
                'behavior_patterns' => $bot_checks[2],
                'user_agent' => $bot_checks[3]
            )
        );
    }
    
    /**
     * Check WordPress botData for bot indicators (mirrors Cloudflare checkWordPressBotData)
     *
     * @since    1.0.0
     * @param    array    $bot_data    The botData from client.
     * @return   array                Bot detection result.
     */
    private function check_wordpress_bot_data($bot_data)
    {
        $score = 0;
        $indicators = array();
        
        // Bot score check (primary indicator)
        if (isset($bot_data['bot_score']) && $bot_data['bot_score'] > 35) {
            $score += 40;
            $indicators[] = 'high_bot_score';
        }
        
        // Webdriver detection
        if (isset($bot_data['webdriver_detected']) && $bot_data['webdriver_detected']) {
            $score += 50;
            $indicators[] = 'webdriver_detected';
        }
        
        // Automation indicators
        if (isset($bot_data['has_automation_indicators']) && $bot_data['has_automation_indicators']) {
            $score += 30;
            $indicators[] = 'automation_indicators';
        }
        
        // JavaScript availability (missing JS is suspicious)
        if (isset($bot_data['has_javascript']) && !$bot_data['has_javascript']) {
            $score += 20;
            $indicators[] = 'no_javascript';
        }
        
        // Hardware checks
        if (isset($bot_data['hardware_concurrency']) && $bot_data['hardware_concurrency'] === 0) {
            $score += 15;
            $indicators[] = 'no_hardware_concurrency';
        }
        
        // Cookie support
        if (isset($bot_data['cookie_enabled']) && !$bot_data['cookie_enabled']) {
            $score += 10;
            $indicators[] = 'cookies_disabled';
        }
        
        // Screen dimension validation
        if (isset($bot_data['screen_available_width']) && isset($bot_data['screen_available_height'])) {
            $width = intval($bot_data['screen_available_width']);
            $height = intval($bot_data['screen_available_height']);
            
            if ($width < 320 || $width > 7680 || $height < 240 || $height > 4320) {
                $score += 25;
                $indicators[] = 'invalid_screen_dimensions';
            }
        }
        
        // Color depth check
        if (isset($bot_data['color_depth'])) {
            $color_depth = intval($bot_data['color_depth']);
            if ($color_depth < 16 || $color_depth > 32) {
                $score += 15;
                $indicators[] = 'invalid_color_depth';
            }
        }
        
        // Engagement time check
        if (isset($bot_data['engagement_calculated']) && $bot_data['engagement_calculated'] < 500) {
            $score += 20;
            $indicators[] = 'low_engagement';
        }
        
        // Timezone check (UTC/GMT is suspicious)
        if (isset($bot_data['timezone'])) {
            $timezone = strtolower($bot_data['timezone']);
            if (in_array($timezone, array('utc', 'gmt', 'utc+0', 'gmt+0'))) {
                $score += 10;
                $indicators[] = 'suspicious_timezone';
            }
        }
        
        return array(
            'is_bot' => $score >= 30, // Threshold for bot classification
            'score' => $score,
            'indicators' => $indicators
        );
    }
    
    /**
     * Check behavior patterns for bot indicators (mirrors Cloudflare checkBehaviorPatterns)
     *
     * @since    1.0.0
     * @param    array    $bot_data      The botData from client.
     * @param    array    $event_params  The event parameters.
     * @return   array                   Bot detection result.
     */
    private function check_behavior_patterns($bot_data, $event_params)
    {
        $score = 0;
        $indicators = array();
        
        // Session timing analysis
        if (isset($bot_data['event_creation_time']) && isset($bot_data['session_start_time'])) {
            $session_duration = $bot_data['event_creation_time'] - $bot_data['session_start_time'];
            if ($session_duration < 1000) { // Less than 1 second
                $score += 25;
                $indicators[] = 'short_session_duration';
            }
        }
        
        // Event timestamp patterns
        if (isset($event_params['event_timestamp'])) {
            $timestamp = $event_params['event_timestamp'];
            // Check for round timestamps (artificial)
            if ($timestamp % 1000 === 0) {
                $score += 15;
                $indicators[] = 'round_timestamp';
            }
        }
        
        // Engagement time analysis
        if (isset($event_params['engagement_time_msec'])) {
            $engagement = intval($event_params['engagement_time_msec']);
            if ($engagement < 100) {
                $score += 20;
                $indicators[] = 'impossible_fast_engagement';
            }
        }
        
        // Perfect scroll percentages (bot-like behavior)
        if (isset($event_params['percent_scrolled'])) {
            $scroll_percent = intval($event_params['percent_scrolled']);
            if (in_array($scroll_percent, array(25, 50, 75, 90, 100))) {
                $score += 10;
                $indicators[] = 'perfect_scroll_percentage';
            }
        }
        
        return array(
            'is_bot' => $score >= 20, // Threshold for bot classification
            'score' => $score,
            'indicators' => $indicators
        );
    }
    
    /**
     * Enhanced user agent analysis (beyond existing is_bot_request)
     *
     * @since    1.0.0
     * @param    string    $user_agent    The user agent string.
     * @return   array                   Bot detection result.
     */
    private function check_enhanced_user_agent($user_agent)
    {
        $score = 0;
        $indicators = array();
        
        if (empty($user_agent) || strlen($user_agent) < 10) {
            $score += 30;
            $indicators[] = 'missing_or_short_ua';
        }
        
        // Additional automation tool patterns not in existing check
        $automation_patterns = array(
            '/puppeteer/i',
            '/playwright/i', 
            '/cypress/i',
            '/testcafe/i',
            '/nightwatch/i',
            '/webdriverio/i'
        );
        
        foreach ($automation_patterns as $pattern) {
            if (preg_match($pattern, $user_agent)) {
                $score += 40;
                $indicators[] = 'automation_tool';
                break;
            }
        }
        
        return array(
            'is_bot' => $score >= 25,
            'score' => $score,
            'indicators' => $indicators
        );
    }

    /**
     * Extract consent status from request data
     *
     * @since    2.1.0
     * @param    array    $request_data    The request data containing consent info.
     * @return   bool|null                Boolean consent status or null if unknown.
     */
    private function extract_consent_status($request_data)
    {
   
        
        if (!isset($request_data['consent']) || !is_array($request_data['consent'])) {
            $this->logger->info('No consent data found or not array', json_encode($request_data['consent'] ?? 'not_set'));
            return null;
        }
        
        $consent = $request_data['consent'];
        
        // Check for legacy consent_mode field first
        if (isset($consent['consent_mode'])) {
            $result = $consent['consent_mode'] === 'GRANTED';
            $this->logger->info('Using legacy consent_mode', json_encode(array('consent_mode' => $consent['consent_mode'], 'result' => $result)));
            return $result;
        }
        
        // Check modern consent fields (ad_user_data, ad_personalization)
        $ad_user_data_granted = isset($consent['ad_user_data']) && $consent['ad_user_data'] === 'GRANTED';
        $ad_personalization_granted = isset($consent['ad_personalization']) && $consent['ad_personalization'] === 'GRANTED';
        
        // If both fields are present, both must be granted for overall consent to be true
        if (isset($consent['ad_user_data']) && isset($consent['ad_personalization'])) {
            $result = $ad_user_data_granted && $ad_personalization_granted;
            return $result;
        }
        
        // If only one field is present, use that
        if (isset($consent['ad_user_data'])) {
            $result = $ad_user_data_granted;
            $this->logger->info('Using ad_user_data only', json_encode(array('ad_user_data' => $consent['ad_user_data'], 'result' => $result)));
            return $result;
        }
        
        if (isset($consent['ad_personalization'])) {
            $result = $ad_personalization_granted;
            $this->logger->info('Using ad_personalization only', json_encode(array('ad_personalization' => $consent['ad_personalization'], 'result' => $result)));
            return $result;
        }
        
        $this->logger->info('No valid consent fields found', json_encode($consent));
        return null;
    }

    /**
     * Filter request headers to only include essential ones for lightweight logging
     *
     * @since    3.0.0
     * @param    \WP_REST_Request    $request    The request object.
     * @return   array                          Filtered headers array.
     */
    private function get_essential_headers($request)
    {
        $essential_headers = array(
            'user_agent', 'accept_language', 'referer', 
            'x_forwarded_for', 'x_real_ip'
        );
        
        $filtered_headers = array();
        foreach ($request->get_headers() as $key => $value) {
            $header_key = strtolower(str_replace('-', '_', $key));
            if (in_array($header_key, $essential_headers)) {
                $filtered_headers[$header_key] = is_array($value) ? implode(', ', $value) : $value;
            }
        }
        
        return $filtered_headers;
    }

    /**
     * Get detailed bot detection information for logging
     *
     * @since    2.1.0
     * @param    \WP_REST_Request    $request    The request object.
     * @return   array                          Bot detection details.
     */
    private function get_bot_detection_details($request)
    {
        $user_agent = $request->get_header('user-agent');
        $client_ip = $this->get_client_ip($request);
        $referer = $request->get_header('referer');

        return array(
            'user_agent_check' => $this->check_user_agent_patterns($user_agent),
            'ip_check' => $this->check_known_bot_ips($client_ip),
            'referer_check' => $this->check_suspicious_referrers($referer),
            'headers_check' => $this->check_missing_headers($request),
            'asn_check' => $this->check_suspicious_asn($client_ip),
            'behavior_check' => $this->check_behavioral_patterns($request),
            'timestamp' => current_time('mysql'),
            'detection_threshold' => 2
        );
    }

}