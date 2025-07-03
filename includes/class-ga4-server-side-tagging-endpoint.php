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
        register_rest_route( 'ga4-server-side-tagging/v1', '/auth-token', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'generate_auth_token' ),
            'permission_callback' => array( $this, 'check_nonce_permission' ),
        ) );
        
        register_rest_route( 'ga4-server-side-tagging/v1', '/secure-config', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'get_secure_config' ),
            'permission_callback' => array( $this, 'check_permission' ),
        ) );
    }

    /**
     * Check permission for the endpoint.
     *
     * @since    1.0.0
     * @param    \WP_REST_Request    $request    The request object.
     * @return   bool                           Whether the request has permission.
     */
    public function check_permission( $request ) {
        $client_ip = $this->get_client_ip( $request );
        
        // Only log failures, not all access attempts

        // Rate limiting check
        if ( ! $this->check_rate_limit( $request ) ) {
            $this->log_security_failure( $request, 'RATE_LIMIT_EXCEEDED', 'Too many requests from IP: ' . $client_ip );
            return false;
        }

        // JWT token validation
        $auth_header = $request->get_header( 'Authorization' );
        if ( empty( $auth_header ) || ! preg_match( '/Bearer\s+(.*)$/i', $auth_header, $matches ) ) {
            $this->log_security_failure( $request, 'MISSING_JWT_TOKEN', 'Missing or invalid Authorization header' );
            return false;
        }
        
        $token = $matches[1];
        $token_payload = $this->validate_jwt_token( $token );
        if ( ! $token_payload ) {
            $this->log_security_failure( $request, 'INVALID_JWT_TOKEN', 'JWT token validation failed' );
            return false;
        }

        // Enhanced origin validation - check multiple headers
        if ( ! $this->validate_request_origin( $request ) ) {
            $this->log_security_failure( $request, 'ORIGIN_VALIDATION_FAILED', 'Request origin validation failed' );
            return false;
        }

        // Additional security checks
        if ( ! $this->validate_request_security( $request ) ) {
            $this->log_security_failure( $request, 'SECURITY_CHECK_FAILED', 'Additional security validation failed' );
            return false;
        }

        // Only log failures, not successful access

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
            $request_data = $this->handle_encrypted_request( $request );
            
            $secure_config = array(
                'cloudflareWorkerUrl' => get_option( 'ga4_cloudflare_worker_url', '' ),
                'workerApiKey' => get_option( 'ga4_worker_api_key', '' ),
                'jwtEncryptionEnabled' => (bool) get_option( 'ga4_jwt_encryption_enabled', false ),
                'jwtEncryptionKey' => get_option( 'ga4_jwt_encryption_key', '' ),
            );

            // Log secure config access for security audit
            $this->logger->info( 'Secure config accessed', array(
                'ip' => $this->get_client_ip( $request ),
                'jwt_encrypted' => GA4_Encryption_Util::is_encrypted_request( $request )
            ) );

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
     * Generate JWT authentication token.
     *
     * @since    1.0.0
     * @param    \WP_REST_Request    $request    The request object.
     * @return   \WP_REST_Response             The response object.
     */
    public function generate_auth_token( $request ) {
        try {
            // Handle encrypted request if present
            $request_data = $this->handle_encrypted_request( $request );
            
            // Log request details for security audit
            $this->logger->info( 'JWT token requested', array(
                'ip' => $this->get_client_ip( $request ),
                'user_agent' => $request->get_header( 'User-Agent' ),
                'jwt_encrypted' => GA4_Encryption_Util::is_encrypted_request( $request )
            ) );
            
            $token = $this->create_jwt_token();
            
            $response_data = array(
                'token' => $token,
                'expires_in' => 300 // 5 minutes
            );
            
            // Return encrypted response if requested
            return $this->create_response( $response_data, $request );
            
        } catch ( \Exception $e ) {
            $this->logger->error( 'Failed to generate JWT token: ' . $e->getMessage() );
            return new \WP_REST_Response( array( 'error' => 'Token generation failed' ), 500 );
        }
    }

    /**
     * Check nonce permission for token generation.
     *
     * @since    1.0.0
     * @param    \WP_REST_Request    $request    The request object.
     * @return   bool                          Whether the request has permission.
     */
    public function check_nonce_permission( $request ) {
        // Basic nonce check for token generation
        $nonce = $request->get_header( 'X-WP-Nonce' );
        if ( ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
            $this->logger->error( 'Invalid nonce for token generation' );
            return false;
        }

        // Basic origin check
        if ( ! $this->validate_request_origin( $request ) ) {
            $this->logger->error( 'Invalid origin for token generation' );
            return false;
        }

        return true;
    }

    /**
     * Create JWT token.
     *
     * @since    1.0.0
     * @return   string    The JWT token.
     */
    private function create_jwt_token() {
        $secret = $this->get_jwt_secret();
        $issued_at = time();
        $expiration = $issued_at + 300; // 5 minutes
        
        $payload = array(
            'iss' => get_site_url(),
            'aud' => 'ga4-server-side-tagging',
            'iat' => $issued_at,
            'exp' => $expiration,
            'sub' => 'api-access',
            'jti' => wp_generate_uuid4()
        );

        return $this->encode_jwt( $payload, $secret );
    }

    /**
     * Validate JWT token.
     *
     * @since    1.0.0
     * @param    string    $token    The JWT token.
     * @return   bool|array         Token payload if valid, false if invalid.
     */
    private function validate_jwt_token( $token ) {
        try {
            $secret = $this->get_jwt_secret();
            $payload = $this->decode_jwt( $token, $secret );
            
            // Check expiration
            if ( isset( $payload['exp'] ) && $payload['exp'] < time() ) {
                return false;
            }
            
            // Check issuer
            if ( ! isset( $payload['iss'] ) || $payload['iss'] !== get_site_url() ) {
                return false;
            }
            
            // Check audience
            if ( ! isset( $payload['aud'] ) || $payload['aud'] !== 'ga4-server-side-tagging' ) {
                return false;
            }
            
            return $payload;
        } catch ( \Exception $e ) {
            $this->logger->error( 'JWT validation failed: ' . $e->getMessage() );
            return false;
        }
    }

    /**
     * Get or generate JWT secret key.
     *
     * @since    1.0.0
     * @return   string    The JWT secret key.
     */
    private function get_jwt_secret() {
        $secret = get_option( 'ga4_jwt_secret' );
        
        if ( empty( $secret ) ) {
            $secret = wp_generate_password( 64, true, true );
            update_option( 'ga4_jwt_secret', $secret );
        }
        
        return $secret;
    }

    /**
     * Simple JWT encode implementation.
     *
     * @since    1.0.0
     * @param    array     $payload    The payload to encode.
     * @param    string    $secret     The secret key.
     * @return   string               The JWT token.
     */
    private function encode_jwt( $payload, $secret ) {
        $header = array(
            'typ' => 'JWT',
            'alg' => 'HS256'
        );
        
        $header_encoded = $this->base64url_encode( wp_json_encode( $header ) );
        $payload_encoded = $this->base64url_encode( wp_json_encode( $payload ) );
        
        $signature = hash_hmac( 'sha256', $header_encoded . '.' . $payload_encoded, $secret, true );
        $signature_encoded = $this->base64url_encode( $signature );
        
        return $header_encoded . '.' . $payload_encoded . '.' . $signature_encoded;
    }

    /**
     * Simple JWT decode implementation.
     *
     * @since    1.0.0
     * @param    string    $token     The JWT token.
     * @param    string    $secret    The secret key.
     * @return   array               The decoded payload.
     */
    private function decode_jwt( $token, $secret ) {
        $parts = explode( '.', $token );
        
        if ( count( $parts ) !== 3 ) {
            throw new \Exception( 'Invalid JWT token format' );
        }
        
        list( $header_encoded, $payload_encoded, $signature_encoded ) = $parts;
        
        $signature = $this->base64url_decode( $signature_encoded );
        $expected_signature = hash_hmac( 'sha256', $header_encoded . '.' . $payload_encoded, $secret, true );
        
        if ( ! hash_equals( $signature, $expected_signature ) ) {
            throw new \Exception( 'JWT signature verification failed' );
        }
        
        $payload = json_decode( $this->base64url_decode( $payload_encoded ), true );
        
        if ( json_last_error() !== JSON_ERROR_NONE ) {
            throw new \Exception( 'Invalid JWT payload JSON' );
        }
        
        return $payload;
    }

    /**
     * Base64URL encode.
     *
     * @since    1.0.0
     * @param    string    $data    The data to encode.
     * @return   string            The encoded data.
     */
    private function base64url_encode( $data ) {
        return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' );
    }

    /**
     * Base64URL decode.
     *
     * @since    1.0.0
     * @param    string    $data    The data to decode.
     * @return   string            The decoded data.
     */
    private function base64url_decode( $data ) {
        return base64_decode( strtr( $data, '-_', '+/' ) . str_repeat( '=', 3 - ( 3 + strlen( $data ) ) % 4 ) );
    }


    /**
     * Check rate limiting for API requests.
     *
     * @since    1.0.0
     * @param    \WP_REST_Request    $request    The request object.
     * @return   bool                          Whether the request passes rate limiting.
     */
    private function check_rate_limit( $request ) {
        $client_ip = $this->get_client_ip( $request );
        
        // Check if IP is currently blocked (1 hour block)
        $block_key = 'ga4_api_blocked_' . md5( $client_ip );
        $is_blocked = get_transient( $block_key );
        
        if ( $is_blocked !== false ) {
            $this->logger->error( 'Blocked IP attempted access: ' . $client_ip . ' (blocked until: ' . date( 'Y-m-d H:i:s', $is_blocked ) . ')' );
            return false;
        }
        
        // Check hourly rate limit (100 requests per hour)
        $hourly_key = 'ga4_api_hourly_' . md5( $client_ip );
        $hourly_count = get_transient( $hourly_key );
        
        if ( $hourly_count === false ) {
            // First request in this hour
            set_transient( $hourly_key, 1, 3600 ); // 1 hour window
            return true;
        }
        
        // Check if under hourly limit (100 requests per hour)
        if ( $hourly_count < 100 ) {
            set_transient( $hourly_key, $hourly_count + 1, 3600 );
            return true;
        }
        
        // Hourly limit exceeded - block IP for 1 hour
        $block_until = time() + 3600; // 1 hour from now
        set_transient( $block_key, $block_until, 3600 );
        
        // Log the block
        $this->logger->error( 'IP blocked for exceeding rate limit: ' . $client_ip . ' (' . $hourly_count . ' requests in last hour, blocked until: ' . date( 'Y-m-d H:i:s', $block_until ) . ')' );
        
        return false;
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
     * Additional security validation checks.
     *
     * @since    1.0.0
     * @param    \WP_REST_Request    $request    The request object.
     * @return   bool                          Whether the request passes security checks.
     */
    private function validate_request_security( $request ) {
        // Check for suspicious User-Agent patterns
        $user_agent = $request->get_header( 'user-agent' );
        if ( empty( $user_agent ) || $this->is_suspicious_user_agent( $user_agent ) ) {
            $this->logger->error( 'Suspicious or missing User-Agent: ' . $user_agent );
            return false;
        }
        
        // Check for required headers that legitimate browsers send
        $required_headers = array( 'accept', 'accept-language' );
        foreach ( $required_headers as $header ) {
            if ( empty( $request->get_header( $header ) ) ) {
                $this->logger->error( 'Missing required header: ' . $header );
                return false;
            }
        }
        
        // Check request method
        if ( $request->get_method() !== 'GET' && $request->get_method() !== 'POST' ) {
            $this->logger->error( 'Invalid request method: ' . $request->get_method() );
            return false;
        }
        
        return true;
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
} 