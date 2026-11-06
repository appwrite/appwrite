<?php

declare(strict_types=1);

namespace Tests\Unit\Auth\LDAP;

use Appwrite\Auth\LDAP\Client;
use Appwrite\Extend\Exception;
use Appwrite\Network\Validator\PublicHostname;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Utopia\Client\Destinations\PublicInternet;
use Utopia\DNS\Lookup\Recursive;

/**
 * Configuration handling, the user filter and identifier rendering. Everything
 * that talks to a directory is covered against a real one in
 * tests/e2e/Services/Project/LDAPSessionIntegrationTest.php.
 */
final class ClientTest extends TestCase
{
    private function client(array $config = []): Client
    {
        return new Client(new PublicHostname(new PublicInternet(), new Recursive(['127.0.0.1'])), \array_merge([
            'hosts' => ['ldap.example.com'],
            'baseDn' => 'dc=example,dc=com',
        ], $config));
    }

    public function testDefaults(): void
    {
        $client = $this->client();

        $this->assertSame('(uid=alice)', $this->client()->filter('alice')->toString());
        $this->assertSame(636, Client::port(Client::ENCRYPTION_LDAPS));
        $this->assertSame(389, Client::port(Client::ENCRYPTION_STARTTLS));
        $this->assertSame(389, Client::port(Client::ENCRYPTION_NONE));
        $this->assertInstanceOf(Client::class, $client);
    }

    public function testFilterCombinesUsernameAndUserFilter(): void
    {
        $client = $this->client([
            'usernameAttribute' => 'sAMAccountName',
            'userFilter' => '(&(objectClass=user)(!(userAccountControl:1.2.840.113556.1.4.803:=2)))',
        ]);

        $this->assertSame(
            '(&(sAMAccountName=alice)(&(objectClass=user)(!(userAccountControl:1.2.840.113556.1.4.803:=2))))',
            $client->filter('alice')->toString()
        );

        // A DN match asked for explicitly is kept.
        $client = $this->client([
            'userFilter' => '(ou:dn:=people)',
        ]);

        $this->assertSame('(&(uid=alice)(ou:dn:=people))', $client->filter('alice')->toString());
    }

    #[DataProvider('injectionProvider')]
    public function testFilterCarriesUsernameAsValue(string $username, string $expected): void
    {
        // The username is an assertion value, so filter syntax inside it is
        // literal: a wildcard never matches everything and a parenthesis never
        // closes the filter early.
        $this->assertSame($expected, $this->client()->filter($username)->toString());
    }

    public static function injectionProvider(): array
    {
        return [
            'wildcard' => ['*', '(uid=\2a)'],
            'partial wildcard' => ['ali*', '(uid=ali\2a)'],
            'closing the filter' => ['alice)(uid=*', '(uid=alice\29\28uid=\2a)'],
            'backslash' => ['ali\\ce', '(uid=ali\5cce)'],
            'null byte' => ["ali\0ce", '(uid=ali\00ce)'],
            'already escaped' => ['\2a', '(uid=\2a)'],
        ];
    }

    public function testFilterRefusesUserFilterThatDoesNotParse(): void
    {
        $client = $this->client([
            'userFilter' => '(objectClass=person',
        ]);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('(objectClass=person');

        try {
            $client->filter('alice');
        } catch (Exception $error) {
            $this->assertSame(Exception::USER_LDAP_DIRECTORY_ERROR, $error->getType());
            throw $error;
        }
    }

    public function testIdentifierRendersObjectGuidLikeActiveDirectory(): void
    {
        // {3F2504E0-4F89-11D3-9A0C-0305E82C3301} as the directory stores it:
        // the first three groups little-endian, the rest in order.
        $bytes = \hex2bin('e004253f894fd3119a0c0305e82c3301');

        $this->assertSame('3f2504e0-4f89-11d3-9a0c-0305e82c3301', Client::identifier('objectGUID', $bytes));
        $this->assertSame('3f2504e0-4f89-11d3-9a0c-0305e82c3301', Client::identifier('objectguid', $bytes));
    }

    public function testIdentifierKeepsText(): void
    {
        $this->assertSame('6c4a7f0e-1d2b-4c8e-9f3a-0a1b2c3d4e5f', Client::identifier('entryUUID', '6c4a7f0e-1d2b-4c8e-9f3a-0a1b2c3d4e5f'));
        $this->assertSame('alice', Client::identifier('uid', 'alice'));
        $this->assertSame('Élodie', Client::identifier('cn', 'Élodie'));
    }

    public function testIdentifierRendersOtherBinaryAsHex(): void
    {
        $this->assertSame('ff00fe', Client::identifier('GUID', "\xff\x00\xfe"));
    }

    #[DataProvider('invalidConfigProvider')]
    public function testConstructorRefusesInvalidConfiguration(array $config, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        $this->client($config);
    }

    public static function invalidConfigProvider(): array
    {
        return [
            'no hosts' => [['hosts' => []], 'host'],
            'blank hosts' => [['hosts' => ['', ' ']], 'host'],
            'too many hosts' => [['hosts' => \array_fill(0, Client::HOSTS_MAX + 1, 'ldap.example.com')], 'hosts'],
            'bad encryption' => [['encryption' => 'ssl'], 'encryption'],
            'port too low' => [['port' => -1], 'port'],
            'port too high' => [['port' => 65536], 'port'],
            'timeout too low' => [['timeout' => 0], 'timeout'],
            'timeout too high' => [['timeout' => Client::TIMEOUT_MAX + 1], 'timeout'],
            'no base dn' => [['baseDn' => ' '], 'base DN'],
            'blank username attribute' => [['usernameAttribute' => ''], 'usernameAttribute'],
            'blank id attribute' => [['idAttribute' => ''], 'idAttribute'],
            'blank email attribute' => [['emailAttribute' => ''], 'emailAttribute'],
            'blank name attribute' => [['nameAttribute' => ''], 'nameAttribute'],
        ];
    }

    public function testAuthenticateRefusesEmptyCredentialsWithoutConnecting(): void
    {
        // RFC 4513 lets a directory treat an empty password as an anonymous bind
        // and report success, so it is refused before any connection is made.
        $client = $this->client([
            'hosts' => ['127.0.0.1'],
            'port' => 1,
            'encryption' => 'none',
        ]);

        foreach ([['alice', ''], ['', 'secret'], ['', '']] as [$username, $password]) {
            try {
                $client->authenticate($username, $password);
                $this->fail('Empty credentials were not refused.');
            } catch (Exception $error) {
                $this->assertSame(Exception::USER_INVALID_CREDENTIALS, $error->getType());
            }
        }
    }
}
