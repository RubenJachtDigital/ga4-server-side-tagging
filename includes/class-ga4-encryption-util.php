<?php

namespace GA4ServerSideTagging\Utilities;

/**
 * JWT Encryption Utilities for GA4 Server-Side Tagging
 * 
 * Provides JWT encryption using HMACSHA256 with the backend key for secure request transmission
 *
 * @since      1.0.0
 * @package    GA4_Server_Side_Tagging
 */

if (!defined('WPINC')) {
    die;
}

class GA4_Encryption_Util
{
    /**
     * Create JWT token with encrypted payload
     * 
     * @param string $plaintext Data to encrypt as JWT payload
     * @param string $key_hex Backend key as hex string (64 characters for 256-bit)
     * @return string|false JWT token, or false on failure
     */
    public static function encrypt($plaintext, $key_hex)
    {
        try {
            // Validate key
            if (strlen($key_hex) !== 64 || !ctype_xdigit($key_hex)) {
                throw new \Exception('Invalid encryption key format. Must be 64 hex characters.');
            }

            // Convert hex key to binary for JWT signing
            $key = hex2bin($key_hex);
            if ($key === false) {
                throw new \Exception('Invalid hex key format.');
            }

            // Create JWT token with the plaintext as payload
            return self::create_jwt_token($plaintext, $key);
            
        } catch (\Exception $e) {
            error_log('GA4 JWT Creation Error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Verify and decrypt JWT token
     * 
     * @param string $jwt_token JWT token to verify and decrypt
     * @param string $key_hex Backend key as hex string (64 characters for 256-bit)
     * @return string|false Decrypted plaintext, or false on failure
     */
    public static function decrypt($jwt_token, $key_hex)
    {
        try {
            // Validate key
            if (strlen($key_hex) !== 64 || !ctype_xdigit($key_hex)) {
                throw new \Exception('Invalid encryption key format. Must be 64 hex characters.');
            }

            // Convert hex key to binary
            $key = hex2bin($key_hex);
            if ($key === false) {
                throw new \Exception('Invalid hex key format.');
            }

            // Verify and decode JWT token
            return self::verify_jwt_token($jwt_token, $key);
            
        } catch (\Exception $e) {
            error_log('GA4 JWT Verification Error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Create JWT token using HMACSHA256 with AES-GCM encrypted payload
     * 
     * @param string $payload Data to encrypt as JWT payload
     * @param string $key Binary signing key
     * @return string JWT token
     */
    private static function create_jwt_token($payload, $key)
    {
        // JWT Header - indicate that payload is encrypted
        $header = array(
            'typ' => 'JWT',
            'alg' => 'HS256',
            'enc' => 'A256GCM' // Indicate AES-256-GCM encryption
        );
        
        // Encrypt the payload data using AES-GCM
        $encryption_result = self::encrypt_aes_gcm($payload, $key);
        
        // JWT Payload - contains encrypted data, IV, and tag
        $jwt_payload = array(
            'enc_data' => self::base64url_encode($encryption_result['encrypted']),
            'iv' => self::base64url_encode($encryption_result['iv']),
            'tag' => self::base64url_encode($encryption_result['tag']),
            'iat' => time(),
            'exp' => time() + 300 // 5 minutes expiry
        );
        
        // Base64URL encode header and payload
        $header_encoded = self::base64url_encode(wp_json_encode($header));
        $payload_encoded = self::base64url_encode(wp_json_encode($jwt_payload));
        
        // Create signature using HMACSHA256
        $signature = hash_hmac('sha256', $header_encoded . '.' . $payload_encoded, $key, true);
        $signature_encoded = self::base64url_encode($signature);
        
        // Return complete JWT token
        return $header_encoded . '.' . $payload_encoded . '.' . $signature_encoded;
    }

    /**
     * Verify JWT token and extract payload with AES-GCM decryption
     * 
     * @param string $jwt_token JWT token to verify
     * @param string $key Binary signing key
     * @return string|false Decrypted payload or false on failure
     */
    private static function verify_jwt_token($jwt_token, $key)
    {
        // Split JWT token into parts
        $parts = explode('.', $jwt_token);
        if (count($parts) !== 3) {
            throw new \Exception('Invalid JWT token format');
        }
        
        list($header_encoded, $payload_encoded, $signature_encoded) = $parts;
        
        // Verify signature first
        $expected_signature = hash_hmac('sha256', $header_encoded . '.' . $payload_encoded, $key, true);
        $provided_signature = self::base64url_decode($signature_encoded);
        
        if (!hash_equals($expected_signature, $provided_signature)) {
            throw new \Exception('JWT signature verification failed');
        }
        
        // Decode and validate payload
        $payload = json_decode(self::base64url_decode($payload_encoded), true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \Exception('Invalid JWT payload JSON');
        }
        
        // Check expiration
        if (isset($payload['exp']) && $payload['exp'] < time()) {
            throw new \Exception('JWT token has expired');
        }
        
        // Check header for encryption information
        $header = json_decode(self::base64url_decode($header_encoded), true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \Exception('Invalid JWT header JSON');
        }
        
        // Handle both encrypted and legacy unencrypted tokens
        if (isset($header['enc']) && $header['enc'] === 'A256GCM' && 
            isset($payload['enc_data']) && isset($payload['iv']) && isset($payload['tag'])) {
            // New encrypted format - decrypt the payload
            try {
                $encrypted_data = self::base64url_decode($payload['enc_data']);
                $iv = self::base64url_decode($payload['iv']);
                $tag = self::base64url_decode($payload['tag']);
                $decrypted_data = self::decrypt_aes_gcm($encrypted_data, $iv, $tag, $key);
                return $decrypted_data;
            } catch (\Exception $decrypt_error) {
                throw new \Exception('JWT payload decryption failed: ' . $decrypt_error->getMessage());
            }
        } elseif (isset($payload['data'])) {
            // Legacy unencrypted format for backwards compatibility
            return $payload['data'];
        } else {
            throw new \Exception('JWT payload format not recognized');
        }
    }

    /**
     * Base64URL encode
     * 
     * @param string $data Data to encode
     * @return string Encoded data
     */
    private static function base64url_encode($data)
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    /**
     * Base64URL decode
     * 
     * @param string $data Data to decode
     * @return string Decoded data
     */
    private static function base64url_decode($data)
    {
        return base64_decode(strtr($data, '-_', '+/') . str_repeat('=', 3 - (3 + strlen($data)) % 4));
    }

    /**
     * Encrypt data using AES-256-GCM
     * 
     * @param string $plaintext Data to encrypt
     * @param string $key 32-byte encryption key
     * @return array Array containing encrypted data and IV
     * @throws Exception If encryption fails
     */
    private static function encrypt_aes_gcm($plaintext, $key)
    {
        // Generate random IV (12 bytes for GCM)
        $iv = random_bytes(12);
        
        // Encrypt using AES-256-GCM
        $encrypted = openssl_encrypt(
            $plaintext,
            'aes-256-gcm',
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );
        
        if ($encrypted === false) {
            throw new \Exception('AES-GCM encryption failed');
        }
        
        return array(
            'encrypted' => $encrypted,
            'iv' => $iv,
            'tag' => $tag
        );
    }

    /**
     * Decrypt data using AES-256-GCM
     * 
     * @param string $encrypted_data Encrypted data
     * @param string $iv Initialization vector
     * @param string $tag Authentication tag
     * @param string $key 32-byte encryption key
     * @return string Decrypted plaintext
     * @throws Exception If decryption fails
     */
    private static function decrypt_aes_gcm($encrypted_data, $iv, $tag, $key)
    {
        $decrypted = openssl_decrypt(
            $encrypted_data,
            'aes-256-gcm',
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );
        
        if ($decrypted === false) {
            throw new \Exception('AES-GCM decryption failed');
        }
        
        return $decrypted;
    }

    /**
     * Create JWT encrypted request payload
     * 
     * @param array $data Request data
     * @param string $encryption_key Backend encryption key (hex)
     * @return array JWT payload structure
     */
    public static function create_encrypted_request($data, $encryption_key)
    {
        $json_data = wp_json_encode($data);
        $jwt_token = self::encrypt($json_data, $encryption_key);
        
        if ($jwt_token === false) {
            throw new \Exception('Failed to create JWT token for request data');
        }
        
        return array(
            'jwt' => $jwt_token
        );
    }

    /**
     * Parse JWT encrypted request payload
     * 
     * @param array $request_data Request data containing JWT field
     * @param string $encryption_key Backend encryption key (hex)
     * @return array|false Decrypted data array, or false on failure
     */
    public static function parse_encrypted_request($request_data, $encryption_key)
    {
        if (!isset($request_data['jwt'])) {
            throw new \Exception('No JWT token found in request');
        }
        
        $decrypted_json = self::decrypt($request_data['jwt'], $encryption_key);
        
        if ($decrypted_json === false) {
            throw new \Exception('Failed to verify JWT token');
        }
        
        $decrypted_data = json_decode($decrypted_json, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \Exception('Failed to parse decrypted JSON: ' . json_last_error_msg());
        }
        
        return $decrypted_data;
    }

    /**
     * Create JWT encrypted response payload
     * 
     * @param array $data Response data
     * @param string $encryption_key Backend encryption key (hex)
     * @return array JWT response structure
     */
    public static function create_encrypted_response($data, $encryption_key)
    {
        $json_data = wp_json_encode($data);
        $jwt_token = self::encrypt($json_data, $encryption_key);
        
        if ($jwt_token === false) {
            throw new \Exception('Failed to create JWT token for response data');
        }
        
        return array(
            'jwt' => $jwt_token
        );
    }

    /**
     * Check if request uses JWT encryption
     * 
     * @param \WP_REST_Request $request Request object
     * @return bool True if request has JWT encryption header
     */
    public static function is_encrypted_request($request)
    {
        return $request->get_header('X-Encrypted') === 'true';
    }

    /**
     * Validate encryption key format
     * 
     * @param string $key_hex Encryption key
     * @return bool True if key is valid 256-bit hex key
     */
    public static function validate_encryption_key($key_hex)
    {
        return !empty($key_hex) && strlen($key_hex) === 64 && ctype_xdigit($key_hex);
    }

    /**
     * Check if a value is in encrypted format (base64 encoded JSON with data/iv/tag structure)
     * 
     * @param string $value Value to check
     * @return bool True if value appears to be encrypted
     */
    public static function is_encrypted_format($value)
    {
        try {
            // Try to decode as base64
            $decoded = base64_decode($value, true);
            if ($decoded === false) {
                return false;
            }
            
            // Try to parse as JSON
            $json_data = json_decode($decoded, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                return false;
            }
            
            // Check if it has the expected encrypted structure
            if (is_array($json_data) && isset($json_data['data'], $json_data['iv'], $json_data['tag'])) {
                return true;
            }
            
            return false;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Encrypt encryption key for database storage using WordPress salts
     * 
     * @param string $key_hex Raw encryption key (64 hex characters)
     * @return string Encrypted key for database storage
     */
    public static function encrypt_key_for_storage($key_hex)
    {
        if (!self::validate_encryption_key($key_hex)) {
            throw new \Exception('Invalid encryption key format for storage');
        }
        
        // Use WordPress option values as additional entropy
        $salt = get_option('ga4_time_based_salt', '');
        $auth_key = get_option('ga4_time_based_auth_key', '');
        
        if (empty($salt) || empty($auth_key)) {
            throw new \Exception('WordPress time-based encryption options not configured');
        }
        
        // Create derived key from WordPress salts
        $derived_key = hash_pbkdf2('sha256', $auth_key, $salt, 10000, 32, true);
        
        // Encrypt the key using AES-256-GCM
        $encrypted_result = self::encrypt_aes_gcm($key_hex, $derived_key);
        
        // Return base64 encoded encrypted data with IV and tag
        return base64_encode(json_encode(array(
            'data' => base64_encode($encrypted_result['encrypted']),
            'iv' => base64_encode($encrypted_result['iv']),
            'tag' => base64_encode($encrypted_result['tag'])
        )));
    }

    /**
     * Decrypt encryption key from database storage using WordPress salts
     * 
     * @param string $encrypted_key Encrypted key from database
     * @return string|false Decrypted key (64 hex characters) or false on failure
     */
    public static function decrypt_key_from_storage($encrypted_key)
    {
        try {
            // Use WordPress option values as additional entropy
            $salt = get_option('ga4_time_based_salt', '');
            $auth_key = get_option('ga4_time_based_auth_key', '');
            
            if (empty($salt) || empty($auth_key)) {
                throw new \Exception('WordPress time-based encryption options not configured');
            }
            
            // Create derived key from WordPress salts
            $derived_key = hash_pbkdf2('sha256', $auth_key, $salt, 10000, 32, true);
            
            // Decode the encrypted data
            $encrypted_data = json_decode(base64_decode($encrypted_key), true);
            
            if (!$encrypted_data || !isset($encrypted_data['data'], $encrypted_data['iv'], $encrypted_data['tag'])) {
                throw new \Exception('Invalid encrypted key format');
            }
            
            // Decrypt the key using AES-256-GCM
            $decrypted_key = self::decrypt_aes_gcm(
                base64_decode($encrypted_data['data']),
                base64_decode($encrypted_data['iv']),
                base64_decode($encrypted_data['tag']),
                $derived_key
            );
            
            // Validate decrypted key format
            if (!self::validate_encryption_key($decrypted_key)) {
                throw new \Exception('Decrypted key format validation failed');
            }
            
            return $decrypted_key;
            
        } catch (\Exception $e) {
            error_log('GA4 Key Decryption Error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Decrypt general key from database storage using WordPress salts
     * This method works with any key format, unlike decrypt_key_from_storage which only works with 64-char hex
     * 
     * @param string $encrypted_key Encrypted key from database
     * @return string|false Decrypted key (any format) or false on failure
     */
    public static function decrypt_general_key_from_storage($encrypted_key)
    {
        try {
            // Use WordPress option values as additional entropy
            $salt = get_option('ga4_time_based_salt', '');
            $auth_key = get_option('ga4_time_based_auth_key', '');
            
            if (empty($salt) || empty($auth_key)) {
                throw new \Exception('WordPress time-based encryption options not configured');
            }
            
            // Create derived key from WordPress salts
            $derived_key = hash_pbkdf2('sha256', $auth_key, $salt, 10000, 32, true);
            
            // Decode the encrypted data
            $encrypted_data = json_decode(base64_decode($encrypted_key), true);
            
            if (!$encrypted_data || !isset($encrypted_data['data'], $encrypted_data['iv'], $encrypted_data['tag'])) {
                throw new \Exception('Invalid encrypted key format');
            }
            
            // Decrypt the key using AES-256-GCM
            $decrypted_key = self::decrypt_aes_gcm(
                base64_decode($encrypted_data['data']),
                base64_decode($encrypted_data['iv']),
                base64_decode($encrypted_data['tag']),
                $derived_key
            );
            
            // No format validation for general keys - return as-is
            return $decrypted_key;
            
        } catch (\Exception $e) {
            error_log('GA4 General Key Decryption Error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Encrypt general key (like API key) for database storage using WordPress salts
     * This method works with any string format, unlike encrypt_key_for_storage which only works with 64-char hex
     * 
     * @param string $key_value Raw key value (any format)
     * @return string Encrypted key for database storage
     */
    public static function encrypt_general_key_for_storage($key_value)
    {
        if (empty($key_value)) {
            throw new \Exception('Empty key value provided for encryption');
        }
        
        // Use WordPress option values as additional entropy
        $salt = get_option('ga4_time_based_salt', '');
        $auth_key = get_option('ga4_time_based_auth_key', '');
        
        if (empty($salt) || empty($auth_key)) {
            throw new \Exception('WordPress time-based encryption options not configured');
        }
        
        // Create derived key from WordPress salts
        $derived_key = hash_pbkdf2('sha256', $auth_key, $salt, 10000, 32, true);
        
        // Encrypt the key using AES-256-GCM
        $encrypted_result = self::encrypt_aes_gcm($key_value, $derived_key);
        
        // Return base64 encoded encrypted data with IV and tag
        return base64_encode(json_encode(array(
            'data' => base64_encode($encrypted_result['encrypted']),
            'iv' => base64_encode($encrypted_result['iv']),
            'tag' => base64_encode($encrypted_result['tag'])
        )));
    }

    /**
     * Securely store encryption key in WordPress options
     * 
     * @param string $key_hex Raw encryption key (64 hex characters)
     * @param string $option_name WordPress option name
     * @return bool True on success, false on failure
     */
    public static function store_encrypted_key($key_hex, $option_name)
    {
        try {
            // Check if this is a JWT encryption key (64 hex chars) or other key type
            if (self::validate_encryption_key($key_hex)) {
                // This is a JWT encryption key - use the strict validation
                $encrypted_key = self::encrypt_key_for_storage($key_hex);
            } else {
                // This is another type of key (like API key) - use general encryption
                $encrypted_key = self::encrypt_general_key_for_storage($key_hex);
            }
            return update_option($option_name, $encrypted_key);
        } catch (\Exception $e) {
            error_log('GA4 Key Storage Error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Securely retrieve encryption key from WordPress options
     * 
     * @param string $option_name WordPress option name
     * @return string|false Decrypted key or false on failure
     */
    public static function retrieve_encrypted_key($option_name)
    {
        $encrypted_key = get_option($option_name, '');
        
        if (empty($encrypted_key)) {
            return false;
        }
        
        // Check if this looks like an encrypted value (base64 encoded JSON)
        $is_encrypted_format = self::is_encrypted_format($encrypted_key);
        
        if ($is_encrypted_format) {
            // This is encrypted data, try to decrypt it
            // First try as a JWT encryption key (strict 64-char hex validation)
            $decrypted_key = self::decrypt_key_from_storage($encrypted_key);
            
            if ($decrypted_key !== false) {
                return $decrypted_key;
            }
            
            // If strict validation failed, try as a general key (no format validation)
            return self::decrypt_general_key_from_storage($encrypted_key);
        } else {
            // This appears to be plaintext (legacy format), return as-is
            return $encrypted_key;
        }
    }

    /**
     * Generate time-based key for client-server encryption (5-minute slots)
     * Same function used on both client and server side
     * 
     * @return string 64-character hex key
     */
    public static function generate_time_based_key()
    {
        // Get current 5-minute slot
        $five_minute_slot = floor(time() / 300) * 300;
        
        // Use self-generating values (same as JavaScript)
        $site_url = self::normalize_site_url();
        $auth_key = 'ga4_time_based_auth_2024'; // Fixed auth key (same as JS)
        $salt = 'ga4_time_based_salt_2024'; // Fixed salt (same as JS)
        
        // Create deterministic key from time slot and site-specific data
        $key_material = $auth_key . $site_url . $five_minute_slot . $salt;
        $temp_key = hash('sha256', $key_material, true);
        
        return bin2hex($temp_key);
    }

    /**
     * Normalize site URL to match JavaScript window.location.origin format
     * 
     * @return string Normalized site URL without trailing slash
     */
    private static function normalize_site_url()
    {
        $site_url = get_site_url();
        
        // Remove trailing slash to match JavaScript behavior
        $site_url = rtrim($site_url, '/');
        
        return $site_url;
    }

    /**
     * Create time-based JWT token for client-server communication
     * 
     * @param array $payload Data to encrypt
     * @return string JWT token
     */
    public static function create_time_based_jwt($payload)
    {
        $time_based_key = self::generate_time_based_key();
        $json_payload = wp_json_encode($payload);
        
        return self::create_jwt_token($json_payload, hex2bin($time_based_key));
    }

    /**
     * Verify and decrypt time-based JWT token with clock tolerance
     * 
     * @param string $jwt_token JWT token to verify
     * @return array|false Decrypted payload or false on failure
     */
    public static function verify_time_based_jwt($jwt_token)
    {
        // Try current time slot first
        try {
            $time_based_key = self::generate_time_based_key();
            $decrypted_json = self::verify_jwt_token($jwt_token, hex2bin($time_based_key));
            
            $decrypted_data = json_decode($decrypted_json, true);
            
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new \Exception('Failed to parse decrypted JSON');
            }
            
            return $decrypted_data;
            
        } catch (\Exception $e) {
            // If current slot fails, try previous slot (for clock skew tolerance)
            try {
                error_log('GA4 Time-based JWT: Current slot failed, trying previous slot. Error: ' . $e->getMessage());
                
                $previous_slot_key = self::generate_time_based_key_for_slot(floor(time() / 300) * 300 - 300);
                $decrypted_json = self::verify_jwt_token($jwt_token, hex2bin($previous_slot_key));
                
                $decrypted_data = json_decode($decrypted_json, true);
                
                if (json_last_error() !== JSON_ERROR_NONE) {
                    throw new \Exception('Failed to parse decrypted JSON from previous slot');
                }
                
                error_log('GA4 Time-based JWT: Successfully decrypted with previous slot');
                return $decrypted_data;
                
            } catch (\Exception $e2) {
                error_log('GA4 Time-based JWT Verification Error (both slots failed): Current=' . $e->getMessage() . ', Previous=' . $e2->getMessage());
                return false;
            }
        }
    }

    /**
     * Generate time-based key for a specific time slot
     * 
     * @param int $time_slot Specific 5-minute time slot
     * @return string 64-character hex key
     */
    private static function generate_time_based_key_for_slot($time_slot)
    {
        // Use self-generating values (same as JavaScript)
        $site_url = self::normalize_site_url();
        $auth_key = 'ga4_time_based_auth_2024'; // Fixed auth key (same as JS)
        $salt = 'ga4_time_based_salt_2024'; // Fixed salt (same as JS)
        
        // Create deterministic key from specific time slot and site-specific data
        $key_material = $auth_key . $site_url . $time_slot . $salt;
        $temp_key = hash('sha256', $key_material, true);
        
        return bin2hex($temp_key);
    }
}