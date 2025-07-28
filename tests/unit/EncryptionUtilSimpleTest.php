<?php
/**
 * Simple unit tests for GA4_Encryption_Util class (no Brain Monkey)
 *
 * @package GA4_Server_Side_Tagging
 * @since 1.0.0
 */

namespace GA4ServerSideTagging\Tests\Unit;

use GA4ServerSideTagging\Utilities\GA4_Encryption_Util;
use PHPUnit\Framework\TestCase;

class EncryptionUtilSimpleTest extends TestCase
{
    /**
     * Test basic data encryption and decryption
     */
    public function test_encrypt_decrypt_data()
    {
        $data = 'test_data_to_encrypt';
        $key = 'test_key_12345678901234567890123456789012'; // 32 chars
        
        // Test encryption
        $encrypted = GA4_Encryption_Util::encrypt_data($data, $key);
        $this->assertIsString($encrypted);
        $this->assertNotEquals($data, $encrypted);
        
        // Test decryption
        $decrypted = GA4_Encryption_Util::decrypt_data($encrypted, $key);
        $this->assertEquals($data, $decrypted);
    }
    
    /**
     * Test array encryption and decryption
     */
    public function test_encrypt_decrypt_array()
    {
        $data = array('key1' => 'value1', 'key2' => 'value2');
        $key = 'test_key_12345678901234567890123456789012';
        
        $encrypted = GA4_Encryption_Util::encrypt_data(json_encode($data), $key);
        $this->assertIsString($encrypted);
        
        $decrypted = GA4_Encryption_Util::decrypt_data($encrypted, $key);
        $this->assertEquals($data, json_decode($decrypted, true));
    }
    
    /**
     * Test encryption with invalid key
     */
    public function test_encrypt_with_invalid_key()
    {
        $data = 'test_data';
        $key = 'short'; // Too short
        
        $result = GA4_Encryption_Util::encrypt_data($data, $key);
        // Should handle invalid key gracefully
        $this->assertIsString($result);
    }
    
    /**
     * Test decryption with wrong key
     */
    public function test_decrypt_with_wrong_key()
    {
        $data = 'test_data';
        $key1 = 'test_key_12345678901234567890123456789012';
        $key2 = 'different_key_123456789012345678901234';
        
        $encrypted = GA4_Encryption_Util::encrypt_data($data, $key1);
        $decrypted = GA4_Encryption_Util::decrypt_data($encrypted, $key2);
        
        // Should fail gracefully
        $this->assertNotEquals($data, $decrypted);
    }
    
    /**
     * Test JWT creation and verification
     */
    public function test_create_and_verify_jwt()
    {
        $payload = array('test' => 'data', 'user_id' => 123);
        $secret = 'test_secret_key_123456789012345678901234';
        
        if (method_exists('GA4ServerSideTagging\Utilities\GA4_Encryption_Util', 'create_jwt')) {
            $jwt = GA4_Encryption_Util::create_jwt($payload, $secret);
            $this->assertIsString($jwt);
            
            $verified = GA4_Encryption_Util::verify_jwt($jwt, $secret);
            $this->assertEquals($payload, $verified);
        } else {
            $this->markTestSkipped('JWT methods not implemented yet');
        }
    }
    
    /**
     * Test time-based JWT expiry
     */
    public function test_time_based_jwt_expiry()
    {
        $payload = array('test' => 'data');
        $secret = 'test_secret_key_123456789012345678901234';
        $expiry_minutes = 1;
        
        if (method_exists('GA4ServerSideTagging\Utilities\GA4_Encryption_Util', 'create_time_based_jwt')) {
            $jwt = GA4_Encryption_Util::create_time_based_jwt($payload, $secret, $expiry_minutes);
            $this->assertIsString($jwt);
            
            // Should be valid immediately
            $verified = GA4_Encryption_Util::verify_time_based_jwt($jwt, $secret);
            $this->assertEquals($payload['test'], $verified['test']);
        } else {
            $this->markTestSkipped('Time-based JWT methods not implemented yet');
        }
    }
    
    /**
     * Test encryption key generation
     */
    public function test_generate_encryption_key()
    {
        if (method_exists('GA4ServerSideTagging\Utilities\GA4_Encryption_Util', 'generate_encryption_key')) {
            $key = GA4_Encryption_Util::generate_encryption_key();
            $this->assertIsString($key);
            $this->assertGreaterThanOrEqual(32, strlen($key));
        } else {
            $this->markTestSkipped('Key generation method not implemented yet');
        }
    }
    
    /**
     * Test request encryption detection
     */
    public function test_is_encrypted_request()
    {
        $encrypted_data = 'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.test';
        $plain_data = 'plain_text_data';
        
        if (method_exists('GA4ServerSideTagging\Utilities\GA4_Encryption_Util', 'is_encrypted_request')) {
            $this->assertTrue(GA4_Encryption_Util::is_encrypted_request($encrypted_data));
            $this->assertFalse(GA4_Encryption_Util::is_encrypted_request($plain_data));
        } else {
            $this->markTestSkipped('Encryption detection method not implemented yet');
        }
    }
}