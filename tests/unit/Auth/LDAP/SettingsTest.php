<?php

declare(strict_types=1);

namespace Tests\Unit\Auth\LDAP;

use Appwrite\Auth\LDAP\Exception;
use Appwrite\Auth\LDAP\Settings;
use PHPUnit\Framework\TestCase;

/**
 * Configuration validation and, more importantly, filter escaping.
 *
 * A search filter is assembled from a value the user supplies, so it carries the
 * same injection risk as a SQL query built by concatenation.
 */
final class SettingsTest extends TestCase
{
    private function settings(array $over = []): Settings
    {
        return new Settings(
            host: $over['host'] ?? 'ldap.example.com',
            port: $over['port'] ?? 389,
            encryption: $over['encryption'] ?? Settings::ENCRYPTION_TLS,
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
        $settings = $this->settings();

        $this->assertSame('ldap.example.com', $settings->getHost());
        $this->assertSame(389, $settings->getPort());
        $this->assertTrue($settings->useStartTls());
        $this->assertFalse($settings->useSsl());
    }

    public function testHostIsRequired(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessageMatches('/host is required/i');

        $this->settings(['host' => '  ']);
    }

    public function testBaseDnIsRequired(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessageMatches('/base DN is required/i');

        $this->settings(['baseDn' => '']);
    }

    public function testPortMustBeInRange(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessageMatches('/port must be/i');

        $this->settings(['port' => 70000]);
    }

    public function testUnsupportedEncryptionIsRejected(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessageMatches('/encryption/i');

        $this->settings(['encryption' => 'rot13']);
    }

    /**
     * A filter without the placeholder resolves to the same entry for every
     * sign-in, which would let anyone in as whoever it happens to match.
     */
    public function testUserFilterWithoutPlaceholderIsRejected(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessageMatches('/placeholder/i');

        $this->settings(['userFilter' => '(uid=admin)']);
    }

    public function testEmailAttributeIsRequired(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessageMatches('/email attribute is required/i');

        $this->settings(['emailAttribute' => '']);
    }

    public function testPlaceholderIsSubstituted(): void
    {
        $this->assertSame('(uid=alice)', $this->settings()->getUserFilter('alice'));
    }

    /**
     * RFC 4515 metacharacters must be escaped, or a username of `*` matches
     * every entry in the subtree.
     */
    public function testWildcardIsEscaped(): void
    {
        $filter = $this->settings()->getUserFilter('*');

        $this->assertSame('(uid=\2a)', $filter);
        $this->assertStringNotContainsString('=*', $filter);
    }

    public function testFilterBreakoutIsEscaped(): void
    {
        $filter = $this->settings()->getUserFilter('alice)(uid=*');

        $this->assertStringNotContainsString(')(', $filter);
        $this->assertStringContainsString('\29\28', $filter);
    }

    public function testNullByteIsEscaped(): void
    {
        $this->assertStringNotContainsString("\x00", $this->settings()->getUserFilter("alice\x00"));
    }

    public function testBackslashIsEscaped(): void
    {
        $this->assertStringContainsString('\5c', $this->settings()->getUserFilter('do\\main'));
    }

    public function testProvisionFilterIsAbsentByDefault(): void
    {
        $settings = $this->settings();

        $this->assertFalse($settings->hasProvisionFilter());
        $this->assertSame('', $settings->getProvisionFilter('alice'));
    }

    /**
     * The provisioning filter is evaluated against the authenticated entry, so
     * the placeholder receives its DN rather than the typed username. A filter
     * that merely matched something in the subtree would authorise anyone.
     */
    public function testProvisionFilterSubstitutesTheEntryDn(): void
    {
        $settings = $this->settings([
            'provisionFilter' => '(member=' . Settings::PLACEHOLDER . ')',
        ]);

        $filter = $settings->getProvisionFilter('uid=alice,ou=people,dc=example,dc=com');

        $this->assertStringContainsString('uid=alice,ou=people,dc=example,dc=com', $filter);
    }

    public function testProvisionFilterSubstitutesAndEscapes(): void
    {
        $settings = $this->settings([
            'provisionFilter' => '(&(cn=staff)(member=uid={{username}},ou=people,dc=example,dc=com))',
        ]);

        $this->assertTrue($settings->hasProvisionFilter());
        $this->assertStringContainsString('uid=alice,', $settings->getProvisionFilter('alice'));
        $this->assertStringNotContainsString('=*', $settings->getProvisionFilter('*'));
    }

    public function testSslAndStartTlsAreDistinct(): void
    {
        $ssl = $this->settings(['encryption' => Settings::ENCRYPTION_SSL, 'port' => 636]);
        $none = $this->settings(['encryption' => Settings::ENCRYPTION_NONE]);

        $this->assertTrue($ssl->useSsl());
        $this->assertFalse($ssl->useStartTls());
        $this->assertFalse($none->useSsl());
        $this->assertFalse($none->useStartTls());
    }
}
