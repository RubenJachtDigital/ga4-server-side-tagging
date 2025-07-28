<?php

namespace GA4ServerSideTagging\Core;

use GA4ServerSideTagging\Utilities\GA4_Encryption_Util;

/**
 * GA4 Payload Transformer
 *
 * Handles transformation of event data to Google Analytics 4 format
 * with consent-aware privacy filtering and device detection
 *
 * @since      3.0.0
 * @package    GA4_Server_Side_Tagging
 */

if (!defined('WPINC')) {
    die;
}

class GA4_Payload_Transformer
{
    /**
     * The logger instance for debugging.
     *
     * @since    3.0.0
     * @access   private
     * @var      GA4_Server_Side_Tagging_Logger    $logger    Handles logging for the plugin.
     */
    private $logger;

    /**
     * Initialize the class and set its properties.
     *
     * @since    3.0.0
     * @param    GA4_Server_Side_Tagging_Logger    $logger    The logger instance.
     */
    public function __construct($logger = null)
    {
        $this->logger = $logger;
    }

    /**
     * Transform event data to Google Analytics expected format
     *
     * @since    3.0.0
     * @param    array    $event_data       The processed event data.
     * @param    object   $original_event   The original queued event object.
     * @param    array    $batch_consent    Batch-level consent data.
     * @return   array    Transformed GA4 payload.
     */
    public function transform_event_for_ga4($event_data, $original_event, $batch_consent = null)
    {
        // Handle nested event structure (event is wrapped in 'event' key)
        if (isset($event_data['event']) && is_array($event_data['event'])) {
            // Extract the actual event from the wrapper
            $actual_event = $event_data['event'];
            
            // Also extract other top-level data that might be useful
            if (isset($event_data['consent']) && !$batch_consent) {
                $batch_consent = $event_data['consent'];
            }
        } else {
            // Event data is already in the correct format
            $actual_event = $event_data;
        }
        
        // Start with basic GA4 payload structure
        $ga4_payload = array(
            'client_id' => $actual_event['client_id'] ?? $this->generate_client_id(),
            'events' => array(
                array(
                    'name' => $actual_event['name'],
                    'params' => $actual_event['params'] ?? array()
                )
            )
        );
        
        // Extract and move client_id from params to top level
        if (isset($actual_event['params']['client_id'])) {
            $ga4_payload['client_id'] = $actual_event['params']['client_id'];
            unset($ga4_payload['events'][0]['params']['client_id']);
        }
        
        // Add user_id at top level if present (and move from params if found there)
        if (isset($actual_event['user_id'])) {
            $ga4_payload['user_id'] = $actual_event['user_id'];
        } elseif (isset($actual_event['params']['user_id'])) {
            $ga4_payload['user_id'] = $actual_event['params']['user_id'];
            unset($ga4_payload['events'][0]['params']['user_id']);
        }
        
        // Add timestamp_micros at top level if present
        if (isset($actual_event['timestamp_micros'])) {
            $ga4_payload['timestamp_micros'] = $actual_event['timestamp_micros'];
        }
        
        // Extract and add consent data at top level with privacy compliance
        $consent_data = $this->extract_consent_data($actual_event, $original_event, $batch_consent);
        $consent_denied = false;
        
        // Always include consent data at top level since it's available and important for GA4
        if ($consent_data) {
            // Include ONLY the 2 allowed consent fields (ad_user_data and ad_personalization)
            // Filter out any other fields like consent_reason which GA4 doesn't accept
            $ga4_payload['consent'] = array();
            
            if (isset($consent_data['ad_user_data'])) {
                $ga4_payload['consent']['ad_user_data'] = $consent_data['ad_user_data'];
            }
            
            if (isset($consent_data['ad_personalization'])) {
                $ga4_payload['consent']['ad_personalization'] = $consent_data['ad_personalization'];
            }
            
            // Apply consent-based processing (similar to Cloudflare worker)
            $ad_user_data_denied = (isset($consent_data['ad_user_data']) && $consent_data['ad_user_data'] === 'DENIED');
            $ad_personalization_denied = (isset($consent_data['ad_personalization']) && $consent_data['ad_personalization'] === 'DENIED');
            
            // Apply analytics consent denied rules (when ad_user_data is DENIED)
            if ($ad_user_data_denied) {
                $ga4_payload = $this->apply_analytics_consent_denied($ga4_payload);
            }
            
            // Apply advertising consent denied rules (when ad_personalization is DENIED)
            if ($ad_personalization_denied) {
                $ga4_payload = $this->apply_advertising_consent_denied($ga4_payload);
            }
        } else {
            // Default consent values if not available (conservative approach)
            $ga4_payload['consent'] = array(
                'ad_user_data' => 'DENIED',
                'ad_personalization' => 'DENIED'
            );
            $consent_denied = true;
        }
        
        // Get original headers for device and location extraction
        $original_headers = $this->decrypt_headers_from_storage($original_event->original_headers);
        
        // Extract location data from params and add at top level (respecting consent)
        $user_location = $this->extract_location_data($ga4_payload['events'][0]['params'], $consent_denied);
        if (!empty($user_location)) {
            $ga4_payload['user_location'] = $user_location;
        }
        
        // Extract device info from params and headers (consent-aware)
        $device_info = $this->extract_device_info($ga4_payload['events'][0]['params'], $consent_denied, $original_headers);
        if (!empty($device_info)) {
            $ga4_payload['device'] = $device_info;
        }
        
        // Extract user agent and add at top level (with privacy handling)
        if (isset($ga4_payload['events'][0]['params']['user_agent'])) {
            $user_agent = $ga4_payload['events'][0]['params']['user_agent'];
            
            // Always use full user_agent (User-Agent is generally considered less sensitive)
            $ga4_payload['user_agent'] = $user_agent;
            unset($ga4_payload['events'][0]['params']['user_agent']);
        }
        
        // Add IP override when consent is granted (analytics consent)
        $ip_override = $this->extract_client_ip_from_headers($original_headers);
        if (!empty($ip_override) && !$consent_denied) {
            // Only add IP when analytics consent is granted
            $ga4_payload['ip_override'] = $ip_override;
        }
        
        // Add consent parameter to event params (similar to Cloudflare worker)
        if ($consent_data) {
            $consent_reason = $consent_data['consent_reason'] ?? $consent_data['reason'] ?? 'button_click';
            $ad_personalization = $consent_data['ad_personalization'] ?? 'DENIED';
            $ad_user_data = $consent_data['ad_user_data'] ?? 'DENIED';
            
            $ga4_payload['events'][0]['params']['consent'] = "ad_personalization: {$ad_personalization}. ad_user_data: {$ad_user_data}. reason: {$consent_reason}";
        } else {
            // Absolute fallback when no consent data available
            $ga4_payload['events'][0]['params']['consent'] = "ad_personalization: DENIED. ad_user_data: DENIED. reason: unknown";
        }
        
        // Clean up params by removing fields that have been moved to top level
        $this->cleanup_moved_params($ga4_payload['events'][0]['params']);
        
        return $ga4_payload;
    }

