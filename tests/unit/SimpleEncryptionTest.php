<?php
/**
 * Very simple encryption test (direct class testing)
 *
 * @package GA4_Server_Side_Tagging
 */

// Note: Bootstrap handles autoloading and WordPress function mocks

use GA4ServerSideTagging\Utilities\GA4_Encryption_Util;
use PHPUnit\Framework\TestCase;

class SimpleEncryptionTest extends TestCase
{
    public function test_encryption_class_exists()
    {
        $this->assertTrue(class_exists('GA4ServerSideTagging\Utilities\GA4_Encryption_Util'));
    }
    
    public function test_encrypt_method_exists()
    {
        $this->assertTrue(method_exists('GA4ServerSideTagging\Utilities\GA4_Encryption_Util', 'encrypt'));
    }
    
    public function test_decrypt_method_exists()
    {
        $this->assertTrue(method_exists('GA4ServerSideTagging\Utilities\GA4_Encryption_Util', 'decrypt'));
    }
    
    public function test_basic_encryption()
    {
        $plaintext = 'test data';
        $key = '1234567890abcdef1234567890abcdef1234567890abcdef1234567890abcdef'; // 64 hex chars
        
        $encrypted = GA4_Encryption_Util::encrypt($plaintext, $key);
        $this->assertIsString($encrypted);
        $this->assertNotEmpty($encrypted);
        $this->assertNotEquals($plaintext, $encrypted);
    }
    
    public function test_encryption_decryption_cycle()
    {
        $plaintext = 'test data for encryption';
        $key = '1234567890abcdef1234567890abcdef1234567890abcdef1234567890abcdef';
        
        $encrypted = GA4_Encryption_Util::encrypt($plaintext, $key);
        $this->assertNotFalse($encrypted);
        
        $decrypted = GA4_Encryption_Util::decrypt($encrypted, $key);
        $this->assertEquals($plaintext, $decrypted);
    }
    
    public function test_invalid_key_handling()
    {
        $plaintext = 'test data';
        $invalid_key = 'short_key';
        
        $result = GA4_Encryption_Util::encrypt($plaintext, $invalid_key);
        $this->assertFalse($result);
    }
}