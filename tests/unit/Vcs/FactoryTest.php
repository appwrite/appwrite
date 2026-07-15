<?php

declare(strict_types=1);

namespace Tests\Unit\Vcs;

use Appwrite\Extend\Exception;
use Appwrite\Vcs\Factory;
use PHPUnit\Framework\TestCase;
use Utopia\Cache\Adapter\None;
use Utopia\Cache\Cache;
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
        $registry = Config::getParam('vcs', []);
        $this->assertNotEmpty($registry);

        foreach ($registry as $key => $entry) {
            $this->assertTrue(\is_subclass_of($entry['adapter'], Git::class), "Adapter for '{$key}' must extend Git");
            $this->assertNotEmpty($entry['variables'], "Variables missing for '{$key}'");
            foreach ($entry['variables'] as $name => $variable) {
                $this->assertStringStartsWith('_APP_VCS_', $variable['envVariable'] ?? '', "Env variable for '{$name}' missing or invalid for '{$key}'");
            }
        }
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

    public function testFromInstallationMissingProviderThrows(): void
    {
        $factory = new Factory($this->cache(), ['github' => $this->githubEntry()]);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Missing VCS provider');

        $factory->fromInstallation(new Document([
            '$id' => 'installation',
        ]));
    }

    public function testIsConfigured(): void
    {
        $entry = [
            'adapter' => GitHub::class,
            'variables' => [
                'token' => ['required' => true, 'envVariable' => '_APP_VCS_TEST_TOKEN'],
            ],
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
            'adapter' => GitHub::class,
            'variables' => [
                'appName' => ['required' => true, 'envVariable' => '_APP_VCS_GITHUB_APP_NAME'],
                'privateKey' => ['required' => true, 'envVariable' => '_APP_VCS_GITHUB_PRIVATE_KEY'],
                'appId' => ['required' => true, 'envVariable' => '_APP_VCS_GITHUB_APP_ID'],
                'clientId' => ['required' => true, 'envVariable' => '_APP_VCS_GITHUB_CLIENT_ID'],
                'clientSecret' => ['required' => true, 'envVariable' => '_APP_VCS_GITHUB_CLIENT_SECRET'],
                'webhookSecret' => ['required' => false, 'envVariable' => '_APP_VCS_GITHUB_WEBHOOK_SECRET'],
            ],
        ];
    }
}