    /**
     * Extract consent data from various sources
     *
     * @since    3.0.0
     * @param    array    $event_data       Event data.
     * @param    object   $original_event   Original event object.
     * @param    array    $batch_consent    Batch consent data.
     * @return   array|null    Consent data or null.
     */
    private function extract_consent_data($event_data, $original_event, $batch_consent = null)
    {
        // First priority: use batch-level consent data (for batch events)
        if ($batch_consent) {
            return $batch_consent;
        }
        
        // Second priority: individual event consent
        if (isset($event_data['consent'])) {
            return $event_data['consent'];
        }
        
        // Third priority: check if event_data has the wrapper structure with consent at top level
        // This handles cases where consent is at the wrapper level, not in the individual event
        
        // Fallback: try to get consent from original event data
        $original_event_data = json_decode($original_event->event_data, true);
        if (isset($original_event_data['consent'])) {
            return $original_event_data['consent'];
        }
        
        return null;
    }

    /**
     * Extract location data from event params
     *
     * @since    3.0.0
     * @param    array    $params         Event parameters.
     * @param    boolean  $consent_denied Whether consent was denied.
     * @return   array    Location data for GA4.
     */
    private function extract_location_data(&$params, $consent_denied = false)
    {
        $user_location = array();
        
        // If consent denied, use timezone-based location data (less precise, privacy compliant)
        if ($consent_denied) {
            // Use timezone-based location data for privacy compliance
            if (isset($params['timezone'])) {
                $timezone = $params['timezone'];
                $timezone_location = $this->get_location_from_timezone($timezone);
                if ($timezone_location) {
                    $user_location = array_merge($user_location, $timezone_location);
                }
            }
            
            // Fallback: use country from timezone data if available
            if (empty($user_location) && isset($params['geo_country_tz'])) {
                $country_name = $params['geo_country_tz'];
                $user_location['country_id'] = $this->convert_country_name_to_iso($country_name);
            } elseif (isset($params['geo_country'])) {
                // Also check geo_country for consent-denied cases
                $country_name = $params['geo_country'];
                $user_location['country_id'] = $this->convert_country_name_to_iso($country_name);
            }
            
            // Include general continent data if available
            if (isset($params['geo_continent'])) {
                $continent = $params['geo_continent'];
                if ($continent === 'Europe') {
                    $user_location['continent_id'] = '150'; // Europe
                    $user_location['subcontinent_id'] = '155'; // Western Europe
                }
            }
        } else {
            // Full location data when consent is granted
            
            // Extract city (precise location)
            if (isset($params['geo_city'])) {
                $user_location['city'] = $params['geo_city'];
            } elseif (isset($params['geo_city_tz'])) {
                $user_location['city'] = $params['geo_city_tz'];
            }
            
            // Extract country - GA4 expects country_id (ISO country code)
            if (isset($params['geo_country'])) {
                $country_name = $params['geo_country'];
                $user_location['country_id'] = $this->convert_country_name_to_iso($country_name);
            } elseif (isset($params['geo_country_tz'])) {
                // Fallback to timezone-based country
                $country_name = $params['geo_country_tz'];
                $user_location['country_id'] = $this->convert_country_name_to_iso($country_name);
            }
            
            // Extract continent and subcontinent IDs (GA4 uses numeric IDs)
            if (isset($params['geo_continent'])) {
                $continent = $params['geo_continent'];
                if ($continent === 'Europe') {
                    $user_location['continent_id'] = '150'; // Europe
                    $user_location['subcontinent_id'] = '155'; // Western Europe
                }
            }
        }
        
        return $user_location;
    }

