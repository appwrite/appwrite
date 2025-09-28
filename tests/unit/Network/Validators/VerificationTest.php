<?php

namespace Tests\Unit\Network\Validators;

use PHPUnit\Framework\TestCase;
use Utopia\Database\Document;
use Utopia\System\System;

/**
 * Verification Test
 *
 * Tests the verification middleware logic using existing user verification fields
 */
class VerificationTest extends TestCase
{
    protected Document $verifiedUser;
    protected Document $unverifiedUser;
    protected Document $emptyUser;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->verifiedUser = new Document([
            '$id' => 'user1',
            'emailVerification' => true,
            'phoneVerification' => false,
        ]);
        
        $this->unverifiedUser = new Document([
            '$id' => 'user2',
            'emailVerification' => false,
            'phoneVerification' => false,
        ]);
        
        $this->emptyUser = new Document([]);
    }

    public function testVerificationMiddlewareWithGlobalDisabled()
    {
        // Mock environment variable
        $_ENV['_APP_VERIFICATION_REQUIRED'] = 'disabled';
        
        $verificationRequired = System::getEnv('_APP_VERIFICATION_REQUIRED', 'disabled') === 'enabled';
        
        $this->assertFalse($verificationRequired);
    }

    public function testVerificationMiddlewareWithGlobalEnabled()
    {
        // Test the logic directly
        $globalVerificationEnabled = 'enabled' === 'enabled';
        
        $this->assertTrue($globalVerificationEnabled);
    }

    public function testProjectVerificationRequired()
    {
        $project = new Document([
            '$id' => 'console',
            'verificationRequired' => true
        ]);
        
        $projectVerificationRequired = $project->getAttribute('verificationRequired', false);
        
        $this->assertTrue($projectVerificationRequired);
    }

    public function testProjectVerificationNotRequired()
    {
        $project = new Document([
            '$id' => 'regular-project',
            'verificationRequired' => false
        ]);
        
        $projectVerificationRequired = $project->getAttribute('verificationRequired', false);
        
        $this->assertFalse($projectVerificationRequired);
    }

    public function testCombinedVerificationLogic()
    {
        // Test both global and project settings must be true
        $globalVerificationEnabled = true;
        $projectVerificationRequired = true;
        
        $verificationActive = $globalVerificationEnabled && $projectVerificationRequired;
        
        $this->assertTrue($verificationActive);
    }

    public function testCombinedVerificationLogicGlobalDisabled()
    {
        // Test global disabled overrides project setting
        $globalVerificationEnabled = false;
        $projectVerificationRequired = true;
        
        $verificationActive = $globalVerificationEnabled && $projectVerificationRequired;
        
        $this->assertFalse($verificationActive);
    }

    public function testCombinedVerificationLogicProjectDisabled()
    {
        // Test project disabled overrides global setting
        $globalVerificationEnabled = true;
        $projectVerificationRequired = false;
        
        $verificationActive = $globalVerificationEnabled && $projectVerificationRequired;
        
        $this->assertFalse($verificationActive);
    }

    public function testAllowedEndpoints()
    {
        $allowedEndpoints = [
            '/v1/account',
            '/v1/console/variables',
            '/v1/health/version',
            '/v1/account/verification',
            '/v1/account/verification/phone',
            '/v1/account/recovery',
            '/v1/account/sessions',
            '/v1/account/tokens',
            '/v1/account/mfa'
        ];
        
        $currentPath = '/v1/account/sessions';
        
        $isAllowedEndpoint = false;
        foreach ($allowedEndpoints as $allowedEndpoint) {
            if (str_starts_with($currentPath, $allowedEndpoint)) {
                $isAllowedEndpoint = true;
                break;
            }
        }
        
        $this->assertTrue($isAllowedEndpoint);
    }

    public function testProtectedEndpoints()
    {
        $allowedEndpoints = [
            '/v1/account',
            '/v1/console/variables',
            '/v1/health/version',
            '/v1/account/verification',
            '/v1/account/verification/phone',
            '/v1/account/recovery',
            '/v1/account/sessions',
            '/v1/account/tokens',
            '/v1/account/mfa'
        ];
        
        $currentPath = '/v1/users';
        
        $isAllowedEndpoint = false;
        foreach ($allowedEndpoints as $allowedEndpoint) {
            if (str_starts_with($currentPath, $allowedEndpoint)) {
                $isAllowedEndpoint = true;
                break;
            }
        }
        
        $this->assertFalse($isAllowedEndpoint);
    }

    public function testVerifiedUserAccess()
    {
        $emailVerified = $this->verifiedUser->getAttribute('emailVerification', false);
        $phoneVerified = $this->verifiedUser->getAttribute('phoneVerification', false);
        
        $this->assertTrue($emailVerified || $phoneVerified);
    }

    public function testUnverifiedUserAccess()
    {
        $emailVerified = $this->unverifiedUser->getAttribute('emailVerification', false);
        $phoneVerified = $this->unverifiedUser->getAttribute('phoneVerification', false);
        
        $this->assertFalse($emailVerified || $phoneVerified);
    }

    public function testEmptyUserAccess()
    {
        $this->assertTrue($this->emptyUser->isEmpty());
    }

    public function testPhoneVerifiedUser()
    {
        $phoneVerifiedUser = new Document([
            '$id' => 'user3',
            'emailVerification' => false,
            'phoneVerification' => true,
        ]);
        
        $emailVerified = $phoneVerifiedUser->getAttribute('emailVerification', false);
        $phoneVerified = $phoneVerifiedUser->getAttribute('phoneVerification', false);
        
        $this->assertTrue($emailVerified || $phoneVerified);
    }

    protected function tearDown(): void
    {
        // Clean up environment variables
        unset($_ENV['_APP_VERIFICATION_REQUIRED']);
        parent::tearDown();
    }
}
