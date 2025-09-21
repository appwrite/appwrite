<?php

namespace Tests\Unit\Auth;

use Appwrite\Auth\Hash\Argon2;
use PHPUnit\Framework\TestCase;

class Argon2Test extends TestCase
{
    private Argon2 $argon2;

    protected function setUp(): void
    {
        $this->argon2 = new Argon2();
    }

    public function testDefaultOptions(): void
    {
        $expected = ['memory_cost' => 65536, 'time_cost' => 4, 'threads' => 3];
        $this->assertEquals($expected, $this->argon2->getDefaultOptions());
    }

    public function testHashWithDefaultOptions(): void
    {
        $password = 'testpassword123';
        $hash = $this->argon2->hash($password);
        
        $this->assertIsString($hash);
        $this->assertStringStartsWith('$argon2id$', $hash);
        $this->assertTrue($this->argon2->verify($password, $hash));
    }

    public function testHashWithSnakeCaseOptions(): void
    {
        $options = ['memory_cost' => 65536, 'time_cost' => 4, 'threads' => 3];
        $argon2 = new Argon2($options);
        
        $password = 'testpassword123';
        $hash = $argon2->hash($password);
        
        $this->assertIsString($hash);
        $this->assertStringStartsWith('$argon2id$', $hash);
        $this->assertTrue($argon2->verify($password, $hash));
    }

    public function testHashWithCamelCaseOptions(): void
    {
        $options = ['memoryCost' => 65536, 'timeCost' => 4, 'threads' => 3];
        $argon2 = new Argon2($options);
        
        $password = 'testpassword123';
        $hash = $argon2->hash($password);
        
        $this->assertIsString($hash);
        $this->assertStringStartsWith('$argon2id$', $hash);
        $this->assertTrue($argon2->verify($password, $hash));
    }

    public function testHashWithMixedCaseOptions(): void
    {
        $options = ['memoryCost' => 65536, 'time_cost' => 4, 'threads' => 3];
        $argon2 = new Argon2($options);
        
        $password = 'testpassword123';
        $hash = $argon2->hash($password);
        
        $this->assertIsString($hash);
        $this->assertStringStartsWith('$argon2id$', $hash);
        $this->assertTrue($argon2->verify($password, $hash));
    }

    public function testHashWithOldFormatOptions(): void
    {
        // This simulates the old problematic format
        $options = ['type' => 'argon2', 'memoryCost' => 2048, 'timeCost' => 4, 'threads' => 3];
        $argon2 = new Argon2($options);
        
        $password = 'testpassword123';
        $hash = $argon2->hash($password);
        
        $this->assertIsString($hash);
        $this->assertStringStartsWith('$argon2id$', $hash);
        $this->assertTrue($argon2->verify($password, $hash));
    }

    public function testVerifyWithOldFormatHash(): void
    {
        // Simulate a hash created with old format options
        $oldOptions = ['type' => 'argon2', 'memoryCost' => 2048, 'timeCost' => 4, 'threads' => 3];
        $oldArgon2 = new Argon2($oldOptions);
        $password = 'testpassword123';
        $oldHash = $oldArgon2->hash($password);
        
        // Now verify with new format Argon2 instance
        $newOptions = ['memory_cost' => 65536, 'time_cost' => 4, 'threads' => 3];
        $newArgon2 = new Argon2($newOptions);
        
        // This should work due to normalizeOptions() method
        $this->assertTrue($newArgon2->verify($password, $oldHash));
    }

    public function testCrossFormatCompatibility(): void
    {
        $password = 'testpassword123';
        
        // Create hash with snake_case format
        $snakeArgon2 = new Argon2(['memory_cost' => 65536, 'time_cost' => 4, 'threads' => 3]);
        $snakeHash = $snakeArgon2->hash($password);
        
        // Create hash with camelCase format
        $camelArgon2 = new Argon2(['memoryCost' => 65536, 'timeCost' => 4, 'threads' => 3]);
        $camelHash = $camelArgon2->hash($password);
        
        // Both should be verifiable by either format
        $this->assertTrue($snakeArgon2->verify($password, $camelHash));
        $this->assertTrue($camelArgon2->verify($password, $snakeHash));
    }

    public function testNormalizeOptionsWithMissingKeys(): void
    {
        // Test with partially missing options
        $options = ['memoryCost' => 65536]; // Missing timeCost and threads
        $argon2 = new Argon2($options);
        
        $password = 'testpassword123';
        $hash = $argon2->hash($password);
        
        $this->assertIsString($hash);
        $this->assertStringStartsWith('$argon2id$', $hash);
        $this->assertTrue($argon2->verify($password, $hash));
    }

    public function testNormalizeOptionsWithEmptyArray(): void
    {
        // Test with empty options array
        $options = [];
        $argon2 = new Argon2($options);
        
        $password = 'testpassword123';
        $hash = $argon2->hash($password);
        
        $this->assertIsString($hash);
        $this->assertStringStartsWith('$argon2id$', $hash);
        $this->assertTrue($argon2->verify($password, $hash));
    }

    public function testPasswordVerificationFailure(): void
    {
        $password = 'testpassword123';
        $wrongPassword = 'wrongpassword';
        $hash = $this->argon2->hash($password);
        
        $this->assertFalse($this->argon2->verify($wrongPassword, $hash));
    }

    public function testHashWithDifferentMemoryCosts(): void
    {
        $password = 'testpassword123';
        
        // Test with different memory costs
        $lowMemory = new Argon2(['memory_cost' => 1024, 'time_cost' => 2, 'threads' => 1]);
        $highMemory = new Argon2(['memory_cost' => 131072, 'time_cost' => 8, 'threads' => 4]);
        
        $lowHash = $lowMemory->hash($password);
        $highHash = $highMemory->hash($password);
        
        $this->assertTrue($lowMemory->verify($password, $lowHash));
        $this->assertTrue($highMemory->verify($password, $highHash));
        
        // Cross-verification should work due to normalizeOptions
        $this->assertTrue($lowMemory->verify($password, $highHash));
        $this->assertTrue($highMemory->verify($password, $lowHash));
    }
}