    /**
     * Extract device information from event params and headers with consent-aware filtering
     *
     * @since    3.0.0
     * @param    array    $params           Event parameters.
     * @param    boolean  $consent_denied   Whether analytics consent was denied.
     * @param    array    $headers          Original request headers.
     * @return   array    Device data for GA4.
     */
    private function extract_device_info(&$params, $consent_denied = false, $headers = array())
    {
        $device = array();
        
        // Parse User-Agent from headers if available
        $user_agent_data = array();
        if (!empty($headers['user_agent'])) {
            $user_agent_data = $this->parse_user_agent($headers['user_agent']);
        } elseif (!empty($params['user_agent'])) {
            $user_agent_data = $this->parse_user_agent($params['user_agent']);
        }
        
        // Always allowed device data (basic functionality, not personally identifiable)
        
        // Extract device category (most important field - always allowed)
        if (isset($params['device_type'])) {
            $device['category'] = $this->normalize_device_category($params['device_type']);
        } elseif (isset($params['is_mobile']) && $params['is_mobile']) {
            $device['category'] = 'mobile';
        } elseif (isset($params['is_tablet']) && $params['is_tablet']) {
            $device['category'] = 'tablet';
        } elseif (isset($params['is_desktop']) && $params['is_desktop']) {
            $device['category'] = 'desktop';
        } elseif (!empty($user_agent_data['device_type'])) {
            // Fallback to User-Agent parsed device type
            $device['category'] = $user_agent_data['device_type'];
        }
        
        // Extract language (important for localization - always allowed)
        if (isset($params['language'])) {
            $device['language'] = $this->normalize_language_code($params['language']);
        } elseif (isset($params['accept_language'])) {
            // Fallback to accept-language header
            $device['language'] = $this->extract_primary_language($params['accept_language']);
        } elseif (!empty($headers['accept_language'])) {
            // Fallback to headers accept-language
            $device['language'] = $this->extract_primary_language($headers['accept_language']);
        }
        
        // Consent-aware device data extraction
        if (!$consent_denied) {
            // Full device data when consent is granted
            
            // Screen resolution (useful for responsive design insights)
            if (isset($params['screen_resolution'])) {
                $device['screen_resolution'] = $this->normalize_screen_resolution($params['screen_resolution']);
            } elseif (isset($params['screen_width']) && isset($params['screen_height'])) {
                $device['screen_resolution'] = $params['screen_width'] . 'x' . $params['screen_height'];
            }
            
            // Operating system information
            if (isset($params['os_name'])) {
                $device['operating_system'] = $this->normalize_os_name($params['os_name']);
            } elseif (!empty($user_agent_data['os_name'])) {
                $device['operating_system'] = $this->normalize_os_name($user_agent_data['os_name']);
            }
            
            if (isset($params['os_version'])) {
                $device['operating_system_version'] = $this->normalize_version($params['os_version']);
            } elseif (!empty($user_agent_data['os_version'])) {
                $device['operating_system_version'] = $this->normalize_version($user_agent_data['os_version']);
            }
            
            // Browser information with version
            if (isset($params['browser_name'])) {
                $device['browser'] = $this->normalize_browser_name($params['browser_name']);
            } elseif (!empty($user_agent_data['browser_name'])) {
                $device['browser'] = $this->normalize_browser_name($user_agent_data['browser_name']);
            }
            
            if (isset($params['browser_version'])) {
                $device['browser_version'] = $this->normalize_version($params['browser_version']);
            } elseif (!empty($user_agent_data['browser_version'])) {
                $device['browser_version'] = $this->normalize_version($user_agent_data['browser_version']);
            }
            
            // Device model and brand (mainly for mobile devices)
            if (isset($params['device_model'])) {
                $device['model'] = $this->normalize_device_model($params['device_model']);
            }
            
            if (isset($params['device_brand'])) {
                $device['brand'] = $this->normalize_device_brand($params['device_brand']);
            }
            
            // Mobile-specific fields (legacy support)
            if (isset($params['mobile_model_name'])) {
                $device['model'] = $this->normalize_device_model($params['mobile_model_name']);
            }
            
            if (isset($params['mobile_brand_name'])) {
                $device['brand'] = $this->normalize_device_brand($params['mobile_brand_name']);
            }
        } else {
            // Consent denied - use generalized device data only
            
            // Generalized screen resolution categories instead of exact values
            if (isset($params['screen_resolution'])) {
                $device['screen_resolution'] = $this->generalize_screen_resolution($params['screen_resolution']);
            } elseif (isset($params['screen_width']) && isset($params['screen_height'])) {
                $resolution = $params['screen_width'] . 'x' . $params['screen_height'];
                $device['screen_resolution'] = $this->generalize_screen_resolution($resolution);
            }
            
            // Generalized OS information (major versions only)
            if (isset($params['os_name'])) {
                $device['operating_system'] = $this->generalize_os_name($params['os_name']);
            } elseif (!empty($user_agent_data['os_name'])) {
                $device['operating_system'] = $this->generalize_os_name($user_agent_data['os_name']);
            }
            
            if (isset($params['os_version'])) {
                $device['operating_system_version'] = $this->generalize_os_version($params['os_version']);
            } elseif (!empty($user_agent_data['os_version'])) {
                $device['operating_system_version'] = $this->generalize_os_version($user_agent_data['os_version']);
            }
            
            // Generalized browser information (major versions only)
            if (isset($params['browser_name'])) {
                $device['browser'] = $this->generalize_browser_name($params['browser_name']);
            } elseif (!empty($user_agent_data['browser_name'])) {
                $device['browser'] = $this->generalize_browser_name($user_agent_data['browser_name']);
            }
            
            if (isset($params['browser_version'])) {
                $device['browser_version'] = $this->generalize_browser_version($params['browser_version']);
            } elseif (!empty($user_agent_data['browser_version'])) {
                $device['browser_version'] = $this->generalize_browser_version($user_agent_data['browser_version']);
            }
            
            // No specific device model/brand when consent is denied
            // These could be used for fingerprinting
        }
        
        return $device;
    }

