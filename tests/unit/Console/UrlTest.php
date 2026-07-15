<?php

namespace Tests\Unit\Console;

use Appwrite\Console\Url;
use PHPUnit\Framework\TestCase;

final class UrlTest extends TestCase
{
    private array $envBackup = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->envBackup = [
            '_APP_CONSOLE_URL_SCHEME' => \getenv('_APP_CONSOLE_URL_SCHEME'),
            '_APP_OPTIONS_FORCE_HTTPS' => \getenv('_APP_OPTIONS_FORCE_HTTPS'),
        ];
    }

    protected function tearDown(): void
    {
        foreach ($this->envBackup as $key => $value) {
            if ($value === false) {
                \putenv($key);
                unset($_ENV[$key], $_SERVER[$key]);
            } else {
                \putenv($key . '=' . $value);
                $_ENV[$key] = $value;
                $_SERVER[$key] = $value;
            }
        }
        parent::tearDown();
    }

    private function setScheme(string $scheme): void
    {
        \putenv('_APP_CONSOLE_URL_SCHEME=' . $scheme);
        $_ENV['_APP_CONSOLE_URL_SCHEME'] = $scheme;
        $_SERVER['_APP_CONSOLE_URL_SCHEME'] = $scheme;
    }

    public function testDefaultsToLegacy(): void
    {
        \putenv('_APP_CONSOLE_URL_SCHEME');
        unset($_ENV['_APP_CONSOLE_URL_SCHEME'], $_SERVER['_APP_CONSOLE_URL_SCHEME']);

        $this->assertSame(Url::SCHEME_LEGACY, Url::scheme());
        $this->assertFalse(Url::isVibes());
        $this->assertSame(
            '/console/project-fra-proj/sites/site-site1/deployments/deployment-dep1',
            Url::siteDeployment('fra', 'proj', 'site1', 'dep1'),
        );
    }

    public function testVibesProjectAndResourcePaths(): void
    {
        $this->setScheme(Url::SCHEME_VIBES);

        $this->assertSame(
            '/projects/proj/sites/site1/deployments/dep1',
            Url::siteDeployment('fra', 'proj', 'site1', 'dep1'),
        );
        $this->assertSame(
            '/projects/proj/functions/fn1/deployments/dep1',
            Url::functionDeployment('fra', 'proj', 'fn1', 'dep1'),
        );
        $this->assertSame(
            '/projects/proj/settings/webhooks',
            Url::webhookSettings('fra', 'proj', 'webhook-1'),
        );
        $this->assertSame('/auth/magic-url', Url::auth('magic-url'));
        $this->assertSame(
            'https://cloud.appwrite.io/projects/proj/functions/fn1',
            Url::absolute('cloud.appwrite.io', Url::projectResource('fra', 'proj', 'functions', 'function', 'fn1')),
        );
    }

    public function testLegacyFunctionDeploymentSiblingSegment(): void
    {
        $this->setScheme(Url::SCHEME_LEGACY);

        $this->assertSame(
            '/console/project-nyc-proj/functions/function-fn1/deployment-dep1',
            Url::functionDeployment('nyc', 'proj', 'fn1', 'dep1'),
        );
        $this->assertSame(
            '/console/project-fra-proj/settings/webhooks/webhook-1',
            Url::webhookSettings('fra', 'proj', 'webhook-1'),
        );
    }
}
