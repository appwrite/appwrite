<?php

namespace Tests\E2E\Services\Distribution;

use Appwrite\ID;
use Tests\E2E\Client;
use Utopia\Database\Query;
use Utopia\Distribution\Signing\Certificate;
use Utopia\Distribution\Signing\Keystore;
use Utopia\Distribution\Tests\Signing\Legacy;

trait SigningBase
{
    private const string TEAM = 'ABCDE12345';

    private const string ALIAS = 'upload';

    private const int DAY = 86400;

    public function testCreateAndroidSigningIdentity(): void
    {
        $keystore = $this->keystore();

        $created = $this->createAndroidSigningIdentity([
            'keystore' => \base64_encode($keystore->archive),
            'storePassword' => $keystore->password,
            'keyPassword' => $keystore->password,
        ]);

        /**
         * Test for SUCCESS
         */
        $this->assertSame(201, $created['headers']['status-code']);
        $this->assertSame('android', $created['body']['platform']);
        $this->assertSame('imported', $created['body']['mode']);
        $this->assertSame('active', $created['body']['status']);
        $this->assertSame(64, \strlen($created['body']['fingerprint']));
        $this->assertSame($keystore->certificate->fingerprint(), $created['body']['fingerprint']);
        $this->assertSame($keystore->certificate->expiresAt()->getTimestamp(), \strtotime($created['body']['expiresAt']));
        $this->assertGreaterThan(\strtotime('+29 years'), \strtotime($created['body']['expiresAt']));
        $this->assertSame('', $created['body']['teamId']);
        $this->assertSame([], $created['body']['identifiers']);
        $this->assertSame('', $created['body']['connectionId']);
        $this->assertArrayNotHasKey('secret', $created['body']);

        $jks = $this->createAndroidSigningIdentity([
            'keystore' => \base64_encode(Keystore::JKS . \random_bytes(64)),
            'storePassword' => 'store-password',
            'keyPassword' => 'key-password',
        ]);

        $this->assertSame(201, $jks['headers']['status-code']);
        $this->assertSame('', $jks['body']['fingerprint']);
        $this->assertNull($jks['body']['expiresAt']);

        /**
         * Test for FAILURE
         */
        $failures = [
            'jceks' => [
                'keystore' => \base64_encode(Keystore::JCEKS . \random_bytes(64)),
            ],
            'wrong password' => [
                'storePassword' => 'wrong',
                'keyPassword' => 'wrong',
            ],
            'mismatched key password' => [
                'keyPassword' => 'other',
            ],
            'malformed base64' => [
                'keystore' => 'not base64!',
            ],
            'oversized' => [
                'keystore' => \base64_encode(\random_bytes(500_001)),
            ],
        ];

        foreach ($failures as $case => $params) {
            $failed = $this->createAndroidSigningIdentity($params);

            $this->assertSame(400, $failed['headers']['status-code'], $case);
            $this->assertSame('distribution_signing_identity_invalid', $failed['body']['type'], $case);
        }

        $legacy = $this->createAndroidSigningIdentity([
            'keystore' => \base64_encode(Legacy::pkcs12('secret')),
            'storePassword' => 'secret',
            'keyPassword' => 'secret',
        ]);

        $this->assertSame(400, $legacy['headers']['status-code']);
        $this->assertSame('distribution_signing_identity_invalid', $legacy['body']['type']);
        $this->assertStringContainsString('AES-256', $legacy['body']['message']);

        $undecoded = $this->createAndroidSigningIdentity([
            'keystore' => \str_repeat('!', 700_000),
        ]);

        $this->assertSame(400, $undecoded['headers']['status-code']);
        $this->assertSame('distribution_signing_identity_invalid', $undecoded['body']['type']);
        $this->assertStringContainsString('must not exceed', $undecoded['body']['message']);

        $duplicate = $this->createAndroidSigningIdentity(['signingIdentityId' => $created['body']['$id']]);

        $this->assertSame(409, $duplicate['headers']['status-code']);
        $this->assertSame('distribution_signing_identity_already_exists', $duplicate['body']['type']);
    }

