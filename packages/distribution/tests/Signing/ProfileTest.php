<?php

declare(strict_types=1);

namespace Utopia\Distribution\Tests\Signing;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Utopia\Distribution\Exception\Invalid;
use Utopia\Distribution\Signing\Certificate;
use Utopia\Distribution\Signing\Profile;

final class ProfileTest extends TestCase
{
    private const string TEAM = 'ABCDE12345';

    private const string IDENTIFIER = 'io.example.app';

    private const string PREFIX = 'XYZ9876543';

    private const string UUID = '0f1e2d3c-4b5a-6978-8796-a5b4c3d2e1f0';

    private const string NAME = 'Example App Store & TestFlight';

    private const string EXPIRATION = '2027-11-17T00:00:00Z';

    private const string DEVICES = '<key>ProvisionedDevices</key><array><string>00008030-001A2B3C4D5E6F70</string></array>';

    private Certificate $identity;

    private Certificate $signer;

    private string $key;

    protected function setUp(): void
    {
        $this->key = Certificate::key();
        $this->identity = $this->certificate(Certificate::key(), ['commonName' => 'Apple Distribution: Example (' . self::TEAM . ')', 'organizationalUnitName' => self::TEAM]);
        $this->signer = $this->certificate($this->key, ['commonName' => 'Apple iPhone OS Provisioning Profile Signing']);
    }

    public function testParseReadsEveryField(): void
    {
        $profile = Profile::parse($this->envelope($this->plist()));

        $this->assertSame(self::NAME, $profile->name);
        $this->assertSame(self::UUID, $profile->uuid);
        $this->assertSame(self::TEAM, $profile->team);
        $this->assertSame(self::IDENTIFIER, $profile->identifier);
        $this->assertSame(Profile::TYPE_APP_STORE, $profile->type);
        $this->assertEquals(new \DateTimeImmutable(self::EXPIRATION), $profile->expiresAt);
        $this->assertSame(
            [
                'application-identifier' => self::TEAM . '.' . self::IDENTIFIER,
                'com.apple.developer.team-identifier' => self::TEAM,
                'aps-environment' => 'production',
                'keychain-access-groups' => [self::TEAM . '.*'],
                'get-task-allow' => false,
            ],
            $profile->entitlements,
        );
        $this->assertSame([$this->identity->fingerprint()], $profile->fingerprints);
    }

    public function testContainsOnlyItsDeveloperCertificates(): void
    {
        $profile = Profile::parse($this->envelope($this->plist()));

        $this->assertTrue($profile->contains($this->identity));
        $this->assertFalse($profile->contains($this->signer));
    }

    public function testTeamFallsBackToTeamIdentifier(): void
    {
        $plist = str_replace('<key>com.apple.developer.team-identifier</key><string>' . self::TEAM . '</string>', '', $this->plist());

        $profile = Profile::parse($this->envelope($plist));

        $this->assertSame(self::TEAM, $profile->team);
        $this->assertSame(self::IDENTIFIER, $profile->identifier);
    }

    public function testIdentifierStripsApplicationIdentifierPrefix(): void
    {
        $plist = str_replace(
            '<string>' . self::TEAM . '.' . self::IDENTIFIER . '</string>',
            '<string>' . self::PREFIX . '.' . self::IDENTIFIER . '</string>',
            $this->plist('<key>ApplicationIdentifierPrefix</key><array><string>' . self::PREFIX . '</string></array>'),
        );

        $profile = Profile::parse($this->envelope($plist));

        $this->assertSame(self::TEAM, $profile->team);
        $this->assertSame(self::IDENTIFIER, $profile->identifier);
        $this->assertSame(self::PREFIX . '.' . self::IDENTIFIER, $profile->entitlements['application-identifier']);
    }

    public function testIdentifierFallsBackToTeamWithoutPrefix(): void
    {
        $plist = str_replace(
            '<string>' . self::TEAM . '.' . self::IDENTIFIER . '</string>',
            '<string>' . self::PREFIX . '.' . self::IDENTIFIER . '</string>',
            $this->plist(),
        );

        $this->assertSame(self::PREFIX . '.' . self::IDENTIFIER, Profile::parse($this->envelope($plist))->identifier);
    }

    public function testEntitlementDataIsBase64(): void
    {
        $plist = str_replace(
            '<key>get-task-allow</key>',
            "<key>com.example.blob</key><data>\n\t\tAAEC\n\t\t/w==\n\t</data><key>get-task-allow</key>",
            $this->plist(),
        );

        $profile = Profile::parse($this->envelope($plist));

        $this->assertSame(base64_encode("\x00\x01\x02\xFF"), $profile->entitlements['com.example.blob']);
        $this->assertJson(json_encode($profile->entitlements, JSON_THROW_ON_ERROR));
        $this->assertSame([$this->identity->fingerprint()], $profile->fingerprints);
    }

    /**
     * @return iterable<string, array{string, bool, string}>
     */
    public static function types(): iterable
    {
        yield 'app store' => ['', false, Profile::TYPE_APP_STORE];
        yield 'development' => [self::DEVICES, true, Profile::TYPE_DEVELOPMENT];
        yield 'ad hoc' => [self::DEVICES, false, Profile::TYPE_AD_HOC];
        yield 'enterprise' => ['<key>ProvisionsAllDevices</key><true/>', false, Profile::TYPE_ENTERPRISE];
    }

