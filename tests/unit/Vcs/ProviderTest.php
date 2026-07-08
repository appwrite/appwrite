<?php

declare(strict_types=1);

namespace Tests\Unit\Vcs;

use Appwrite\Auth\OAuth2;
use Appwrite\Vcs\Provider;
use PHPUnit\Framework\TestCase;
use Utopia\Cache\Adapter\None;
use Utopia\Cache\Cache;
use Utopia\VCS\Adapter\Git;

final class ProviderTest extends TestCase
{
    protected function tearDown(): void
    {
        foreach (['_APP_VCS_TEST_TOKEN', '_APP_VCS_TEST_ENDPOINT', '_APP_VCS_TEST_BROWSER_ENDPOINT'] as $key) {
            \putenv($key);
        }
    }

    /**
     * Every registry entry must be complete and reference real classes.
     */
    public function testRegistryEntries(): void
    {
        $registry = require __DIR__ . '/../../../app/config/vcs.php';

        $this->assertArrayHasKey('github', $registry);

        foreach ($registry as $key => $config) {
            $this->assertTrue(\class_exists($config['adapter']), "Adapter class missing for '{$key}'");
            $this->assertTrue(\is_subclass_of($config['adapter'], Git::class), "Adapter for '{$key}' must extend Git");
            $this->assertTrue(\class_exists($config['oauth2']), "OAuth2 class missing for '{$key}'");
            $this->assertTrue(\is_subclass_of($config['oauth2'], OAuth2::class), "OAuth2 for '{$key}' must extend OAuth2");
            $this->assertContains($config['auth'], [Provider::AUTH_APP, Provider::AUTH_OAUTH2], "Invalid auth type for '{$key}'");
            $this->assertNotEmpty($config['requiredEnvVariables'], "Required env variables missing for '{$key}'");
            foreach ($config['requiredEnvVariables'] as $requiredKey) {
                $this->assertStringStartsWith('_APP_VCS_', $config['envVariables'][$requiredKey] ?? '', "Env variable for required key '{$requiredKey}' missing or invalid for '{$key}'");
            }
            $this->assertNotEmpty($config['headers']['event'] ?? '', "Event header missing for '{$key}'");
            $this->assertNotEmpty($config['headers']['signature'] ?? '', "Signature header missing for '{$key}'");
            foreach (['repository', 'branch', 'commit', 'file'] as $template) {
                $this->assertNotEmpty($config[$template . 'Url'] ?? '', "URL template '{$template}Url' missing for '{$key}'");
            }

            $provider = new Provider($key, $config);
            $this->assertInstanceOf(Git::class, $provider->createAdapter(new Cache(new None())));
        }
    }

    public function testUrls(): void
    {
        $provider = new Provider('github', $this->githubConfig());

        $this->assertSame('https://github.com/appwrite/appwrite', $provider->getRepositoryUrl('appwrite', 'appwrite'));
        $this->assertSame('https://github.com/appwrite/appwrite/tree/main', $provider->getBranchUrl('appwrite', 'appwrite', 'main'));
        $this->assertSame('https://github.com/appwrite/appwrite/commit/abc123', $provider->getCommitUrl('appwrite', 'appwrite', 'abc123'));
        $this->assertSame('https://github.com/appwrite/appwrite/blob/main', $provider->getFileUrl('appwrite', 'appwrite', 'main'));
    }

    public function testBrowserEndpointFallsBackToEndpoint(): void
    {
        \putenv('_APP_VCS_TEST_ENDPOINT=http://gitea:3000/');

        $provider = new Provider('test', [
            'envVariables' => [
                'ENDPOINT' => '_APP_VCS_TEST_ENDPOINT',
                'BROWSER_ENDPOINT' => '_APP_VCS_TEST_BROWSER_ENDPOINT',
            ],
            'browserEndpoint' => null,
            'repositoryUrl' => '{base}/{owner}/{repository}',
        ]);

        $this->assertSame('http://gitea:3000', $provider->getEndpoint());
        $this->assertSame('http://gitea:3000', $provider->getBrowserEndpoint());
        $this->assertSame('http://gitea:3000/owner/repo', $provider->getRepositoryUrl('owner', 'repo'));

        \putenv('_APP_VCS_TEST_BROWSER_ENDPOINT=http://localhost:9510');
        $this->assertSame('http://localhost:9510/owner/repo', $provider->getRepositoryUrl('owner', 'repo'));
    }

    public function testIsConfigured(): void
    {
        $provider = new Provider('test', [
            'envVariables' => ['TOKEN' => '_APP_VCS_TEST_TOKEN'],
            'requiredEnvVariables' => ['TOKEN'],
        ]);

        $this->assertFalse($provider->isConfigured());

        \putenv('_APP_VCS_TEST_TOKEN=disabled');
        $this->assertFalse($provider->isConfigured(), "The 'disabled' sentinel must count as unset");

        \putenv('_APP_VCS_TEST_TOKEN=secret');
        $this->assertTrue($provider->isConfigured());
    }

    public function testEnvName(): void
    {
        $provider = new Provider('github', $this->githubConfig());

        $this->assertSame('_APP_VCS_GITHUB_WEBHOOK_SECRET', $provider->getEnvName('WEBHOOK_SECRET'));
    }

    private function githubConfig(): array
    {
        return (require __DIR__ . '/../../../app/config/vcs.php')['github'];
    }
}