    public function testCreateIosSigningIdentity(): void
    {
        $identity = $this->identity();
        $soon = \time() + 100 * self::DAY;

        $created = $this->createIosSigningIdentity([
            'certificate' => \base64_encode($identity['archive']),
            'password' => $identity['password'],
            'profiles' => [
                \base64_encode($this->profile($identity['certificate'], 'io.appwrite.first')),
                \base64_encode($this->profile($identity['certificate'], 'io.appwrite.second', expiresAt: $soon)),
            ],
        ]);

        /**
         * Test for SUCCESS
         */
        $this->assertSame(201, $created['headers']['status-code']);
        $this->assertSame('ios', $created['body']['platform']);
        $this->assertSame('imported', $created['body']['mode']);
        $this->assertSame('active', $created['body']['status']);
        $this->assertSame(self::TEAM, $created['body']['teamId']);
        $this->assertSame(['io.appwrite.first', 'io.appwrite.second'], $created['body']['identifiers']);
        $this->assertSame($identity['certificate']->fingerprint(), $created['body']['fingerprint']);
        $this->assertSame($soon, \strtotime($created['body']['expiresAt']));
        $this->assertArrayNotHasKey('secret', $created['body']);

        /**
         * Test for FAILURE
         */
        $failures = [
            'profile without the certificate' => [
                $this->profile($this->identity()['certificate'], 'io.appwrite.other'),
            ],
            'profile of another team' => [
                $this->profile($identity['certificate'], 'io.appwrite.team', team: 'ZYXWV98765'),
            ],
            'ad hoc profile' => [
                $this->profile($identity['certificate'], 'io.appwrite.devices', devices: true),
            ],
            'expired profile' => [
                $this->profile($identity['certificate'], 'io.appwrite.expired', expiresAt: \time() - self::DAY),
            ],
            'duplicate bundle ID' => [
                $this->profile($identity['certificate'], 'io.appwrite.same'),
                $this->profile($identity['certificate'], 'io.appwrite.same'),
            ],
        ];

        foreach ($failures as $case => $profiles) {
            $failed = $this->createIosSigningIdentity([
                'certificate' => \base64_encode($identity['archive']),
                'password' => $identity['password'],
                'profiles' => \array_map(\base64_encode(...), $profiles),
            ]);

            $this->assertSame(400, $failed['headers']['status-code'], $case);
            $this->assertSame('distribution_signing_identity_invalid', $failed['body']['type'], $case);
        }

        $wrongPassword = $this->createIosSigningIdentity([
            'certificate' => \base64_encode($identity['archive']),
            'password' => 'wrong',
            'profiles' => [\base64_encode($this->profile($identity['certificate'], 'io.appwrite.password'))],
        ]);

        $this->assertSame(400, $wrongPassword['headers']['status-code']);
        $this->assertSame('distribution_signing_identity_invalid', $wrongPassword['body']['type']);

        $undecoded = $this->createIosSigningIdentity([
            'certificate' => \str_repeat('!', 700_000),
            'password' => $identity['password'],
            'profiles' => [\base64_encode($this->profile($identity['certificate'], 'io.appwrite.oversized'))],
        ]);

        $this->assertSame(400, $undecoded['headers']['status-code']);
        $this->assertSame('distribution_signing_identity_invalid', $undecoded['body']['type']);
        $this->assertStringContainsString('must not exceed', $undecoded['body']['message']);

        $duplicate = $this->createIosSigningIdentity(['signingIdentityId' => $created['body']['$id']]);

        $this->assertSame(409, $duplicate['headers']['status-code']);
        $this->assertSame('distribution_signing_identity_already_exists', $duplicate['body']['type']);
    }

    public function testListSigningIdentities(): void
    {
        $android = $this->createAndroidSigningIdentity();
        $ios = $this->createIosSigningIdentity();
        $this->assertSame(201, $android['headers']['status-code']);
        $this->assertSame(201, $ios['headers']['status-code']);

        /**
         * Test for SUCCESS
         */
        $listed = $this->client->call(Client::METHOD_GET, '/distribution/signing-identities', $this->headers(), [
            'queries' => [Query::equal('platform', ['ios'])->toString()],
        ]);

        $this->assertSame(200, $listed['headers']['status-code']);
        $this->assertGreaterThanOrEqual(1, $listed['body']['total']);

        $ids = \array_column($listed['body']['signingIdentities'], '$id');
        $this->assertContains($ios['body']['$id'], $ids);
        $this->assertNotContains($android['body']['$id'], $ids);

        foreach ($listed['body']['signingIdentities'] as $signingIdentity) {
            $this->assertSame('ios', $signingIdentity['platform']);
            $this->assertArrayNotHasKey('secret', $signingIdentity);
        }

        /**
         * Test for FAILURE
         */
        $invalid = $this->client->call(Client::METHOD_GET, '/distribution/signing-identities', $this->headers(), [
            'queries' => [Query::equal('secret', ['x'])->toString()],
        ]);

        $this->assertSame(400, $invalid['headers']['status-code']);
    }

