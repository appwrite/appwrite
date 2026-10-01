<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\Tasks;

use Appwrite\Auth\EncryptionKey;
use Appwrite\Platform\Tasks\Install;
use PHPUnit\Framework\TestCase;

final class InstallEnvironmentTest extends TestCase
{
    public function testTokenFilterGeneratesInsteadOfPlaceholder(): void
    {
        $install = new Install();
        $vars = [
            [
                'name' => '_APP_OPENSSL_KEY_V1',
                'default' => EncryptionKey::PLACEHOLDER,
                'filter' => 'token',
            ],
            [
                'name' => '_APP_DOMAIN',
                'default' => 'localhost',
                'filter' => '',
            ],
        ];

        $input = $install->prepareEnvironmentVariables([], $vars);

        $this->assertArrayHasKey('_APP_OPENSSL_KEY_V1', $input);
        $this->assertNotSame(EncryptionKey::PLACEHOLDER, $input['_APP_OPENSSL_KEY_V1']);
        $this->assertNotSame('', $input['_APP_OPENSSL_KEY_V1']);
        $this->assertSame('localhost', $input['_APP_DOMAIN']);
    }

    public function testUserSuppliedPlaceholderIsReplaced(): void
    {
        $install = new Install();
        $vars = [
            [
                'name' => '_APP_OPENSSL_KEY_V1',
                'default' => '',
                'filter' => 'token',
            ],
        ];

        $input = $install->prepareEnvironmentVariables([
            '_APP_OPENSSL_KEY_V1' => EncryptionKey::PLACEHOLDER,
        ], $vars);

        $this->assertNotSame(EncryptionKey::PLACEHOLDER, $input['_APP_OPENSSL_KEY_V1']);
        $this->assertNotSame('', $input['_APP_OPENSSL_KEY_V1']);
    }

    public function testEmptyTokenDefaultIsGenerated(): void
    {
        $install = new Install();
        $vars = [
            [
                'name' => '_APP_OPENSSL_KEY_V1',
                'default' => '',
                'filter' => 'token',
            ],
        ];

        $input = $install->prepareEnvironmentVariables([], $vars);

        $this->assertNotSame('', $input['_APP_OPENSSL_KEY_V1']);
        $this->assertNotSame(EncryptionKey::PLACEHOLDER, $input['_APP_OPENSSL_KEY_V1']);
    }

    public function testUniqueUserTokenIsKept(): void
    {
        $install = new Install();
        $vars = [
            [
                'name' => '_APP_OPENSSL_KEY_V1',
                'default' => EncryptionKey::PLACEHOLDER,
                'filter' => 'token',
            ],
        ];

        $input = $install->prepareEnvironmentVariables([
            '_APP_OPENSSL_KEY_V1' => 'operator-chosen-secret',
        ], $vars);

        $this->assertSame('operator-chosen-secret', $input['_APP_OPENSSL_KEY_V1']);
    }
}
