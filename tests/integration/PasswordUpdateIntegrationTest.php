<?php

namespace Tests\Integration;

use Appwrite\Auth\Auth;
use Appwrite\Auth\Hash\Argon2;
use Appwrite\Utopia\Response\Filters\V17;
use Appwrite\Utopia\Response\Model\AlgoArgon2;
use PHPUnit\Framework\TestCase;

class PasswordUpdateIntegrationTest extends TestCase
{
    public function testCompletePasswordUpdateFlow(): void
    {
        // This test simulates the complete password update flow
        // from the original issue to the fix

        $oldPassword = 'oldpassword123';
        $newPassword = 'newpassword456';

        // Step 1: Simulate existing user with old format (the problematic case)
        $oldUserOptions = ['type' => 'argon2', 'memoryCost' => 2048, 'timeCost' => 4, 'threads' => 3];
        $userPassword = Auth::passwordHash($oldPassword, Auth::DEFAULT_ALGO, $oldUserOptions);

        // Step 2: Simulate updatePassword API call
        // This is where the original error occurred
        $argon2 = new Argon2($oldUserOptions);
        $isOldPasswordValid = $argon2->verify($oldPassword, $userPassword);

        $this->assertTrue($isOldPasswordValid, 'Old password verification should work with fix');

        if ($isOldPasswordValid) {
            // Step 3: Hash new password with updated format
            $newHashedPassword = Auth::passwordHash($newPassword, Auth::DEFAULT_ALGO, Auth::DEFAULT_ALGO_OPTIONS);

            // Step 4: Verify new password works
            $newArgon2 = new Argon2(Auth::DEFAULT_ALGO_OPTIONS);
            $isNewPasswordValid = $newArgon2->verify($newPassword, $newHashedPassword);

            $this->assertTrue($isNewPasswordValid, 'New password should be valid');

            // Step 5: Simulate API response with hash options
            $userData = [
                '$id' => 'user123',
                'email' => 'test@example.com',
                'hash' => 'argon2',
                'hashOptions' => Auth::DEFAULT_ALGO_OPTIONS
            ];

            // Step 6: Test API response conversion
            $v17Filter = new V17();
            $apiResponse = $v17Filter->parse($userData, 'user');

            $this->assertArrayHasKey('hashOptions', $apiResponse);
            $this->assertEquals('argon2', $apiResponse['hashOptions']['type']);
            $this->assertEquals(65536, $apiResponse['hashOptions']['memoryCost']);
            $this->assertEquals(4, $apiResponse['hashOptions']['timeCost']);
            $this->assertEquals(3, $apiResponse['hashOptions']['threads']);
        }
    }

    public function testBackwardCompatibilityWithMultipleUsers(): void
    {
        // Test that the fix works with users created at different times
        // with different hash option formats

        $password = 'testpassword123';
        $users = [];

        // User 1: Created with very old format
        $users[] = [
            'id' => 'user1',
            'options' => ['type' => 'argon2', 'memoryCost' => 1024, 'timeCost' => 2, 'threads' => 1],
            'password' => Auth::passwordHash($password, Auth::DEFAULT_ALGO, ['type' => 'argon2', 'memoryCost' => 1024, 'timeCost' => 2, 'threads' => 1])
        ];

        // User 2: Created with old format (the problematic case)
        $users[] = [
            'id' => 'user2',
            'options' => ['type' => 'argon2', 'memoryCost' => 2048, 'timeCost' => 4, 'threads' => 3],
            'password' => Auth::passwordHash($password, Auth::DEFAULT_ALGO, ['type' => 'argon2', 'memoryCost' => 2048, 'timeCost' => 4, 'threads' => 3])
        ];

        // User 3: Created with new format
        $users[] = [
            'id' => 'user3',
            'options' => Auth::DEFAULT_ALGO_OPTIONS,
            'password' => Auth::passwordHash($password, Auth::DEFAULT_ALGO, Auth::DEFAULT_ALGO_OPTIONS)
        ];

        // Test that all users can verify their passwords
        foreach ($users as $user) {
            $argon2 = new Argon2($user['options']);
            $isValid = $argon2->verify($password, $user['password']);

            $this->assertTrue($isValid, "User {$user['id']} should be able to verify password");
        }

        // Test that all users can update their passwords
        $newPassword = 'newpassword456';
        foreach ($users as $user) {
            // Verify old password
            $oldArgon2 = new Argon2($user['options']);
            $isOldValid = $oldArgon2->verify($password, $user['password']);

            $this->assertTrue($isOldValid, "User {$user['id']} should be able to verify old password");

            if ($isOldValid) {
                // Hash new password with new format
                $newHashedPassword = Auth::passwordHash($newPassword, Auth::DEFAULT_ALGO, Auth::DEFAULT_ALGO_OPTIONS);

                // Verify new password
                $newArgon2 = new Argon2(Auth::DEFAULT_ALGO_OPTIONS);
                $isNewValid = $newArgon2->verify($newPassword, $newHashedPassword);

                $this->assertTrue($isNewValid, "User {$user['id']} should be able to verify new password");
            }
        }
    }

