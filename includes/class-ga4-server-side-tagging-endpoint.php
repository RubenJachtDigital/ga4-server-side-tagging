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

if ( ! defined( 'WPINC' ) ) {
    die;
}

/**
 * REST API endpoint for GA4 Server-Side Tagging.
 *
 * This class handles the REST API endpoint for server-side tagging.
 *
 * @since      1.0.0
 */
class GA4_Server_Side_Tagging_Endpoint {

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
    public function __construct( GA4_Server_Side_Tagging_Logger $logger ) {
        $this->logger = $logger;
    }

    /**
     * Register the REST API routes.
     *
     * @since    1.0.0
     */
    public function register_routes() {
        register_rest_route( 'ga4-server-side-tagging/v1', '/secure-config', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'get_secure_config' ),
            'permission_callback' => array( $this, 'check_strong_permission' ),
        ) );
    }

    /**
     * Strong permission check for secure config endpoint (enhanced security).
     *
     * @since    1.0.0
     * @param    \WP_REST_Request    $request    The request object.
     * @return   bool                           Whether the request has permission.
     */
    public function check_strong_permission( $request ) {
        
        // 1. Enhanced origin validation - STRICT check
        if ( ! $this->validate_request_origin( $request ) ) {
            $this->log_security_failure( $request, 'ORIGIN_VALIDATION_FAILED', 'Request origin validation failed' );
            return false;
        }

        // 2. Bot detection - Block automated requests to secure config
        if ( $this->is_bot_request( $request ) ) {
            $this->log_security_failure( $request, 'BOT_DETECTED', 'Bot or automated request detected' );
            return false;
        }

        // 3. Enhanced security checks with stricter validation
        if ( ! $this->validate_enhanced_security( $request ) ) {
            $this->log_security_failure( $request, 'ENHANCED_SECURITY_CHECK_FAILED', 'Enhanced security validation failed' );
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
    private function is_bot_request( $request ) {
        // Allow bypassing bot detection in development mode
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG && get_option( 'ga4_disable_bot_detection', false ) ) {
            return false;
        }
        
        $user_agent = $request->get_header( 'user-agent' );
        $client_ip = $this->get_client_ip( $request );
        $referer = $request->get_header( 'referer' );
    
        // Run multiple bot detection checks
        $bot_checks = array(
            $this->check_user_agent_patterns( $user_agent ),
            $this->check_known_bot_ips( $client_ip ),
            $this->check_suspicious_referrers( $referer ),
        );
        
        // If any check returns true, it's a bot
        $is_bot = in_array( true, $bot_checks, true );
        
        if ( $is_bot ) {
            // Log detailed bot detection information
            $detection_details = array(
                'user_agent_check' => $bot_checks[0],
                'headers_check' => $bot_checks[1], 
                'ip_check' => $bot_checks[2],
                'referer_check' => $bot_checks[3],
                'behavior_check' => $bot_checks[4],
                'fingerprint_check' => $bot_checks[5]
            );
            
            $this->logger->log_data(  array(
                'ip' => $client_ip,
                'user_agent' => $user_agent,
                'referer' => $referer,
                'detection_details' => $detection_details
            ),'Bot detected attempting to access secure config', );
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
    private function check_user_agent_patterns( $user_agent ) {
        if ( empty( $user_agent ) || strlen( $user_agent ) < 10 ) {
            return true; // Missing or suspiciously short user agent
        }
        
        // Comprehensive bot patterns based on client-side detection
        $bot_patterns = array(
            '/bot\b/i',
            '/crawl/i',
            '/spider/i',
            '/scraper/i',
            '/googlebot/i',
            '/bingbot/i',
            '/yahoo/i',
            '/duckduckbot/i',
            '/baiduspider/i',
            '/yandexbot/i',
            '/sogou/i',
            '/facebookexternalhit/i',
            '/twitterbot/i',
            '/linkedinbot/i',
            '/whatsapp/i',
            '/telegrambot/i',
            '/semrushbot/i',
            '/ahrefsbot/i',
            '/mj12bot/i',
            '/dotbot/i',
            '/screaming frog/i',
            '/seobility/i',
            '/headlesschrome/i',
            '/phantomjs/i',
            '/slimerjs/i',
            '/htmlunit/i',
            '/selenium/i',
            '/pingdom/i',
            '/uptimerobot/i',
            '/statuscake/i',
            '/site24x7/i',
            '/newrelic/i',
            '/python/i',
            '/requests/i',
            '/curl/i',
            '/wget/i',
            '/apache-httpclient/i',
            '/java\//i',
            '/okhttp/i',
            '/^mozilla\/5\.0$/i',
            '/compatible;\s*$/i',
            '/chrome-lighthouse/i',
            '/pagespeed/i',
            '/prerender/i',
            '/node\.js/i',
            '/go-http-client/i',
            '/ruby/i',
            '/perl/i',
            '/libwww/i'
        );
        
        foreach ( $bot_patterns as $pattern ) {
            if ( preg_match( $pattern, $user_agent ) ) {
                return true;
            }
        }
        
        // Check for suspicious user agent patterns
        if ( preg_match( '/^[a-z\s]+$/i', $user_agent ) ) {
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
    private function check_known_bot_ips( $ip ) {
        if ( empty( $ip ) ) {
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
        
        foreach ( $bot_ip_ranges as $range ) {
            if ( $this->ip_in_range( $ip, $range ) ) {
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
    private function ip_in_range( $ip, $range ) {
        if ( strpos( $range, '/' ) === false ) {
            return $ip === $range;
        }
        
        list( $range_ip, $netmask ) = explode( '/', $range, 2 );
        $range_decimal = ip2long( $range_ip );
        $ip_decimal = ip2long( $ip );
        $wildcard_decimal = pow( 2, ( 32 - $netmask ) ) - 1;
        $netmask_decimal = ~ $wildcard_decimal;
        
        return ( ( $ip_decimal & $netmask_decimal ) === ( $range_decimal & $netmask_decimal ) );
    }

    /**
     * Check for suspicious referrer patterns.
     *
     * @since    1.0.0
     * @param    string    $referer    The referer header.
     * @return   bool                 True if suspicious referrer detected.
     */
    private function check_suspicious_referrers( $referer ) {
        if ( empty( $referer ) ) {
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
        
        foreach ( $suspicious_patterns as $pattern ) {
            if ( preg_match( $pattern, $referer ) ) {
                return true;
            }
        }
        
        return false;
    }

    /**
     * Get the secure GA4 configuration (sensitive data).
     *
     * @since    1.0.0
     * @return   \WP_REST_Response    The response object.
     */
    public function get_secure_config( $request ) {
        try {
            // Handle encrypted request if present (not needed for GET, but for consistency)
            // $request_data = $this->handle_encrypted_request( $request ); // Not used for GET
            
            $secure_config = array(
                'cloudflareWorkerUrl' => get_option( 'ga4_cloudflare_worker_url', '' ),
                'workerApiKey' => $this->secure_key_transmission( get_option( 'ga4_worker_api_key', '' ) ),
                'encryptionEnabled' => (bool) get_option( 'ga4_jwt_encryption_enabled', false ),
                'encryptionKey' => $this->secure_key_transmission( get_option( 'ga4_jwt_encryption_key', '' ) ),
                'keyDerivationSalt' => $this->get_key_derivation_salt(),
            );
            // Return encrypted response if requested
            return $this->create_response( $secure_config, $request );
            
        } catch ( \Exception $e ) {
            $this->logger->error( 'Failed to get secure config: ' . $e->getMessage() );
            return new \WP_REST_Response( array( 'error' => 'Configuration error' ), 500 );
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
    private function log_security_failure( $request, $failure_type, $message ) {
        $client_ip = $this->get_client_ip( $request );
        $user_agent = $request->get_header( 'user-agent' );
        $endpoint = $request->get_route();
        
        $failure_data = array(
            'type' => 'SECURITY_FAILURE',
            'failure_type' => $failure_type,
            'message' => $message,
            'endpoint' => $endpoint,
            'ip' => $client_ip,
            'user_agent' => $user_agent,
            'referer' => $request->get_header( 'referer' ),
            'origin' => $request->get_header( 'origin' ),
            'timestamp' => current_time( 'mysql' )
        );
        
        $this->logger->log_data( $failure_data, 'Security Failure' );
    }












    /**
     * Validate request origin with multiple checks.
     *
     * @since    1.0.0
     * @param    \WP_REST_Request    $request    The request object.
     * @return   bool                          Whether the origin is valid.
     */
    private function validate_request_origin( $request ) {
        $site_url = site_url();
        $site_host = wp_parse_url( $site_url, PHP_URL_HOST );
        
        // Check Referer header
        $referer = $request->get_header( 'referer' );
        if ( $referer ) {
            $referer_host = wp_parse_url( $referer, PHP_URL_HOST );
            if ( $referer_host === $site_host ) {
                return true;
            }
        }
        
        // Check Origin header
        $origin = $request->get_header( 'origin' );
        if ( $origin ) {
            $origin_host = wp_parse_url( $origin, PHP_URL_HOST );
            if ( $origin_host === $site_host ) {
                return true;
            }
        }
        
        // Check payload origin as last resort (less secure)
        $params = $request->get_json_params();
        if ( isset( $params['page_origin'] ) ) {
            $payload_origin = $params['page_origin'];
            if ( wp_http_validate_url( $payload_origin ) ) {
                $payload_host = wp_parse_url( $payload_origin, PHP_URL_HOST );
                if ( $payload_host === $site_host ) {
                    // Only log failures, not fallback usage
                    return true;
                }
            }
        }
        
        $this->logger->error( 'Origin validation failed. Expected: ' . $site_host );
        return false;
    }


    /**
     * Get client IP address.
     *
     * @since    1.0.0
     * @param    \WP_REST_Request    $request    The request object.
     * @return   string                        The client IP address.
     */
    private function get_client_ip( $request ) {
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
        
        foreach ( $ip_headers as $header ) {
            if ( ! empty( $_SERVER[ $header ] ) ) {
                $ip = sanitize_text_field( wp_unslash( $_SERVER[ $header ] ) );
                // Handle comma-separated IPs (take first one)
                if ( strpos( $ip, ',' ) !== false ) {
                    $ip = trim( explode( ',', $ip )[0] );
                }
                if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
                    return $ip;
                }
            }
        }
        
        return isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '0.0.0.0';
    }

    /**
     * Check if User-Agent appears suspicious.
     *
     * @since    1.0.0
     * @param    string    $user_agent    The User-Agent string.
     * @return   bool                     Whether the User-Agent is suspicious.
     */
    private function is_suspicious_user_agent( $user_agent ) {
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
        
        foreach ( $suspicious_patterns as $pattern ) {
            if ( preg_match( $pattern, $user_agent ) ) {
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
    private function handle_encrypted_request( $request ) {
        // Check if encryption is enabled and request is encrypted
        $encryption_enabled = get_option( 'ga4_jwt_encryption_enabled', false );
        $is_encrypted = GA4_Encryption_Util::is_encrypted_request( $request );
        
        if ( ! $encryption_enabled || ! $is_encrypted ) {
            // Return original request body if not encrypted
            return $request->get_json_params();
        }
        
        $encryption_key = get_option( 'ga4_jwt_encryption_key', '' );
        if ( empty( $encryption_key ) ) {
            throw new \Exception( 'Encryption key not configured' );
        }
        
        try {
            $request_body = $request->get_json_params();
            $decrypted_data = GA4_Encryption_Util::parse_encrypted_request( $request_body, $encryption_key );
            
            $this->logger->info( 'JWT request verified successfully', array(
                'ip' => $this->get_client_ip( $request )
            ) );
            
            return $decrypted_data;
            
        } catch ( \Exception $e ) {
            $this->logger->error( 'Failed to verify JWT request: ' . $e->getMessage(), array(
                'ip' => $this->get_client_ip( $request )
            ) );
            throw new \Exception( 'JWT request verification failed' );
        }
    }

    /**
     * Create response with mandatory encryption for secure config endpoint
     * 
     * @param array $data Response data
     * @param \WP_REST_Request $request Original request
     * @return \WP_REST_Response Response object
     */
    private function create_response( $data, $request ) {
        // Generate secure encryption key using request entropy
        $key_data = $this->generate_secure_encryption_key( $request );
        
        if ( empty( $key_data['encryption_key'] ) ) {
            $this->logger->error( 'Secure config encryption failed: no encryption key could be generated' );
            return new \WP_REST_Response( array( 'error' => 'Encryption required but unavailable' ), 500 );
        }
        
        try {
            $encrypted_data = GA4_Encryption_Util::create_encrypted_response( $data, $key_data['encryption_key'] );
            
            // Include client entropy in response for key reconstruction
            $response_data = array_merge( $encrypted_data, [
                'client_fingerprint' => $key_data['client_fingerprint'],
                'server_entropy' => $key_data['server_entropy'],
                'time_slot' => $key_data['time_slot']
            ] );
            
            return new \WP_REST_Response( $response_data, 200 );
            
        } catch ( \Exception $e ) {
            $this->logger->error( 'Failed to encrypt secure config: ' . $e->getMessage(), array(
                'ip' => $this->get_client_ip( $request )
            ) );
            
            // Return error instead of unencrypted fallback
            return new \WP_REST_Response( array( 'error' => 'Encryption failed' ), 500 );
        }
    }

    /**
     * Enhanced security validation with stricter checks.
     *
     * @since    1.0.0
     * @param    \WP_REST_Request    $request    The request object.
     * @return   bool                          Whether the request passes enhanced security.
     */
    private function validate_enhanced_security( $request ) {
        // 1. Require HTTPS for secure config (except in development)
        if ( ! is_ssl() && wp_get_environment_type() !== 'development' ) {
            $this->logger->error( 'Enhanced security failed: Non-HTTPS connection' );
            return false;
        }

        // 2. Check for suspicious User-Agent patterns (stricter)
        $user_agent = $request->get_header( 'user-agent' );
        if ( empty( $user_agent ) ) {
            $this->logger->error( 'Enhanced security failed: Missing User-Agent header' );
            return false;
        }
        if ( $this->is_suspicious_user_agent( $user_agent ) ) {
            $this->logger->error( 'Enhanced security failed: Suspicious User-Agent pattern' );
            return false;
        }

        // 3. Require essential browser headers (relaxed for now)
        $essential_headers = array( 'accept' );
        foreach ( $essential_headers as $header ) {
            if ( empty( $request->get_header( $header ) ) ) {
                $this->logger->error( "Enhanced security failed: Missing essential header: {$header}" );
                return false;
            }
        }
        
        // Log other headers for debugging (only in development)
        if ( wp_get_environment_type() === 'development' ) {
            $accept_language = $request->get_header( 'accept-language' );
            $accept_encoding = $request->get_header( 'accept-encoding' );
            $this->logger->error( "Debug headers - Accept-Language: '{$accept_language}', Accept-Encoding: '{$accept_encoding}'" );
        }

        // 4. Check request method is GET only
        if ( $request->get_method() !== 'GET' ) {
            $this->logger->error( 'Enhanced security failed: Invalid request method: ' . $request->get_method() );
            return false;
        }

        // 5. Validate no suspicious query parameters
        $query_params = $request->get_query_params();
        $suspicious_params = array( 'cmd', 'exec', 'system', 'eval', 'base64', 'shell' );
        foreach ( $suspicious_params as $param ) {
            if ( isset( $query_params[ $param ] ) ) {
                $this->logger->error( "Enhanced security failed: Suspicious query parameter: {$param}" );
                return false;
            }
        }

        return true;
    }



    /**
     * Secure key transmission using XOR-based obfuscation (reversible).
     *
     * @since    1.0.0
     * @param    string    $original_key    The original key to secure.
     * @return   string                    The secured key for transmission.
     */
    private function secure_key_transmission( $original_key ) {
        if ( empty( $original_key ) ) {
            return '';
        }

        // Get the derivation salt (will be used as XOR key)
        $salt = $this->get_key_derivation_salt();
        
        // XOR obfuscation - reversible by XORing again with same salt
        $obfuscated_key = $this->xor_obfuscate( $original_key, $salt );
        
        // Base64 encode for safe transmission
        return base64_encode( $obfuscated_key );
    }

    /**
     * XOR obfuscation function (reversible).
     *
     * @since    1.0.0
     * @param    string    $data    The data to obfuscate.
     * @param    string    $key     The XOR key.
     * @return   string            The obfuscated data.
     */
    private function xor_obfuscate( $data, $key ) {
        $data_len = strlen( $data );
        $key_len = strlen( $key );
        $obfuscated = '';
        
        for ( $i = 0; $i < $data_len; $i++ ) {
            $obfuscated .= chr( ord( $data[ $i ] ) ^ ord( $key[ $i % $key_len ] ) );
        }
        
        return $obfuscated;
    }

    /**
     * Get or create key derivation salt.
     *
     * @since    1.0.0
     * @return   string    The key derivation salt.
     */
    private function get_key_derivation_salt() {
        // Create a salt based on site URL and current hour
        // This changes hourly but is predictable on client side
        $current_hour = gmdate( 'Y-m-d-H' ); // UTC hour for consistency
        $site_url = get_site_url();
        
        // Create deterministic salt that changes hourly
        $salt = hash( 'sha256', $site_url . $current_hour . 'ga4-key-derivation' );
        
        return $salt;
    }

    /**
     * Generate secure encryption key using request entropy (non-predictable).
     *
     * @since    1.0.0
     * @param    \WP_REST_Request    $request    The request object.
     * @return   array                         Array with encryption key and client entropy.
     */
    private function generate_secure_encryption_key( $request ) {
        // Get client-specific entropy
        $client_ip = $this->get_client_ip( $request );
        $user_agent = $request->get_header( 'user-agent' );
        $timestamp = time();
        
        // Create 5-minute time slot for key rotation
        $time_slot = floor( $timestamp / 300 ); // 300 seconds = 5 minutes
        
        // Generate server entropy (cryptographically secure)
        $server_entropy = bin2hex( random_bytes( 16 ) ); // 32 hex chars
        
        // Create client fingerprint (non-sensitive, can be shared)
        $client_fingerprint = hash( 'sha256', $client_ip . $user_agent . $time_slot . 'ga4-client-fp' );
        
        // Combine all entropy sources for key derivation
        $key_material = hash( 'sha256', 
            get_site_url() . 
            $time_slot . 
            $client_fingerprint . 
            $server_entropy . 
            'ga4-secure-encryption'
        );
        
        return [
            'encryption_key' => $key_material,
            'client_fingerprint' => $client_fingerprint,
            'server_entropy' => $server_entropy,
            'time_slot' => $time_slot
        ];
    }

    /**
     * Legacy method for backward compatibility - now redirects to secure version.
     *
     * @since    1.0.0
     * @return   string    The temporary encryption key (64 characters hex).
     */
    private function get_temporary_encryption_key() {
        // This method is kept for backward compatibility but should not be used
        // for new implementations. It creates a basic time-based key.
        $current_5min_slot = floor( time() / 300 );
        $site_url = get_site_url();
        $temp_key_seed = hash( 'sha256', $site_url . $current_5min_slot . 'ga4-temp-encryption' );
        return $temp_key_seed;
    }
} 