    #[DataProvider('types')]
    public function testTypeDetection(string $extra, bool $debuggable, string $type): void
    {
        $this->assertSame($type, Profile::parse($this->envelope($this->plist($extra, $debuggable)))->type);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function envelopes(): iterable
    {
        yield 'empty' => [''];
        yield 'garbage' => ['not a profile'];
        yield 'bare property list' => ['<?xml version="1.0" encoding="UTF-8"?><plist version="1.0"><dict/></plist>'];
    }

    #[DataProvider('envelopes')]
    public function testMalformedEnvelopeThrows(string $envelope): void
    {
        $this->expectException(Invalid::class);

        Profile::parse($envelope);
    }

    public function testTamperedEnvelopeThrows(): void
    {
        $envelope = $this->envelope($this->plist());
        $tampered = str_replace(self::IDENTIFIER, 'io.attacker.app', $envelope);
        $this->assertNotSame($envelope, $tampered);

        $this->expectException(Invalid::class);

        Profile::parse($tampered);
    }

    public function testMissingFieldThrows(): void
    {
        $plist = str_replace('<key>UUID</key><string>' . self::UUID . '</string>', '', $this->plist());

        $this->expectException(Invalid::class);
        $this->expectExceptionMessage('UUID');

        Profile::parse($this->envelope($plist));
    }

    public function testMalformedDataThrows(): void
    {
        $plist = str_replace('<key>DeveloperCertificates</key><array>', '<key>DeveloperCertificates</key><array><data>!!!</data>', $this->plist());

        $this->expectException(Invalid::class);

        Profile::parse($this->envelope($plist));
    }

    public function testExternalEntityIsNotExpanded(): void
    {
        $secret = tempnam(sys_get_temp_dir(), 'secret');
        $this->assertIsString($secret);

        try {
            file_put_contents($secret, 'TOP-SECRET-CONTENT');
            $plist = str_replace(
                ['<plist version="1.0">', '<string>' . self::NAME . '</string>'],
                ['<!DOCTYPE plist [<!ENTITY secret SYSTEM "file://' . $secret . '">]><plist version="1.0">', '<string>&secret;</string>'],
                $this->plist(),
            );

            try {
                Profile::parse($this->envelope($plist));
                $this->fail('A property list declaring an entity must be rejected');
            } catch (Invalid $invalid) {
                $this->assertStringNotContainsString('TOP-SECRET-CONTENT', $invalid->getMessage());
            }
        } finally {
            unlink($secret);
        }
    }

    public function testUnsupportedTypeThrows(): void
    {
        $this->expectException(Invalid::class);

        new Profile(self::NAME, self::UUID, self::TEAM, self::IDENTIFIER, 'unknown', new \DateTimeImmutable(), [], []);
    }

    private function plist(string $extra = '', bool $debuggable = false): string
    {
        $debug = $debuggable ? '<true/>' : '<false/>';
        $certificate = chunk_split(base64_encode($this->identity->der()), 52, "\n\t\t");
        $name = htmlspecialchars(self::NAME, ENT_XML1);

        return <<<XML
            <?xml version="1.0" encoding="UTF-8"?>
            <!-- comment -->
            <plist version="1.0">
            <dict>
            	<key>AppIDName</key><string>Example</string>
            	<key>CreationDate</key><date>2026-11-17T00:00:00Z</date>
            	<key>DeveloperCertificates</key><array>
            		<data>
            		{$certificate}</data>
            	</array>
            	<key>Entitlements</key><dict>
            		<key>application-identifier</key><string>ABCDE12345.io.example.app</string>
            		<key>com.apple.developer.team-identifier</key><string>ABCDE12345</string>
            		<key>aps-environment</key><string>production</string>
            		<key>keychain-access-groups</key><array><string>ABCDE12345.*</string></array>
            		<key>get-task-allow</key>{$debug}
            	</dict>
            	<key>ExpirationDate</key><date>2027-11-17T00:00:00Z</date>
            	<key>Name</key><string>{$name}</string>
            	<key>TeamIdentifier</key><array><string>ABCDE12345</string></array>
            	<key>TeamName</key><string>Example</string>
            	<key>TimeToLive</key><integer>365</integer>
            	<key>UUID</key><string>0f1e2d3c-4b5a-6978-8796-a5b4c3d2e1f0</string>
            	<key>Version</key><integer>1</integer>
            	{$extra}
            </dict>
            </plist>

            XML;
    }

    private function envelope(string $plist): string
    {
        $input = tempnam(sys_get_temp_dir(), 'plist');
        $output = tempnam(sys_get_temp_dir(), 'envelope');
        $this->assertIsString($input);
        $this->assertIsString($output);

        try {
            file_put_contents($input, $plist);
            $this->assertTrue(openssl_cms_sign($input, $output, $this->signer->pem, $this->key, null, OPENSSL_CMS_BINARY, OPENSSL_ENCODING_DER));

            $envelope = file_get_contents($output);
            $this->assertIsString($envelope);

            return $envelope;
        } finally {
            unlink($input);
            unlink($output);
        }
    }

    /**
     * @param array<string, string> $subject
     */
    private function certificate(string $key, array $subject): Certificate
    {
        $options = ['digest_alg' => 'sha256', 'config' => Certificate::CONFIG];
        $signed = openssl_csr_sign(Certificate::request($key, $subject), null, $key, 365, $options, random_int(1, PHP_INT_MAX));
        $this->assertNotFalse($signed);

        $pem = null;
        $this->assertTrue(openssl_x509_export($signed, $pem));
        $this->assertIsString($pem);

        return new Certificate($pem);
    }
}
