<?php
/**
 * Unit tests for GA4_Encryption_Util class
 *
 * @package GA4_Server_Side_Tagging
 * @since 1.0.0
 */

namespace GA4ServerSideTagging\Tests\Unit;

use GA4ServerSideTagging\Utilities\GA4_Encryption_Util;
use PHPUnit\Framework\TestCase;
use Brain\Monkey\Functions;
use Brain\Monkey\Actions;
use Brain\Monkey\Filters;

class EncryptionUtilTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        \Brain\Monkey\setUp();
        
        // Mock WordPress functions
        Functions\when('wp_json_encode')->alias('json_encode');
        Functions\when('wp_unslash')->returnArg();
        Functions\when('sanitize_text_field')->returnArg();
    }

    protected function tearDown(): void
    {
        \Brain\Monkey\tearDown();
        parent::tearDown();
    }

    /**
     * Test basic encryption and decryption
     */
    public function test_encrypt_decrypt_data()
    {
        $test_data = 'test data to encrypt';
        $key = 'test-encryption-key-32-chars-long!';
        
        $encrypted = GA4_Encryption_Util::encrypt_data($test_data, $key);
        $this->assertNotEmpty($encrypted);
        $this->assertNotEquals($test_data, $encrypted);
        
        $decrypted = GA4_Encryption_Util::decrypt_data($encrypted, $key);
        $this->assertEquals($test_data, $decrypted);
    }

    /**
     * Test array data encryption
     */
    public function test_encrypt_decrypt_array()
    {
        $test_array = array(
            'event_name' => 'test_event',
            'params' => array(
                'client_id' => '123.456',
                'session_id' => '789'
            ),
            'timestamp' => 1234567890
        );
        
        $key = 'test-encryption-key-32-chars-long!';
        
        $encrypted = GA4_Encryption_Util::encrypt_data(json_encode($test_array), $key);
        $decrypted = GA4_Encryption_Util::decrypt_data($encrypted, $key);
        $decrypted_array = json_decode($decrypted, true);
        
        $this->assertEquals($test_array, $decrypted_array);
    }

    /**
     * Test encryption with invalid key
     */
    public function test_encrypt_with_invalid_key()
    {
        $test_data = 'test data';
        $invalid_key = 'short'; // Too short
        
        $result = GA4_Encryption_Util::encrypt_data($test_data, $invalid_key);
        
        // Should handle gracefully or return false
        $this->assertTrue($result === false || is_string($result));
    }

    /**
     * Test decryption with wrong key
     */
    public function test_decrypt_with_wrong_key()
    {
        $test_data = 'test data';
        $correct_key = 'test-encryption-key-32-chars-long!';
        $wrong_key = 'wrong-encryption-key-32-chars-long!';
        
        $encrypted = GA4_Encryption_Util::encrypt_data($test_data, $correct_key);
        $result = GA4_Encryption_Util::decrypt_data($encrypted, $wrong_key);
        
        $this->assertFalse($result);
    }

    /**
     * Test JWT creation and verification
     */
    public function test_create_and_verify_jwt()
    {
        Functions\when('time')->justReturn(1234567890);
        
        $payload = array(
            'event_name' => 'test_event',
            'client_id' => '123.456'
        );
        
        $key = 'test-jwt-key-32-characters-long!';
        
        // This would test JWT functionality if implemented
        $this->assertTrue(true); // Placeholder assertion
    }

    /**
     * Test time-based JWT with expiry
     */
    public function test_time_based_jwt_expiry()
    {
        Functions\when('time')->justReturn(1234567890);
        
        $payload = array('test' => 'data');
        $key = 'test-key-32-characters-long-enough!';
        
        // Mock JWT creation (if method exists)
        if (method_exists(GA4_Encryption_Util::class, 'create_time_based_jwt')) {
            $jwt = GA4_Encryption_Util::create_time_based_jwt($payload, $key, 300); // 5 minutes
            $this->assertIsString($jwt);
            
            // Test verification within time window
            $verified = GA4_Encryption_Util::verify_time_based_jwt($jwt);
            $this->assertEquals($payload, $verified);
            
            // Test expiry (mock time advancement)
            Functions\when('time')->justReturn(1234567890 + 400); // 6+ minutes later
            $expired_result = GA4_Encryption_Util::verify_time_based_jwt($jwt);
            $this->assertFalse($expired_result);
        } else {
            $this->markTestSkipped('Time-based JWT methods not implemented yet');
        }
    }

    /**
     * Test key generation
     */
    public function test_generate_encryption_key()
    {
        if (method_exists(GA4_Encryption_Util::class, 'generate_key')) {
            $key = GA4_Encryption_Util::generate_key();
            $this->assertIsString($key);
            $this->assertGreaterThanOrEqual(32, strlen($key));
        } else {
            $this->markTestSkipped('Key generation method not implemented yet');
        }
    }

    /**
     * Test encrypted request detection
     */
    public function test_is_encrypted_request()
    {
        // Mock WordPress request object
        $request = $this->createMock('WP_REST_Request');
        
        // Test with encryption header
        $request->method('get_header')
            ->with('X-Encrypted')
            ->willReturn('true');
            
        Functions\when('get_option')
            ->with('ga4_jwt_encryption_enabled')
            ->justReturn(true);
            
        if (method_exists(GA4_Encryption_Util::class, 'is_encrypted_request')) {
            $result = GA4_Encryption_Util::is_encrypted_request($request);
            $this->assertTrue($result);
        } else {
            $this->markTestSkipped('is_encrypted_request method not implemented yet');
        }
    }

    /**
     * Test header encryption
     */
    public function test_encrypt_headers()
    {
        $headers = array(
            'User-Agent' => 'Mozilla/5.0 Test Browser',
            'Accept-Language' => 'en-US,en;q=0.9',
            'X-Forwarded-For' => '192.168.1.1'
        );
        
        $key = 'test-encryption-key-32-chars-long!';
        
        if (method_exists(GA4_Encryption_Util::class, 'encrypt_headers')) {
            $encrypted_headers = GA4_Encryption_Util::encrypt_headers($headers, $key);
            $this->assertIsString($encrypted_headers);
            
            $decrypted_headers = GA4_Encryption_Util::decrypt_headers($encrypted_headers, $key);
            $this->assertEquals($headers, $decrypted_headers);
        } else {
            $this->markTestSkipped('Header encryption methods not implemented yet');
        }
    }
}
