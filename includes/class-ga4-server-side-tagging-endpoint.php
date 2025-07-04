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
        $client_ip = $this->get_client_ip( $request );
        
        // 1. STRICT rate limiting check (more restrictive than normal)
        if ( ! $this->check_strict_rate_limit( $request ) ) {
            $this->log_security_failure( $request, 'STRICT_RATE_LIMIT_EXCEEDED', "Too many secure config requests from IP: {$client_ip}" );
            return false;
        }

        // 2. Enhanced origin validation - STRICT check
        if ( ! $this->validate_request_origin( $request ) ) {
            $this->log_security_failure( $request, 'ORIGIN_VALIDATION_FAILED', 'Request origin validation failed' );
            return false;
        }

        // 3. Enhanced security checks with stricter validation
        if ( ! $this->validate_enhanced_security( $request ) ) {
            $this->log_security_failure( $request, 'ENHANCED_SECURITY_CHECK_FAILED', 'Enhanced security validation failed' );
            return false;
        }

        // 4. Browser fingerprint validation
        if ( ! $this->validate_browser_fingerprint( $request ) ) {
            $this->log_security_failure( $request, 'BROWSER_FINGERPRINT_FAILED', 'Browser fingerprint validation failed' );
            return false;
        }

        // 5. Time-based request validation (prevent replay attacks)
        if ( ! $this->validate_request_timing( $request ) ) {
            $this->log_security_failure( $request, 'REQUEST_TIMING_FAILED', 'Request timing validation failed' );
            return false;
        }

        return true;
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

            // Log secure config access for security audit
            $this->logger->log_data(  array(
                'ip' => $this->get_client_ip( $request ),
                'encrypted' => GA4_Encryption_Util::is_encrypted_request( $request )
            ), 'Secure config accessed', 'info');

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
     * Create response with optional encryption
     * 
     * @param array $data Response data
     * @param \WP_REST_Request $request Original request
     * @return \WP_REST_Response Response object
     */
    private function create_response( $data, $request ) {
        // Check if encryption is enabled and client supports it
        $encryption_enabled = get_option( 'ga4_jwt_encryption_enabled', false );
        $is_encrypted_request = GA4_Encryption_Util::is_encrypted_request( $request );
        
        if ( ! $encryption_enabled || ! $is_encrypted_request ) {
            // Return normal response
            return new \WP_REST_Response( $data, 200 );
        }
        
        $encryption_key = get_option( 'ga4_jwt_encryption_key', '' );
        if ( empty( $encryption_key ) ) {
            // Fallback to unencrypted response if no key
            $this->logger->warning( 'JWT encryption requested but no key configured' );
            return new \WP_REST_Response( $data, 200 );
        }
        
        try {
            $encrypted_data = GA4_Encryption_Util::create_encrypted_response( $data, $encryption_key );
            
            $this->logger->info( 'JWT response created successfully', array(
                'ip' => $this->get_client_ip( $request )
            ) );
            
            return new \WP_REST_Response( $encrypted_data, 200 );
            
        } catch ( \Exception $e ) {
            $this->logger->error( 'Failed to create JWT response: ' . $e->getMessage(), array(
                'ip' => $this->get_client_ip( $request )
            ) );
            
            // Fallback to unencrypted response
            return new \WP_REST_Response( $data, 200 );
        }
    }

    /**
     * Enhanced rate limiting for secure config endpoint (stricter than normal).
     *
     * @since    1.0.0
     * @param    \WP_REST_Request    $request    The request object.
     * @return   bool                          Whether the request passes strict rate limiting.
     */
    private function check_strict_rate_limit( $request ) {
        $client_ip = $this->get_client_ip( $request );
        
        // Stricter limits for secure config: 10 requests per hour (vs 100 for normal endpoints)
        $hourly_key = 'ga4_secure_config_hourly_' . md5( $client_ip );
        $hourly_count = get_transient( $hourly_key );
        
        if ( $hourly_count === false ) {
            set_transient( $hourly_key, 1, 3600 ); // 1 hour window
            return true;
        }
        
        if ( $hourly_count < 10 ) { // Only 10 requests per hour for secure config
            set_transient( $hourly_key, $hourly_count + 1, 3600 );
            return true;
        }
        
        // Block IP for 1 hour if limit exceeded
        $block_key = 'ga4_secure_config_blocked_' . md5( $client_ip );
        $block_until = time() + 3600;
        set_transient( $block_key, $block_until, 3600 );
        
        return false;
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
        
        // Log other headers for debugging
        $accept_language = $request->get_header( 'accept-language' );
        $accept_encoding = $request->get_header( 'accept-encoding' );
        $this->logger->error( "Debug headers - Accept-Language: '{$accept_language}', Accept-Encoding: '{$accept_encoding}'" );

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
     * Validate browser fingerprint consistency.
     *
     * @since    1.0.0
     * @param    \WP_REST_Request    $request    The request object.
     * @return   bool                          Whether browser fingerprint is valid.
     */
    private function validate_browser_fingerprint( $request ) {
        $user_agent = $request->get_header( 'user-agent' );
        $accept = $request->get_header( 'accept' );
        $accept_language = $request->get_header( 'accept-language' );
        $accept_encoding = $request->get_header( 'accept-encoding' );

        // Check for consistent browser fingerprint (relaxed for incognito/fetch API)
        if ( empty( $user_agent ) || empty( $accept ) ) {
            $this->logger->error( 'Browser fingerprint failed: Missing User-Agent or Accept header' );
            return false;
        }
        
        // Accept-Language is optional (some browsers/modes might not send it)
        if ( empty( $accept_language ) ) {
            $this->logger->error( 'Browser fingerprint warning: Missing Accept-Language (but allowing)' );
        }
        
        // Accept-Encoding is often missing in fetch API / incognito mode - don't require it
        if ( empty( $accept_encoding ) ) {
            $this->logger->error( 'Browser fingerprint note: Missing Accept-Encoding (common in incognito/fetch)' );
        }

        // Detect common bot patterns in headers
        if ( strpos( $accept, '*/*' ) === 0 && strlen( $accept ) < 10 ) {
            // Suspicious: only accepts */*, too simple for real browser
            $this->logger->error( 'Browser fingerprint failed: Accept header too simple (only */* and short)' );
            return false;
        }

        // Check for missing typical browser accept headers
        $has_html = strpos( $accept, 'text/html' ) !== false;
        $has_json = strpos( $accept, 'application/json' ) !== false;
        
        $this->logger->error( "Accept header: '{$accept}'" );
        $this->logger->error( "Has text/html: " . ( $has_html ? 'yes' : 'no' ) );
        $this->logger->error( "Has application/json: " . ( $has_json ? 'yes' : 'no' ) );
        
        if ( ! $has_html && ! $has_json ) {
            $this->logger->error( 'Browser fingerprint failed: Accept header missing text/html and application/json' );
            return false;
        }

        // Check for realistic accept-language (if present)
        if ( ! empty( $accept_language ) && strlen( $accept_language ) < 5 ) {
            $this->logger->error( 'Browser fingerprint failed: Accept-Language too short' );
            return false;
        }

        return true;
    }

    /**
     * Validate request timing to prevent replay attacks.
     *
     * @since    1.0.0
     * @param    \WP_REST_Request    $request    The request object.
     * @return   bool                          Whether request timing is valid.
     */
    private function validate_request_timing( $request ) {
        $client_ip = $this->get_client_ip( $request );
        $current_time = time();
        
        // Check last request time for this IP
        $last_request_key = 'ga4_secure_config_last_' . md5( $client_ip );
        $last_request_time = get_transient( $last_request_key );
        
        if ( $last_request_time !== false ) {
            // Don't allow requests more frequent than once per minute for secure config
            if ( ( $current_time - $last_request_time ) < 60 ) {
                return false;
            }
        }
        
        // Update last request time
        set_transient( $last_request_key, $current_time, 3600 ); // Store for 1 hour
        
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
} 