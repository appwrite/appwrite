<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\Modules\Account;

use Appwrite\Auth\OAuth2;
use Appwrite\Platform\Modules\Account\Http\Account\Sessions\OAuth2\Get as CreateOAuth2Session;
use Appwrite\Utopia\Database\Documents\User;
use Appwrite\Utopia\Request;
use Appwrite\Utopia\Response;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Utopia\Client\Adapter\Curl\Client as CurlAdapter;
use Utopia\Client\Client;
use Utopia\Config\Config;
use Utopia\Database\Document;

/**
 * Records what the flow hands the provider, and sends people to a fixed login page.
 */
final class RecordingProvider extends OAuth2
{
    /** @var list<array{appId: string, callback: string}> */
    public static array $created = [];

    public function __construct(Client $client, string $appId, string $appSecret, string $callback, array $state = [], array $scopes = [])
    {
        parent::__construct($client, $appId, $appSecret, $callback, $state, $scopes);

        self::$created[] = ['appId' => $appId, 'callback' => $callback];
    }

    public function getName(): string
    {
        return 'recording';
    }

    public function getLoginURL(): string
    {
        return 'https://login.example.com/authorize';
    }

    protected function getTokens(string $code): array
    {
        return [];
    }

    public function refreshTokens(string $refreshToken): array
    {
        return [];
    }

    public function getUserID(string $accessToken): string
    {
        return '';
    }

    public function getUserEmail(string $accessToken): string
    {
        return '';
    }

    public function isEmailVerified(string $accessToken): bool
    {
        return false;
    }

    public function getUserName(string $accessToken): string
    {
        return '';
    }
}

/**
 * The stock flow, with what a test needs to read from it.
 */
class StockFlow extends CreateOAuth2Session
{
    public function callbackFor(string $provider, Document $project, Request $request): string
    {
        return $this->getOAuth2Callback($provider, $project, $request);
    }

    /**
     * @return array<string, mixed>
     */
    public function providerFor(string $provider): array
    {
        return $this->getOAuth2Provider($provider);
    }
}

/**
 * A flow of its own: a provider the config does not list, landing on its own route.
 */
final class OwnFlow extends StockFlow
{
    #[\Override]
    protected function getOAuth2CallbackPath(string $provider, Document $project): string
    {
        return '/v1/own/callback';
    }

    #[\Override]
    protected function getOAuth2Provider(string $provider): array
    {
        return $provider === 'own' ? ['name' => 'Own', 'class' => RecordingProvider::class] : parent::getOAuth2Provider($provider);
    }
}

/**
 * The start and the end of an OAuth2 flow read the callback URL and the provider's
 * class from the account base action, so a flow that has its own route, or a
 * provider that is not in the `oAuthProviders` config, changes them in one place.
 */
#[AllowMockObjectsWithoutExpectations]
final class OAuth2FlowTest extends TestCase
{
    /** @var array<string, string|false> */
    private array $env = [];

    /** @var array<string, mixed>|null */
    private ?array $providers = null;

    protected function setUp(): void
    {
        foreach (['_APP_OPTIONS_FORCE_HTTPS'] as $name) {
            $this->env[$name] = \getenv($name);
        }
        $this->providers = Config::getParam('oAuthProviders', null);
        RecordingProvider::$created = [];
    }

    protected function tearDown(): void
    {
        foreach ($this->env as $name => $value) {
            \putenv($value === false ? $name : "{$name}={$value}");
        }
        Config::setParam('oAuthProviders', $this->providers);
    }

    public function testTheCallbackNamesTheProviderAndTheProjectOnTheRequestsHost(): void
    {
        \putenv('_APP_OPTIONS_FORCE_HTTPS=enabled');
        $project = new Document(['$id' => 'shop']);

        $this->assertSame(
            'https://cloud.example.com/v1/account/sessions/oauth2/callback/github/shop',
            (new StockFlow())->callbackFor('github', $project, $this->request('cloud.example.com', '443')),
        );
        $this->assertSame(
            'https://cloud.example.com/v1/account/sessions/oauth2/callback/github/shop',
            (new StockFlow())->callbackFor('github', $project, $this->request('cloud.example.com', '')),
            'A host that names no port is on the default one.',
        );
        $this->assertSame(
            'https://localhost:8443/v1/account/sessions/oauth2/callback/github/shop',
            (new StockFlow())->callbackFor('github', $project, $this->request('localhost', '8443')),
        );

        \putenv('_APP_OPTIONS_FORCE_HTTPS=disabled');
        $this->assertSame(
            'http://localhost/v1/account/sessions/oauth2/callback/github/shop',
            (new StockFlow())->callbackFor('github', $project, $this->request('localhost', '80')),
        );
    }

    public function testAProviderIsWhatTheConfigListsForIt(): void
    {
        $github = ['name' => 'GitHub', 'enabled' => true, 'mock' => false, 'class' => 'Appwrite\\Auth\\OAuth2\\Github'];
        Config::setParam('oAuthProviders', ['github' => $github]);

        $this->assertSame($github, (new StockFlow())->providerFor('github'));
        $this->assertSame([], (new StockFlow())->providerFor('own'), 'The config does not list it.');
    }

    public function testAFlowOfItsOwnStartsWithItsProviderAndItsCallback(): void
    {
        \putenv('_APP_OPTIONS_FORCE_HTTPS=enabled');
        // The provider is not in the config: only the flow knows it.
        Config::setParam('oAuthProviders', []);

        $redirects = [];
        $response = $this->createStub(Response::class);
        $response->method('addCookie')->willReturnSelf();
        $response->method('addHeader')->willReturnSelf();
        $response->method('redirect')->willReturnCallback(static function (string $url) use (&$redirects): void {
            $redirects[] = $url;
        });

        (new OwnFlow())->action(
            'own',
            'https://cloud.example.com/done',
            'https://cloud.example.com/failed',
            [],
            '',
            $this->request('cloud.example.com', '443'),
            $response,
            new Document(['$id' => 'shop', 'oAuthProviders' => ['ownEnabled' => true, 'ownAppid' => 'client-id', 'ownSecret' => 'client-secret']]),
            ['consoleUrl' => 'https://cloud.example.com'],
            new Client(new CurlAdapter()),
            new User(),
        );

        $this->assertSame([['appId' => 'client-id', 'callback' => 'https://cloud.example.com/v1/own/callback']], RecordingProvider::$created);
        $this->assertSame(['https://login.example.com/authorize'], $redirects);
    }

    private function request(string $hostname, string $port): Request
    {
        $request = $this->createStub(Request::class);
        $request->method('getHostname')->willReturn($hostname);
        $request->method('getPort')->willReturn($port);
        $request->method('getCookie')->willReturn('');

        return $request;
    }
}
