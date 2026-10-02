<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\Modules\Installer\Http\Installer;

use Appwrite\Platform\Installer\Http\Installer\Validate;
use Appwrite\Platform\Installer\Server;
use PHPUnit\Framework\TestCase;
use Swoole\Http\Request as SwooleRequest;
use Utopia\Http\Adapter\Swoole\Request;

final class ValidateTest extends TestCase
{
    protected function tearDown(): void
    {
        Server::setInstallerSecret('');
    }

    public function testValidateCsrfRequiresMatchingCookieAndHeader(): void
    {
        $this->assertFalse(Validate::validateCsrf($this->request()));
        $this->assertFalse(Validate::validateCsrf($this->request(
            cookies: [Server::CSRF_COOKIE => 'abc'],
            headers: ['x-appwrite-installer-csrf' => 'def'],
        )));
        $this->assertTrue(Validate::validateCsrf($this->request(
            cookies: [Server::CSRF_COOKIE => 'abc'],
            headers: ['x-appwrite-installer-csrf' => 'abc'],
        )));
    }

    public function testValidateSecretRequiresIssuedValue(): void
    {
        Server::setInstallerSecret('');
        $this->assertFalse(Validate::validateSecret($this->request(
            headers: [Server::INSTALLER_SECRET_HEADER => 'anything'],
        )));

        Server::setInstallerSecret('issued-secret');
        $this->assertFalse(Validate::validateSecret($this->request()));
        $this->assertFalse(Validate::validateSecret($this->request(
            headers: [Server::INSTALLER_SECRET_HEADER => 'wrong'],
        )));
        $this->assertTrue(Validate::validateSecret($this->request(
            headers: [Server::INSTALLER_SECRET_HEADER => 'issued-secret'],
        )));
    }

    public function testIssueInstallerSecretUsesEnvironment(): void
    {
        $previous = getenv('APPWRITE_INSTALLER_SECRET');
        putenv('APPWRITE_INSTALLER_SECRET=from-parent');

        try {
            $this->assertSame('from-parent', Server::issueInstallerSecret());
            $this->assertTrue(Validate::validateSecret($this->request(
                headers: [Server::INSTALLER_SECRET_HEADER => 'from-parent'],
            )));
        } finally {
            putenv($previous === false ? 'APPWRITE_INSTALLER_SECRET' : 'APPWRITE_INSTALLER_SECRET=' . $previous);
        }
    }

    /**
     * @param array<string, string> $cookies
     * @param array<string, string> $headers
     */
    private function request(array $cookies = [], array $headers = []): Request
    {
        $swoole = new SwooleRequest();
        $swoole->cookie = $cookies;
        $swoole->header = $headers;

        return new Request($swoole);
    }
}