    /**
     * Parse User-Agent string to extract device information
     *
     * @since    3.0.0
     * @param    string   $user_agent   User-Agent string.
     * @return   array    Parsed device information.
     */
    private function parse_user_agent($user_agent)
    {
        if (empty($user_agent)) {
            return array();
        }
        
        $parsed = array();
        
        // Device type detection
        $mobile_patterns = array(
            '/Mobile|iPhone|iPod|Android|BlackBerry|Opera Mini|IEMobile|Windows Phone/i'
        );
        $tablet_patterns = array(
            '/iPad|Tablet|Kindle|Silk|PlayBook/i'
        );
        
        if (preg_match('/iPad/i', $user_agent)) {
            $parsed['device_type'] = 'tablet';
        } elseif (preg_match($tablet_patterns[0], $user_agent)) {
            $parsed['device_type'] = 'tablet';
        } elseif (preg_match($mobile_patterns[0], $user_agent)) {
            $parsed['device_type'] = 'mobile';
        } else {
            $parsed['device_type'] = 'desktop';
        }
        
        // Operating System detection
        if (preg_match('/Windows NT (\d+\.\d+)/i', $user_agent, $matches)) {
            $parsed['os_name'] = 'Windows';
            $version_map = array(
                '10.0' => '10',
                '6.3' => '8.1',
                '6.2' => '8',
                '6.1' => '7',
                '6.0' => 'Vista',
                '5.1' => 'XP'
            );
            $parsed['os_version'] = isset($version_map[$matches[1]]) ? $version_map[$matches[1]] : $matches[1];
        } elseif (preg_match('/Mac OS X (\d+[._]\d+)/i', $user_agent, $matches)) {
            $parsed['os_name'] = 'macOS';
            $parsed['os_version'] = str_replace('_', '.', $matches[1]);
        } elseif (preg_match('/iPhone OS (\d+[._]\d+)/i', $user_agent, $matches)) {
            $parsed['os_name'] = 'iOS';
            $parsed['os_version'] = str_replace('_', '.', $matches[1]);
        } elseif (preg_match('/Android (\d+\.\d+)/i', $user_agent, $matches)) {
            $parsed['os_name'] = 'Android';
            $parsed['os_version'] = $matches[1];
        } elseif (preg_match('/Linux/i', $user_agent)) {
            $parsed['os_name'] = 'Linux';
        }
        
        // Browser detection
        if (preg_match('/Chrome\/(\d+\.\d+)/i', $user_agent, $matches)) {
            // Check if it's actually Edge using Chrome engine
            if (preg_match('/Edg\/(\d+\.\d+)/i', $user_agent, $edge_matches)) {
                $parsed['browser_name'] = 'Edge';
                $parsed['browser_version'] = $edge_matches[1];
            } else {
                $parsed['browser_name'] = 'Chrome';
                $parsed['browser_version'] = $matches[1];
            }
        } elseif (preg_match('/Firefox\/(\d+\.\d+)/i', $user_agent, $matches)) {
            $parsed['browser_name'] = 'Firefox';
            $parsed['browser_version'] = $matches[1];
        } elseif (preg_match('/Safari\/[\d.]+/i', $user_agent) && !preg_match('/Chrome/i', $user_agent)) {
            $parsed['browser_name'] = 'Safari';
            if (preg_match('/Version\/(\d+\.\d+)/i', $user_agent, $matches)) {
                $parsed['browser_version'] = $matches[1];
            }
        } elseif (preg_match('/Opera[\/\s](\d+\.\d+)/i', $user_agent, $matches)) {
            $parsed['browser_name'] = 'Opera';
            $parsed['browser_version'] = $matches[1];
        } elseif (preg_match('/MSIE (\d+\.\d+)/i', $user_agent, $matches)) {
            $parsed['browser_name'] = 'Internet Explorer';
            $parsed['browser_version'] = $matches[1];
        } elseif (preg_match('/Trident.*rv:(\d+\.\d+)/i', $user_agent, $matches)) {
            $parsed['browser_name'] = 'Internet Explorer';
            $parsed['browser_version'] = $matches[1];
        }
        
        // Mobile device model detection (basic patterns)
        if ($parsed['device_type'] === 'mobile' || $parsed['device_type'] === 'tablet') {
            // iPhone detection
            if (preg_match('/iPhone/i', $user_agent)) {
                $parsed['device_brand'] = 'Apple';
                if (preg_match('/iPhone(\d+,\d+)/i', $user_agent, $matches)) {
                    $parsed['device_model'] = 'iPhone ' . $matches[1];
                } else {
                    $parsed['device_model'] = 'iPhone';
                }
            }
            // iPad detection
            elseif (preg_match('/iPad/i', $user_agent)) {
                $parsed['device_brand'] = 'Apple';
                $parsed['device_model'] = 'iPad';
            }
            // Samsung detection
            elseif (preg_match('/Samsung/i', $user_agent)) {
                $parsed['device_brand'] = 'Samsung';
                if (preg_match('/SM-([A-Z0-9]+)/i', $user_agent, $matches)) {
                    $parsed['device_model'] = 'SM-' . $matches[1];
                }
            }
            // Google Pixel detection
            elseif (preg_match('/Pixel (\d+)/i', $user_agent, $matches)) {
                $parsed['device_brand'] = 'Google';
                $parsed['device_model'] = 'Pixel ' . $matches[1];
            }
        }
        
        return $parsed;
    }

