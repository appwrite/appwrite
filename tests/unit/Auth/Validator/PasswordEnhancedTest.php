<?php

namespace Tests\Unit\Auth\Validator;

use Appwrite\Auth\Validator\Password;
use PHPUnit\Framework\TestCase;

/**
 * Enhanced Password Validator Test Suite
 *
 * Tests the enhanced Password validator with new security features,
 * strength checking, and configurable requirements.
 */
class PasswordEnhancedTest extends TestCase
{
    protected ?Password $object = null;

    public function setUp(): void
    {
        $this->object = new Password();
    }

    public function testBasicValidation(): void
    {
        // Test basic length validation
        $this->assertEquals($this->object->isValid(false), false);
        $this->assertEquals($this->object->isValid(null), false);
        $this->assertEquals($this->object->isValid(''), false);
        $this->assertEquals($this->object->isValid('1234567'), false); // Too short
        $this->assertEquals($this->object->isValid('WUnOZcn0piQMN8Mh31xw4KQPF0gcNGVA'), true);
    }

    public function testUppercaseRequirement(): void
    {
        $passwordValidator = new Password(
            false,  // allowEmpty
            true,   // requireUppercase
            false,  // requireLowercase
            false,  // requireNumbers
            false   // requireSpecialChars
        );

        $this->assertEquals($passwordValidator->isValid('password'), false); // No uppercase
        $this->assertEquals($passwordValidator->isValid('Password'), true);  // Has uppercase
        $this->assertEquals($passwordValidator->isValid('PASSWORD'), true);  // All uppercase
    }

    public function testLowercaseRequirement(): void
    {
        $passwordValidator = new Password(
            false,  // allowEmpty
            false,  // requireUppercase
            true,   // requireLowercase
            false,  // requireNumbers
            false   // requireSpecialChars
        );

        $this->assertEquals($passwordValidator->isValid('PASSWORD'), false); // No lowercase
        $this->assertEquals($passwordValidator->isValid('Password'), true);  // Has lowercase
        $this->assertEquals($passwordValidator->isValid('password'), true);  // All lowercase
    }

    public function testNumberRequirement(): void
    {
        $passwordValidator = new Password(
            false,  // allowEmpty
            false,  // requireUppercase
            false,  // requireLowercase
            true,   // requireNumbers
            false   // requireSpecialChars
        );

        $this->assertEquals($passwordValidator->isValid('Password'), false); // No numbers
        $this->assertEquals($passwordValidator->isValid('Password1'), true); // Has numbers
        $this->assertEquals($passwordValidator->isValid('12345678'), true);  // All numbers
    }

    public function testSpecialCharRequirement(): void
    {
        $passwordValidator = new Password(
            false,  // allowEmpty
            false,  // requireUppercase
            false,  // requireLowercase
            false,  // requireNumbers
            true    // requireSpecialChars
        );

        $this->assertEquals($passwordValidator->isValid('Password1'), false); // No special chars
        $this->assertEquals($passwordValidator->isValid('Password1!'), true); // Has special char
        $this->assertEquals($passwordValidator->isValid('!@#$%^&*'), true);  // All special chars
    }

    public function testCombinedRequirements(): void
    {
        $passwordValidator = new Password(
            false,  // allowEmpty
            true,   // requireUppercase
            true,   // requireLowercase
            true,   // requireNumbers
            true    // requireSpecialChars
        );

        // Test various combinations
        $this->assertEquals($passwordValidator->isValid('password'), false);     // Missing uppercase, numbers, special
        $this->assertEquals($passwordValidator->isValid('Password'), false);     // Missing numbers, special
        $this->assertEquals($passwordValidator->isValid('Password1'), false);   // Missing special
        $this->assertEquals($passwordValidator->isValid('Password!'), false);    // Missing numbers
        $this->assertEquals($passwordValidator->isValid('Password1!'), true);   // All requirements met
    }

    public function testCustomLengthRequirements(): void
    {
        $passwordValidator = new Password(
            false,  // allowEmpty
            false,  // requireUppercase
            false,  // requireLowercase
            false,  // requireNumbers
            false,  // requireSpecialChars
            12,     // minLength
            50      // maxLength
        );

        $this->assertEquals($passwordValidator->isValid('short'), false);     // Too short
        $this->assertEquals($passwordValidator->isValid('longenough'), true); // Meets min length
        
        // Test max length
        $longPassword = str_repeat('a', 51);
        $this->assertEquals($passwordValidator->isValid($longPassword), false); // Too long
    }

    public function testStrengthChecking(): void
    {
        $passwordValidator = new Password(
            false,  // allowEmpty
            false,  // requireUppercase
            false,  // requireLowercase
            false,  // requireNumbers
            false,  // requireSpecialChars
            8,      // minLength
            256,    // maxLength
            true    // checkStrength
        );

        // Test weak passwords
        $this->assertEquals($passwordValidator->isValid('aaaaaaaa'), false); // Low entropy
        $this->assertEquals($passwordValidator->isValid('12345678'), false); // Sequential
        $this->assertEquals($passwordValidator->isValid('password'), false);  // Common password
        $this->assertEquals($passwordValidator->isValid('abcabc'), false);   // Repeated pattern

        // Test strong passwords
        $this->assertEquals($passwordValidator->isValid('StrongP@ssw0rd!'), true);
        $this->assertEquals($passwordValidator->isValid('MySecurePass123!'), true);
    }

