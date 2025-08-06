<?php
/**
 * Mock WordPress upgrade.php for testing
 */

// Already defined in bootstrap-simple.php, but just in case
if (!function_exists('dbDelta')) {
    function dbDelta($queries) {
        return array('ga4_event_logs' => 'Created table ga4_event_logs');
    }
}