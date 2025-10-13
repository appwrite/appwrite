<?php

namespace Tests\Unit\Auth;

use Appwrite\Auth\Auth;
use Appwrite\Auth\Hash\Argon2;
use PHPUnit\Framework\TestCase;

class PasswordUpdateFixTest extends TestCase
{
    public function testDefaultAlgoOptionsFormat(): void
    {
        $expected = ['memory_cost' => 65536, 'time_cost' => 4, 'threads' => 3];
        $this->assertEquals($expected, Auth::DEFAULT_ALGO_OPTIONS);
    }

    public function testPasswordHashWithNewDefaultOptions(): void
    {
        $password = 'testpassword123';
        $hash = Auth::passwordHash($password, Auth::DEFAULT_ALGO, Auth::DEFAULT_ALGO_OPTIONS);
        
        $this->assertIsString($hash);
        $this->assertStringStartsWith('$argon2id$', $hash);
        
        // Verify the password
        $isValid = Auth::passwordVerify($password, $hash, Auth::DEFAULT_ALGO, Auth::DEFAULT_ALGO_OPTIONS);
        $this->assertTrue($isValid);
    }

    public function testPasswordVerificationWithOldFormat(): void
    {
        $password = 'testpassword123';
        
        // Simulate old format options (the problematic case)
        $oldFormatOptions = ['type' => 'argon2', 'memoryCost' => 2048, 'timeCost' => 4, 'threads' => 3];
        
        // Create hash with old format
        $oldHash = Auth::passwordHash($password, Auth::DEFAULT_ALGO, $oldFormatOptions);
        
        // Verify with Argon2 class (which uses normalizeOptions)
        $argon2 = new Argon2($oldFormatOptions);
        $isValid = $argon2->verify($password, $oldHash);
        
        $this->assertTrue($isValid, 'Password verification should work with old format due to normalizeOptions');
    }

    public function testPasswordVerificationWithNewFormat(): void
    {
        $password = 'testpassword123';
        
        // Use new format options
        $newFormatOptions = ['memory_cost' => 65536, 'time_cost' => 4, 'threads' => 3];
        
        // Create hash with new format
        $newHash = Auth::passwordHash($password, Auth::DEFAULT_ALGO, $newFormatOptions);
        
        // Verify with Argon2 class
        $argon2 = new Argon2($newFormatOptions);
        $isValid = $argon2->verify($password, $newHash);
        
        $this->assertTrue($isValid, 'Password verification should work with new format');
    }

    public function testCrossFormatPasswordVerification(): void
    {
        $password = 'testpassword123';
        
        // Create hash with old format
        $oldFormatOptions = ['type' => 'argon2', 'memoryCost' => 2048, 'timeCost' => 4, 'threads' => 3];
        $oldHash = Auth::passwordHash($password, Auth::DEFAULT_ALGO, $oldFormatOptions);
        
        // Verify with new format Argon2
        $newFormatOptions = ['memory_cost' => 65536, 'time_cost' => 4, 'threads' => 3];
        $newArgon2 = new Argon2($newFormatOptions);
        $isValid = $newArgon2->verify($password, $oldHash);
        
        $this->assertTrue($isValid, 'Cross-format verification should work due to normalizeOptions');
    }

    public function testPasswordUpdateScenario(): void
    {
        $oldPassword = 'oldpassword123';
        $newPassword = 'newpassword456';
        
        // Simulate existing user with old format (the problematic case)
        $oldFormatOptions = ['type' => 'argon2', 'memoryCost' => 2048, 'timeCost' => 4, 'threads' => 3];
        $userPassword = Auth::passwordHash($oldPassword, Auth::DEFAULT_ALGO, $oldFormatOptions);
        
        // Simulate updatePassword endpoint logic
        // 1. Verify old password (this was failing before the fix)
        $argon2 = new Argon2($oldFormatOptions);
        $isOldPasswordValid = $argon2->verify($oldPassword, $userPassword);
        
        $this->assertTrue($isOldPasswordValid, 'Old password verification should work');
        
        if ($isOldPasswordValid) {
            // 2. Hash new password with new format
            $newHashedPassword = Auth::passwordHash($newPassword, Auth::DEFAULT_ALGO, Auth::DEFAULT_ALGO_OPTIONS);
            
            // 3. Verify new password works
            $newArgon2 = new Argon2(Auth::DEFAULT_ALGO_OPTIONS);
            $isNewPasswordValid = $newArgon2->verify($newPassword, $newHashedPassword);
            
            $this->assertTrue($isNewPasswordValid, 'New password should be valid');
        }
    }