    /**
     * Apply analytics consent denied rules (when ad_user_data is DENIED)
     *
     * @since    3.0.0
     * @param    array    $ga4_payload    The GA4 payload.
     * @return   array    Modified payload.
     */
    private function apply_analytics_consent_denied($ga4_payload)
    {
        // Remove or anonymize personal identifiers
        if (isset($ga4_payload['user_id'])) {
            unset($ga4_payload['user_id']);
        }
        
        // Use session-based client ID if available
        if (isset($ga4_payload['client_id']) && strpos($ga4_payload['client_id'], 'session_') !== 0) {
            // Keep session-based client IDs, anonymize persistent ones
            $timestamp = time();
            $ga4_payload['client_id'] = "session_{$timestamp}";
        }
        
        // Remove precise location data from params
        if (isset($ga4_payload['events'][0]['params'])) {
            $location_params = array('geo_latitude', 'geo_longitude', 'geo_city', 'geo_region', 'geo_country');
            foreach ($location_params as $param) {
                unset($ga4_payload['events'][0]['params'][$param]);
            }
        }
        
        // Anonymize user agent if present
        if (isset($ga4_payload['events'][0]['params']['user_agent'])) {
            $ga4_payload['events'][0]['params']['user_agent'] = $this->anonymize_user_agent($ga4_payload['events'][0]['params']['user_agent']);
        }
        
        return $ga4_payload;
    }

    /**
     * Apply advertising consent denied rules (when ad_personalization is DENIED)
     *
     * @since    3.0.0
     * @param    array    $ga4_payload    The GA4 payload.
     * @return   array    Modified payload.
     */
    private function apply_advertising_consent_denied($ga4_payload)
    {
        if (!isset($ga4_payload['events'][0]['params'])) {
            return $ga4_payload;
        }
        
        $params = &$ga4_payload['events'][0]['params'];
        
        // Remove advertising attribution data
        $ad_params = array('gclid', 'content', 'term', 'originalGclid', 'originalContent', 'originalTerm');
        foreach ($ad_params as $param) {
            unset($params[$param]);
        }
        
        // Anonymize campaign data for paid traffic
        if (isset($params['campaign']) &&
            !in_array($params['campaign'], array('(organic)', '(direct)', '(not set)', '(referral)'))) {
            $params['campaign'] = '(denied consent)';
        }
        
        // Anonymize original campaign data for paid traffic
        if (isset($params['originalCampaign']) &&
            !in_array($params['originalCampaign'], array('(organic)', '(direct)', '(not set)', '(referral)'))) {
            $params['originalCampaign'] = '(denied consent)';
        }
        
        // Anonymize source/medium for paid traffic
        $paid_mediums = array('cpc', 'ppc', 'paidsearch', 'display', 'banner', 'cpm');
        if (isset($params['medium']) && in_array($params['medium'], $paid_mediums)) {
            $params['source'] = '(denied consent)';
            $params['medium'] = '(denied consent)';
        }
        
        // Anonymize original source/medium for paid traffic
        if (isset($params['originalMedium']) && in_array($params['originalMedium'], $paid_mediums)) {
            $params['originalSource'] = '(denied consent)';
            $params['originalMedium'] = '(denied consent)';
        }
        
        // Anonymize traffic type if it reveals paid advertising
        $paid_traffic_types = array('paid_search', 'paid_social', 'display', 'cpc');
        if (isset($params['traffic_type']) && in_array($params['traffic_type'], $paid_traffic_types)) {
            $params['traffic_type'] = '(denied consent)';
        }
        
        // Anonymize original traffic type if it reveals paid advertising
        if (isset($params['originalTrafficType']) && in_array($params['originalTrafficType'], $paid_traffic_types)) {
            $params['originalTrafficType'] = '(denied consent)';
        }
        
        return $ga4_payload;
    }

    /**
     * Clean up params by removing fields that have been moved to top level
     *
     * @since    3.0.0
     * @param    array    $params    Event parameters to clean.
     */
    private function cleanup_moved_params(&$params)
    {
        $fields_to_remove = array(
            // Geographic data (moved to user_location)
            'geo_city', 'geo_country', 'geo_region', 'geo_continent',
            'geo_city_tz', 'geo_country_tz', 'geo_latitude', 'geo_longitude',
            // Device data (moved to device object)
            'device_type', 'is_mobile', 'is_tablet', 'is_desktop',
            'browser_name', 'browser_version', 'screen_resolution', 'screen_width', 'screen_height',
            'os_name', 'os_version', 'device_model', 'device_brand',
            'mobile_model_name', 'mobile_brand_name',
            'viewport_width', 'viewport_height', 'language', 'accept_language',
            // User identification (moved to top level)
            'user_id'
        );
        
        foreach ($fields_to_remove as $field) {
            unset($params[$field]);
        }
    }

