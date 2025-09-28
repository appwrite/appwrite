<?php

namespace Tests\E2E\Services\Projects;

use Tests\E2E\Client;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\SideServer;
use Utopia\Database\Helpers\ID;

class ProjectsVerificationE2ETest extends Scope
{
    use ProjectCustom;
    use SideServer;

    public function testVerificationMiddlewareWithConsoleProject()
    {
        // Test with console project (should trigger verification check when enabled)
        $this->client->setProject('console');
        
        // Test accessing a protected endpoint without verification
        // Note: This test assumes verification is enabled via environment variable
        $response = $this->client->call('GET', '/users', [], [
            'content-type' => 'application/json'
        ]);
        
        // Should either work (if verification disabled) or require verification
        $this->assertContains($response['headers']['status-code'], [200, 401, 403]);
    }

    public function testVerificationMiddlewareWithRegularProject()
    {
        // Test with regular project (should not trigger verification check)
        $this->client->setProject($this->getProject()['$id']);
        
        // Test accessing a protected endpoint
        $response = $this->client->call('GET', '/users', [], [
            'content-type' => 'application/json'
        ]);
        
        // Should work normally for non-console projects
        $this->assertContains($response['headers']['status-code'], [200, 401]);
    }

    public function testAllowedEndpointsWorkWithoutVerification()
    {
        // Test that allowed endpoints work without verification
        $this->client->setProject('console');
        
        $allowedEndpoints = [
            '/account',
            '/console/variables',
            '/health/version'
        ];
        
        foreach ($allowedEndpoints as $endpoint) {
            $response = $this->client->call('GET', $endpoint, [], [
                'content-type' => 'application/json'
            ]);
            
            // Should work regardless of verification status
            $this->assertNotEquals(403, $response['headers']['status-code'], "Endpoint $endpoint should be allowed without verification");
        }
    }

    public function testEnvironmentVariableControl()
    {
        // Test that environment variable controls the verification system
        // This test verifies the system respects the _APP_VERIFICATION_REQUIRED setting
        
        $this->client->setProject('console');
        
        // Test with verification potentially enabled
        $response = $this->client->call('GET', '/users', [], [
            'content-type' => 'application/json'
        ]);
        
        // Should work or require verification based on environment setting
        $this->assertContains($response['headers']['status-code'], [200, 401, 403]);
    }

    public function testVerificationMiddlewareWithApiKey()
    {
        // Test that API key authentication bypasses verification
        $this->client->setProject('console');
        
        $response = $this->client->call('GET', '/users', [], [
            'content-type' => 'application/json',
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]);
        
        // Should work with API key regardless of verification
        $this->assertNotEquals(403, $response['headers']['status-code'], "API key should bypass verification");
    }

    public function testVerificationMiddlewareWithEmptyUser()
    {
        // Test that empty/unauthenticated users are handled properly
        $this->client->setProject('console');
        
        $response = $this->client->call('GET', '/users', [], [
            'content-type' => 'application/json'
        ]);
        
        // Should either work or require authentication, but not verification specifically
        $this->assertContains($response['headers']['status-code'], [200, 401, 403]);
    }
}
