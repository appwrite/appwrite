<?php

namespace Tests\Unit\Auth\OAuth2;

use Appwrite\Auth\OAuth2\Authentik;
use PHPUnit\Framework\TestCase;

class AuthentikTest extends TestCase
{
    private string $appId = 'app-id';
    private string $appSecret;
    private string $callback = 'https://example.com/callback';

    protected function setUp(): void
    {
        // Create a JSON string for appSecret that includes clientSecret and authentikDomain
        $this->appSecret = json_encode([
            'clientSecret' => 'client-secret',
            'authentikDomain' => 'authentik.example.com'
        ]);
    }

    /**
     * Test getTokens method includes the code parameter in HTTP request
     */
    public function testGetTokensIncludesCodeParameter(): void
    {
        // Create a mock of Authentik with the request method stubbed
        $authentikMock = $this->getMockBuilder(Authentik::class)
            ->setConstructorArgs([$this->appId, $this->appSecret, $this->callback])
            ->onlyMethods(['request'])
            ->getMock();

        // Set up expectations for the request method
        $authentikMock->expects($this->once())
            ->method('request')
            ->with(
                $this->equalTo('POST'),
                $this->equalTo('https://authentik.example.com/application/o/token/'),
                $this->anything(),
                // Validate that http_build_query includes 'code' => 'test-code'
                $this->callback(function ($queryString) {
                    parse_str($queryString, $params);
                    return isset($params['code']) && $params['code'] === 'test-code';
                })
            )
            ->willReturn(json_encode([
                'access_token' => 'access-token',
                'refresh_token' => 'refresh-token',
                'expires_in' => 3600
            ]));

        // Use reflection to call the protected getTokens method
        $reflection = new \ReflectionClass($authentikMock);
        $method = $reflection->getMethod('getTokens');
        $method->setAccessible(true);

        // Call the method with a test code
        $result = $method->invoke($authentikMock, 'test-code');

        // Assert the result contains expected token data
        $this->assertArrayHasKey('access_token', $result);
        $this->assertArrayHasKey('refresh_token', $result);
    }

    /**
     * Test refreshTokens method includes the refresh_token parameter in HTTP request
     */
    public function testRefreshTokensIncludesRefreshTokenParameter(): void
    {
        // Create a mock of Authentik with the request method stubbed
        $authentikMock = $this->getMockBuilder(Authentik::class)
            ->setConstructorArgs([$this->appId, $this->appSecret, $this->callback])
            ->onlyMethods(['request'])
            ->getMock();

        // Set up expectations for the request method
        $authentikMock->expects($this->once())
            ->method('request')
            ->with(
                $this->equalTo('POST'),
                $this->equalTo('https://authentik.example.com/application/o/token/'),
                $this->anything(),
                // Validate that http_build_query includes 'refresh_token' => 'test-refresh-token'
                $this->callback(function ($queryString) {
                    parse_str($queryString, $params);
                    return isset($params['refresh_token']) && $params['refresh_token'] === 'test-refresh-token';
                })
            )
            ->willReturn(json_encode([
                'access_token' => 'new-access-token',
                'refresh_token' => 'new-refresh-token',
                'expires_in' => 3600
            ]));

        // Call the refreshTokens method with a test refresh token
        $result = $authentikMock->refreshTokens('test-refresh-token');

        // Assert the result contains expected token data
        $this->assertArrayHasKey('access_token', $result);
        $this->assertArrayHasKey('refresh_token', $result);
        $this->assertEquals('new-access-token', $result['access_token']);
    }

    /**
     * Test refreshTokens method when the response doesn't include a refresh token
     */
    public function testRefreshTokensPreservesOriginalTokenWhenMissingInResponse(): void
    {
        // Create a mock of Authentik with the request method stubbed
        $authentikMock = $this->getMockBuilder(Authentik::class)
            ->setConstructorArgs([$this->appId, $this->appSecret, $this->callback])
            ->onlyMethods(['request'])
            ->getMock();

        // Set up expectations for the request method
        $authentikMock->expects($this->once())
            ->method('request')
            ->willReturn(json_encode([
                'access_token' => 'new-access-token',
                'expires_in' => 3600
                // No refresh_token in the response
            ]));

        // Call the refreshTokens method with a test refresh token
        $result = $authentikMock->refreshTokens('original-refresh-token');

        // Assert the result contains the original refresh token
        $this->assertArrayHasKey('refresh_token', $result);
        $this->assertEquals('original-refresh-token', $result['refresh_token']);
    }

    /**
     * Test getLoginURL method
     */
    public function testGetLoginURL(): void
    {
        $authentik = new Authentik($this->appId, $this->appSecret, $this->callback);
        $loginUrl = $authentik->getLoginURL();

        // Check that the URL contains the necessary parameters
        $this->assertStringContainsString('client_id=' . $this->appId, $loginUrl);
        $this->assertStringContainsString('redirect_uri=' . urlencode($this->callback), $loginUrl);
        $this->assertStringContainsString('response_type=code', $loginUrl);
        $this->assertStringContainsString('https://authentik.example.com/application/o/authorize/', $loginUrl);
    }
}
