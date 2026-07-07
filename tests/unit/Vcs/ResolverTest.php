<?php

declare(strict_types=1);

namespace Tests\Unit\Vcs;

use Appwrite\Auth\OAuth2\Github as GithubOAuth2;
use Appwrite\Extend\Exception;
use Appwrite\Vcs\Provider;
use Appwrite\Vcs\Resolver;
use PHPUnit\Framework\TestCase;
use Utopia\Cache\Adapter\None;
use Utopia\Cache\Cache;
use Utopia\Database\Document;
use Utopia\VCS\Adapter\Git\GitHub;

final class ResolverTest extends TestCase
{
    protected function tearDown(): void
    {
        \putenv('_APP_VCS_TEST_TOKEN');
    }

    public function testDisabledProvidersAreNotRegistered(): void
    {
        $resolver = new Resolver($this->cache(), [
            'github' => $this->githubConfig(),
            'legacy' => ['enabled' => false] + $this->githubConfig(),
        ]);

        $this->assertInstanceOf(Provider::class, $resolver->getProvider('github'));

        $this->expectException(Exception::class);
        $resolver->getProvider('legacy');
    }

    public function testUnknownProviderThrows(): void
    {
        $resolver = new Resolver($this->cache(), ['github' => $this->githubConfig()]);

        $this->expectException(Exception::class);
        $resolver->getProvider('bitbucket');
    }

    public function testGetProvidersFiltersUnconfigured(): void
    {
        $test = [
            'name' => 'Test',
            'enabled' => true,
            'adapter' => GitHub::class,
            'oauth2' => GithubOAuth2::class,
            'auth' => Provider::AUTH_OAUTH2,
            'envPrefix' => '_APP_VCS_TEST',
            'required' => ['TOKEN'],
            'endpoint' => false,
            'browserEndpoint' => 'https://example.com',
            'urls' => [],
            'headers' => ['event' => 'x-test-event', 'signature' => 'x-test-signature'],
            'scopes' => [],
            'repositoryWebhook' => true,
        ];

        $resolver = new Resolver($this->cache(), ['test' => $test]);
        $this->assertArrayNotHasKey('test', $resolver->getProviders());
        $this->assertFalse($resolver->isEnabled());

        \putenv('_APP_VCS_TEST_TOKEN=secret');
        $this->assertArrayHasKey('test', $resolver->getProviders());
        $this->assertTrue($resolver->isEnabled());
    }

    public function testProviderForInstallationDefaultsToGitHub(): void
    {
        $resolver = new Resolver($this->cache(), ['github' => $this->githubConfig()]);

        $provider = $resolver->getProviderForInstallation(new Document([]));
        $this->assertSame('github', $provider->getKey());

        $provider = $resolver->getProviderForInstallation(new Document(['provider' => 'github']));
        $this->assertSame('github', $provider->getKey());
    }

    public function testGetOwnerUsesOrganizationForOAuth2(): void
    {
        $config = $this->githubConfig();
        $config['auth'] = Provider::AUTH_OAUTH2;

        $resolver = new Resolver($this->cache(), ['github' => $config]);
        $adapter = $resolver->createAdapter('github');

        $installation = new Document([
            'provider' => 'github',
            'organization' => 'appwrite-tests',
        ]);

        $this->assertSame('appwrite-tests', $resolver->getOwner($adapter, $installation));
        $this->assertSame('appwrite-tests', $resolver->getOwner($adapter, $installation, '42'));
    }

    private function cache(): Cache
    {
        return new Cache(new None());
    }

    private function githubConfig(): array
    {
        return (require __DIR__ . '/../../../app/config/vcs.php')['github'];
    }
}
