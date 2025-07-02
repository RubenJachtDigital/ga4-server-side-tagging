<?php

namespace GA4ServerSideTagging\API;

use GA4ServerSideTagging\Core\GA4_Server_Side_Tagging_Logger;

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

        // Check if the request has a valid nonce
        $nonce = $request->get_header( 'X-WP-Nonce' );
        if ( ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
            $this->log_security_failure( $request, 'INVALID_NONCE', 'Invalid or missing nonce token' );
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
    public function get_secure_config() {
        try {
            $secure_config = array(
                'cloudflareWorkerUrl' => get_option( 'ga4_cloudflare_worker_url', '' ),
                'workerApiKey' => get_option( 'ga4_worker_api_key', '' ),
            );

            return new \WP_REST_Response( $secure_config, 200 );
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
     * Check rate limiting for API requests.
     *
     * @since    1.0.0
     * @param    \WP_REST_Request    $request    The request object.
     * @return   bool                          Whether the request passes rate limiting.
     */
    private function check_rate_limit( $request ) {
        $client_ip = $this->get_client_ip( $request );
        $rate_limit_key = 'ga4_api_rate_limit_' . md5( $client_ip );
        
        // Get current request count for this IP
        $request_count = get_transient( $rate_limit_key );
        
        if ( $request_count === false ) {
            // First request in this time window
            set_transient( $rate_limit_key, 1, 60 ); // 1 minute window
            return true;
        }
        
        // Check if under limit (60 requests per minute)
        if ( $request_count < 60 ) {
            set_transient( $rate_limit_key, $request_count + 1, 60 );
            return true;
        }
        
        // Rate limit exceeded - log the failure
        $this->logger->error( 'Rate limit exceeded for IP: ' . $client_ip );
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
} 