    public function testGetPasswordStrength(): void
    {
        // Test weak password
        $weakStrength = $this->object->getPasswordStrength('password');
        $this->assertLessThan(40, $weakStrength['score']);
        $this->assertEquals('Weak', $weakStrength['level']);

        // Test medium password
        $mediumStrength = $this->object->getPasswordStrength('Password1');
        $this->assertGreaterThanOrEqual(40, $mediumStrength['score']);
        $this->assertLessThan(60, $mediumStrength['score']);
        $this->assertEquals('Medium', $mediumStrength['level']);

        // Test strong password
        $strongStrength = $this->object->getPasswordStrength('StrongP@ssw0rd!');
        $this->assertGreaterThanOrEqual(60, $strongStrength['score']);
        $this->assertEquals('Strong', $strongStrength['level']);

        // Verify strength details
        $this->assertArrayHasKey('length', $strongStrength);
        $this->assertArrayHasKey('has_lowercase', $strongStrength);
        $this->assertArrayHasKey('has_uppercase', $strongStrength);
        $this->assertArrayHasKey('has_numbers', $strongStrength);
        $this->assertArrayHasKey('has_special', $strongStrength);
    }

    public function testGenerateSecurePassword(): void
    {
        $password = $this->object->generateSecurePassword(16, true);
        
        $this->assertEquals(16, strlen($password));
        $this->assertTrue($this->object->isValid($password));
        
        // Test without special characters
        $passwordNoSpecial = $this->object->generateSecurePassword(12, false);
        $this->assertEquals(12, strlen($passwordNoSpecial));
        $this->assertFalse(preg_match('/[^a-zA-Z0-9]/', $passwordNoSpecial));
    }

    public function testIsValidHashFormat(): void
    {
        // Test valid hash formats
        $this->assertTrue($this->object->isValidHashFormat(str_repeat('a', 64))); // SHA-256
        $this->assertTrue($this->object->isValidHashFormat(str_repeat('b', 40))); // SHA-1
        $this->assertTrue($this->object->isValidHashFormat(str_repeat('c', 32))); // MD5
        $this->assertTrue($this->object->isValidHashFormat('$2a$10$abcdefghijklmnopqrstuv')); // bcrypt

        // Test invalid hash formats
        $this->assertFalse($this->object->isValidHashFormat('short'));
        $this->assertFalse($this->object->isValidHashFormat(str_repeat('d', 50))); // Invalid length
        $this->assertFalse($this->object->isValidHashFormat('invalid@hash'));
    }

    public function testCheckPwnedPassword(): void
    {
        $result = $this->object->checkPwnedPassword('password');
        
        $this->assertArrayHasKey('isPwned', $result);
        $this->assertArrayHasKey('count', $result);
        $this->assertArrayHasKey('hashPrefix', $result);
        $this->assertArrayHasKey('hashSuffix', $result);
        
        $this->assertEquals(5, strlen($result['hashPrefix']));
        $this->assertEquals(35, strlen($result['hashSuffix']));
    }

    public function testGetDescription(): void
    {
        // Test basic description
        $basicValidator = new Password();
        $description = $basicValidator->getDescription();
        $this->assertStringContainsString('between 8 and 256 characters', $description);

        // Test with requirements
        $complexValidator = new Password(
            false,  // allowEmpty
            true,   // requireUppercase
            true,   // requireLowercase
            true,   // requireNumbers
            true    // requireSpecialChars
        );
        $complexDescription = $complexValidator->getDescription();
        $this->assertStringContainsString('at least one uppercase letter', $complexDescription);
        $this->assertStringContainsString('at least one lowercase letter', $complexDescription);
        $this->assertStringContainsString('at least one number', $complexDescription);
        $this->assertStringContainsString('at least one special character', $complexDescription);
    }

    public function testAllowEmpty(): void
    {
        $emptyValidator = new Password(true); // allowEmpty = true
        
        $this->assertEquals($emptyValidator->isValid(''), true);
        $this->assertEquals($emptyValidator->isValid('   '), false); // Not truly empty
        $this->assertEquals($emptyValidator->isValid('password'), false); // Still needs to meet requirements
    }

    public function testCommonPasswords(): void
    {
        // Test that common passwords are rejected
        $commonPasswords = [
            '123456', 'password', '123456789', 'qwerty', 'abc123',
            '111111', '123123', 'admin', 'letmein', 'welcome'
        ];

        foreach ($commonPasswords as $password) {
            $this->assertEquals($this->object->isValid($password), false, 
                "Common password '{$password}' should be rejected");
        }
    }

    public function testSequentialPatterns(): void
    {
        $sequentialPasswords = [
            '12345678', '87654321', 'qwerty', 'asdfghjkl',
            'zxcvbnm', '1234', '4321'
        ];

        foreach ($sequentialPasswords as $password) {
            // These should be rejected when strength checking is enabled
            $strongValidator = new Password(false, false, false, false, false, 8, 256, true);
            $this->assertEquals($strongValidator->isValid($password), false,
                "Sequential password '{$password}' should be rejected");
        }
    }

    public function testRepeatedPatterns(): void
    {
        $repeatedPasswords = [
            'aaaaaa', '111111', 'abcabc', '123123', 'passwordpassword'
        ];

        foreach ($repeatedPasswords as $password) {
            // These should be rejected when strength checking is enabled
            $strongValidator = new Password(false, false, false, false, false, 8, 256, true);
            $this->assertEquals($strongValidator->isValid($password), false,
                "Repeated pattern password '{$password}' should be rejected");
        }
    }
}