    public function testGetSigningIdentity(): void
    {
        $created = $this->createAndroidSigningIdentity();
        $this->assertSame(201, $created['headers']['status-code']);

        /**
         * Test for SUCCESS
         */
        $fetched = $this->client->call(Client::METHOD_GET, '/distribution/signing-identities/' . $created['body']['$id'], $this->headers());

        $this->assertSame(200, $fetched['headers']['status-code']);
        $this->assertSame($created['body']['name'], $fetched['body']['name']);
        $this->assertSame($created['body']['fingerprint'], $fetched['body']['fingerprint']);
        $this->assertArrayNotHasKey('secret', $fetched['body']);

        /**
         * Test for FAILURE
         */
        $missing = $this->client->call(Client::METHOD_GET, '/distribution/signing-identities/missing', $this->headers());

        $this->assertSame(404, $missing['headers']['status-code']);
        $this->assertSame('distribution_signing_identity_not_found', $missing['body']['type']);
    }

    public function testUpdateSigningIdentity(): void
    {
        $created = $this->createAndroidSigningIdentity();
        $this->assertSame(201, $created['headers']['status-code']);
        $path = '/distribution/signing-identities/' . $created['body']['$id'];

        /**
         * Test for SUCCESS
         */
        $renamed = $this->client->call(Client::METHOD_PATCH, $path, $this->headers(), [
            'name' => 'Renamed keystore',
        ]);

        $this->assertSame(200, $renamed['headers']['status-code']);
        $this->assertSame('Renamed keystore', $renamed['body']['name']);
        $this->assertSame($created['body']['fingerprint'], $renamed['body']['fingerprint']);

        $fetched = $this->client->call(Client::METHOD_GET, $path, $this->headers());
        $this->assertSame('Renamed keystore', $fetched['body']['name']);

        /**
         * Test for FAILURE
         */
        $rotated = $this->client->call(Client::METHOD_PATCH, $path, $this->headers(), [
            'rotate' => true,
        ]);

        $this->assertSame(400, $rotated['headers']['status-code']);
        $this->assertSame('distribution_signing_identity_invalid', $rotated['body']['type']);

        $empty = $this->client->call(Client::METHOD_PATCH, $path, $this->headers());

        $this->assertSame(400, $empty['headers']['status-code']);
        $this->assertSame('general_argument_invalid', $empty['body']['type']);

        $missing = $this->client->call(Client::METHOD_PATCH, '/distribution/signing-identities/missing', $this->headers(), [
            'name' => 'Missing',
        ]);

        $this->assertSame(404, $missing['headers']['status-code']);
        $this->assertSame('distribution_signing_identity_not_found', $missing['body']['type']);
    }

    public function testDeleteSigningIdentity(): void
    {
        $created = $this->createAndroidSigningIdentity();
        $this->assertSame(201, $created['headers']['status-code']);
        $path = '/distribution/signing-identities/' . $created['body']['$id'];

        /**
         * Test for SUCCESS
         */
        $deleted = $this->client->call(Client::METHOD_DELETE, $path, $this->headers());

        $this->assertSame(204, $deleted['headers']['status-code']);

        $fetched = $this->client->call(Client::METHOD_GET, $path, $this->headers());
        $this->assertSame(404, $fetched['headers']['status-code']);

        /**
         * Test for FAILURE
         */
        $missing = $this->client->call(Client::METHOD_DELETE, '/distribution/signing-identities/missing', $this->headers());

        $this->assertSame(404, $missing['headers']['status-code']);
        $this->assertSame('distribution_signing_identity_not_found', $missing['body']['type']);
    }

