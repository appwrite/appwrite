<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\Modules\Account;

use Appwrite\Platform\Modules\Account\Base;
use Appwrite\Utopia\Request;
use PHPUnit\Framework\TestCase;

final class OAuth2UrlResolutionTest extends TestCase
{
    public function testResolveRequestProtocolHonoursProxyAndForceHttps(): void
    {
        $httpsProxy = $this->stubRequest('https', 'app.example.com', '443');
        $httpDirect = $this->stubRequest('http', 'localhost', '8080');
        $wssRequest = $this->stubRequest('wss', 'app.example.com', '443');
        $wsRequest = $this->stubRequest('ws', 'app.example.com', '80');
        $emptyRequest = $this->stubRequest('', 'app.example.com', '');

        $this->assertSame('https', Base::resolveRequestProtocol($httpsProxy, 'disabled'));
        $this->assertSame('http', Base::resolveRequestProtocol($httpDirect, 'disabled'));
        $this->assertSame('https', Base::resolveRequestProtocol($wssRequest, 'disabled'));
        $this->assertSame('http', Base::resolveRequestProtocol($wsRequest, 'disabled'));
        $this->assertSame('http', Base::resolveRequestProtocol($emptyRequest, 'disabled'));
        $this->assertSame('https', Base::resolveRequestProtocol($httpDirect, 'enabled'));
        $this->assertSame('https', Base::resolveRequestProtocol($httpsProxy, 'enabled'));
        $this->assertSame('https', Base::resolveRequestProtocol($this->stubRequest('HTTPS', 'app.example.com', '443'), 'disabled'));
    }

    public function testResolveRequestOriginOmitsEmptyAndDefaultPorts(): void
    {
        // Reverse proxy terminating TLS with _APP_OPTIONS_FORCE_HTTPS=disabled (issue #10704)
        $this->assertSame(
            'https://domain.com',
            Base::resolveRequestOrigin($this->stubRequest('https', 'domain.com', '443'), 'disabled'),
        );
        $this->assertSame(
            'https://domain.com',
            Base::resolveRequestOrigin($this->stubRequest('https', 'domain.com', ''), 'disabled'),
        );

        // Plain HTTP with empty or default port (no trailing colon)
        $this->assertSame(
            'http://appwrite',
            Base::resolveRequestOrigin($this->stubRequest('http', 'appwrite', ''), 'disabled'),
        );
        $this->assertSame(
            'http://domain.com',
            Base::resolveRequestOrigin($this->stubRequest('http', 'domain.com', '80'), 'disabled'),
        );

        // Forced HTTPS ignores empty/443 ports and preserves custom ports
        $this->assertSame(
            'https://domain.com',
            Base::resolveRequestOrigin($this->stubRequest('http', 'domain.com', ''), 'enabled'),
        );
        $this->assertSame(
            'https://domain.com',
            Base::resolveRequestOrigin($this->stubRequest('http', 'domain.com', '443'), 'enabled'),
        );
        $this->assertSame(
            'https://domain.com:8443',
            Base::resolveRequestOrigin($this->stubRequest('https', 'domain.com', '8443'), 'disabled'),
        );
        $this->assertSame(
            'http://localhost:8080',
            Base::resolveRequestOrigin($this->stubRequest('http', 'localhost', '8080'), 'disabled'),
        );
        $this->assertSame(
            'https://domain.com',
            Base::resolveRequestOrigin($this->stubRequest('https', 'domain.com', 'not-a-port'), 'disabled'),
        );
    }

    public function testResolveConsoleRedirectBaseHandlesDerivedAndExplicitUrls(): void
    {
        // Derived consoleUrl generated with FORCE_HTTPS=disabled upgrades to https behind TLS proxy
        $this->assertSame(
            'https://console.example.com',
            Base::resolveConsoleRedirectBase(
                $this->stubRequest('https', 'api.example.com', '443'),
                ['consoleUrl' => 'http://console.example.com'],
                'disabled',
                '',
            ),
        );

        // Empty port must never produce a trailing colon on derived consoleUrl
        $this->assertSame(
            'http://appwrite',
            Base::resolveConsoleRedirectBase(
                $this->stubRequest('http', 'appwrite', ''),
                ['consoleUrl' => 'http://appwrite'],
                'disabled',
                '',
            ),
        );

        // Custom request port is appended once on derived consoleUrl without its own port
        $this->assertSame(
            'http://localhost:8080',
            Base::resolveConsoleRedirectBase(
                $this->stubRequest('http', 'localhost', '8080'),
                ['consoleUrl' => 'http://localhost'],
                'disabled',
                '',
            ),
        );

        // Existing port inside custom _APP_CONSOLE_DOMAIN is preserved without doubling
        $this->assertSame(
            'http://localhost:3000',
            Base::resolveConsoleRedirectBase(
                $this->stubRequest('http', 'localhost', '8080'),
                ['consoleUrl' => 'http://localhost:3000'],
                'disabled',
                '',
            ),
        );

        // Explicit _APP_CONSOLE_URL is returned verbatim
        $this->assertSame(
            'https://console.example.com:9443',
            Base::resolveConsoleRedirectBase(
                $this->stubRequest('http', 'api.internal', '8080'),
                ['consoleUrl' => 'https://console.example.com:9443'],
                'disabled',
                'https://console.example.com:9443',
            ),
        );

        // Empty consoleUrl falls back to request origin
        $this->assertSame(
            'https://api.example.com',
            Base::resolveConsoleRedirectBase(
                $this->stubRequest('https', 'api.example.com', ''),
                [],
                'disabled',
                '',
            ),
        );
    }

    private function stubRequest(string $protocol, string $hostname, string $port): Request
    {
        $request = $this->createStub(Request::class);
        $request->method('getProtocol')->willReturn($protocol);
        $request->method('getHostname')->willReturn($hostname);
        $request->method('getPort')->willReturn($port);

        return $request;
    }
}
