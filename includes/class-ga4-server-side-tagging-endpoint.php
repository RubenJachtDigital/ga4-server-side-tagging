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
        register_rest_route( 'ga4-server-side-tagging/v1', '/collect', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'collect_event' ),
            'permission_callback' => array( $this, 'check_permission' ),
        ) );

        register_rest_route( 'ga4-server-side-tagging/v1', '/config', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'get_config' ),
            'permission_callback' => '__return_true',
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
        // Check if the request has a valid nonce
        $nonce = $request->get_header( 'X-WP-Nonce' );
        if ( ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
            $this->logger->warning( 'Invalid nonce in API request' );
            return false;
        }

        // Check if the request is from a valid origin
        $referer = $request->get_header( 'referer' );
        
        // Get origin from payload if referer header is not present
        if ( ! $referer ) {
            $params = $request->get_json_params();
            if ( isset( $params['page_origin'] ) ) {
                $referer = $params['page_origin'];
            }
        }
        
        if ( ! $referer || ! wp_http_validate_url( $referer ) ) {
            $this->logger->warning( 'Invalid referer in API request' );
            return false;
        }

        $site_url = site_url();
        $referer_host = wp_parse_url( $referer, PHP_URL_HOST );
        $site_host = wp_parse_url( $site_url, PHP_URL_HOST );

        if ( $referer_host !== $site_host ) {
            $this->logger->warning( 'Cross-origin request rejected: ' . $referer_host );
            return false;
        }

        return true;
    }

    /**
     * Handle the collect event endpoint.
     *
     * @since    1.0.0
     * @param    \WP_REST_Request    $request    The request object.
     * @return   \WP_REST_Response                The response object.
     */
    public function collect_event( $request ) {
        $params = $request->get_json_params();
        
        if ( empty( $params ) || ! isset( $params['event_name'] ) || ! isset( $params['event_data'] ) ) {
            $this->logger->warning( 'Invalid event data in API request' );
            return new \WP_REST_Response( array( 'success' => false, 'message' => 'Invalid event data' ), 400 );
        }

        $event_name = sanitize_text_field( $params['event_name'] );
        $event_data = $params['event_data'];
        
        $this->logger->info( 'API event received: ' . $event_name );
        $this->logger->log_data( $event_data, 'Event data' );

        // Forward to GA4
        $result = $this->forward_to_ga4( $event_name, $event_data );
        
        if ( is_wp_error( $result ) ) {
            $this->logger->error( 'Error forwarding event to GA4: ' . $result->get_error_message() );
            return new \WP_REST_Response( array( 'success' => false, 'message' => $result->get_error_message() ), 500 );
        }

        return new \WP_REST_Response( array( 'success' => true ), 200 );
    }

    /**
     * Get the GA4 configuration.
     *
     * @since    1.0.0
     * @return   \WP_REST_Response    The response object.
     */
    public function get_config() {
        $measurement_id = get_option( 'ga4_measurement_id', '' );
        $debug_mode = get_option( 'ga4_server_side_tagging_debug_mode', false );
        
        $config = array(
            'measurement_id' => $measurement_id,
            'debug_mode' => $debug_mode,
            'api_endpoint' => rest_url( 'ga4-server-side-tagging/v1/collect' ),
        );

        return new \WP_REST_Response( $config, 200 );
    }

    /**
     * Get the secure GA4 configuration (sensitive data).
     *
     * @since    1.0.0
     * @return   \WP_REST_Response    The response object.
     */
    public function get_secure_config() {
        $secure_config = array(
            'cloudflareWorkerUrl' => get_option( 'ga4_cloudflare_worker_url', '' ),
            'workerApiKey' => get_option( 'ga4_worker_api_key', '' ),
        );

        return new \WP_REST_Response( $secure_config, 200 );
    }

    /**
     * Forward event to GA4.
     *
     * @since    1.0.0
     * @param    string    $event_name    The event name.
     * @param    array     $event_data    The event data.
     * @return   mixed                    The response or \WP_Error.
     */
    private function forward_to_ga4( $event_name, $event_data ) {
        $measurement_id = get_option( 'ga4_measurement_id' );
        $api_secret = get_option( 'ga4_api_secret' );
        
        if ( empty( $measurement_id ) || empty( $api_secret ) ) {
            return new \WP_Error( 'missing_config', 'Missing measurement ID or API secret' );
        }

        // Get client ID from request or generate one
        $client_id = isset( $event_data['client_id'] ) ? $event_data['client_id'] : $this->generate_client_id();
        unset( $event_data['client_id'] );

        // Prepare event data
        $payload = array(
            'client_id' => $client_id,
            'events' => array(
                array(
                    'name' => $event_name,
                    'params' => $event_data,
                ),
            ),
        );

        // Add user ID if provided
        if ( isset( $event_data['user_id'] ) ) {
            $payload['user_id'] = $event_data['user_id'];
            unset( $event_data['user_id'] );
        }

        // Send to GA4 Measurement Protocol
        $url = 'https://www.google-analytics.com/mp/collect?measurement_id=' . $measurement_id . '&api_secret=' . $api_secret;
        
        $response = wp_remote_post( $url, array(
            'method' => 'POST',
            'timeout' => 5,
            'redirection' => 5,
            'httpversion' => '1.1',
            'blocking' => true,
            'headers' => array( 'Content-Type' => 'application/json' ),
            'body' => wp_json_encode( $payload ),
            'cookies' => array(),
        ) );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $response_code = wp_remote_retrieve_response_code( $response );
        
        if ( $response_code < 200 || $response_code >= 300 ) {
            $body = wp_remote_retrieve_body( $response );
            return new \WP_Error( 'ga4_error', 'GA4 API error: ' . $response_code . ' ' . $body );
        }

        return $response;
    }

    /**
     * Generate a random client ID.
     *
     * @since    1.0.0
     * @return   string    A random client ID.
     */
    private function generate_client_id() {
        return sprintf( '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff ),
            mt_rand( 0, 0xffff ),
            mt_rand( 0, 0x0fff ) | 0x4000,
            mt_rand( 0, 0x3fff ) | 0x8000,
            mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff )
        );
    }
} 