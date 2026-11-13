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

    #[DataProvider('userFilterProvider')]
    public function testFilterPutsUsernameInTheUserFilter(string $userFilter, string $expected): void
    {
        $this->assertSame($expected, $this->client(['userFilter' => $userFilter])->filter('alice')->toString());
    }

    public static function userFilterProvider(): \Iterator
    {
        yield 'empty means the default' => ['', '(uid=alice)'];
        yield 'Active Directory' => [
            '(&(objectClass=user)(sAMAccountName={username})(!(userAccountControl:1.2.840.113556.1.4.803:=2)))',
            '(&(objectClass=user)(sAMAccountName=alice)(!(userAccountControl:1.2.840.113556.1.4.803:=2)))',
        ];
        yield 'username or email' => ['(|(uid={username})(mail={username}))', '(|(uid=alice)(mail=alice))'];
        yield 'part of a value' => ['(mail={username}@example.com)', '(mail=alice@example.com)'];
        yield 'inside a substring' => ['(cn=*{username}*)', '(cn=*alice*)'];
        // A DN match asked for explicitly is kept.
        yield 'DN match' => ['(&(uid={username})(ou:dn:=people))', '(&(uid=alice)(ou:dn:=people))'];
    }

    #[DataProvider('injectionProvider')]
    public function testFilterCarriesUsernameAsValue(string $username, string $expected): void
    {
        // The username is an assertion value, so filter syntax inside it is
        // literal: a wildcard never matches everything and a parenthesis never
        // closes the filter early.
        $this->assertSame($expected, $this->client()->filter($username)->toString());
    }

    public static function injectionProvider(): \Iterator
    {
        yield 'wildcard' => ['*', '(uid=\2a)'];
        yield 'partial wildcard' => ['ali*', '(uid=ali\2a)'];
        yield 'closing the filter' => ['alice)(uid=*', '(uid=alice\29\28uid=\2a)'];
        yield 'backslash' => ['ali\\ce', '(uid=ali\5cce)'];
        yield 'null byte' => ["ali\0ce", '(uid=ali\00ce)'];
        yield 'already escaped' => ['\2a', '(uid=\2a)'];
        yield 'the placeholder itself' => ['{username}', '(uid={username})'];
    }

    public function testFilterCarriesUsernameAsValueInsideSubstrings(): void
    {
        $client = $this->client(['userFilter' => '(cn=*{username}*)']);

        $this->assertSame('(cn=*\2a\29\28*)', $client->filter('*)(')->toString());
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

    public function testFilterRefusesUserFilterWithoutPlaceholder(): void
    {
        // Written around the API's check: without {username} every sign-in would look up
        // the same entries.
        $client = $this->client([
            'userFilter' => '(objectClass=inetOrgPerson)',
        ]);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('{username}');

        try {
            $client->filter('alice');
        } catch (Exception $error) {
            $this->assertSame(Exception::USER_LDAP_DIRECTORY_ERROR, $error->getType());
            throw $error;
        }
    }

    #[DataProvider('checkUserFilterProvider')]
    public function testCheckUserFilter(string $userFilter, ?string $error): void
    {
        if ($error !== null) {
            $this->expectException(InvalidArgumentException::class);
            $this->expectExceptionMessage($error);
        }

        Client::checkUserFilter($userFilter);

        if ($error === null) {
            $this->addToAssertionCount(1);
        }
    }

    public static function checkUserFilterProvider(): \Iterator
    {
        yield 'default' => [Client::USER_FILTER_DEFAULT, null];
        yield 'username or email' => ['(|(uid={username})(mail={username}))', null];
        yield 'substring' => ['(cn=*{username}*)', null];
        yield 'does not parse' => ['(objectClass=person', 'not a valid LDAP filter'];
        yield 'no placeholder' => ['(objectClass=person)', 'must contain {username}'];
        yield 'placeholder as attribute name only' => ['({username}=alice)', 'must contain {username}'];
        yield 'placeholder in another case' => ['(uid={USERNAME})', 'must contain {username}'];
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

    public static function invalidConfigProvider(): \Iterator
    {
        yield 'no hosts' => [['hosts' => []], 'host'];
        yield 'blank hosts' => [['hosts' => ['', ' ']], 'host'];
        yield 'too many hosts' => [['hosts' => \array_fill(0, Client::HOSTS_MAX + 1, 'ldap.example.com')], 'hosts'];
        yield 'bad encryption' => [['encryption' => 'ssl'], 'encryption'];
        yield 'port too low' => [['port' => -1], 'port'];
        yield 'port too high' => [['port' => 65536], 'port'];
        yield 'timeout too low' => [['timeout' => 0], 'timeout'];
        yield 'timeout too high' => [['timeout' => Client::TIMEOUT_MAX + 1], 'timeout'];
        yield 'no base dn' => [['baseDn' => ' '], 'base DN'];
        yield 'blank id attribute' => [['idAttribute' => ''], 'idAttribute'];
        yield 'blank email attribute' => [['emailAttribute' => ''], 'emailAttribute'];
        yield 'blank name attribute' => [['nameAttribute' => ''], 'nameAttribute'];
    }

    public function testVerificationRefusesAddressLiterals(): void
    {
        // Nothing listens on loopback, so a refusal naming the certificate rather than
        // the connection proves the host was turned away before dialling.
        $client = $this->client([
            'hosts' => ['127.0.0.1'],
            'port' => 1,
            'encryption' => 'ldaps',
            'verification' => true,
        ]);

        try {
            $client->test();
            $this->fail('An address literal was accepted for a verified connection.');
        } catch (Exception $error) {
            $this->assertSame(Exception::PROJECT_LDAP_CONFIG_INVALID, $error->getType());
            $this->assertStringContainsString('IP address', $error->getMessage());
        }
    }

    public function testCheckCertificateAcceptsOneOrMoreCertificates(): void
    {
        $first = self::certificate('Halden Root CA');
        $second = self::certificate('Halden Issuing CA');

        Client::checkCertificate($first);
        Client::checkCertificate($first . $second);
        // openssl prints a subject and issuer line above each certificate in a bundle
        Client::checkCertificate("subject=CN = Halden Root CA\nissuer=CN = Halden Root CA\n" . $first . "\n\n" . $second);

        $this->addToAssertionCount(1);
    }

    #[DataProvider('invalidCertificateProvider')]
    public function testCheckCertificateRefuses(string $pem, string $error): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($error);

        Client::checkCertificate($pem);
    }

    public static function invalidCertificateProvider(): \Iterator
    {
        $certificate = self::certificate('Halden Root CA');
        $key = \openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        \openssl_pkey_export($key, $keyPem);

        yield 'not PEM' => ['MIIBkTCB+wIJAKHBfpegPjMCMA0GCSqGSIb3DQEBBQUAMBExDzANBgNVBAMMBnVu', 'PEM format'];
        yield 'missing END line' => [\substr($certificate, 0, (int) \strpos($certificate, '-----END')), 'PEM format'];
        yield 'one complete, one cut short' => [$certificate . \substr($certificate, 0, 100), 'PEM format'];
        yield 'private key alongside' => [$certificate . $keyPem, 'PRIVATE KEY'];
        yield 'damaged body' => [(string) \preg_replace('/^(-----BEGIN CERTIFICATE-----\n)..../', '$1AAAA', $certificate), 'could not be read'];
    }

    private static function certificate(string $commonName): string
    {
        $key = \openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        $csr = \openssl_csr_new(['commonName' => $commonName], $key, ['digest_alg' => 'sha256']);
        $certificate = \openssl_csr_sign($csr, null, $key, 1, ['digest_alg' => 'sha256']);
        \openssl_x509_export($certificate, $pem);

        return $pem;
    }

    #[DataProvider('phoneProvider')]
    public function testPhoneWritesInternationalNumbersAsE164(string $phone, string $expected): void
    {
        $this->assertSame($expected, Client::phone($phone));
    }

    public static function phoneProvider(): \Iterator
    {
        yield 'already E.164' => ['+420777123456', '+420777123456'];
        yield 'spaces' => ['+420 777 123 456', '+420777123456'];
        yield 'no-break spaces' => ["+420\u{00A0}777\u{00A0}123\u{00A0}456", '+420777123456'];
        yield 'brackets and dashes' => ['+1 (425) 555-0100', '+14255550100'];
        yield 'trunk prefix' => ['+44 (0)20 7946 0018', '+442079460018'];
        yield 'slash' => ['+49 30/1234567', '+49301234567'];
        yield 'dots' => ['+33.1.23.45.67.89', '+33123456789'];
        // Without a country code there is no telling which country it is in, so it stays
        // as written and the sign-in leaves the phone alone.
        yield 'national' => ['0777 123 456', '0777 123 456'];
        yield 'empty' => ['', ''];
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
