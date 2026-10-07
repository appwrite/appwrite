<?php

declare(strict_types=1);

namespace Tests\Unit\Utopia;

use Appwrite\Config\Config;
use Appwrite\Utopia\View;
use PHPUnit\Framework\TestCase;

final class ErrorViewTest extends TestCase
{
    private mixed $platform;

    private string|false $environment;

    private string|false $https;

    protected function setUp(): void
    {
        $this->platform = Config::getParam('platform');
        $this->environment = \getenv('_APP_ENV');
        $this->https = \getenv('_APP_OPTIONS_FORCE_HTTPS');
    }

    protected function tearDown(): void
    {
        Config::setParam('platform', $this->platform);
        \putenv($this->environment === false ? '_APP_ENV' : '_APP_ENV=' . $this->environment);
        \putenv($this->https === false ? '_APP_OPTIONS_FORCE_HTTPS' : '_APP_OPTIONS_FORCE_HTTPS=' . $this->https);
    }

    public function testErrorPageLinksAssetsToTheConsoleHostname(): void
    {
        Config::setParam('platform', ['consoleHostname' => 'console.example.com']);
        \putenv('_APP_ENV=production');
        \putenv('_APP_OPTIONS_FORCE_HTTPS=enabled');

        $html = (new View(__DIR__ . '/../../../app/views/general/error.phtml'))
            ->setParam('message', 'Project not found')
            ->setParam('type', 'project_not_found')
            ->setParam('code', 404)
            ->render();

        $this->assertStringContainsString('href="https://console.example.com/images/logos/appwrite-icon.svg"', $html);
        $this->assertStringContainsString('Project not found', $html);
    }
}
