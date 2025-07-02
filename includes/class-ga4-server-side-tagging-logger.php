<?php

namespace GA4ServerSideTagging\Core;

/**
 * Handles logging for the plugin.
 *
 * @since      1.0.0
 * @package    GA4_Server_Side_Tagging
 */

if ( ! defined( 'WPINC' ) ) {
    die;
}

/**
 * Handles logging for the plugin.
 *
 * This class provides logging functionality for debugging purposes.
 *
 * @since      1.0.0
 */
class GA4_Server_Side_Tagging_Logger {

    /**
     * Log file path.
     *
     * @since    1.0.0
     * @access   private
     * @var      string    $log_file    Path to the log file.
     */
    private $log_file;

    /**
     * Debug mode status.
     *
     * @since    1.0.0
     * @access   private
     * @var      bool    $debug_mode    Whether debug mode is enabled.
     */
    private $debug_mode;

    /**
     * Initialize the logger.
     *
     * @since    1.0.0
     */
    public function __construct() {
        $this->log_file = GA4_SERVER_SIDE_TAGGING_PLUGIN_DIR . 'logs/ga4-server-side-tagging.log';
        $this->debug_mode = get_option( 'ga4_server_side_tagging_debug_mode', false );
        
        // Create logs directory if it doesn't exist
        if ( ! file_exists( dirname( $this->log_file ) ) ) {
            wp_mkdir_p( dirname( $this->log_file ) );
        }
        
        // Create an empty log file if it doesn't exist
        if ( ! file_exists( $this->log_file ) ) {
            file_put_contents( $this->log_file, '--- GA4 Server-Side Tagging Log Started: ' . current_time( 'mysql' ) . " ---\n" );
            // Set appropriate permissions
            chmod( $this->log_file, 0644 );
        }
        
        // Rotate log file if it's too large (over 5MB)
        $this->maybe_rotate_log_file();
    }

    /**
     * Rotate log file if it's too large.
     *
     * @since    1.0.0
     */
    private function maybe_rotate_log_file() {
        if ( file_exists( $this->log_file ) && filesize( $this->log_file ) > 5 * 1024 * 1024 ) {
            $archive_file = $this->log_file . '.' . date( 'Y-m-d-H-i-s' ) . '.bak';
            rename( $this->log_file, $archive_file );
            file_put_contents( $this->log_file, '--- GA4 Server-Side Tagging Log Rotated: ' . current_time( 'mysql' ) . " ---\n" );
            chmod( $this->log_file, 0644 );
        }
    }

    /**
     * Log a message if debug mode is enabled.
     *
     * @since    1.0.0
     * @param    string    $message    The message to log.
     * @param    string    $level      The log level (info, warning, error).
     */
    public function log( $message, $level = 'info' ) {

        $timestamp = current_time( 'mysql' );
        $formatted_message = sprintf( '[%s] [%s] %s', $timestamp, strtoupper( $level ), $message );

        error_log( $formatted_message . PHP_EOL, 3, $this->log_file );
    }

    /**
     * Log an info message.
     *
     * @since    1.0.0
     * @param    string    $message    The message to log.
     */
    public function info( $message ) {
        $this->log( $message, 'info' );
    }

    /**
     * Log a warning message.
     *
     * @since    1.0.0
     * @param    string    $message    The message to log.
     */
    public function warning( $message ) {
        $this->log( $message, 'warning' );
    }

    /**
     * Log an error message.
     *
     * @since    1.0.0
     * @param    string    $message    The message to log.
     */
    public function error( $message ) {
        $this->log( $message, 'error' );
    }

    /**
     * Log an array or object as JSON.
     *
     * @since    1.0.0
     * @param    mixed     $data       The data to log.
     * @param    string    $label      Optional label for the data.
     * @param    string    $level      The log level (info, warning, error).
     */
    public function log_data( $data, $label = '', $level = 'info' ) {
        // if ( ! $this->debug_mode ) {
        //     return;
        // }

        $json = wp_json_encode( $data, JSON_PRETTY_PRINT );
        
        if ( $json === false ) {
            // Handle JSON encoding errors
            $this->error( 'Failed to encode data as JSON: ' . json_last_error_msg() );
            $json = wp_json_encode( 'Data could not be encoded as JSON' );
        }
        
        $message = $label ? $label . ': ' . $json : $json;
        
        // For large data, split into multiple log entries
        if ( strlen( $message ) > 1000 ) {
            $this->log( $label . ' (start of large data)', $level );
            $chunks = str_split( $json, 900 );
            foreach ( $chunks as $index => $chunk ) {
                $this->log( $label . ' (part ' . ( $index + 1 ) . '/' . count( $chunks ) . '): ' . $chunk, $level );
            }
            $this->log( $label . ' (end of large data)', $level );
        } else {
            $this->log( $message, $level );
        }
    }

    /**
     * Log a tracking event.
     *
     * @since    1.0.0
     * @param    string    $event_name    The event name.
     * @param    array     $event_data    The event data.
     */
    public function log_event( $event_name, $event_data ) {
        if ( ! $this->debug_mode ) {
            return;
        }
        
        $this->info( 'Tracking event: ' . $event_name );
        $this->log_data( $event_data, 'Event data for ' . $event_name );
    }

    /**
     * Get the log file path.
     *
     * @since    1.0.0
     * @return   string    The log file path.
     */
    public function get_log_file_path() {
        return $this->log_file;
    }

    /**
     * Clear the log file.
     *
     * @since    1.0.0
     */
    public function clear_log() {
        if ( file_exists( $this->log_file ) ) {
            file_put_contents( $this->log_file, '--- GA4 Server-Side Tagging Log Cleared: ' . current_time( 'mysql' ) . " ---\n" );
            return true;
        }
        return false;
    }

    /**
     * Check if debug mode is enabled.
     *
     * @since    1.0.0
     * @return   bool    Whether debug mode is enabled.
     */
    public function is_debug_mode() {
        return $this->debug_mode;
    }

    /**
     * Enable or disable debug mode.
     *
     * @since    1.0.0
     * @param    bool    $enabled    Whether to enable debug mode.
     */
    public function set_debug_mode( $enabled ) {
        $this->debug_mode = (bool) $enabled;
        update_option( 'ga4_server_side_tagging_debug_mode', $this->debug_mode );
    }
} 