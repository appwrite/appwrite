<?php

declare(strict_types=1);

namespace Tests\Unit\Auth\LDAP;

use Appwrite\Auth\LDAP\Client;
use Appwrite\Extend\Exception;
use PHPUnit\Framework\TestCase;

/**
 * Configuration validation and, more importantly, filter escaping.
 *
 * A search filter is assembled from a value the user supplies, so it carries the
 * same injection risk as a SQL query built by concatenation.
 */
final class ClientTest extends TestCase
{
    private function client(array $over = []): Client
    {
        return new Client(
            host: $over['host'] ?? 'ldap.example.com',
            port: $over['port'] ?? 389,
            encryption: $over['encryption'] ?? Client::ENCRYPTION_TLS,
            baseDn: $over['baseDn'] ?? 'dc=example,dc=com',
            bindDn: $over['bindDn'] ?? 'cn=service,dc=example,dc=com',
            bindPassword: $over['bindPassword'] ?? 'secret',
            userFilter: $over['userFilter'] ?? '(uid={{username}})',
            provisionFilter: $over['provisionFilter'] ?? '',
            emailAttribute: $over['emailAttribute'] ?? 'mail',
            nameAttribute: $over['nameAttribute'] ?? 'cn',
        );
    }

    public function testValidConfigurationIsAccepted(): void
    {
        $client = $this->client();

        $this->assertTrue($client->useStartTls());
        $this->assertFalse($client->useSsl());
    }

    public function testHostIsRequired(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessageMatches('/host is required/i');

        $this->client(['host' => '  ']);
    }

    public function testBaseDnIsRequired(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessageMatches('/base DN is required/i');

        $this->client(['baseDn' => '']);
    }

    public function testPortMustBeInRange(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessageMatches('/port must be/i');

        $this->client(['port' => 70000]);
    }

    public function testUnsupportedEncryptionIsRejected(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessageMatches('/encryption/i');

        $this->client(['encryption' => 'rot13']);
    }

    /**
     * A filter without the placeholder resolves to the same entry for every
     * sign-in, which would let anyone in as whoever it happens to match.
     */
    public function testUserFilterWithoutPlaceholderIsRejected(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessageMatches('/placeholder/i');

        $this->client(['userFilter' => '(uid=admin)']);
    }

    public function testEmailAttributeIsRequired(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessageMatches('/email attribute is required/i');

        $this->client(['emailAttribute' => '']);
    }

    public function testPlaceholderIsSubstituted(): void
    {
        $this->assertSame('(uid=alice)', $this->client()->getUserFilter('alice'));
    }

    /**
     * RFC 4515 metacharacters must be escaped, or a username of `*` matches
     * every entry in the subtree.
     */
    public function testWildcardIsEscaped(): void
    {
        $filter = $this->client()->getUserFilter('*');

        $this->assertSame('(uid=\2a)', $filter);
        $this->assertStringNotContainsString('=*', $filter);
    }

    public function testFilterBreakoutIsEscaped(): void
    {
        $filter = $this->client()->getUserFilter('alice)(uid=*');

        $this->assertStringNotContainsString(')(', $filter);
        $this->assertStringContainsString('\29\28', $filter);
    }

    public function testNullByteIsEscaped(): void
    {
        $this->assertStringNotContainsString("\x00", $this->client()->getUserFilter("alice\x00"));
    }

    public function testBackslashIsEscaped(): void
    {
        $this->assertStringContainsString('\5c', $this->client()->getUserFilter('do\\main'));
    }

    public function testProvisionFilterIsAbsentByDefault(): void
    {
        $client = $this->client();

        $this->assertFalse($client->hasProvisionFilter());
        $this->assertSame('', $client->getProvisionFilter('alice'));
    }

    /**
     * The provisioning filter is evaluated against the authenticated entry, so
     * the placeholder receives its DN rather than the typed username. A filter
     * that merely matched something in the subtree would authorise anyone.
     */
    public function testProvisionFilterSubstitutesTheEntryDn(): void
    {
        $client = $this->client([
            'provisionFilter' => '(member=' . Client::PLACEHOLDER . ')',
        ]);

        $filter = $client->getProvisionFilter('uid=alice,ou=people,dc=example,dc=com');

        $this->assertStringContainsString('uid=alice,ou=people,dc=example,dc=com', $filter);
    }

    public function testProvisionFilterSubstitutesAndEscapes(): void
    {
        $client = $this->client([
            'provisionFilter' => '(&(cn=staff)(member=uid={{username}},ou=people,dc=example,dc=com))',
        ]);

        $this->assertTrue($client->hasProvisionFilter());
        $this->assertStringContainsString('uid=alice,', $client->getProvisionFilter('alice'));
        $this->assertStringNotContainsString('=*', $client->getProvisionFilter('*'));
    }

    public function testSslAndStartTlsAreDistinct(): void
    {
        $ssl = $this->client(['encryption' => Client::ENCRYPTION_SSL, 'port' => 636]);
        $none = $this->client(['encryption' => Client::ENCRYPTION_NONE]);

        $this->assertTrue($ssl->useSsl());
        $this->assertFalse($ssl->useStartTls());
        $this->assertFalse($none->useSsl());
        $this->assertFalse($none->useStartTls());
    }
}
