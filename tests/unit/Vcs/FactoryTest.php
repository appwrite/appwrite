<?php

declare(strict_types=1);

namespace Tests\Unit\Vcs;

use Appwrite\Extend\Exception;
use Appwrite\Vcs\Factory;
use PHPUnit\Framework\TestCase;
use Utopia\Cache\Adapter\None;
use Utopia\Cache\Cache;
use Utopia\Config\Adapters\PHP as ConfigPHP;
use Utopia\Config\Config;
use Utopia\Database\Document;
use Utopia\VCS\Adapter\Git;
use Utopia\VCS\Adapter\Git\GitHub;

final class FactoryTest extends TestCase
{
    protected function tearDown(): void
    {
        \putenv('_APP_VCS_TEST_TOKEN');
    }

    public function testRegistryEntries(): void
    {
        Config::load('vcs', __DIR__ . '/../../../app/config/vcs.php', new ConfigPHP());

        $registry = Config::getParam('vcs', []);
        $this->assertNotEmpty($registry);

        foreach ($registry as $key => $entry) {
            $this->assertTrue(\is_subclass_of($entry['adapter'], Git::class), "Adapter for '{$key}' must extend Git");
            $this->assertNotEmpty($entry['requiredEnvVariables'], "Required env variables missing for '{$key}'");
            foreach ($entry['requiredEnvVariables'] as $required) {
                $this->assertStringStartsWith('_APP_VCS_', $entry['envVariables'][$required] ?? '', "Env variable for required key '{$required}' missing or invalid for '{$key}'");
            }
        }
    }

    public function testDisabledProvidersAreNotRegistered(): void
    {
        $factory = new Factory($this->cache(), [
            'github' => $this->githubEntry(),
            'legacy' => ['enabled' => false] + $this->githubEntry(),
        ]);

        $this->expectException(Exception::class);
        $factory->fromProvider('legacy');
    }

    public function testFromProviderUnknownThrows(): void
    {
        $factory = new Factory($this->cache(), ['github' => $this->githubEntry()]);

        $this->expectException(Exception::class);
        $factory->fromProvider('bitbucket');
    }

    public function testFromProviderBuildsAdapter(): void
    {
        $factory = new Factory($this->cache(), ['github' => $this->githubEntry()]);

        $this->assertInstanceOf(GitHub::class, $factory->fromProvider('github'));
    }

    public function testFromInstallationEmptyDocumentThrows(): void
    {
        $factory = new Factory($this->cache(), ['github' => $this->githubEntry()]);

        $this->expectException(Exception::class);
        $factory->fromInstallation(new Document());
    }

    public function testIsConfigured(): void
    {
        $entry = [
            'enabled' => true,
            'adapter' => GitHub::class,
            'envVariables' => ['TOKEN' => '_APP_VCS_TEST_TOKEN'],
            'requiredEnvVariables' => ['TOKEN'],
        ];
        $factory = new Factory($this->cache(), ['test' => $entry]);

        $this->assertFalse($factory->isConfigured('test'));
        $this->assertFalse($factory->isConfigured('unknown'));
        $this->assertSame([], $factory->getProviders());

        \putenv('_APP_VCS_TEST_TOKEN=secret');
        $this->assertTrue($factory->isConfigured('test'));
        $this->assertSame(['test'], $factory->getProviders());
    }

    public function testGetWebhookSecret(): void
    {
        $factory = new Factory($this->cache(), ['github' => $this->githubEntry()]);

        $this->assertSame('', $factory->getWebhookSecret('github'));

        \putenv('_APP_VCS_GITHUB_WEBHOOK_SECRET=hunter2');
        $this->assertSame('hunter2', $factory->getWebhookSecret('github'));
        \putenv('_APP_VCS_GITHUB_WEBHOOK_SECRET');
    }

    protected function cache(): Cache
    {
        return new Cache(new None());
    }

    /**
     * @return array<string, mixed>
     */
    protected function githubEntry(): array
    {
        return [
            'enabled' => true,
            'adapter' => GitHub::class,
            'envVariables' => [
                'APP_NAME' => '_APP_VCS_GITHUB_APP_NAME',
                'PRIVATE_KEY' => '_APP_VCS_GITHUB_PRIVATE_KEY',
                'APP_ID' => '_APP_VCS_GITHUB_APP_ID',
                'CLIENT_ID' => '_APP_VCS_GITHUB_CLIENT_ID',
                'CLIENT_SECRET' => '_APP_VCS_GITHUB_CLIENT_SECRET',
                'WEBHOOK_SECRET' => '_APP_VCS_GITHUB_WEBHOOK_SECRET',
            ],
            'requiredEnvVariables' => ['APP_NAME', 'PRIVATE_KEY', 'APP_ID', 'CLIENT_ID', 'CLIENT_SECRET'],
        ];
    }
}