    public function testBackwardCompatibilityWithDifferentMemoryCosts(): void
    {
        $password = 'testpassword123';
        
        // Test various memory cost values that might exist in old systems
        $memoryCosts = [1024, 2048, 4096, 8192, 16384, 32768, 65536];
        
        foreach ($memoryCosts as $memoryCost) {
            $oldOptions = ['type' => 'argon2', 'memoryCost' => $memoryCost, 'timeCost' => 4, 'threads' => 3];
            $argon2 = new Argon2($oldOptions);
            
            $hash = $argon2->hash($password);
            $isValid = $argon2->verify($password, $hash);
            
            $this->assertTrue($isValid, "Password verification should work with memory cost: $memoryCost");
        }
    }

    public function testApiFormatCompatibility(): void
    {
        $password = 'testpassword123';
        
        // Test that both API format and internal format work
        $apiFormatOptions = ['memoryCost' => 65536, 'timeCost' => 4, 'threads' => 3];
        $internalFormatOptions = ['memory_cost' => 65536, 'time_cost' => 4, 'threads' => 3];
        
        $apiArgon2 = new Argon2($apiFormatOptions);
        $internalArgon2 = new Argon2($internalFormatOptions);
        
        $apiHash = $apiArgon2->hash($password);
        $internalHash = $internalArgon2->hash($password);
        
        // Both should be verifiable by either format
        $this->assertTrue($apiArgon2->verify($password, $internalHash));
        $this->assertTrue($internalArgon2->verify($password, $apiHash));
    }

    public function testEdgeCases(): void
    {
        $password = 'testpassword123';
        
        // Test with empty options
        $emptyOptions = [];
        $argon2Empty = new Argon2($emptyOptions);
        $hashEmpty = $argon2Empty->hash($password);
        $this->assertTrue($argon2Empty->verify($password, $hashEmpty));
        
        // Test with null values
        $nullOptions = ['memoryCost' => null, 'timeCost' => null, 'threads' => null];
        $argon2Null = new Argon2($nullOptions);
        $hashNull = $argon2Null->hash($password);
        $this->assertTrue($argon2Null->verify($password, $hashNull));
        
        // Test with mixed null and valid values
        $mixedOptions = ['memoryCost' => 65536, 'timeCost' => null, 'threads' => 3];
        $argon2Mixed = new Argon2($mixedOptions);
        $hashMixed = $argon2Mixed->hash($password);
        $this->assertTrue($argon2Mixed->verify($password, $hashMixed));
    }

    public function testOriginalIssueScenario(): void
    {
        // This test specifically reproduces the original issue and verifies the fix
        
        $password = 'mypassword123';
        
        // Simulate user created with old Appwrite version
        $oldUserOptions = ['type' => 'argon2', 'memoryCost' => 2048, 'timeCost' => 4, 'threads' => 3];
        $userPassword = Auth::passwordHash($password, Auth::DEFAULT_ALGO, $oldUserOptions);
        
        // Simulate updatePassword call with correct oldPassword
        $oldPassword = $password; // User provides correct old password
        
        // This is what was failing before the fix
        $argon2 = new Argon2($oldUserOptions);
        $isValid = $argon2->verify($oldPassword, $userPassword);
        
        $this->assertTrue($isValid, 'Original issue should be fixed - password verification should work');
        
        // If verification passes, password update should succeed
        if ($isValid) {
            $newPassword = 'newpassword456';
            $newHashedPassword = Auth::passwordHash($newPassword, Auth::DEFAULT_ALGO, Auth::DEFAULT_ALGO_OPTIONS);
            
            $newArgon2 = new Argon2(Auth::DEFAULT_ALGO_OPTIONS);
            $isNewValid = $newArgon2->verify($newPassword, $newHashedPassword);
            
            $this->assertTrue($isNewValid, 'New password should be valid after update');
        }
    }
}
