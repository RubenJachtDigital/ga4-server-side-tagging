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
        // 1. Enhanced origin validation - STRICT check
        if (!$this->validate_request_origin($request)) {
            $this->log_security_failure($request, 'ORIGIN_VALIDATION_FAILED', 'Request origin validation failed');
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

        // Whitelist legitimate user agents and services
        if ($this->is_whitelisted_request($user_agent, $client_ip, $referer)) {
            return false;
        }

        // Run multiple bot detection checks
        $bot_checks = array(
            $this->check_user_agent_patterns($user_agent),
            $this->check_known_bot_ips($client_ip),
            $this->check_suspicious_referrers($referer),
            $this->check_missing_headers($request),
            $this->check_suspicious_asn($client_ip),
            $this->check_behavioral_patterns($request)
        );

        // Require at least 2 positive checks to classify as bot (balanced detection)
        $positive_checks = array_filter($bot_checks, function ($check) {
            return $check === true;
        });
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
            
            $this->logger->bot_detected($message, array(
                'ip' => $client_ip,
                'user_agent' => $user_agent,
                'referer' => $referer,
                'detection_details' => $detection_details,
                'context' => $context
            ));
        }

        return $is_bot;
    }

    /**
     * Check if request is from whitelisted legitimate services
     *
     * @since    3.0.0
     * @param    string    $user_agent    User agent string.
     * @param    string    $client_ip     Client IP address.
     * @param    string    $referer       Referer header.
     * @return   bool                    True if whitelisted.
     */
    private function is_whitelisted_request($user_agent, $client_ip, $referer)
    {
        if (empty($user_agent)) {
            return false;
        }

        // Legitimate mobile browsers (common patterns)
        $legitimate_browsers = array(
            // Mobile Safari (iOS)
            '/Mozilla.*iPhone.*Safari/i',
            '/Mozilla.*iPad.*Safari/i',
            
            // Chrome Mobile
            '/Mozilla.*Android.*Chrome/i',
            '/Mozilla.*Mobile.*Chrome/i',
            
            // Firefox Mobile
            '/Mozilla.*Mobile.*Firefox/i',
            '/Mozilla.*Android.*Firefox/i',
            
            // Edge Mobile
            '/Mozilla.*Mobile.*Edge/i',
            '/Mozilla.*Android.*Edge/i',
            
            // Samsung Browser
            '/Mozilla.*Android.*SamsungBrowser/i',
            
            // Desktop browsers (excluding headless/automation)
            '/Mozilla.*Windows NT.*Chrome/i',
            '/Mozilla.*Macintosh.*Safari/i',
            '/Mozilla.*Windows.*Firefox/i',
            '/Mozilla.*X11.*Linux.*Chrome(?!.*[Hh]eadless)/i', // Exclude HeadlessChrome
            '/Mozilla.*X11.*Linux.*Firefox(?!.*[Hh]eadless)/i' // Exclude headless Firefox
        );

        foreach ($legitimate_browsers as $pattern) {
            if (preg_match($pattern, $user_agent)) {
                // Double-check: even if it matches legitimate pattern, reject if it contains automation indicators
                if (stripos($user_agent, 'headless') !== false ||
                    stripos($user_agent, 'selenium') !== false ||
                    stripos($user_agent, 'webdriver') !== false ||
                    stripos($user_agent, 'puppeteer') !== false ||
                    stripos($user_agent, 'playwright') !== false) {
                    return false; // Override whitelist for automation tools
                }
                return true;
            }
        }

        // Known legitimate services
        $legitimate_services = array(
            // Payment processors
            '/PayPal/i',
            '/Stripe/i',
            
            // Social media legitimate crawlers
            '/facebookexternalhit.*facebook\.com/i',
            '/Twitterbot.*twitter\.com/i',
            
            // Search engines (legitimate ones only)
            '/Googlebot.*google\.com/i',
            '/bingbot.*bing\.com/i',
            
            // Monitoring services
            '/UptimeRobot/i',
            '/GTmetrix/i',
            '/Pingdom/i'
        );

        foreach ($legitimate_services as $pattern) {
            if (preg_match($pattern, $user_agent)) {
                return true;
            }
        }

        return false;
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

            // Headless browsers and automation (enhanced patterns)
            '/headlesschrome/i',
            '/phantomjs/i',
            '/slimerjs/i',
            '/htmlunit/i',
            '/selenium/i',
            '/selenium[\s\-_]?server/i',
            '/selenium[\s\-_]?webdriver/i',
            '/selenium[\s\-_]?grid/i',
            '/webdriver/i',
            '/chromedriver/i',
            '/geckodriver/i',
            '/edgedriver/i',
            '/safaridriver/i',
            '/puppeteer/i',
            '/playwright/i',
            '/cypress/i',
            '/testcafe/i',
            '/nightwatch/i',
            '/webdriverio/i',
            '/protractor/i',
            '/zombie\.js/i',
            '/casperjs/i',
            '/karma/i',
            '/jest-puppeteer/i',
            '/chrome-headless/i',
            '/firefox-headless/i',
            '/headless[\s\-_]?firefox/i',
            '/remote[\s\-_]?webdriver/i',
            '/appium/i',
            '/browserstack/i',
            '/saucelabs/i',
            '/lambdatest/i',

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

            // Generic automation and tools (more specific patterns to avoid false positives)
            '/^python-/i',        // Only user agents starting with "python-" (not apps containing "python")
            '/^curl\//i',         // Only user agents starting with "curl/" (not apps containing "curl")
            '/^wget\//i',         // Only user agents starting with "wget/" (not apps containing "wget")
            '/apache-httpclient\/[0-9]/i', // Specific Apache HTTP Client pattern
            '/^java\//i',         // Only user agents starting with "java/" (not apps containing "java")
            '/^node\.js\//i',     // Only user agents starting with "node.js/" (not apps containing "node")
            '/^go-http-client\//i', // Only user agents starting with "go-http-client/"
            '/^http_request/i',   // Only user agents starting with "http_request"
            '/^ruby\//i',         // Only user agents starting with "ruby/" (not apps containing "ruby")
            '/^perl\//i',         // Only user agents starting with "perl/" (not apps containing "perl")
            '/libwww-perl/i',     // More specific libwww pattern

            // AI/ML crawlers
            '/gptbot/i', // Added
            '/chatgpt/i', // Added
            '/claudebot/i', // Added
            '/anthropic/i', // Added
            '/openai/i', // Added
            '/perplexity/i', // Added
            '/cohere/i', // Added

            // Academic and research bots (more specific patterns)
            '/researchbot/i',    // Added
            '/academicbot/i',    // Added
            '/university.*bot/i', // Only university bots, not general university references

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
        
        // Additional checks for common selenium characteristics
        // Check for user agents that look like real browsers but have selenium characteristics
        if (stripos($user_agent, 'mozilla') !== false && stripos($user_agent, 'webkit') !== false) {
            // Check for suspicious version combinations or missing expected components
            if (stripos($user_agent, 'selenium') !== false || 
                stripos($user_agent, 'webdriver') !== false ||
                stripos($user_agent, 'chromedriver') !== false ||
                stripos($user_agent, 'geckodriver') !== false) {
                return true;
            }
            
            // Check for common automation frameworks embedded in real browser UAs
            if (preg_match('/selenium[\s\-_\/]?[\d\.]+/i', $user_agent) ||
                preg_match('/webdriver[\s\-_\/]?[\d\.]+/i', $user_agent) ||
                preg_match('/chrome[\s\-_\/]?driver/i', $user_agent) ||
                preg_match('/gecko[\s\-_\/]?driver/i', $user_agent)) {
                return true;
            }
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
        // Note: accept: '*/*' is actually common and legitimate for AJAX requests, mobile browsers, and APIs
        // Only flag if accept header is completely missing AND other suspicious patterns exist
        if (empty($accept) && $missing_count >= 1) {
            return true;
        }

        // Note: Connection: close can be legitimate (mobile browsers, HTTP/1.0 clients, proxies)
        // Only flag if combined with other suspicious indicators
        // Removing this check to reduce false positives

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

        // Known bot/hosting provider IP ranges (hosting providers commonly used by automated bots)
        // NOTE: Cloudflare IPs removed as they represent legitimate users behind Cloudflare proxy
        $suspicious_ip_ranges = array(
            // Major cloud providers commonly used by bots (but not CDN/proxy services)
            '13.107.42.0/24',     // Microsoft Azure (specific bot-heavy ranges)
            '20.36.0.0/14',       // Microsoft Azure
            '40.74.0.0/15',       // Microsoft Azure
            '52.0.0.0/11',        // Amazon AWS
            '54.0.0.0/15',        // Amazon AWS
            '35.0.0.0/8',         // Google Cloud
            '34.0.0.0/9',         // Google Cloud
            
            // Known VPS/hosting providers commonly used by bots
            '45.77.0.0/16',       // Vultr
            '108.61.0.0/16',      // Vultr  
            '207.148.0.0/16',     // Vultr
            '149.28.0.0/16',      // Vultr
            '95.179.0.0/16',      // Vultr
            '144.202.0.0/16',     // Vultr
            '198.13.32.0/20',     // Vultr
            
            // DigitalOcean ranges commonly used by bots
            '165.227.0.0/16',     // DigitalOcean
            '142.93.0.0/16',      // DigitalOcean
            '164.90.0.0/16',      // DigitalOcean
            '167.99.0.0/16',      // DigitalOcean
            '178.62.0.0/16',      // DigitalOcean
            
            // Linode ranges
            '172.104.0.0/15',     // Linode
            '139.144.0.0/16',     // Linode
            '45.79.0.0/16',       // Linode
            
            // OVH ranges commonly used by bots
            '51.254.0.0/16',      // OVH
            '151.80.0.0/16'       // OVH
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
        
        // Check for automation tools in user agent (enhanced patterns)
        $automation_patterns = array(
            '/^curl\//i',               // Only user agents starting with "curl/"
            '/^wget\//i',               // Only user agents starting with "wget/"
            '/automation/i',            // Generic automation indicator
            '/postman/i',               // API testing tool
            '/insomnia/i',              // API testing tool
            '/selenium/i',              // Browser automation
            '/selenium[\s\-_]?server/i', // Selenium server patterns
            '/selenium[\s\-_]?webdriver/i', // Selenium webdriver patterns
            '/selenium[\s\-_]?grid/i',   // Selenium grid patterns
            '/webdriver/i',             // Browser automation
            '/chromedriver/i',          // Chrome WebDriver
            '/geckodriver/i',           // Firefox WebDriver
            '/edgedriver/i',            // Edge WebDriver
            '/safaridriver/i',          // Safari WebDriver
            '/puppeteer/i',             // Browser automation
            '/playwright/i',            // Browser automation
            '/phantomjs/i',             // Headless browser
            '/testcafe/i',              // TestCafe automation
            '/nightwatch/i',            // Nightwatch automation
            '/webdriverio/i',           // WebdriverIO automation
            '/protractor/i',            // Protractor automation
            '/zombie\.js/i',            // Zombie.js headless browser
            '/casperjs/i',              // CasperJS automation
            '/karma/i',                 // Karma test runner
            '/jest-puppeteer/i',        // Jest with Puppeteer
            '/chrome-headless/i',       // Headless Chrome
            '/firefox-headless/i',      // Headless Firefox
            '/headless[\s\-_]?firefox/i', // Various headless Firefox patterns
            '/remote[\s\-_]?webdriver/i', // Remote WebDriver
            '/appium/i',                // Mobile automation
            '/browserstack/i',          // BrowserStack automation
            '/saucelabs/i',             // SauceLabs automation
            '/lambdatest/i',            // LambdaTest automation
            '/automated/i',             // Generic automated indicator
            '/robot/i'                  // Generic robot indicator
        );

        foreach ($automation_patterns as $pattern) {
            if (preg_match($pattern, $user_agent)) {
                return true;
            }
        }

        // Check content type (more lenient for legitimate requests)
        $content_type = $request->get_header('content-type');
        if ($content_type && !preg_match('/application\/json|text\/plain|application\/x-www-form-urlencoded/i', $content_type)) {
            return true; // Allow common legitimate content types
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

        // Only flag referrers that are actually suspicious (not legitimate search traffic)
        $suspicious_patterns = array(
            '/bot/i',          // Referrers containing "bot" 
            '/crawl/i',        // Referrers containing "crawl"
            '/spider/i',       // Referrers containing "spider"
            '/scraper/i',      // Referrers containing "scraper"
            '/automated/i',    // Referrers containing "automated"
            '/test\..*\.com/i' // Test domains that shouldn't be referrers
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
        // This endpoint receives unencrypted payloads and encrypts them server-side using permanent encryption
        $original_body = $request->get_json_params();
        
        // SECURITY: For encrypted endpoint, we use enhanced session validation instead of URL nonces
        // This is much safer than exposing nonces in URL parameters which can be logged/cached
        
        // The session-based validation happens in check_strong_permission() which is already
        // applied to this route, providing adequate security for encrypted requests
        
        // Validate that we have a valid payload structure (events array expected)
        if (!isset($original_body['events']) || !is_array($original_body['events'])) {
            $client_ip = $this->get_client_ip($request);
            $this->logger->warning('Encrypted endpoint accessed without valid events payload from IP: ' . $client_ip);
            $event_name = $this->extract_event_name_from_request($original_body);
            
            $this->event_logger->create_event_record(
                $request->get_body(),
                'error', // monitor_status
                $this->get_essential_headers($request),
                false,
                array(
                    'event_name' => $event_name,
                    'reason' => 'Encrypted endpoint accessed without valid events payload',
                    'error_type' => 'endpoint_access_violation',
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
        
        // Check if encryption is enabled
        $encryption_enabled = get_option('ga4_jwt_encryption_enabled', false);
        if (!$encryption_enabled) {
            // If encryption is not enabled, treat as regular unencrypted request
            return $this->send_events($request);
        }
        
        try {
            // Get encryption key
            $encryption_key = GA4_Encryption_Util::retrieve_encrypted_key('ga4_jwt_encryption_key');
            if (!$encryption_key) {
                throw new \Exception('Encryption is enabled but no encryption key is configured');
            }
            
            // Encrypt the payload using permanent JWT encryption
            $payload_json = wp_json_encode($original_body);
            $encrypted_jwt = GA4_Encryption_Util::create_permanent_jwt_token($payload_json, $encryption_key);
            
            if ($encrypted_jwt === false) {
                throw new \Exception('Failed to encrypt payload with permanent key');
            }
            
            // Create a new request with the encrypted payload in the expected format
            $encrypted_body = array('jwt' => $encrypted_jwt);
            
            // Create a new request object with the encrypted body
            $new_request = new \WP_REST_Request($request->get_method(), $request->get_route());
            $new_request->set_headers($request->get_headers());
            $new_request->set_body(wp_json_encode($encrypted_body));
            $new_request->set_header('content-type', 'application/json');
            
            // Add header to indicate this is encrypted (so it gets decrypted properly)
            $new_request->set_header('X-Encrypted', 'true');
            // Also add a header to indicate this was encrypted server-side for debugging
            $new_request->set_header('X-Server-Side-Encrypted', 'true');
            
            // Delegate to the main send_events method with encrypted payload
            return $this->send_events($new_request);
            
        } catch (\Exception $e) {
            $client_ip = $this->get_client_ip($request);
            $this->logger->error('Failed to encrypt payload at encrypted endpoint: ' . $e->getMessage() . ' from IP: ' . $client_ip);
            
            $this->event_logger->create_event_record(
                $request->get_body(),
                'error', // monitor_status
                $this->get_essential_headers($request),
                false,
                array(
                    'event_name' => 'encryption_error',
                    'reason' => 'Failed to encrypt payload: ' . $e->getMessage(),
                    'ip_address' => $client_ip,
                    'user_agent' => $request->get_header('user-agent'),
                    'url' => $request->get_header('origin'),
                    'referrer' => $request->get_header('referer')
                )
            );
            
            return new \WP_REST_Response(
                array('error' => 'Failed to process encrypted request'),
                500
            );
        }
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
                // Log comprehensive bot detection details only if extensive error logging is enabled
                $extensive_logging = get_option('ga4_extensive_logging', false);
                if ($extensive_logging) {
                    $event_name = $this->extract_event_name_from_request($request->get_json_params());
                    $bot_detection_details = $this->get_bot_detection_details($request);
                    $error_message = $this->generate_bot_detection_error_message($bot_detection_details, $client_ip, $request->get_header('user-agent'));
                    
                    $this->event_logger->create_event_record(
                        $request->get_body(),
                        'bot_detected', // monitor_status
                        $this->get_essential_headers($request),
                        false,
                        array(
                            'event_name' => $event_name,
                            'reason' => 'Multi-factor bot detection triggered',
                            'error_message' => $error_message,
                            'error_type' => 'bot_request_blocked',
                            'ip_address' => $client_ip,
                            'user_agent' => $request->get_header('user-agent'),
                            'url' => $request->get_header('origin'),
                            'referrer' => $request->get_header('referer'),
                            'session_id' => $session_id,
                            'bot_detection_rules' => $bot_detection_details
                        )
                    );
                }

                $this->logger->warning("Bot detected attempting to send events - blocked from database storage. IP: {$client_ip}, User-Agent: " . $request->get_header('user-agent') . ", Referer: " . $request->get_header('referer'));
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
                // Log rate limiting event only if extensive error logging is enabled
                $extensive_logging = get_option('ga4_extensive_logging', false);
                if ($extensive_logging) {
                    $event_name = $this->extract_event_name_from_request($request->get_json_params());
                    $rate_limit_info = array_merge($rate_limit_check, array('ip' => $client_ip));
                    $error_message = $this->generate_rate_limit_error_message($rate_limit_info);
                    
                    $this->event_logger->create_event_record(
                        substr($request->get_body(), 0, 1000) . '...', // Truncate large payloads
                        'denied', // monitor_status
                        $this->get_essential_headers($request),
                        false,
                        array(
                            'event_name' => $event_name,
                            'reason' => 'Rate limit exceeded: ' . $rate_limit_check['retry_after'] . 's retry',
                            'error_message' => $error_message,
                            'error_type' => 'rate_limit_exceeded',
                            'ip_address' => $client_ip,
                            'user_agent' => $request->get_header('user-agent'),
                            'url' => $request->get_header('origin'),
                            'referrer' => $request->get_header('referer'),
                            'session_id' => $session_id
                        )
                    );
                }

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
                $event_name = $this->extract_event_name_from_request($request->get_json_params());
                
                $error_message = $this->generate_validation_error_message('empty_request', 'Request body was empty or could not be parsed');
                
                $this->event_logger->create_event_record(
                    $request->get_body(),
                    'error', // monitor_status
                    $this->get_essential_headers($request),
                    false,
                    array(
                        'event_name' => $event_name,
                        'reason' => 'Empty request data received',
                        'error_message' => $error_message,
                        'error_type' => 'data_validation_error',
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
                    $event_name = $this->extract_event_name_from_request($request_data);
                    $error_message = $this->generate_validation_error_message('empty_events_array', 'The events array is empty or missing');
                    
                    $this->event_logger->create_event_record(
                        $request->get_body(),
                        'error', // monitor_status
                        $this->get_essential_headers($request),
                        false,
                        array(
                            'event_name' => $event_name,
                            'reason' => 'Empty events array received',
                            'error_message' => $error_message,
                            'error_type' => 'data_validation_error',
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
                        $event_name = $this->extract_event_name_from_request($request_data);
                        $error_message = $this->generate_validation_error_message('invalid_event_format', "Event at index {$index} is not an array - expected event object");
                        
                        $this->event_logger->create_event_record(
                            $request->get_body(),
                            'error', // monitor_status
                            $this->get_essential_headers($request),
                            false,
                            array(
                                'event_name' => $event_name,
                                'reason' => "Event at index {$index} is not an array",
                                'error_message' => $error_message,
                                'error_type' => 'data_validation_error',
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
                        $event_name = isset($event['name']) ? $event['name'] : 'unknown';
                        $error_message = $this->generate_validation_error_message('missing_event_name', "Event at index {$index} is missing required 'name' field");
                        
                        $this->event_logger->create_event_record(
                            $request->get_body(),
                            'error', // monitor_status
                            $this->get_essential_headers($request),
                            false,
                            array(
                                'event_name' => $event_name,
                                'reason' => "Missing event name at index {$index}",
                                'error_message' => $error_message,
                                'error_type' => 'data_validation_error',
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
                $event_name = $this->extract_event_name_from_request($request_data);
                $this->event_logger->create_event_record(
                    $request->get_body(),
                    'error', // monitor_status
                    $this->get_essential_headers($request),
                    false,
                    array(
                        'event_name' => $event_name,
                        'reason' => 'Missing events array or single event data',
                        'error_type' => 'data_validation_error',
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

            // Apply admin consent override if enabled
            $force_consent_enabled = get_option('ga4_force_consent_enabled', false);
            if ($force_consent_enabled) {
                $force_consent_value = get_option('ga4_force_consent_value', 'GRANTED');
                $this->logger->info("Admin consent override applied: overriding user consent with {$force_consent_value}");
                $request_data['consent'] = array(
                    'ad_user_data' => $force_consent_value,
                    'ad_personalization' => $force_consent_value,
                    'consent_reason' => 'admin_override'
                );
            }

            // WordPress-side bot detection (mirrors Cloudflare Worker logic)
            $bot_detection_result = $this->detect_bot_from_event_data($request, $request_data);
            if ($bot_detection_result['is_bot']) {
                // Log each event as bot detected only if extensive error logging is enabled
                $extensive_logging = get_option('ga4_extensive_logging', false);
                if ($extensive_logging) {
                    foreach ($request_data['events'] as $event) {
                        $this->event_logger->create_event_record(
                            json_encode($request_data, JSON_PRETTY_PRINT),
                            'bot_detected', // monitor_status
                            $this->get_essential_headers($request),
                            false,
                            array(
                                'event_name' => $event['name'] ?? 'unknown',
                                'reason' => 'WordPress-side bot detection: Score ' . $bot_detection_result['score'] . '/100',
                                'error_type' => 'wordpress_bot_detection',
                                'ip_address' => $client_ip,
                                'user_agent' => $request->get_header('user-agent'),
                                'url' => $request->get_header('origin'),
                                'referrer' => $request->get_header('referer'),
                                'session_id' => $session_id,
                                'consent_given' => $this->extract_consent_status($request_data),
                                'consent_data' => $request_data['consent'] ?? null,
                                'bot_detection_rules' => array_merge($this->get_bot_detection_details($request), $bot_detection_result)
                            )
                        );
                    }
                }

                    $this->logger->warning("Bot detected via WordPress endpoint - blocked from processing. IP: {$client_ip}, User-Agent: " . $request->get_header('user-agent') . ", Bot Score: {$bot_detection_result['score']}, Reasons: " . implode(', ', $bot_detection_result['reasons']) . ", Event Count: " . count($request_data['events']));
                    
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

            // Check if direct sending is enabled or cronjob batching is disabled or WP-Cron is unavailable
            $send_events_directly = get_option('ga4_send_events_directly', false);
            $cronjob_enabled = true;
            $wp_cron_disabled = defined('DISABLE_WP_CRON') && DISABLE_WP_CRON;
            
            if ($send_events_directly || !$cronjob_enabled || $wp_cron_disabled) {
                // Use direct sending if enabled or if cronjobs disabled or WP-Cron is disabled
                if ($send_events_directly) {
                    $this->logger->info('Direct event sending is enabled, bypassing queue processing');
                } elseif ($wp_cron_disabled) {
                    $this->logger->info('WP-Cron is disabled (DISABLE_WP_CRON=true), using direct sending instead of queue');
                }
                return $this->send_events_directly($request_data, $start_time, $session_id, $request);
            }
            
            // Queue events for batch processing instead of sending directly
            $queued_events = 0;
            $failed_events = 0;
            
            // Check if encryption is enabled for storing in database
            $encryption_enabled = (bool) get_option('ga4_jwt_encryption_enabled', false);
            
            // Determine if the original request was encrypted (regular JWT or server-side encrypted)
            $original_request_body = $request->get_json_params();
            $was_originally_encrypted = (isset($original_request_body['encrypted']) && $original_request_body['encrypted'] === true) ||
                                       $request->get_header('X-Server-Side-Encrypted') === 'true';
            
            // Process each event for queuing
            foreach ($request_data['events'] as $event) {
                // Check for duplicate transaction_id for purchase events
                if (isset($event['name']) && $event['name'] === 'purchase' && 
                    isset($event['params']['transaction_id'])) {
                    
                    $transaction_id = $event['params']['transaction_id'];
                    
                    if ($this->event_logger->transaction_exists($transaction_id)) {
                        // Log the duplicate attempt
                        $this->event_logger->create_event_record(
                            array(
                                'event' => $event,
                                'consent' => $request_data['consent'] ?? null,
                                'batch' => $request_data['batch'] ?? false,
                                'timestamp' => time()
                            ),
                            'denied', // monitor_status - denied due to duplicate
                            $this->get_essential_headers($request),
                            $was_originally_encrypted,
                            array(
                                'event_name' => $event['name'],
                                'reason' => "Duplicate transaction_id: {$transaction_id}",
                                'error_type' => 'duplicate_transaction',
                                'ip_address' => $client_ip,
                                'user_agent' => $request->get_header('user-agent'),
                                'url' => $request->get_header('origin'),
                                'referrer' => $request->get_header('referer'),
                                'session_id' => $session_id,
                                'consent_given' => $this->extract_consent_status($request_data),
                                'consent_data' => $request_data['consent'] ?? null,
                                'batch_size' => count($request_data['events']),
                                'duplicate_transaction_id' => $transaction_id
                            )
                        );
                        
                        $this->logger->warning("Duplicate purchase transaction blocked: {$transaction_id} from IP: {$client_ip}");
                        $failed_events++;
                        continue; // Skip processing this event
                    }
                }
                
                // Check for duplicate conversion_id for form_conversion events
                if (isset($event['name']) && $event['name'] === 'form_conversion' && 
                    isset($event['params']['conversion_id'])) {
                    
                    $conversion_id = $event['params']['conversion_id'];
                    
                    if ($this->event_logger->conversion_exists($conversion_id)) {
                        // Log the duplicate attempt
                        $this->event_logger->create_event_record(
                            array(
                                'event' => $event,
                                'consent' => $request_data['consent'] ?? null,
                                'batch' => $request_data['batch'] ?? false,
                                'timestamp' => time()
                            ),
                            'denied', // monitor_status - denied due to duplicate
                            $this->get_essential_headers($request),
                            $was_originally_encrypted,
                            array(
                                'event_name' => $event['name'],
                                'reason' => "Duplicate conversion_id: {$conversion_id}",
                                'error_type' => 'duplicate_conversion',
                                'ip_address' => $client_ip,
                                'user_agent' => $request->get_header('user-agent'),
                                'url' => $request->get_header('origin'),
                                'referrer' => $request->get_header('referer'),
                                'session_id' => $session_id,
                                'consent_given' => $this->extract_consent_status($request_data),
                                'consent_data' => $request_data['consent'] ?? null,
                                'batch_size' => count($request_data['events']),
                                'duplicate_conversion_id' => $conversion_id
                            )
                        );
                        
                        $this->logger->warning("Duplicate form conversion blocked: {$conversion_id} from IP: {$client_ip}");
                        $failed_events++;
                        continue; // Skip processing this event
                    }
                }
                
                // Check for duplicate conversion_id for quote_request events  
                if (isset($event['name']) && $event['name'] === 'quote_request' && 
                    isset($event['params']['conversion_id'])) {
                    
                    $conversion_id = $event['params']['conversion_id'];
                    
                    if ($this->event_logger->conversion_exists($conversion_id)) {
                        // Log the duplicate attempt
                        $this->event_logger->create_event_record(
                            array(
                                'event' => $event,
                                'consent' => $request_data['consent'] ?? null,
                                'batch' => $request_data['batch'] ?? false,
                                'timestamp' => time()
                            ),
                            'denied', // monitor_status - denied due to duplicate
                            $this->get_essential_headers($request),
                            $was_originally_encrypted,
                            array(
                                'event_name' => $event['name'],
                                'reason' => "Duplicate conversion_id: {$conversion_id}",
                                'error_type' => 'duplicate_conversion',
                                'ip_address' => $client_ip,
                                'user_agent' => $request->get_header('user-agent'),
                                'url' => $request->get_header('origin'),
                                'referrer' => $request->get_header('referer'),
                                'session_id' => $session_id,
                                'consent_given' => $this->extract_consent_status($request_data),
                                'consent_data' => $request_data['consent'] ?? null,
                                'batch_size' => count($request_data['events']),
                                'duplicate_conversion_id' => $conversion_id
                            )
                        );
                        
                        $this->logger->warning("Duplicate quote request conversion blocked: {$conversion_id} from IP: {$client_ip}");
                        $failed_events++;
                        continue; // Skip processing this event
                    }
                }
                
                // Prepare event data with context
                $event_data = array(
                    'event' => $event,
                    'consent' => $request_data['consent'] ?? null,
                    'batch' => $request_data['batch'] ?? false,
                    'timestamp' => time()
                );
                
                // Note: Encryption will be handled by the event logger's log_event method
                // based on the ga4_jwt_encryption_enabled setting, so we don't encrypt here
                $should_encrypt = $encryption_enabled;
                
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
                        'original_payload' => $event_data,  // Always pass array data - encryption handled by log_event
                        'ip_address' => $client_ip,
                        'user_agent' => $request->get_header('user-agent'),
                        'url' => $request->get_header('origin'),
                        'referrer' => $request->get_header('referer'),
                        'session_id' => $session_id,
                        'consent_given' => $this->extract_consent_status($request_data),
                        'consent_data' => $request_data['consent'] ?? null,
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
            
            $event_name = $this->extract_event_name_from_request($request->get_json_params());
            $this->event_logger->create_event_record(
                $request->get_body(),
                'error', // monitor_status
                $this->get_essential_headers($request),
                false,
                array(
                    'event_name' => $event_name,
                    'reason' => 'Processing exception: ' . $e->getMessage(),
                    'error_type' => 'system_error',
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
        $processing_time = round((microtime(true) - $start_time) * 1000, 2);
        
        // Determine if the original request was encrypted
        $original_request_body = $request->get_json_params();
        $was_originally_encrypted = (isset($original_request_body['encrypted']) && $original_request_body['encrypted'] === true) ||
                                   $request->get_header('X-Server-Side-Encrypted') === 'true';
        
        // Check if encryption is enabled for processing
        $encryption_enabled = (bool) get_option('ga4_jwt_encryption_enabled', false);
        
        // Get original request headers
        $original_headers = $this->get_essential_headers($request);
        
        // Check for duplicate transactions in direct sending mode
        $client_ip = $this->get_client_ip($request);
        $events_to_process = array();
        $duplicate_count = 0;
        
        foreach ($request_data['events'] as $event) {
            // Check for duplicate transaction_id for purchase events
            if (isset($event['name']) && $event['name'] === 'purchase' && 
                isset($event['params']['transaction_id'])) {
                
                $transaction_id = $event['params']['transaction_id'];
                
                if ($this->event_logger->transaction_exists($transaction_id)) {
                    // Log the duplicate attempt
                    $this->event_logger->create_event_record(
                        array(
                            'event' => $event,
                            'consent' => $request_data['consent'] ?? null,
                            'batch' => $request_data['batch'] ?? false,
                            'timestamp' => time()
                        ),
                        'denied', // monitor_status - denied due to duplicate
                        $original_headers,
                        $was_originally_encrypted,
                        array(
                            'event_name' => $event['name'],
                            'reason' => "Duplicate transaction_id: {$transaction_id} (direct mode)",
                            'error_type' => 'duplicate_transaction',
                            'ip_address' => $client_ip,
                            'user_agent' => $request->get_header('user-agent'),
                            'url' => $request->get_header('origin'),
                            'referrer' => $request->get_header('referer'),
                            'session_id' => $session_id,
                            'consent_given' => $this->extract_consent_status($request_data),
                            'consent_data' => $request_data['consent'] ?? null,
                            'duplicate_transaction_id' => $transaction_id
                        )
                    );
                    
                    $this->logger->warning("Duplicate purchase transaction blocked (direct mode): {$transaction_id} from IP: {$client_ip}");
                    $duplicate_count++;
                    continue; // Skip this event
                }
            }
            
            // Check for duplicate conversion_id for form_conversion events
            if (isset($event['name']) && $event['name'] === 'form_conversion' && 
                isset($event['params']['conversion_id'])) {
                
                $conversion_id = $event['params']['conversion_id'];
                
                if ($this->event_logger->conversion_exists($conversion_id)) {
                    // Log the duplicate attempt
                    $this->event_logger->create_event_record(
                        array(
                            'event' => $event,
                            'consent' => $request_data['consent'] ?? null,
                            'batch' => $request_data['batch'] ?? false,
                            'timestamp' => time()
                        ),
                        'denied', // monitor_status - denied due to duplicate
                        $original_headers,
                        $was_originally_encrypted,
                        array(
                            'event_name' => $event['name'],
                            'reason' => "Duplicate conversion_id: {$conversion_id} (direct mode)",
                            'error_type' => 'duplicate_conversion',
                            'ip_address' => $client_ip,
                            'user_agent' => $request->get_header('user-agent'),
                            'url' => $request->get_header('origin'),
                            'referrer' => $request->get_header('referer'),
                            'session_id' => $session_id,
                            'consent_given' => $this->extract_consent_status($request_data),
                            'consent_data' => $request_data['consent'] ?? null,
                            'duplicate_conversion_id' => $conversion_id
                        )
                    );
                    
                    $this->logger->warning("Duplicate form conversion blocked (direct mode): {$conversion_id} from IP: {$client_ip}");
                    $duplicate_count++;
                    continue; // Skip this event
                }
            }
            
            // Check for duplicate conversion_id for quote_request events  
            if (isset($event['name']) && $event['name'] === 'quote_request' && 
                isset($event['params']['conversion_id'])) {
                
                $conversion_id = $event['params']['conversion_id'];
                
                if ($this->event_logger->conversion_exists($conversion_id)) {
                    // Log the duplicate attempt
                    $this->event_logger->create_event_record(
                        array(
                            'event' => $event,
                            'consent' => $request_data['consent'] ?? null,
                            'batch' => $request_data['batch'] ?? false,
                            'timestamp' => time()
                        ),
                        'denied', // monitor_status - denied due to duplicate
                        $original_headers,
                        $was_originally_encrypted,
                        array(
                            'event_name' => $event['name'],
                            'reason' => "Duplicate conversion_id: {$conversion_id} (direct mode)",
                            'error_type' => 'duplicate_conversion',
                            'ip_address' => $client_ip,
                            'user_agent' => $request->get_header('user-agent'),
                            'url' => $request->get_header('origin'),
                            'referrer' => $request->get_header('referer'),
                            'session_id' => $session_id,
                            'consent_given' => $this->extract_consent_status($request_data),
                            'consent_data' => $request_data['consent'] ?? null,
                            'duplicate_conversion_id' => $conversion_id
                        )
                    );
                    
                    $this->logger->warning("Duplicate quote request conversion blocked (direct mode): {$conversion_id} from IP: {$client_ip}");
                    $duplicate_count++;
                    continue; // Skip this event
                }
            }
            
            // Add event to processing list
            $events_to_process[] = $event;
        }
        
        // If all events were duplicates, return error
        if (empty($events_to_process)) {
            return new \WP_REST_Response(array(
                'success' => false,
                'events_failed' => $event_count,
                'duplicates_blocked' => $duplicate_count,
                'processing_method' => 'direct',
                'processing_time_ms' => $processing_time,
                'error' => 'All events were duplicate transactions',
                'message' => 'All purchase events have already been processed'
            ), 400);
        }
        
        // Update request data with filtered events
        $request_data['events'] = $events_to_process;
        
        // Add session_id to the request data for logging
        $request_data['session_id'] = $session_id;
        
        // Send the complete payload structure as-is to the cronjob manager
        $result = $this->cronjob_manager->send_events_directly(
            $request_data, // Send the complete original payload structure
            $encryption_enabled,
            $original_headers,
            $was_originally_encrypted
        );
        
        // Return appropriate response based on results
        if ($result['success']) {
            $response_data = array(
                'success' => true,
                'events_processed' => count($events_to_process),
                'processing_method' => 'direct',
                'transmission_method' => $result['transmission_method'] ?? 'unknown',
                'processing_time_ms' => $processing_time
            );
            
            // Include duplicate information if any were blocked
            if ($duplicate_count > 0) {
                $response_data['duplicates_blocked'] = $duplicate_count;
                $response_data['total_events_received'] = $event_count;
            }
            
            return new \WP_REST_Response($response_data, 200);
        } else {
            return new \WP_REST_Response(array(
                'success' => false,
                'events_failed' => count($events_to_process),
                'duplicates_blocked' => $duplicate_count,
                'total_events_received' => $event_count,
                'processing_method' => 'direct',
                'transmission_method' => $result['transmission_method'] ?? 'unknown',
                'processing_time_ms' => $processing_time,
                'error' => $result['message'],
                'details' => $result['message']
            ), 500);
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

        // Always log to file-based logger
        $this->logger->log_data($failure_data, 'Security Failure');
        
        // Always log bot detection to Event Monitor database for visibility
        // Other security failures only logged if extensive error logging is enabled
        $extensive_logging = get_option('ga4_extensive_logging', false);
        if ($extensive_logging || $failure_type === 'BOT_DETECTED') {
            $event_name = $this->extract_event_name_from_request($request->get_json_params());
            
            // Map failure types to monitor status
            $monitor_status = ($failure_type === 'BOT_DETECTED') ? 'bot_detected' : 'denied';
            
            $this->event_logger->create_event_record(
                $request->get_body(),
                $monitor_status, // monitor_status
                $this->get_essential_headers($request),
                false,
                array(
                    'event_name' => $event_name,
                    'reason' => $message,
                    'error_type' => strtolower($failure_type),
                    'ip_address' => $client_ip,
                    'user_agent' => $user_agent,
                    'url' => $request->get_header('origin'),
                    'referrer' => $request->get_header('referer'),
                    'endpoint' => $endpoint,
                    'security_failure_type' => $failure_type
                )
            );
        }
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
        // Check if this is a test scenario first
        if (isset($request->scenario_data)) {
            return $request->scenario_data['ip'];
        }
        
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
            $client_ip = $this->get_client_ip($request);
            $event_name = $this->extract_event_name_from_request($request_body);
            
            $this->event_logger->create_event_record(
                $request->get_body(),
                'error', // monitor_status
                $this->get_essential_headers($request),
                false,
                array(
                    'event_name' => $event_name,
                    'reason' => 'Encryption is enabled but no permanent encryption key is configured',
                    'error_type' => 'encryption_key_missing',
                    'ip_address' => $client_ip,
                    'user_agent' => $request->get_header('user-agent'),
                    'url' => $request->get_header('origin'),
                    'referrer' => $request->get_header('referer')
                )
            );
            
            throw new \Exception('Encryption is enabled but no permanent encryption key is configured');
        }

        try {
            $decrypted_data = GA4_Encryption_Util::parse_encrypted_request($request_body, $encryption_key);
            return $decrypted_data;
        } catch (\Exception $e) {
            $client_ip = $this->get_client_ip($request);
            $this->logger->error('Failed to verify permanent JWT request from IP ' . $client_ip . ': ' . $e->getMessage());
            $event_name = $this->extract_event_name_from_request($request_body);
            
            $this->event_logger->create_event_record(
                $request->get_body(),
                'error', // monitor_status
                $this->get_essential_headers($request),
                false,
                array(
                    'event_name' => $event_name,
                    'reason' => 'Permanent JWT request verification failed: ' . $e->getMessage(),
                    'error_type' => 'jwt_decryption_failed',
                    'ip_address' => $client_ip,
                    'user_agent' => $request->get_header('user-agent'),
                    'url' => $request->get_header('origin'),
                    'referrer' => $request->get_header('referer')
                )
            );
            
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
    private function is_key_in_encrypted_format($key)
    {
        // Encrypted keys are base64 encoded JSON with specific structure
        try {
            // Try to decode as base64
            $decoded = base64_decode($key, true);
            if ($decoded === false) {
                return false;
            }
            
            // Try to parse as JSON
            $json_data = json_decode($decoded, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                return false;
            }
            
            // Check if it has the expected encrypted key structure
            if (is_array($json_data) && isset($json_data['data'], $json_data['iv'], $json_data['tag'])) {
                return true;
            }
            
            return false;
        } catch (\Exception $e) {
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
        
        // 2. Skip WordPress botData analysis (removed)
        $bot_checks[] = false;
        
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
        
        // Balanced detection: require at least 2 positive checks OR very high score to classify as bot
        $positive_checks = array_filter($bot_checks);
        $is_bot = count($positive_checks) >= 2 || $total_score >= 60;
        
        return array(
            'is_bot' => $is_bot,
            'score' => $total_score,
            'reasons' => $reasons,
            'positive_checks' => count($positive_checks),
            'check_details' => array(
                'wordpress_patterns' => $bot_checks[0],
                'botdata_analysis' => false,
                'behavior_patterns' => $bot_checks[1],
                'user_agent' => $bot_checks[2]
            )
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
        
        // Check for suspiciously fast navigation (bot-like rapid page changes)
        if (isset($bot_data['page_load_time']) && $bot_data['page_load_time'] < 100) {
            $score += 15;
            $indicators[] = 'impossibly_fast_navigation';
        }
        
        // Check for missing interaction data (bots often don't simulate mouse/keyboard properly)
        if (isset($bot_data['mouse_movements']) && $bot_data['mouse_movements'] === 0) {
            $score += 20;
            $indicators[] = 'no_mouse_movement';
        }
        
        // Check for unnatural event sequences (page_view immediately followed by purchase without clicks)
        if (isset($event_params['event_name']) && $event_params['event_name'] === 'purchase' && 
            isset($bot_data['time_since_last_interaction']) && $bot_data['time_since_last_interaction'] < 1000) {
            $score += 25;
            $indicators[] = 'unnatural_purchase_sequence';
        }
        
        // Check for missing referrer on direct navigation (suspicious for automated tools)
        if (isset($event_params['page_referrer']) && empty($event_params['page_referrer']) &&
            isset($event_params['source']) && $event_params['source'] === '(direct)') {
            $score += 10;
            $indicators[] = 'suspicious_direct_navigation';
        }
        
        // Check for identical repeated values (automation often uses fixed values)
        if (isset($bot_data['viewport_width']) && isset($bot_data['viewport_height'])) {
            $viewport_width = intval($bot_data['viewport_width']);
            $viewport_height = intval($bot_data['viewport_height']);
            // Common automation default screen sizes
            if (($viewport_width === 1024 && $viewport_height === 768) ||
                ($viewport_width === 1366 && $viewport_height === 768) ||
                ($viewport_width === 1920 && $viewport_height === 1080)) {
                $score += 8;
                $indicators[] = 'common_automation_screen_size';
            }
        }
        
        return array(
            'is_bot' => $score >= 30, // More balanced threshold for bot classification
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
        
        // Additional automation tool patterns not in existing check (enhanced)
        $automation_patterns = array(
            '/puppeteer/i',
            '/playwright/i',
            '/cypress/i',
            '/testcafe/i',
            '/nightwatch/i',
            '/webdriverio/i',
            '/selenium[\s\-_]?server/i',
            '/selenium[\s\-_]?webdriver/i',
            '/selenium[\s\-_]?grid/i',
            '/chromedriver/i',
            '/geckodriver/i',
            '/edgedriver/i',
            '/safaridriver/i',
            '/protractor/i',
            '/zombie\.js/i',
            '/casperjs/i',
            '/karma/i',
            '/jest-puppeteer/i',
            '/chrome-headless/i',
            '/firefox-headless/i',
            '/headless[\s\-_]?firefox/i',
            '/remote[\s\-_]?webdriver/i',
            '/appium/i',
            '/browserstack/i',
            '/saucelabs/i',
            '/lambdatest/i',
            '/automated/i',
            '/robot/i'
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
        // Check for admin consent override setting
        $force_consent_enabled = get_option('ga4_force_consent_enabled', false);
        if ($force_consent_enabled) {
            $force_consent_value = get_option('ga4_force_consent_value', 'GRANTED');
            $override_result = $force_consent_value === 'GRANTED';
            $this->logger->info('Admin consent override active: ' . json_encode(array('force_value' => $force_consent_value, 'result' => $override_result)));
            return $override_result;
        }
        
        if (!isset($request_data['consent']) || !is_array($request_data['consent'])) {
            $this->logger->info('No consent data found or not array: ' . json_encode($request_data['consent'] ?? 'not_set'));
            return null;
        }
        
        $consent = $request_data['consent'];
        
        // Check for legacy consent_mode field first
        if (isset($consent['consent_mode'])) {
            $result = $consent['consent_mode'] === 'GRANTED';
            $this->logger->info('Using legacy consent_mode: ' . json_encode(array('consent_mode' => $consent['consent_mode'], 'result' => $result)));
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
            $this->logger->info('Using ad_user_data only: ' . json_encode(array('ad_user_data' => $consent['ad_user_data'], 'result' => $result)));
            return $result;
        }
        
        if (isset($consent['ad_personalization'])) {
            $result = $ad_personalization_granted;
            $this->logger->info('Using ad_personalization only: ' . json_encode(array('ad_personalization' => $consent['ad_personalization'], 'result' => $result)));
            return $result;
        }
        
        $this->logger->info('No valid consent fields found: ' . json_encode($consent));
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
            'user_agent', 'accept_language', 'accept', 'referer',
            'accept_encoding', 'x_forwarded_for', 'x_real_ip'
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
            'detection_threshold' => 3
        );
    }

    /**
     * Generate detailed error message based on bot detection results
     *
     * @since    3.0.0
     * @param    array    $detection_details    Bot detection results.
     * @param    string   $client_ip           Client IP address.
     * @param    string   $user_agent          User agent string.
     * @return   string                        Formatted error message.
     */
    private function generate_bot_detection_error_message($detection_details, $client_ip, $user_agent)
    {
        $failed_checks = array();
        $check_descriptions = array(
            'user_agent_check' => 'User Agent patterns indicate bot behavior',
            'ip_check' => 'IP address matches known bot networks',
            'referer_check' => 'Suspicious or missing referrer information',
            'headers_check' => 'Missing essential browser headers',
            'asn_check' => 'IP belongs to suspicious ASN/hosting provider',
            'behavior_check' => 'Behavioral patterns indicate automated requests'
        );

        foreach ($detection_details as $check => $result) {
            if ($result === true && isset($check_descriptions[$check])) {
                $failed_checks[] = $check_descriptions[$check];
            }
        }

        $error_message = array(
            'type' => 'bot_detection',
            'message' => 'Multi-factor bot detection triggered',
            'failed_checks' => $failed_checks,
            'checks_failed' => count($failed_checks),
            'threshold' => 3,
            'client_info' => array(
                'ip' => $client_ip,
                'user_agent' => substr($user_agent, 0, 200) . (strlen($user_agent) > 200 ? '...' : ''),
                'detected_at' => current_time('mysql')
            ),
            'action' => 'Event blocked from processing'
        );

        return json_encode($error_message, JSON_PRETTY_PRINT);
    }

    /**
     * Generate error message for rate limiting
     *
     * @since    3.0.0
     * @param    array    $rate_limit_info    Rate limit details.
     * @return   string                       Formatted error message.
     */
    private function generate_rate_limit_error_message($rate_limit_info)
    {
        $error_message = array(
            'type' => 'rate_limit',
            'message' => 'Request rate limit exceeded',
            'limit' => 100,
            'period' => '60 seconds',
            'retry_after' => $rate_limit_info['retry_after'] . ' seconds',
            'client_info' => array(
                'ip' => $rate_limit_info['ip'] ?? 'unknown',
                'current_count' => $rate_limit_info['current_count'] ?? 'unknown',
                'window_start' => $rate_limit_info['window_start'] ?? 'unknown'
            ),
            'action' => 'Event rejected - retry after cooldown period'
        );

        return json_encode($error_message, JSON_PRETTY_PRINT);
    }

    /**
     * Generate error message for validation errors
     *
     * @since    3.0.0
     * @param    string   $validation_type     Type of validation that failed.
     * @param    string   $details            Specific error details.
     * @return   string                       Formatted error message.
     */
    private function generate_validation_error_message($validation_type, $details)
    {
        $error_message = array(
            'type' => 'validation_error',
            'validation_type' => $validation_type,
            'message' => 'Request validation failed',
            'details' => $details,
            'detected_at' => current_time('mysql'),
            'action' => 'Event rejected due to invalid data'
        );

        return json_encode($error_message, JSON_PRETTY_PRINT);
    }

    /**
     * Extract event name from request data for error logging
     *
     * @since    3.0.0
     * @param    array    $request_data    The request data (could be encrypted or decrypted).
     * @return   string                   The event name or 'unknown'.
     */
    private function extract_event_name_from_request($request_data)
    {
        if (empty($request_data) || !is_array($request_data)) {
            return 'unknown';
        }

        // Try to extract from events array (batch format)
        if (isset($request_data['events']) && is_array($request_data['events']) && !empty($request_data['events'])) {
            if (isset($request_data['events'][0]['name'])) {
                return $request_data['events'][0]['name'];
            }
        }

        // Try legacy single event format
        if (isset($request_data['event_name'])) {
            return $request_data['event_name'];
        }
        if (isset($request_data['name'])) {
            return $request_data['name'];
        }

        // For encrypted JWT payloads, try to get from 'jwt' field by parsing it
        if (isset($request_data['jwt']) && is_string($request_data['jwt'])) {
            try {
                // Try to decode the JWT payload section (without verification for event name extraction)
                $jwt_parts = explode('.', $request_data['jwt']);
                if (count($jwt_parts) >= 2) {
                    $payload = json_decode(base64_decode($jwt_parts[1]), true);
                    if (is_array($payload)) {
                        return $this->extract_event_name_from_request($payload);
                    }
                }
            } catch (\Exception $e) {
                // Ignore JWT parsing errors for event name extraction
            }
        }

        return 'unknown';
    }

    /**
     * Simulate bot requests for testing bot detection
     *
     * @since    3.0.0
     * @param    string    $scenario    Type of bot to simulate.
     * @return   array                  Test results.
     */
    public function simulate_bot_request($scenario = 'default')
    {
        if (!current_user_can('manage_options')) {
            return array('error' => 'Unauthorized');
        }

        $test_scenarios = array(
            'python_bot' => array(
                'user_agent' => 'python-requests/2.25.1',
                'headers' => array(),
                'ip' => '142.93.100.50', // DigitalOcean IP (should be flagged by ASN)
                'referer' => '',
                'expected' => 'bot_detected'
            ),
            'curl_bot' => array(
                'user_agent' => 'curl/7.68.0',
                'headers' => array('accept' => '*/*'),
                'ip' => '165.227.50.25', // DigitalOcean IP
                'referer' => '',
                'expected' => 'bot_detected'
            ),
            'selenium_bot' => array(
                'user_agent' => 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 selenium/4.0.0',
                'headers' => array(
                    'accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                    'accept-language' => 'en-US,en;q=0.5',
                    'accept-encoding' => 'gzip, deflate'
                ),
                'ip' => '52.91.75.123', // AWS IP
                'referer' => '',
                'expected' => 'bot_detected'
            ),
            'headless_chrome' => array(
                'user_agent' => 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) HeadlessChrome/91.0.4472.124 Safari/537.36',
                'headers' => array(
                    'accept' => '*/*',
                    'accept-language' => 'en-US,en;q=0.9'
                ),
                'ip' => '34.102.136.180', // Google Cloud IP
                'referer' => '',
                'expected' => 'bot_detected'
            ),
            'scraper_bot' => array(
                'user_agent' => 'Mozilla/5.0 (compatible; CustomScraper/1.0; +http://example.com/bot)',
                'headers' => array(
                    'accept' => 'text/html',
                    'from' => 'bot@example.com' // Bot indicator header
                ),
                'ip' => '45.77.125.89', // Vultr IP
                'referer' => 'http://scraper-referrer.com/bot',
                'expected' => 'bot_detected'
            ),
            'legitimate_mobile' => array(
                'user_agent' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 15_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/15.0 Mobile/15E148 Safari/604.1',
                'headers' => array(
                    'accept' => '*/*',
                    'accept-language' => 'en-US,en;q=0.9',
                    'accept-encoding' => 'gzip, deflate, br'
                ),
                'ip' => '104.28.62.24', // Cloudflare IP (legitimate user behind proxy)
                'referer' => 'https://www.bilderrahmenspezialisten.de/bilderrahmen/',
                'expected' => 'allowed'
            ),
            'legitimate_desktop' => array(
                'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36',
                'headers' => array(
                    'accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8',
                    'accept-language' => 'en-US,en;q=0.5',
                    'accept-encoding' => 'gzip, deflate'
                ),
                'ip' => '192.168.1.100',
                'referer' => 'https://www.google.com/search?q=bilderrahmen',
                'expected' => 'allowed'
            ),
            'api_integration' => array(
                'user_agent' => 'MyLegitimateApp/2.1.0 (iOS; Version 14.0; iPhone12,1)',
                'headers' => array(
                    'accept' => 'application/json',
                    'accept-language' => 'en-US',
                    'content-type' => 'application/json'
                ),
                'ip' => '203.0.113.45', // Example IP
                'referer' => '',
                'expected' => 'allowed'
            )
        );

        $scenario_data = isset($test_scenarios[$scenario]) ? $test_scenarios[$scenario] : $test_scenarios['python_bot'];
        
        // Create a mock request object
        $mock_request = $this->create_mock_request($scenario_data);
        
        // Test bot detection
        $is_bot = $this->is_bot_request($mock_request);
        $bot_details = $this->get_bot_detection_details($mock_request);
        
        // Log the test event for monitoring
        $this->log_test_event($scenario, $scenario_data, $is_bot, $bot_details);
        
        return array(
            'scenario' => $scenario,
            'expected' => $scenario_data['expected'],
            'detected_as_bot' => $is_bot,
            'passed_test' => ($is_bot && $scenario_data['expected'] === 'bot_detected') || 
                           (!$is_bot && $scenario_data['expected'] === 'allowed'),
            'detection_details' => $bot_details,
            'test_data' => $scenario_data
        );
    }

    /**
     * Create a mock WordPress request object for testing
     *
     * @since    3.0.0
     * @param    array    $scenario_data    Test scenario data.
     * @return   object                     Mock WP_REST_Request.
     */
    private function create_mock_request($scenario_data)
    {
        // Create a proper mock request object
        return new class($scenario_data) {
            private $scenario_data;
            
            public function __construct($scenario_data) {
                $this->scenario_data = $scenario_data;
            }
            
            public function get_header($header) {
                $header = strtolower($header);
                
                // Special handling for user-agent
                if ($header === 'user-agent') {
                    return $this->scenario_data['user_agent'];
                }
                
                // Special handling for referer
                if ($header === 'referer') {
                    return $this->scenario_data['referer'];
                }
                
                // Handle other headers
                $header_key = str_replace('-', '_', $header);
                return isset($this->scenario_data['headers'][$header]) ? $this->scenario_data['headers'][$header] : 
                       (isset($this->scenario_data['headers'][$header_key]) ? $this->scenario_data['headers'][$header_key] : '');
            }
            
            public function get_json_params() {
                return array(
                    'events' => array(
                        array('name' => 'test_bot_simulation', 'params' => array())
                    ),
                    'client_id' => 'test-client-123'
                );
            }
            
            public function get_body() {
                return json_encode(array(
                    'events' => array(
                        array('name' => 'test_bot_simulation', 'params' => array('test_scenario' => 'bot_simulation'))
                    ),
                    'client_id' => 'test-client-123',
                    'test_simulation' => true
                ));
            }
        };
    }


    /**
     * Log test event for monitoring
     *
     * @since    3.0.0
     * @param    string    $scenario        Test scenario name.
     * @param    array     $scenario_data   Scenario data.
     * @param    bool      $detected_as_bot Whether detected as bot.
     * @param    array     $bot_details     Detection details.
     */
    private function log_test_event($scenario, $scenario_data, $detected_as_bot, $bot_details)
    {
        $monitor_status = $detected_as_bot ? 'bot_detected' : 'allowed';
        $error_message = null;
        
        if ($detected_as_bot) {
            $error_message = $this->generate_bot_detection_error_message(
                $bot_details, 
                $scenario_data['ip'], 
                $scenario_data['user_agent']
            );
        }
        
        $this->event_logger->create_event_record(
            json_encode(array(
                'events' => array(
                    array('name' => 'test_bot_simulation', 'params' => array('scenario' => $scenario))
                ),
                'client_id' => 'test-simulation-' . time(),
                'test_simulation' => true
            )),
            $monitor_status,
            $scenario_data['headers'],
            false,
            array(
                'event_name' => 'test_bot_simulation',
                'reason' => $detected_as_bot ? 'Bot detection test - ' . $scenario : 'Legitimate traffic test - ' . $scenario,
                'error_message' => $error_message,
                'error_type' => $detected_as_bot ? 'bot_simulation_test' : null,
                'ip_address' => $scenario_data['ip'],
                'user_agent' => $scenario_data['user_agent'],
                'url' => home_url(),
                'referrer' => $scenario_data['referer'],
                'session_id' => 'test-session-' . time(),
                'bot_detection_rules' => $detected_as_bot ? $bot_details : null,
                'test_scenario' => $scenario,
                'test_expected' => $scenario_data['expected'],
                'test_result' => $detected_as_bot ? 'bot_detected' : 'allowed'
            )
        );
    }

}
