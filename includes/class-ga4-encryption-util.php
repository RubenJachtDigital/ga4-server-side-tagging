<?php

namespace GA4ServerSideTagging\Utilities;

/**
 * JWT Encryption Utilities for GA4 Server-Side Tagging
 * 
 * Provides AES-256-GCM encryption/decryption that matches the Cloudflare Worker implementation
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
     * Encrypt data using AES-256-GCM
     * 
     * @param string $plaintext Data to encrypt
     * @param string $key_hex Encryption key as hex string (64 characters for 256-bit)
     * @return string|false Encrypted data as hex string, or false on failure
     */
    public static function encrypt($plaintext, $key_hex)
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

            // Check if OpenSSL extension is available
            if (!extension_loaded('openssl')) {
                return self::encrypt_xor($plaintext, $key_hex);
            }

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
                throw new \Exception('Encryption failed: ' . openssl_error_string());
            }

            // Combine IV + encrypted data + tag
            $combined = $iv . $encrypted . $tag;
            
            return bin2hex($combined);
            
        } catch (\Exception $e) {
            error_log('GA4 Encryption Error: ' . $e->getMessage());
            
            // Fallback to XOR encryption
            return self::encrypt_xor($plaintext, $key_hex);
        }
    }

    /**
     * Decrypt data using AES-256-GCM
     * 
     * @param string $encrypted_hex Encrypted data as hex string
     * @param string $key_hex Encryption key as hex string (64 characters for 256-bit)
     * @return string|false Decrypted plaintext, or false on failure
     */
    public static function decrypt($encrypted_hex, $key_hex)
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

            // Convert hex data to binary
            $encrypted_data = hex2bin($encrypted_hex);
            if ($encrypted_data === false) {
                throw new \Exception('Invalid hex data format.');
            }

            // Check if OpenSSL extension is available
            if (!extension_loaded('openssl')) {
                return self::decrypt_xor($encrypted_hex, $key_hex);
            }

            // Extract IV, encrypted data, and tag
            $iv_length = 12; // GCM IV length
            $tag_length = 16; // GCM tag length
            
            if (strlen($encrypted_data) < $iv_length + $tag_length) {
                throw new \Exception('Invalid encrypted data length.');
            }

            $iv = substr($encrypted_data, 0, $iv_length);
            $tag = substr($encrypted_data, -$tag_length);
            $encrypted = substr($encrypted_data, $iv_length, -$tag_length);
            
            // Decrypt using AES-256-GCM
            $decrypted = openssl_decrypt(
                $encrypted,
                'aes-256-gcm',
                $key,
                OPENSSL_RAW_DATA,
                $iv,
                $tag
            );

            if ($decrypted === false) {
                throw new \Exception('Decryption failed: ' . openssl_error_string());
            }

            return $decrypted;
            
        } catch (\Exception $e) {
            error_log('GA4 Decryption Error: ' . $e->getMessage());
            
            // Fallback to XOR decryption
            return self::decrypt_xor($encrypted_hex, $key_hex);
        }
    }

    /**
     * Fallback XOR encryption (for compatibility)
     * 
     * @param string $plaintext Data to encrypt
     * @param string $key_hex Encryption key as hex string
     * @return string Encrypted data as hex string
     */
    private static function encrypt_xor($plaintext, $key_hex)
    {
        $key_bytes = hex2bin($key_hex);
        $key_length = strlen($key_bytes);
        $plaintext_bytes = $plaintext;
        $encrypted = '';
        
        for ($i = 0; $i < strlen($plaintext_bytes); $i++) {
            $encrypted .= chr(ord($plaintext_bytes[$i]) ^ ord($key_bytes[$i % $key_length]));
        }
        
        return bin2hex($encrypted);
    }

    /**
     * Fallback XOR decryption (for compatibility)
     * 
     * @param string $encrypted_hex Encrypted data as hex string
     * @param string $key_hex Encryption key as hex string
     * @return string Decrypted plaintext
     */
    private static function decrypt_xor($encrypted_hex, $key_hex)
    {
        $key_bytes = hex2bin($key_hex);
        $key_length = strlen($key_bytes);
        $encrypted_bytes = hex2bin($encrypted_hex);
        $decrypted = '';
        
        for ($i = 0; $i < strlen($encrypted_bytes); $i++) {
            $decrypted .= chr(ord($encrypted_bytes[$i]) ^ ord($key_bytes[$i % $key_length]));
        }
        
        return $decrypted;
    }

    /**
     * Create encrypted request payload
     * 
     * @param array $data Request data
     * @param string $encryption_key Encryption key (hex)
     * @return array Encrypted payload structure
     */
    public static function create_encrypted_request($data, $encryption_key)
    {
        $json_data = wp_json_encode($data);
        $encrypted = self::encrypt($json_data, $encryption_key);
        
        if ($encrypted === false) {
            throw new \Exception('Failed to encrypt request data');
        }
        
        return array(
            'encrypted' => $encrypted
        );
    }

    /**
     * Parse encrypted request payload
     * 
     * @param array $request_data Request data containing encrypted field
     * @param string $encryption_key Encryption key (hex)
     * @return array|false Decrypted data array, or false on failure
     */
    public static function parse_encrypted_request($request_data, $encryption_key)
    {
        if (!isset($request_data['encrypted'])) {
            throw new \Exception('No encrypted data found in request');
        }
        
        $decrypted_json = self::decrypt($request_data['encrypted'], $encryption_key);
        
        if ($decrypted_json === false) {
            throw new \Exception('Failed to decrypt request data');
        }
        
        $decrypted_data = json_decode($decrypted_json, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \Exception('Failed to parse decrypted JSON: ' . json_last_error_msg());
        }
        
        return $decrypted_data;
    }

    /**
     * Create encrypted response payload
     * 
     * @param array $data Response data
     * @param string $encryption_key Encryption key (hex)
     * @return array Encrypted response structure
     */
    public static function create_encrypted_response($data, $encryption_key)
    {
        $json_data = wp_json_encode($data);
        $encrypted = self::encrypt($json_data, $encryption_key);
        
        if ($encrypted === false) {
            throw new \Exception('Failed to encrypt response data');
        }
        
        return array(
            'encrypted' => $encrypted
        );
    }

    /**
     * Check if request is encrypted
     * 
     * @param \WP_REST_Request $request Request object
     * @return bool True if request has encryption header
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