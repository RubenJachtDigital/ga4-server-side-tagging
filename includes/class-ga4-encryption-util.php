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
     * Create JWT token using HMACSHA256
     * 
     * @param string $payload Data to encrypt as JWT payload
     * @param string $key Binary signing key
     * @return string JWT token
     */
    private static function create_jwt_token($payload, $key)
    {
        // JWT Header
        $header = array(
            'typ' => 'JWT',
            'alg' => 'HS256'
        );
        
        // JWT Payload - wrap the data
        $jwt_payload = array(
            'data' => $payload,
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
     * Verify JWT token and extract payload
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
        
        // Verify signature
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
        
        // Return the original data
        return isset($payload['data']) ? $payload['data'] : '';
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
}