    public function testApiResponseConsistency(): void
    {
        // Test that API responses are consistent regardless of internal format

        $testCases = [
            'snake_case' => ['memory_cost' => 65536, 'time_cost' => 4, 'threads' => 3],
            'camelCase' => ['memoryCost' => 2048, 'timeCost' => 4, 'threads' => 3],
            'mixed' => ['memory_cost' => 32768, 'timeCost' => 6, 'threads' => 2],
            'old_format' => ['type' => 'argon2', 'memoryCost' => 16384, 'timeCost' => 3, 'threads' => 4]
        ];

        $v17Filter = new V17();

        foreach ($testCases as $format => $options) {
            $userData = [
                '$id' => 'user123',
                'email' => 'test@example.com',
                'hash' => 'argon2',
                'hashOptions' => $options
            ];

            $apiResponse = $v17Filter->parse($userData, 'user');

            // All responses should have the same structure
            $this->assertArrayHasKey('hashOptions', $apiResponse);
            $this->assertArrayHasKey('type', $apiResponse['hashOptions']);
            $this->assertArrayHasKey('memoryCost', $apiResponse['hashOptions']);
            $this->assertArrayHasKey('timeCost', $apiResponse['hashOptions']);
            $this->assertArrayHasKey('threads', $apiResponse['hashOptions']);

            // Type should always be 'argon2'
            $this->assertEquals('argon2', $apiResponse['hashOptions']['type']);

            // Values should be preserved (or defaulted)
            $this->assertIsInt($apiResponse['hashOptions']['memoryCost']);
            $this->assertIsInt($apiResponse['hashOptions']['timeCost']);
            $this->assertIsInt($apiResponse['hashOptions']['threads']);
        }
    }

    public function testAlgoArgon2ModelIntegration(): void
    {
        // Test the AlgoArgon2 model with various input formats

        $testCases = [
            'snake_case' => ['memory_cost' => 65536, 'time_cost' => 4, 'threads' => 3],
            'camelCase' => ['memoryCost' => 2048, 'timeCost' => 4, 'threads' => 3],
            'mixed' => ['memory_cost' => 32768, 'timeCost' => 6, 'threads' => 2],
            'old_format' => ['type' => 'argon2', 'memoryCost' => 16384, 'timeCost' => 3, 'threads' => 4]
        ];

        foreach ($testCases as $format => $options) {
            $apiOptions = AlgoArgon2::convertToApiFormat($options);

            // All conversions should have the same structure
            $this->assertArrayHasKey('type', $apiOptions);
            $this->assertArrayHasKey('memoryCost', $apiOptions);
            $this->assertArrayHasKey('timeCost', $apiOptions);
            $this->assertArrayHasKey('threads', $apiOptions);

            // Type should always be 'argon2'
            $this->assertEquals('argon2', $apiOptions['type']);

            // Values should be preserved (or defaulted)
            $this->assertIsInt($apiOptions['memoryCost']);
            $this->assertIsInt($apiOptions['timeCost']);
            $this->assertIsInt($apiOptions['threads']);
        }
    }

    public function testEdgeCasesAndErrorHandling(): void
    {
        // Test various edge cases that might occur in production

        $password = 'testpassword123';

        // Test with completely invalid options
        $invalidOptions = ['invalid_key' => 'invalid_value'];
        $argon2Invalid = new Argon2($invalidOptions);
        $hashInvalid = $argon2Invalid->hash($password);
        $this->assertTrue($argon2Invalid->verify($password, $hashInvalid));

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

        // Test API response with invalid data
        $v17Filter = new V17();
        $invalidUserData = [
            '$id' => 'user123',
            'email' => 'test@example.com',
            'hash' => 'argon2',
            'hashOptions' => 'invalid_string'
        ];

        $apiResponse = $v17Filter->parse($invalidUserData, 'user');
        $this->assertEquals('invalid_string', $apiResponse['hashOptions']);
    }

    public function testPerformanceWithLargeMemoryCosts(): void
    {
        // Test that the fix works with various memory cost values
        // without performance issues

        $password = 'testpassword123';
        $memoryCosts = [1024, 2048, 4096, 8192, 16384, 32768, 65536];

        foreach ($memoryCosts as $memoryCost) {
            $options = ['memory_cost' => $memoryCost, 'time_cost' => 2, 'threads' => 1];
            $argon2 = new Argon2($options);

            $startTime = microtime(true);
            $hash = $argon2->hash($password);
            $hashTime = microtime(true) - $startTime;

            $startTime = microtime(true);
            $isValid = $argon2->verify($password, $hash);
            $verifyTime = microtime(true) - $startTime;

            $this->assertTrue($isValid, "Password verification should work with memory cost: $memoryCost");
            $this->assertLessThan(5.0, $hashTime, "Hashing should complete within 5 seconds for memory cost: $memoryCost");
            $this->assertLessThan(1.0, $verifyTime, "Verification should complete within 1 second for memory cost: $memoryCost");
        }
    }
}
