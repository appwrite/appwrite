<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\Modules\Account;

use Appwrite\Platform\Modules\Account\Base;
use Appwrite\Platform\Modules\Account\Http\Account\Sessions\OAuth2\Callback\Create as CallbackCreate;
use Appwrite\Platform\Modules\Account\Http\Account\Sessions\OAuth2\Callback\Get as CallbackGet;
use Appwrite\Utopia\Request;
use Appwrite\Utopia\Response;
use PHPUnit\Framework\TestCase;
use Utopia\Config\Config;

require_once \dirname(__DIR__, 5) . '/app/init/constants.php';

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

    public function testOAuth2CallbackForwardersEmitValidRedirectUrls(): void
    {
        $redirected = [];
        $response = $this->createStub(Response::class);
        $response->method('addHeader')->willReturnSelf();
        $response->method('redirect')->willReturnCallback(function (string $url) use (&$redirected): void {
            $redirected[] = $url;
        });

        $hasOAuthProviders = !empty(Config::getParam('oAuthProviders'));
        $callbackGet = $hasOAuthProviders
            ? new CallbackGet()
            : (new \ReflectionClass(CallbackGet::class))->newInstanceWithoutConstructor();
        $callbackCreate = $hasOAuthProviders
            ? new CallbackCreate()
            : (new \ReflectionClass(CallbackCreate::class))->newInstanceWithoutConstructor();

        $proxyRequest = $this->stubRequest('https', 'domain.com', '443', ['code' => 'abc', 'state' => 'xyz']);
        $callbackGet->action('proj1', 'discord', 'abc', 'xyz', '', '', $proxyRequest, $response);

        $noPortRequest = $this->stubRequest('http', 'appwrite', '', ['code' => 'abc', 'state' => 'xyz']);
        $callbackCreate->action('proj1', 'mock', 'abc', 'xyz', '', '', $noPortRequest, $response);

        $this->assertSame([
            'https://domain.com/v1/account/sessions/oauth2/discord/redirect?code=abc&state=xyz&project=proj1',
            'http://appwrite/v1/account/sessions/oauth2/mock/redirect?code=abc&state=xyz&project=proj1',
        ], $redirected);
    }

    /**
     * @param array<string, mixed> $params
     */
    private function stubRequest(string $protocol, string $hostname, string $port, array $params = []): Request
    {
        $request = $this->createStub(Request::class);
        $request->method('getProtocol')->willReturn($protocol);
        $request->method('getHostname')->willReturn($hostname);
        $request->method('getPort')->willReturn($port);
        $request->method('getParams')->willReturn($params);

        return $request;
    }
}