    /**
     * Generate a client ID for GA4
     *
     * @since    3.0.0
     * @return   string   The generated client ID.
     */
    private function generate_client_id()
    {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff)
        );
    }

    /**
     * Extract client IP from stored headers
     *
     * @since    3.0.0
     * @param    array    $headers    The headers array.
     * @return   string             The client IP address or empty string.
     */
    private function extract_client_ip_from_headers($headers)
    {
        if (empty($headers) || !is_array($headers)) {
            return '';
        }
        
        // Check for IP from various headers (in order of preference)
        $ip_header_mapping = array(
            'cf_connecting_ip' => 'HTTP_CF_CONNECTING_IP',     // Cloudflare
            'x_real_ip' => 'HTTP_X_REAL_IP',                  // Nginx proxy
            'x_forwarded_for' => 'HTTP_X_FORWARDED_FOR',      // Load balancer
            'x_forwarded' => 'HTTP_X_FORWARDED',              // Proxy
            'x_cluster_client_ip' => 'HTTP_X_CLUSTER_CLIENT_IP', // Cluster
            'forwarded_for' => 'HTTP_FORWARDED_FOR',          // Proxy
            'forwarded' => 'HTTP_FORWARDED',                  // Proxy
            'remote_addr' => 'REMOTE_ADDR'                    // Standard
        );
        
        foreach ($ip_header_mapping as $stored_key => $header_name) {
            if (isset($headers[$stored_key]) && !empty($headers[$stored_key])) {
                $ip = $headers[$stored_key];
                
                // Handle comma-separated IPs (take first one)
                if (strpos($ip, ',') !== false) {
                    $ip = trim(explode(',', $ip)[0]);
                }
                
                // Validate IP address
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    return $ip;
                }
            }
        }
        
        return '';
    }

    /**
     * Decrypt headers from database storage
     *
     * @since    3.0.0
     * @param    string    $stored_headers    The stored headers data (may be encrypted).
     * @return   array                       The decrypted headers array.
     */
    private function decrypt_headers_from_storage($stored_headers)
    {
        return GA4_Encryption_Util::decrypt_headers_from_storage($stored_headers);
    }

    /**
     * Get location data from timezone (privacy-compliant, less precise)
     *
     * @since    3.0.0
     * @param    string   $timezone    Timezone string (e.g., "Europe/Amsterdam").
     * @return   array    Location data derived from timezone.
     */
    private function get_location_from_timezone($timezone)
    {
        $location = array();
        
        // Common European timezone mappings (privacy-compliant general locations)
        $timezone_map = array(
            // Netherlands
            'Europe/Amsterdam' => array(
                'country_id' => 'NL',
                'continent_id' => '150',
                'subcontinent_id' => '155'
            ),
            // Belgium
            'Europe/Brussels' => array(
                'country_id' => 'BE',
                'continent_id' => '150',
                'subcontinent_id' => '155'
            ),
            // Germany
            'Europe/Berlin' => array(
                'country_id' => 'DE',
                'continent_id' => '150',
                'subcontinent_id' => '155'
            ),
            // France
            'Europe/Paris' => array(
                'country_id' => 'FR',
                'continent_id' => '150',
                'subcontinent_id' => '155'
            ),
            // United Kingdom
            'Europe/London' => array(
                'country_id' => 'GB',
                'continent_id' => '150',
                'subcontinent_id' => '154' // Northern Europe
            ),
            // Spain
            'Europe/Madrid' => array(
                'country_id' => 'ES',
                'continent_id' => '150',
                'subcontinent_id' => '039' // Southern Europe
            ),
            // Italy
            'Europe/Rome' => array(
                'country_id' => 'IT',
                'continent_id' => '150',
                'subcontinent_id' => '039' // Southern Europe
            ),
            // United States
            'America/New_York' => array(
                'country_id' => 'US',
                'continent_id' => '003', // North America
                'subcontinent_id' => '021' // Northern America
            ),
            'America/Los_Angeles' => array(
                'country_id' => 'US',
                'continent_id' => '003',
                'subcontinent_id' => '021'
            ),
            'America/Chicago' => array(
                'country_id' => 'US',
                'continent_id' => '003',
                'subcontinent_id' => '021'
            ),
            // Canada
            'America/Toronto' => array(
                'country_id' => 'CA',
                'continent_id' => '003',
                'subcontinent_id' => '021'
            )
        );
        
        // Direct timezone match
        if (isset($timezone_map[$timezone])) {
            return $timezone_map[$timezone];
        }
        
        // Fallback: extract from timezone format (e.g., "Europe/Amsterdam" -> Europe, NL)
        if (strpos($timezone, '/') !== false) {
            $parts = explode('/', $timezone);
            $continent_name = $parts[0];
            $city_name = $parts[1] ?? '';
            
            // Map continent names to IDs
            $continent_mapping = array(
                'Europe' => array('continent_id' => '150', 'subcontinent_id' => '155'),
                'America' => array('continent_id' => '003', 'subcontinent_id' => '021'),
                'Asia' => array('continent_id' => '142', 'subcontinent_id' => '030'),
                'Africa' => array('continent_id' => '002', 'subcontinent_id' => '015'),
                'Australia' => array('continent_id' => '009', 'subcontinent_id' => '053')
            );
            
            if (isset($continent_mapping[$continent_name])) {
                $location = $continent_mapping[$continent_name];
                
                // Try to infer country from major cities (privacy-compliant general mapping)
                $city_country_map = array(
                    'Amsterdam' => 'NL', 'Brussels' => 'BE', 'Berlin' => 'DE',
                    'Paris' => 'FR', 'London' => 'GB', 'Madrid' => 'ES',
                    'Rome' => 'IT', 'New_York' => 'US', 'Los_Angeles' => 'US',
                    'Chicago' => 'US', 'Toronto' => 'CA'
                );
                
                if (isset($city_country_map[$city_name])) {
                    $location['country_id'] = $city_country_map[$city_name];
                }
            }
        }
        
        return $location;
    }

    /**
     * Convert country name to ISO country code for GA4
     *
     * @since    3.0.0
     * @param    string   $country_name    Country name.
     * @return   string   ISO country code.
     */
    private function convert_country_name_to_iso($country_name)
    {
        // Common country name to ISO code mappings
        $country_mappings = array(
            'The Netherlands' => 'NL',
            'Netherlands' => 'NL',
            'Belgium' => 'BE',
            'Germany' => 'DE',
            'France' => 'FR',
            'United Kingdom' => 'GB',
            'United States' => 'US',
            'Canada' => 'CA',
            'Australia' => 'AU',
            'Japan' => 'JP',
            'China' => 'CN',
            'India' => 'IN',
            'Brazil' => 'BR',
            'Mexico' => 'MX',
            'Italy' => 'IT',
            'Spain' => 'ES',
            'Poland' => 'PL',
            'Sweden' => 'SE',
            'Norway' => 'NO',
            'Denmark' => 'DK',
            'Finland' => 'FI',
            'Switzerland' => 'CH',
            'Austria' => 'AT',
            'Czech Republic' => 'CZ',
            'Hungary' => 'HU',
            'Portugal' => 'PT',
            'Ireland' => 'IE',
            'Russia' => 'RU',
            'Turkey' => 'TR',
            'South Africa' => 'ZA'
        );
        
        // Check if we have a direct mapping
        if (isset($country_mappings[$country_name])) {
            return $country_mappings[$country_name];
        }
        
        // If country name is already 2 characters (likely ISO code), return as-is
        if (strlen($country_name) === 2 && ctype_alpha($country_name)) {
            return strtoupper($country_name);
        }
        
        // Fallback: return the original name (not ideal but better than nothing)
        return $country_name;
    }

    /**
     * Normalize device category to GA4 standard values
     *
     * @since    3.0.0
     * @param    string   $category   Raw device category.
     * @return   string   Normalized category.
     */
    private function normalize_device_category($category)
    {
        $category = strtolower(trim($category));
        
        // Map common variations to GA4 standard values
        $category_map = array(
            'phone' => 'mobile',
            'smartphone' => 'mobile',
            'mobile phone' => 'mobile',
            'tablet' => 'tablet',
            'desktop' => 'desktop',
            'computer' => 'desktop',
            'pc' => 'desktop',
            'laptop' => 'desktop',
            'smart tv' => 'smart tv',
            'tv' => 'smart tv',
            'smarttv' => 'smart tv',
            'wearable' => 'wearable',
            'watch' => 'wearable',
            'smart watch' => 'wearable'
        );
        
        return isset($category_map[$category]) ? $category_map[$category] : $category;
    }

    /**
     * Normalize language code to ISO 639-1 format
     *
     * @since    3.0.0
     * @param    string   $language   Raw language string.
     * @return   string   Normalized language code.
     */
    private function normalize_language_code($language)
    {
        if (empty($language)) {
            return '';
        }
        
        // Extract primary language from locale (e.g., "en-US" -> "en")
        $language = strtolower(trim($language));
        if (strpos($language, '-') !== false) {
            return substr($language, 0, strpos($language, '-'));
        }
        
        return $language;
    }

    /**
     * Extract primary language from Accept-Language header
     *
     * @since    3.0.0
     * @param    string   $accept_language   Accept-Language header value.
     * @return   string   Primary language code.
     */
    private function extract_primary_language($accept_language)
    {
        if (empty($accept_language)) {
            return '';
        }
        
        // Parse Accept-Language header (e.g., "en-US,en;q=0.9,es;q=0.8")
        $languages = explode(',', $accept_language);
        $primary_lang = trim($languages[0]);
        
        // Remove quality factor if present
        if (strpos($primary_lang, ';') !== false) {
            $primary_lang = substr($primary_lang, 0, strpos($primary_lang, ';'));
        }
        
        return $this->normalize_language_code($primary_lang);
    }

    /**
     * Normalize screen resolution
     *
     * @since    3.0.0
     * @param    string   $resolution   Raw resolution string.
     * @return   string   Normalized resolution.
     */
    private function normalize_screen_resolution($resolution)
    {
        if (empty($resolution)) {
            return '';
        }
        
        // Ensure format is WIDTHxHEIGHT
        $resolution = preg_replace('/[^\dx]/', '', $resolution);
        if (preg_match('/^(\d+)[x\*](\d+)$/', $resolution, $matches)) {
            return $matches[1] . 'x' . $matches[2];
        }
        
        return $resolution;
    }

    /**
     * Normalize OS name
     *
     * @since    3.0.0
     * @param    string   $os_name   Raw OS name.
     * @return   string   Normalized OS name.
     */
    private function normalize_os_name($os_name)
    {
        $os_name = trim($os_name);
        
        // Common OS name mappings
        $os_map = array(
            'Windows NT' => 'Windows',
            'Win32' => 'Windows',
            'Win64' => 'Windows',
            'Mac OS' => 'macOS',
            'Mac OS X' => 'macOS',
            'MacOS' => 'macOS',
            'iPhone OS' => 'iOS',
            'iPad OS' => 'iPadOS',
            'iPadOS' => 'iPadOS'
        );
        
        foreach ($os_map as $pattern => $normalized) {
            if (stripos($os_name, $pattern) !== false) {
                return $normalized;
            }
        }
        
        return $os_name;
    }

    /**
     * Normalize browser name
     *
     * @since    3.0.0
     * @param    string   $browser_name   Raw browser name.
     * @return   string   Normalized browser name.
     */
    private function normalize_browser_name($browser_name)
    {
        $browser_name = trim($browser_name);
        
        // Common browser name mappings
        $browser_map = array(
            'Google Chrome' => 'Chrome',
            'Mozilla Firefox' => 'Firefox',
            'Internet Explorer' => 'Internet Explorer',
            'Microsoft Edge' => 'Edge',
            'Safari' => 'Safari',
            'Opera' => 'Opera',
            'Samsung Internet' => 'Samsung Internet'
        );
        
        foreach ($browser_map as $pattern => $normalized) {
            if (stripos($browser_name, $pattern) !== false) {
                return $normalized;
            }
        }
        
        return $browser_name;
    }

    /**
     * Normalize version strings
     *
     * @since    3.0.0
     * @param    string   $version   Raw version string.
     * @return   string   Normalized version.
     */
    private function normalize_version($version)
    {
        if (empty($version)) {
            return '';
        }
        
        // Extract version numbers (e.g., "13.5.1" from "Version 13.5.1")
        if (preg_match('/(\d+(?:\.\d+)*(?:\.\d+)?)/', $version, $matches)) {
            return $matches[1];
        }
        
        return $version;
    }

    /**
     * Normalize device model
     *
     * @since    3.0.0
     * @param    string   $model   Raw device model.
     * @return   string   Normalized model.
     */
    private function normalize_device_model($model)
    {
        return trim($model);
    }

    /**
     * Normalize device brand
     *
     * @since    3.0.0
     * @param    string   $brand   Raw device brand.
     * @return   string   Normalized brand.
     */
    private function normalize_device_brand($brand)
    {
        return trim($brand);
    }

    /**
     * Generalize screen resolution for privacy (consent denied)
     *
     * @since    3.0.0
     * @param    string   $resolution   Exact resolution.
     * @return   string   Generalized resolution category.
     */
    private function generalize_screen_resolution($resolution)
    {
        if (empty($resolution)) {
            return '';
        }
        
        // Extract width and height
        if (preg_match('/^(\d+)[x\*](\d+)$/', $resolution, $matches)) {
            $width = intval($matches[1]);
            
            // Categorize by common resolution ranges
            if ($width <= 768) {
                return 'mobile'; // Mobile/small tablet
            } elseif ($width <= 1024) {
                return 'tablet'; // Tablet
            } elseif ($width <= 1366) {
                return 'laptop'; // Small laptop
            } elseif ($width <= 1920) {
                return 'desktop'; // Desktop/large laptop
            } else {
                return 'large'; // Large displays
            }
        }
        
        return 'unknown';
    }

    /**
     * Generalize OS name for privacy (consent denied)
     *
     * @since    3.0.0
     * @param    string   $os_name   Specific OS name.
     * @return   string   Generalized OS category.
     */
    private function generalize_os_name($os_name)
    {
        $os_name = strtolower($os_name);
        
        if (strpos($os_name, 'windows') !== false) {
            return 'Windows';
        } elseif (strpos($os_name, 'mac') !== false || strpos($os_name, 'darwin') !== false) {
            return 'macOS';
        } elseif (strpos($os_name, 'ios') !== false) {
            return 'iOS';
        } elseif (strpos($os_name, 'android') !== false) {
            return 'Android';
        } elseif (strpos($os_name, 'linux') !== false) {
            return 'Linux';
        }
        
        return 'Other';
    }

    /**
     * Generalize OS version for privacy (consent denied)
     *
     * @since    3.0.0
     * @param    string   $os_version   Specific OS version.
     * @return   string   Generalized version.
     */
    private function generalize_os_version($os_version)
    {
        if (empty($os_version)) {
            return '';
        }
        
        // Extract major version only (e.g., "13.5.1" -> "13")
        if (preg_match('/^(\d+)/', $os_version, $matches)) {
            return $matches[1];
        }
        
        return '';
    }

    /**
     * Generalize browser name for privacy (consent denied)
     *
     * @since    3.0.0
     * @param    string   $browser_name   Specific browser name.
     * @return   string   Generalized browser category.
     */
    private function generalize_browser_name($browser_name)
    {
        $browser_name = strtolower($browser_name);
        
        if (strpos($browser_name, 'chrome') !== false) {
            return 'Chrome';
        } elseif (strpos($browser_name, 'firefox') !== false) {
            return 'Firefox';
        } elseif (strpos($browser_name, 'safari') !== false) {
            return 'Safari';
        } elseif (strpos($browser_name, 'edge') !== false) {
            return 'Edge';
        } elseif (strpos($browser_name, 'opera') !== false) {
            return 'Opera';
        }
        
        return 'Other';
    }

    /**
     * Generalize browser version for privacy (consent denied)
     *
     * @since    3.0.0
     * @param    string   $browser_version   Specific browser version.
     * @return   string   Generalized version.
     */
    private function generalize_browser_version($browser_version)
    {
        if (empty($browser_version)) {
            return '';
        }
        
        // Extract major version only (e.g., "136.0.7103.60" -> "136")
        if (preg_match('/^(\d+)/', $browser_version, $matches)) {
            return $matches[1];
        }
        
        return '';
    }

    /**
     * Anonymize User-Agent string for privacy compliance
     *
     * @since    3.0.0
     * @param    string   $user_agent    Original User-Agent string.
     * @return   string   Anonymized User-Agent string.
     */
    private function anonymize_user_agent($user_agent)
    {
        if (empty($user_agent)) {
            return '';
        }
        
        // Replace version numbers with x.x (removes specific software versions)
        $anonymized = preg_replace('/\d+\.\d+[\.\d]*/', 'x.x', $user_agent);
        
        // Replace system info in parentheses with (anonymous)
        $anonymized = preg_replace('/\([^)]*\)/', '(anonymous)', $anonymized);
        
        // Truncate to 100 characters to prevent potential fingerprinting
        $anonymized = substr($anonymized, 0, 100);
        
        return $anonymized;
    }
}
