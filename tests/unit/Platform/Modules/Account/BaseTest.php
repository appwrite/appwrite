<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\Modules\Account;

use Appwrite\Platform\Modules\Account\Base;
use Appwrite\Utopia\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Utopia\Config\Config;
use Utopia\Database\Document;

/**
 * A request that came to a given host and port.
 */
final class FakeRequest extends Request
{
    public function __construct(private readonly string $hostname, private readonly string $port)
    {
    }

    #[\Override]
    public function getHostname(): string
    {
        return $this->hostname;
    }

    #[\Override]
    public function getPort(): string
    {
        return $this->port;
    }
}

/**
 * The stock OAuth2 flow, with what a test reads from it.
 */
class StockFlow extends Base
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
 * A flow on a route of its own, with a provider the config does not list.
 */
final class OwnRouteFlow extends StockFlow
{
    #[\Override]
    protected function getOAuth2CallbackPath(string $provider, Document $project): string
    {
        return '/v1/own/callback';
    }

    #[\Override]
    protected function getOAuth2Provider(string $provider): array
    {
        return $provider === 'own' ? ['name' => 'Own', 'class' => 'Acme\\OwnProvider'] : parent::getOAuth2Provider($provider);
    }
}

/**
 * The start and the end of an OAuth2 flow read the callback URL and the provider
 * from here, so a flow on a route of its own changes the path and the provider in
 * one place and both ends still agree.
 */
final class BaseTest extends TestCase
{
    private string|false $forceHttps = false;

    /** @var array<string, mixed>|null */
    private ?array $providers = null;

    protected function setUp(): void
    {
        $this->forceHttps = \getenv('_APP_OPTIONS_FORCE_HTTPS');
        $this->providers = Config::getParam('oAuthProviders', null);
    }

    protected function tearDown(): void
    {
        \putenv($this->forceHttps === false ? '_APP_OPTIONS_FORCE_HTTPS' : '_APP_OPTIONS_FORCE_HTTPS=' . $this->forceHttps);
        Config::setParam('oAuthProviders', $this->providers);
    }

    /**
     * @return array<string, array{string, string, string, string}>
     */
    public static function callbacks(): array
    {
        return [
            'https on its default port' => ['enabled', 'cloud.example.com', '443', 'https://cloud.example.com'],
            'https, the host names no port' => ['enabled', 'cloud.example.com', '', 'https://cloud.example.com'],
            'https on another port' => ['enabled', 'localhost', '8443', 'https://localhost:8443'],
            'http on its default port' => ['disabled', 'localhost', '80', 'http://localhost'],
            'http on another port' => ['disabled', 'localhost', '9501', 'http://localhost:9501'],
        ];
    }

    #[DataProvider('callbacks')]
    public function testOAuth2CallbackIsOnTheHostTheRequestCameTo(string $forceHttps, string $hostname, string $port, string $origin): void
    {
        \putenv('_APP_OPTIONS_FORCE_HTTPS=' . $forceHttps);
        $project = new Document(['$id' => 'shop']);
        $request = new FakeRequest($hostname, $port);

        $this->assertSame($origin . '/v1/account/sessions/oauth2/callback/github/shop', (new StockFlow())->callbackFor('github', $project, $request));
        $this->assertSame($origin . '/v1/own/callback', (new OwnRouteFlow())->callbackFor('own', $project, $request), 'A flow on a route of its own changes the path, not the host.');
    }

    public function testOAuth2ProviderIsWhatTheConfigListsForIt(): void
    {
        $github = ['name' => 'GitHub', 'enabled' => true, 'mock' => false, 'class' => 'Appwrite\\Auth\\OAuth2\\Github'];
        Config::setParam('oAuthProviders', ['github' => $github]);

        $this->assertSame($github, (new StockFlow())->providerFor('github'));
        $this->assertSame([], (new StockFlow())->providerFor('own'), 'The config does not list it.');
    }

    public function testOAuth2ProviderOfAFlowCanBeOneTheConfigDoesNotList(): void
    {
        $github = ['name' => 'GitHub', 'enabled' => true, 'mock' => false, 'class' => 'Appwrite\\Auth\\OAuth2\\Github'];
        Config::setParam('oAuthProviders', ['github' => $github]);

        $this->assertSame(['name' => 'Own', 'class' => 'Acme\\OwnProvider'], (new OwnRouteFlow())->providerFor('own'));
        $this->assertSame($github, (new OwnRouteFlow())->providerFor('github'));
    }
}
