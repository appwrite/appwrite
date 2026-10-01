<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\Modules\Migrations\Validator;

use Appwrite\Platform\Modules\Migrations\Validator\Endpoint;
use PHPUnit\Framework\TestCase;

final class EndpointTest extends TestCase
{
    private string|false $allowlist = false;

    protected function setUp(): void
    {
        $this->allowlist = \getenv('_APP_MIGRATIONS_ALLOWED_HOSTS');
        \putenv('_APP_MIGRATIONS_ALLOWED_HOSTS');
    }

    protected function tearDown(): void
    {
        \putenv($this->allowlist === false ? '_APP_MIGRATIONS_ALLOWED_HOSTS' : '_APP_MIGRATIONS_ALLOWED_HOSTS=' . $this->allowlist);
    }

    public function testRejectsPrivateAndLocalEndpointsByDefault(): void
    {
        $validator = new Endpoint();

        $this->assertTrue($validator->isValid('https://1.1.1.1/v1'), $validator->getDescription());

        foreach ($this->rejectedEndpoints() as $endpoint) {
            $this->assertFalse($validator->isValid($endpoint), "Expected {$endpoint} to be rejected");
        }
    }

    public function testDescriptionDoesNotDependOnTheEndpoint(): void
    {
        $validator = new Endpoint();
        $descriptions = [];

        foreach (['http://10.0.0.5/v1', 'http://[::1]/v1', 'http://a-hostname-that-does-not-exist.invalid/v1', 'http://user@1.1.1.1/v1', 'gopher://1.1.1.1/'] as $endpoint) {
            $this->assertFalse($validator->isValid($endpoint), "Expected {$endpoint} to be rejected");
            $descriptions[] = $validator->getDescription();
        }

        $this->assertCount(1, \array_unique($descriptions));
        $this->assertStringNotContainsString('10.0.0.5', $descriptions[0]);
    }

    public function testAcceptsAllowedHostnamesAndSubnets(): void
    {
        \putenv('_APP_MIGRATIONS_ALLOWED_HOSTS=appwrite.test, 10.0.0.0/8, fd00::/8');
        $validator = new Endpoint();

        $this->assertTrue($validator->isValid('http://appwrite.test/v1'), $validator->getDescription());
        $this->assertTrue($validator->isValid('http://10.1.2.3/v1'), $validator->getDescription());
        $this->assertTrue($validator->isValid('http://[fd12::1]/v1'), $validator->getDescription());

        $this->assertFalse($validator->isValid('http://evil-appwrite.test/v1'));
        $this->assertFalse($validator->isValid('http://db.appwrite.test/v1'));
        $this->assertFalse($validator->isValid('http://192.168.1.1/v1'));
        $this->assertFalse($validator->isValid('http://169.254.169.254/v1'));
        $this->assertFalse($validator->isValid('http://[fe80::1]/v1'));
    }

    /**
     * @return array<string>
     */
    private function rejectedEndpoints(): array
    {
        return [
            'http://169.254.169.254/v1',
            'http://127.0.0.1/v1',
            'http://10.0.0.5/v1',
            'http://[::1]/v1',
            'http://[::ffff:169.254.169.254]/v1',
            'http://[::7f00:1]/v1',
            'http://[fec0::1]/v1',
            'http://2130706433/v1',
            'http://0x7f.0.0.1/v1',
            'http://localhost/v1',
            'http://appwrite.test/v1',
            'http://1.1.1.1@127.0.0.1/v1',
            'gopher://1.1.1.1/',
            'file:///etc/hosts',
        ];
    }
}
