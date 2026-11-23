<?php

declare(strict_types=1);

namespace Utopia\Distribution\Tests\Credential;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Utopia\Distribution\Credential;
use Utopia\Distribution\Credential\Google;
use Utopia\Distribution\Exception\Invalid;

final class GoogleTest extends TestCase
{
    private const string EMAIL = 'publisher@example.iam.gserviceaccount.com';

    private const string KEY = "-----BEGIN PRIVATE KEY-----\nkey\n-----END PRIVATE KEY-----\n";

    public function testFromServiceAccountReadsOnlyTheThreeFields(): void
    {
        $credential = Google::fromServiceAccount([
            'type' => 'service_account',
            'project_id' => 'example-project',
            'private_key_id' => 'abc',
            'private_key' => self::KEY,
            'client_email' => self::EMAIL,
            'token_uri' => 'https://attacker.example/token',
        ]);

        $this->assertInstanceOf(Credential::class, $credential);
        $this->assertSame(self::EMAIL, $credential->email);
        $this->assertSame(self::KEY, $credential->privateKey);
        $this->assertSame('example-project', $credential->project);
        $this->assertSame(['email', 'privateKey', 'project'], array_keys(get_object_vars($credential)));
    }

    public function testProjectIsOptional(): void
    {
        $credential = Google::fromServiceAccount(['client_email' => self::EMAIL, 'private_key' => self::KEY]);

        $this->assertNull($credential->project);
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function malformed(): iterable
    {
        yield 'missing private key' => [['client_email' => self::EMAIL]];
        yield 'missing email' => [['private_key' => self::KEY]];
        yield 'empty private key' => [['client_email' => self::EMAIL, 'private_key' => '']];
        yield 'empty email' => [['client_email' => '', 'private_key' => self::KEY]];
        yield 'non-string private key' => [['client_email' => self::EMAIL, 'private_key' => ['nested']]];
        yield 'non-string project' => [['client_email' => self::EMAIL, 'private_key' => self::KEY, 'project_id' => 42]];
        yield 'empty project' => [['client_email' => self::EMAIL, 'private_key' => self::KEY, 'project_id' => '']];
        yield 'non-pem private key' => [['client_email' => self::EMAIL, 'private_key' => 'key']];
        yield 'private key path' => [['client_email' => self::EMAIL, 'private_key' => 'file:///keys/service-account.pem']];
        yield 'private key path with marker' => [['client_email' => self::EMAIL, 'private_key' => "file:///keys/service-account.pem\n" . self::KEY]];
    }

    /**
     * @param array<string, mixed> $serviceAccount
     */
    #[DataProvider('malformed')]
    public function testMalformedServiceAccountThrows(array $serviceAccount): void
    {
        $this->expectException(Invalid::class);

        Google::fromServiceAccount($serviceAccount);
    }

    public function testConstructorRejectsKeyPath(): void
    {
        $this->expectException(Invalid::class);
        $this->expectExceptionMessage('PEM text');

        new Google(self::EMAIL, 'file:///keys/service-account.pem');
    }

    public function testDebugOutputRedactsPrivateKey(): void
    {
        $credential = new Google(self::EMAIL, self::KEY, 'example-project');

        ob_start();
        var_dump($credential);
        $dump = (string) ob_get_clean();

        $this->assertStringNotContainsString('PRIVATE KEY', $dump);
        $this->assertStringNotContainsString('PRIVATE KEY', print_r($credential, true));
        $this->assertStringContainsString(self::EMAIL, $dump);
    }
}