    public function testDeleteSigningIdentityInUse(): void
    {
        $app = $this->createApp();
        $this->assertSame(201, $app['headers']['status-code']);

        $created = $this->createAndroidSigningIdentity();
        $this->assertSame(201, $created['headers']['status-code']);

        $target = $this->createTarget($app['body']['$id'], [
            'signingIdentityId' => $created['body']['$id'],
        ]);
        $this->assertSame(201, $target['headers']['status-code']);

        /**
         * Test for FAILURE
         */
        $deleted = $this->client->call(Client::METHOD_DELETE, '/distribution/signing-identities/' . $created['body']['$id'], $this->headers());

        $this->assertSame(409, $deleted['headers']['status-code']);
        $this->assertSame('distribution_signing_identity_in_use', $deleted['body']['type']);

        $fetched = $this->client->call(Client::METHOD_GET, '/distribution/signing-identities/' . $created['body']['$id'], $this->headers());
        $this->assertSame(200, $fetched['headers']['status-code']);

        /**
         * Test for SUCCESS
         */
        $appDeleted = $this->client->call(Client::METHOD_DELETE, '/distribution/apps/' . $app['body']['$id'], $this->headers());
        $this->assertSame(204, $appDeleted['headers']['status-code']);

        $released = $this->client->call(Client::METHOD_DELETE, '/distribution/signing-identities/' . $created['body']['$id'], $this->headers());
        $this->assertSame(204, $released['headers']['status-code'], 'A deleted app kept its signing identity in use.');
    }

    public function testGetSigningIdentityDownload(): void
    {
        $keystore = $this->keystore();
        $android = $this->createAndroidSigningIdentity([
            'keystore' => \base64_encode($keystore->archive),
            'storePassword' => $keystore->password,
            'keyPassword' => $keystore->password,
        ]);
        $this->assertSame(201, $android['headers']['status-code']);

        /**
         * Test for SUCCESS
         */
        $download = $this->client->call(Client::METHOD_GET, '/distribution/signing-identities/' . $android['body']['$id'] . '/download', $this->headers());

        $this->assertSame(200, $download['headers']['status-code']);
        $this->assertStringContainsString('no-store', $download['headers']['cache-control']);
        $this->assertCount(1, $download['body']['files']);
        $this->assertSame('keystore.p12', $download['body']['files'][0]['name']);
        $this->assertSame($keystore->archive, \base64_decode($download['body']['files'][0]['content'], true));
        $this->assertSame($keystore->password, $download['body']['passwords']['storePassword']);
        $this->assertSame($keystore->password, $download['body']['passwords']['keyPassword']);
        $this->assertSame(self::ALIAS, $download['body']['alias']);

        $jks = $this->createAndroidSigningIdentity([
            'keystore' => \base64_encode(Keystore::JKS . \random_bytes(64)),
        ]);
        $this->assertSame(201, $jks['headers']['status-code']);

        $jksDownload = $this->client->call(Client::METHOD_GET, '/distribution/signing-identities/' . $jks['body']['$id'] . '/download', $this->headers());

        $this->assertSame(200, $jksDownload['headers']['status-code']);
        $this->assertSame('keystore.jks', $jksDownload['body']['files'][0]['name']);

        $identity = $this->identity();
        $ios = $this->createIosSigningIdentity([
            'certificate' => \base64_encode($identity['archive']),
            'password' => $identity['password'],
            'profiles' => [\base64_encode($this->profile($identity['certificate'], 'io.appwrite.download'))],
        ]);
        $this->assertSame(201, $ios['headers']['status-code']);

        $iosDownload = $this->client->call(Client::METHOD_GET, '/distribution/signing-identities/' . $ios['body']['$id'] . '/download', $this->headers());

        $this->assertSame(200, $iosDownload['headers']['status-code']);
        $this->assertStringContainsString('no-store', $iosDownload['headers']['cache-control']);
        $this->assertSame(['certificate.p12', 'io.appwrite.download.mobileprovision'], \array_column($iosDownload['body']['files'], 'name'));
        $this->assertSame($identity['archive'], \base64_decode($iosDownload['body']['files'][0]['content'], true));
        $this->assertSame($identity['password'], $iosDownload['body']['passwords']['certificate']);
        $this->assertSame('', $iosDownload['body']['alias']);

        /**
         * Test for FAILURE
         */
        $read = [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getNewKey(['distribution.credentials.read']),
        ];

        $readable = $this->client->call(Client::METHOD_GET, '/distribution/signing-identities/' . $android['body']['$id'], $read);
        $this->assertSame(200, $readable['headers']['status-code']);

        $refused = $this->client->call(Client::METHOD_GET, '/distribution/signing-identities/' . $android['body']['$id'] . '/download', $read);
        $this->assertSame(401, $refused['headers']['status-code']);
        $this->assertSame('general_unauthorized_scope', $refused['body']['type']);

        $missing = $this->client->call(Client::METHOD_GET, '/distribution/signing-identities/missing/download', $this->headers());

        $this->assertSame(404, $missing['headers']['status-code']);
        $this->assertSame('distribution_signing_identity_not_found', $missing['body']['type']);
    }

    public function testCreateTargetWithSigningIdentity(): void
    {
        $app = $this->createApp();
        $this->assertSame(201, $app['headers']['status-code']);

        $android = $this->createAndroidSigningIdentity();
        $this->assertSame(201, $android['headers']['status-code']);

        /**
         * Test for SUCCESS
         */
        $target = $this->createTarget($app['body']['$id'], [
            'platform' => 'android',
            'signingIdentityId' => $android['body']['$id'],
        ]);

        $this->assertSame(201, $target['headers']['status-code']);
        $this->assertSame($android['body']['$id'], $target['body']['signingIdentityId']);

        /**
         * Test for FAILURE
         */
        $mismatched = $this->createTarget($app['body']['$id'], [
            'platform' => 'ios',
            'identifier' => 'io.appwrite.t' . \uniqid(),
            'signingIdentityId' => $android['body']['$id'],
        ]);

        $this->assertSame(400, $mismatched['headers']['status-code']);
        $this->assertSame('general_argument_invalid', $mismatched['body']['type']);
    }

    public function testDistributionFoundations(): void
    {
        $app = $this->createApp(['framework' => 'flutter']);
        $this->assertSame(201, $app['headers']['status-code']);
        $appId = $app['body']['$id'];

        $android = $this->createTarget($appId, ['platform' => 'android']);
        $this->assertSame(201, $android['headers']['status-code']);

        $ios = $this->createTarget($appId, [
            'platform' => 'ios',
            'identifier' => 'io.appwrite.t' . \uniqid(),
        ]);
        $this->assertSame(201, $ios['headers']['status-code']);

        $connection = $this->createGoogleConnection();
        $this->assertSame(201, $connection['headers']['status-code']);
        $this->assertSame('google', $connection['body']['provider']);
        $this->assertSame('active', $connection['body']['status']);

        $keystore = $this->keystore();
        $signingIdentity = $this->createAndroidSigningIdentity([
            'keystore' => \base64_encode($keystore->archive),
            'storePassword' => $keystore->password,
            'keyPassword' => $keystore->password,
        ]);
        $this->assertSame(201, $signingIdentity['headers']['status-code']);
        $this->assertSame($keystore->certificate->fingerprint(), $signingIdentity['body']['fingerprint']);

        $path = '/distribution/apps/' . $appId . '/targets/' . $android['body']['$id'];

        $updated = $this->client->call(Client::METHOD_PATCH, $path, $this->headers(), [
            'connectionId' => $connection['body']['$id'],
            'signingIdentityId' => $signingIdentity['body']['$id'],
        ]);

        $this->assertSame(200, $updated['headers']['status-code']);
        $this->assertSame($connection['body']['$id'], $updated['body']['connectionId']);
        $this->assertSame($signingIdentity['body']['$id'], $updated['body']['signingIdentityId']);

        $fetched = $this->client->call(Client::METHOD_GET, $path, $this->headers());

        $this->assertSame(200, $fetched['headers']['status-code']);
        $this->assertSame($connection['body']['$id'], $fetched['body']['connectionId']);
        $this->assertSame($signingIdentity['body']['$id'], $fetched['body']['signingIdentityId']);
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    protected function createAndroidSigningIdentity(array $params = []): array
    {
        if (!isset($params['keystore'])) {
            $keystore = $this->keystore();
            $params += [
                'keystore' => \base64_encode($keystore->archive),
                'storePassword' => $keystore->password,
                'keyPassword' => $keystore->password,
            ];
        }

        return $this->client->call(Client::METHOD_POST, '/distribution/signing-identities/android', $this->headers(), \array_merge([
            'signingIdentityId' => ID::unique(),
            'name' => 'Keystore ' . \uniqid(),
            'storePassword' => 'password',
            'keyAlias' => self::ALIAS,
            'keyPassword' => 'password',
        ], $params));
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    protected function createIosSigningIdentity(array $params = []): array
    {
        if (!isset($params['certificate'])) {
            $identity = $this->identity();
            $params += [
                'certificate' => \base64_encode($identity['archive']),
                'password' => $identity['password'],
                'profiles' => [\base64_encode($this->profile($identity['certificate'], 'io.appwrite.t' . \uniqid()))],
            ];
        }

        return $this->client->call(Client::METHOD_POST, '/distribution/signing-identities/ios', $this->headers(), \array_merge([
            'signingIdentityId' => ID::unique(),
            'name' => 'Certificate ' . \uniqid(),
        ], $params));
    }

    private function keystore(): Keystore
    {
        return Keystore::generate(self::ALIAS, ['CN' => 'Appwrite Test']);
    }

    /**
     * @return array{archive: string, password: string, certificate: Certificate}
     */
    private function identity(string $team = self::TEAM): array
    {
        $key = Certificate::key();
        $certificate = $this->certificate($key, [
            'commonName' => 'Apple Distribution: Appwrite (' . $team . ')',
            'organizationalUnitName' => $team,
        ]);
        $password = \bin2hex(\random_bytes(12));

        return [
            'archive' => $certificate->export($key, $password, 'distribution'),
            'password' => $password,
            'certificate' => $certificate,
        ];
    }

    /**
     * @param array<string, string> $subject
     */
    private function certificate(string $key, array $subject): Certificate
    {
        $signed = \openssl_csr_sign(Certificate::request($key, $subject), null, $key, 365, ['digest_alg' => 'sha256', 'config' => Certificate::CONFIG], \random_int(1, PHP_INT_MAX));
        $this->assertNotFalse($signed);

        $pem = null;
        $this->assertTrue(\openssl_x509_export($signed, $pem));
        $this->assertIsString($pem);

        return new Certificate($pem);
    }

    private function profile(Certificate $certificate, string $identifier, string $team = self::TEAM, bool $devices = false, ?int $expiresAt = null): string
    {
        $developer = \base64_encode($certificate->der());
        $expiration = \gmdate('Y-m-d\TH:i:s\Z', $expiresAt ?? \time() + 200 * self::DAY);
        $uuid = \vsprintf('%s%s-%s-%s-%s-%s%s%s', \str_split(\bin2hex(\random_bytes(16)), 4));
        $extra = $devices ? '<key>ProvisionedDevices</key><array><string>00008030-001A2B3C4D5E6F70</string></array>' : '';

        $plist = <<<XML
            <?xml version="1.0" encoding="UTF-8"?>
            <plist version="1.0">
            <dict>
            	<key>DeveloperCertificates</key><array><data>{$developer}</data></array>
            	<key>Entitlements</key><dict>
            		<key>application-identifier</key><string>{$team}.{$identifier}</string>
            		<key>com.apple.developer.team-identifier</key><string>{$team}</string>
            		<key>get-task-allow</key><false/>
            	</dict>
            	<key>ExpirationDate</key><date>{$expiration}</date>
            	<key>Name</key><string>Appwrite {$identifier}</string>
            	<key>TeamIdentifier</key><array><string>{$team}</string></array>
            	<key>UUID</key><string>{$uuid}</string>
            	{$extra}
            </dict>
            </plist>
            XML;

        $key = Certificate::key();
        $signer = $this->certificate($key, ['commonName' => 'Apple iPhone OS Provisioning Profile Signing']);

        $input = \tempnam(\sys_get_temp_dir(), 'plist');
        $output = \tempnam(\sys_get_temp_dir(), 'profile');
        $this->assertIsString($input);
        $this->assertIsString($output);

        try {
            \file_put_contents($input, $plist);
            $this->assertTrue(\openssl_cms_sign($input, $output, $signer->pem, $key, null, OPENSSL_CMS_BINARY, OPENSSL_ENCODING_DER));

            $envelope = \file_get_contents($output);
            $this->assertIsString($envelope);

            return $envelope;
        } finally {
            \unlink($input);
            \unlink($output);
        }
    }
}
