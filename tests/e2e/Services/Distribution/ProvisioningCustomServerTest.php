<?php

declare(strict_types=1);

namespace Tests\E2E\Services\Distribution;

use Appwrite\ID;
use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideServer;
use Utopia\Distribution\Signing\Certificate;
use Utopia\Distribution\Signing\Profile;
use Utopia\Distribution\Store;

final class ProvisioningCustomServerTest extends Scope
{
    use DistributionBase;
    use MockStores;
    use ProjectCustom;
    use SideServer;

    private const int DAY = 86400;

    private const int RENEWAL_TIMEOUT_MS = 180_000;

    private const int DELIVERY_TIMEOUT_MS = 30_000;

    private const string LIMIT = 'Import an existing certificate';

    public function testCreateManagedIosSigningIdentity(): void
    {
        $team = $this->prepareAppleTeam();
        $first = $this->identifier();
        $second = $this->identifier();

        $created = $this->createManagedIosSigningIdentity($team['connectionId'], [$first, $second]);

        /**
         * Test for SUCCESS
         */
        $this->assertSame(201, $created['headers']['status-code']);
        $this->assertSame('ios', $created['body']['platform']);
        $this->assertSame('managed', $created['body']['mode']);
        $this->assertSame('active', $created['body']['status']);
        $this->assertSame($team['teamId'], $created['body']['teamId']);
        $this->assertSame([$first, $second], $created['body']['identifiers']);
        $this->assertSame($team['connectionId'], $created['body']['connectionId']);
        $this->assertSame(64, \strlen($created['body']['fingerprint']));
        $this->assertTrue(\ctype_xdigit($created['body']['fingerprint']));
        $this->assertNotNull($created['body']['expiresAt']);
        $this->assertGreaterThan(\time() + 300 * self::DAY, \strtotime($created['body']['expiresAt']));
        $this->assertArrayNotHasKey('secret', $created['body']);

        $download = $this->getSigningIdentityDownload($created['body']['$id']);

        $this->assertSame(['certificate.p12', $first . '.mobileprovision', $second . '.mobileprovision'], \array_column($download['files'], 'name'));
        $this->assertNotSame('', $download['passwords']['certificate']);

        $certificate = Certificate::fromPkcs12($this->content($download, 'certificate.p12'), $download['passwords']['certificate']);
        $this->assertSame($created['body']['fingerprint'], $certificate->fingerprint());

        foreach ([$first, $second] as $identifier) {
            $profile = Profile::parse($this->content($download, $identifier . '.mobileprovision'));

            $this->assertSame($identifier, $profile->identifier);
            $this->assertSame($team['teamId'], $profile->team);
            $this->assertSame(Profile::TYPE_APP_STORE, $profile->type);
            $this->assertTrue($profile->contains($certificate), 'The profile for ' . $identifier . ' does not contain the managed certificate.');
        }

        $certificates = $this->certificates($team['key']);
        $this->assertCount(1, $certificates);
        $this->assertSame($created['body']['fingerprint'], $certificates[0]->certificate->fingerprint());

        $third = $this->identifier();
        $shared = $this->createManagedIosSigningIdentity($team['connectionId'], [$third]);

        $this->assertSame(201, $shared['headers']['status-code']);
        $this->assertSame('managed', $shared['body']['mode']);
        $this->assertSame($team['teamId'], $shared['body']['teamId']);
        $this->assertSame([$third], $shared['body']['identifiers']);
        $this->assertSame($created['body']['fingerprint'], $shared['body']['fingerprint'], 'A second identity of the team did not reuse the team certificate.');
        $this->assertCount(1, $this->certificates($team['key']), 'A second identity of the team issued another certificate.');

        $sharedDownload = $this->getSigningIdentityDownload($shared['body']['$id']);
        $sharedProfile = Profile::parse($this->content($sharedDownload, $third . '.mobileprovision'));
        $this->assertTrue($sharedProfile->contains($certificate));

        /**
         * Test for FAILURE
         */
        $failures = [
            'files with a connection' => [
                'certificate' => \base64_encode(\random_bytes(64)),
                'password' => 'password',
                'profiles' => [\base64_encode(\random_bytes(64))],
                'connectionId' => $team['connectionId'],
                'identifiers' => [$this->identifier()],
            ],
            'connection without identifiers' => [
                'connectionId' => $team['connectionId'],
                'identifiers' => [],
            ],
            'repeated identifiers' => [
                'connectionId' => $team['connectionId'],
                'identifiers' => [$first, $first],
            ],
        ];

        foreach ($failures as $case => $params) {
            $failed = $this->client->call(Client::METHOD_POST, '/distribution/signing-identities/ios', $this->headers(), \array_merge([
                'signingIdentityId' => ID::unique(),
                'name' => 'Managed ' . \uniqid(),
            ], $params));

            $this->assertSame(400, $failed['headers']['status-code'], $case);
        }

        $google = $this->createGoogleConnection();
        $this->assertSame(201, $google['headers']['status-code']);

        $wrongProvider = $this->createManagedIosSigningIdentity($google['body']['$id'], [$this->identifier()]);

        $this->assertSame(400, $wrongProvider['headers']['status-code']);
        $this->assertSame('distribution_connection_invalid', $wrongProvider['body']['type']);

        $unknown = $this->createManagedIosSigningIdentity('missing', [$this->identifier()]);

        $this->assertSame(404, $unknown['headers']['status-code']);
        $this->assertSame('distribution_connection_not_found', $unknown['body']['type']);

        $duplicate = $this->createManagedIosSigningIdentity($team['connectionId'], [$this->identifier()], [
            'signingIdentityId' => $created['body']['$id'],
        ]);

        $this->assertSame(409, $duplicate['headers']['status-code']);
        $this->assertSame('distribution_signing_identity_already_exists', $duplicate['body']['type']);

        $full = $this->prepareAppleTeam(['certificates' => 0]);
        $signingIdentityId = ID::unique();

        $limited = $this->createManagedIosSigningIdentity($full['connectionId'], [$this->identifier()], [
            'signingIdentityId' => $signingIdentityId,
        ]);

        $this->assertSame(400, $limited['headers']['status-code']);
        $this->assertSame('distribution_signing_identity_invalid', $limited['body']['type']);
        $this->assertStringContainsString(self::LIMIT, (string) $limited['body']['message']);
        $this->assertCount(0, $this->certificates($full['key']));

        $absent = $this->client->call(Client::METHOD_GET, '/distribution/signing-identities/' . $signingIdentityId, $this->headers());
        $this->assertSame(404, $absent['headers']['status-code'], 'A refused managed create left a signing identity behind.');
    }

    public function testRenewManagedIosSigningIdentity(): void
    {
        $team = $this->prepareAppleTeam(['profileDays' => 10]);
        $identifier = $this->identifier();

        $created = $this->createManagedIosSigningIdentity($team['connectionId'], [$identifier]);

        /**
         * Test for SUCCESS
         */
        $this->assertSame(201, $created['headers']['status-code']);
        $this->assertSame('managed', $created['body']['mode']);
        $this->assertSame('expiring', $created['body']['status']);
        $this->assertGreaterThan(\time() + 9 * self::DAY, \strtotime($created['body']['expiresAt']));
        $this->assertLessThan(\time() + 11 * self::DAY, \strtotime($created['body']['expiresAt']));

        $signingIdentityId = $created['body']['$id'];
        $original = $this->content($this->getSigningIdentityDownload($signingIdentityId), $identifier . '.mobileprovision');
        $originalUuid = Profile::parse($original)->uuid;

        $this->assertSame([$originalUuid], $this->profileUuids($team['key'], $identifier));

        $renewed = '';

        $this->assertEventually(function () use ($signingIdentityId, $identifier, $original, &$renewed): void {
            $renewed = $this->content($this->getSigningIdentityDownload($signingIdentityId), $identifier . '.mobileprovision');
            $this->assertNotSame($original, $renewed, 'The expiring profile has not been renewed yet.');
        }, self::RENEWAL_TIMEOUT_MS, 5000);

        $profile = Profile::parse($renewed);
        $this->assertNotSame($originalUuid, $profile->uuid);
        $this->assertSame($identifier, $profile->identifier);
        $this->assertSame($team['teamId'], $profile->team);

        $download = $this->getSigningIdentityDownload($signingIdentityId);
        $certificate = Certificate::fromPkcs12($this->content($download, 'certificate.p12'), $download['passwords']['certificate']);
        $this->assertTrue($profile->contains($certificate));
        $this->assertSame($created['body']['fingerprint'], $certificate->fingerprint(), 'Renewal replaced the certificate instead of only the profile.');

        $fetched = $this->client->call(Client::METHOD_GET, '/distribution/signing-identities/' . $signingIdentityId, $this->headers());

        $this->assertSame(200, $fetched['headers']['status-code']);
        $this->assertSame('expiring', $fetched['body']['status']);
        $this->assertSame($created['body']['fingerprint'], $fetched['body']['fingerprint']);
        $this->assertGreaterThanOrEqual(\strtotime($created['body']['expiresAt']), \strtotime($fetched['body']['expiresAt']));

        $this->assertSame([$profile->uuid], $this->profileUuids($team['key'], $identifier), 'Renewal left the replaced profile in the store.');
        $this->assertCount(1, $this->certificates($team['key']));
    }

    public function testRotateManagedIosSigningIdentity(): void
    {
        $team = $this->prepareAppleTeam();
        $first = $this->identifier();
        $second = $this->identifier();
        $third = $this->identifier();

        $one = $this->createManagedIosSigningIdentity($team['connectionId'], [$first]);
        $two = $this->createManagedIosSigningIdentity($team['connectionId'], [$second, $third]);
        $this->assertSame(201, $one['headers']['status-code']);
        $this->assertSame(201, $two['headers']['status-code']);
        $this->assertSame($one['body']['fingerprint'], $two['body']['fingerprint']);

        $webhook = $this->client->call(Client::METHOD_POST, '/webhooks', $this->headers(), [
            'webhookId' => ID::unique(),
            'name' => 'Signing identities ' . \uniqid(),
            'events' => ['distributionSigningIdentities.*.update'],
            'url' => 'http://request-catcher-webhook:5000/',
            'tls' => false,
        ]);
        $this->assertSame(201, $webhook['headers']['status-code'], \json_encode($webhook['body']));

        $previous = $one['body']['fingerprint'];
        $before = [
            $first => $this->profileUuids($team['key'], $first),
            $second => $this->profileUuids($team['key'], $second),
            $third => $this->profileUuids($team['key'], $third),
        ];

        /**
         * Test for SUCCESS
         */
        $rotated = $this->client->call(Client::METHOD_PATCH, '/distribution/signing-identities/' . $one['body']['$id'], $this->headers(), [
            'rotate' => true,
        ]);

        $this->assertSame(200, $rotated['headers']['status-code']);
        $this->assertSame('managed', $rotated['body']['mode']);
        $this->assertSame('active', $rotated['body']['status']);
        $this->assertSame([$first], $rotated['body']['identifiers']);
        $this->assertNotSame($previous, $rotated['body']['fingerprint']);
        $this->assertSame(64, \strlen($rotated['body']['fingerprint']));

        $fingerprint = $rotated['body']['fingerprint'];

        $sibling = $this->client->call(Client::METHOD_GET, '/distribution/signing-identities/' . $two['body']['$id'], $this->headers());

        $this->assertSame(200, $sibling['headers']['status-code']);
        $this->assertSame($fingerprint, $sibling['body']['fingerprint'], 'Rotation did not move every managed identity of the team to the new certificate.');
        $this->assertSame([$second, $third], $sibling['body']['identifiers']);
        $this->assertNotSame($two['body']['$updatedAt'], $sibling['body']['$updatedAt']);

        foreach ([$one['body']['$id'], $two['body']['$id']] as $signingIdentityId) {
            $event = 'distributionSigningIdentities.' . $signingIdentityId . '.update';

            $this->assertEventually(function () use ($webhook, $event, $signingIdentityId, $fingerprint): void {
                $deliveries = $this->webhookDeliveries($webhook['body']['$id'], $event);
                $this->assertCount(1, $deliveries, 'Rotation did not emit the update event of ' . $signingIdentityId . '.');

                $payload = $this->decodeRequestData($deliveries[0])['data'] ?? [];
                $this->assertSame($signingIdentityId, $payload['$id'] ?? null);
                $this->assertSame($fingerprint, $payload['fingerprint'] ?? null);
            }, self::DELIVERY_TIMEOUT_MS, 1000);
        }

        $certificates = $this->certificates($team['key']);
        $this->assertCount(1, $certificates, 'Rotation left the old certificate current.');
        $this->assertSame($fingerprint, $certificates[0]->certificate->fingerprint());

        $identities = [
            $one['body']['$id'] => [$first],
            $two['body']['$id'] => [$second, $third],
        ];

        foreach ($identities as $signingIdentityId => $identifiers) {
            $download = $this->getSigningIdentityDownload($signingIdentityId);
            $certificate = Certificate::fromPkcs12($this->content($download, 'certificate.p12'), $download['passwords']['certificate']);
            $this->assertSame($fingerprint, $certificate->fingerprint());

            foreach ($identifiers as $identifier) {
                $profile = Profile::parse($this->content($download, $identifier . '.mobileprovision'));

                $this->assertSame($identifier, $profile->identifier);
                $this->assertTrue($profile->contains($certificate), 'The rotated profile for ' . $identifier . ' does not contain the new certificate.');
                $this->assertNotContains($profile->uuid, $before[$identifier]);
                $this->assertSame([$profile->uuid], $this->profileUuids($team['key'], $identifier), 'Rotation left the old profile for ' . $identifier . ' in the store.');
            }
        }

        /**
         * Test for FAILURE
         */
        $download = $this->getSigningIdentityDownload($one['body']['$id']);
        $imported = $this->client->call(Client::METHOD_POST, '/distribution/signing-identities/ios', $this->headers(), [
            'signingIdentityId' => ID::unique(),
            'name' => 'Imported ' . \uniqid(),
            'certificate' => \base64_encode($this->content($download, 'certificate.p12')),
            'password' => $download['passwords']['certificate'],
            'profiles' => [\base64_encode($this->content($download, $first . '.mobileprovision'))],
        ]);

        $this->assertSame(201, $imported['headers']['status-code']);
        $this->assertSame('imported', $imported['body']['mode']);

        $managedAndroid = $this->client->call(Client::METHOD_POST, '/distribution/signing-identities/android', $this->headers(), [
            'signingIdentityId' => ID::unique(),
            'name' => 'Managed ' . \uniqid(),
        ]);

        $this->assertSame(201, $managedAndroid['headers']['status-code']);
        $this->assertSame('managed', $managedAndroid['body']['mode']);

        foreach (['imported iOS' => $imported, 'managed Android' => $managedAndroid] as $case => $signingIdentity) {
            $refused = $this->client->call(Client::METHOD_PATCH, '/distribution/signing-identities/' . $signingIdentity['body']['$id'], $this->headers(), [
                'rotate' => true,
            ]);

            $this->assertSame(400, $refused['headers']['status-code'], $case);
            $this->assertSame('distribution_signing_identity_invalid', $refused['body']['type'], $case);

            $unchanged = $this->client->call(Client::METHOD_GET, '/distribution/signing-identities/' . $signingIdentity['body']['$id'], $this->headers());
            $this->assertSame($signingIdentity['body']['fingerprint'], $unchanged['body']['fingerprint'], $case);
        }

        $this->assertSame($fingerprint, $this->certificates($team['key'])[0]->certificate->fingerprint());
    }

    public function testRotateManagedIosSigningIdentityAtCertificateLimit(): void
    {
        $team = $this->prepareAppleTeam(['certificates' => 1]);
        $first = $this->identifier();
        $second = $this->identifier();

        $one = $this->createManagedIosSigningIdentity($team['connectionId'], [$first]);
        $two = $this->createManagedIosSigningIdentity($team['connectionId'], [$second]);
        $this->assertSame(201, $one['headers']['status-code']);
        $this->assertSame(201, $two['headers']['status-code']);

        $fingerprint = $one['body']['fingerprint'];
        $this->assertSame($fingerprint, $two['body']['fingerprint']);

        $downloads = [
            $one['body']['$id'] => $this->getSigningIdentityDownload($one['body']['$id']),
            $two['body']['$id'] => $this->getSigningIdentityDownload($two['body']['$id']),
        ];
        $uuids = [
            $first => $this->profileUuids($team['key'], $first),
            $second => $this->profileUuids($team['key'], $second),
        ];

        /**
         * Test for FAILURE
         */
        $rotated = $this->client->call(Client::METHOD_PATCH, '/distribution/signing-identities/' . $one['body']['$id'], $this->headers(), [
            'rotate' => true,
        ]);

        $this->assertSame(400, $rotated['headers']['status-code']);
        $this->assertSame('distribution_signing_identity_invalid', $rotated['body']['type']);
        $this->assertStringContainsString(self::LIMIT, (string) $rotated['body']['message']);

        foreach ($downloads as $signingIdentityId => $download) {
            $fetched = $this->client->call(Client::METHOD_GET, '/distribution/signing-identities/' . $signingIdentityId, $this->headers());

            $this->assertSame(200, $fetched['headers']['status-code']);
            $this->assertSame($fingerprint, $fetched['body']['fingerprint'], 'A failed rotation changed the fingerprint of ' . $signingIdentityId . '.');
            $this->assertSame($download['files'], $this->getSigningIdentityDownload($signingIdentityId)['files'], 'A failed rotation changed the signing files of ' . $signingIdentityId . '.');
        }

        $certificates = $this->certificates($team['key']);
        $this->assertCount(1, $certificates);
        $this->assertSame($fingerprint, $certificates[0]->certificate->fingerprint(), 'A failed rotation revoked the team certificate.');

        foreach ($uuids as $identifier => $expected) {
            $this->assertSame($expected, $this->profileUuids($team['key'], $identifier), 'A failed rotation changed the store profiles for ' . $identifier . '.');
        }
    }

    public function testDeleteManagedIosSigningIdentity(): void
    {
        $team = $this->prepareAppleTeam();
        $first = $this->identifier();
        $second = $this->identifier();

        $one = $this->createManagedIosSigningIdentity($team['connectionId'], [$first]);
        $two = $this->createManagedIosSigningIdentity($team['connectionId'], [$second]);
        $this->assertSame(201, $one['headers']['status-code']);
        $this->assertSame(201, $two['headers']['status-code']);
        $this->assertCount(1, $this->certificates($team['key']));

        /**
         * Test for SUCCESS
         */
        $deleted = $this->client->call(Client::METHOD_DELETE, '/distribution/signing-identities/' . $one['body']['$id'], $this->headers());

        $this->assertSame(204, $deleted['headers']['status-code']);
        $this->assertSame([], $this->profileUuids($team['key'], $first), 'Deleting a managed identity left its profile in the store.');
        $this->assertCount(1, $this->profileUuids($team['key'], $second));
        $this->assertCount(1, $this->certificates($team['key']), 'Deleting one managed identity revoked the certificate another still holds.');

        $last = $this->client->call(Client::METHOD_DELETE, '/distribution/signing-identities/' . $two['body']['$id'], $this->headers());

        $this->assertSame(204, $last['headers']['status-code']);
        $this->assertSame([], $this->profileUuids($team['key'], $second));
        $this->assertCount(0, $this->certificates($team['key']), 'Deleting the last managed identity of the team left its certificate in the store.');

        /**
         * Test for FAILURE
         */
        $absent = $this->client->call(Client::METHOD_GET, '/distribution/signing-identities/' . $two['body']['$id'], $this->headers());
        $this->assertSame(404, $absent['headers']['status-code']);

        $again = $this->client->call(Client::METHOD_DELETE, '/distribution/signing-identities/' . $two['body']['$id'], $this->headers());
        $this->assertSame(404, $again['headers']['status-code']);
        $this->assertSame('distribution_signing_identity_not_found', $again['body']['type']);
    }

    /**
     * @param array<string, mixed> $seed
     * @return array{key: array{keyId: string, issuerId: string, privateKey: string}, teamId: string, connectionId: string}
     */
    private function prepareAppleTeam(array $seed = []): array
    {
        $key = $this->seedAppleKey();
        $teamId = \strtoupper(\bin2hex(\random_bytes(5)));

        $seeded = $this->client->call(Client::METHOD_POST, '/mock/distribution/apple/seeds/teams', $this->mockHeaders(), \array_merge([
            'issuerId' => $key['issuerId'],
            'teamId' => $teamId,
        ], $seed));
        $this->assertSame(201, $seeded['headers']['status-code'], 'Seeding an App Store Connect team requires _APP_ENV=development.');

        $connection = $this->createAppleConnection([], $key);
        $this->assertSame(201, $connection['headers']['status-code']);

        return [
            'key' => $key,
            'teamId' => $teamId,
            'connectionId' => $connection['body']['$id'],
        ];
    }

    /**
     * @param list<string> $identifiers
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function createManagedIosSigningIdentity(string $connectionId, array $identifiers, array $params = []): array
    {
        return $this->client->call(Client::METHOD_POST, '/distribution/signing-identities/ios', $this->headers(), \array_merge([
            'signingIdentityId' => ID::unique(),
            'name' => 'Managed ' . \uniqid(),
            'connectionId' => $connectionId,
            'identifiers' => $identifiers,
        ], $params));
    }

    /**
     * @return array<string, mixed>
     */
    private function getSigningIdentityDownload(string $signingIdentityId): array
    {
        $download = $this->client->call(Client::METHOD_GET, '/distribution/signing-identities/' . $signingIdentityId . '/download', $this->headers());
        $this->assertSame(200, $download['headers']['status-code']);

        return $download['body'];
    }

    /**
     * @param array<string, mixed> $download
     */
    private function content(array $download, string $name): string
    {
        $files = \array_column($download['files'], 'content', 'name');
        $this->assertArrayHasKey($name, $files);

        $content = \base64_decode($files[$name], true);
        $this->assertIsString($content);

        return $content;
    }

    /**
     * @param array{keyId: string, issuerId: string, privateKey: string} $key
     * @return list<Store\Apple\Certificate>
     */
    private function certificates(array $key): array
    {
        return $this->appleStore($key)->listCertificates();
    }

    /**
     * @param array{keyId: string, issuerId: string, privateKey: string} $key
     * @return list<string>
     */
    private function profileUuids(array $key, string $identifier): array
    {
        $store = $this->appleStore($key);
        $profiles = $store->listProfiles($store->getBundle($identifier)->id);

        return \array_map(fn (Store\Apple\Profile $profile): string => $profile->uuid, $profiles);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function webhookDeliveries(string $webhookId, string $event): array
    {
        $response = @\file_get_contents('http://request-catcher-webhook:5000/__find_request__?' . \http_build_query([
            'header_X-Appwrite-Webhook-Id' => $webhookId,
        ]));
        $requests = \is_string($response) ? \json_decode($response, true) : [];

        return \array_values(\array_filter(
            \is_array($requests) ? $requests : [],
            fn (array $request): bool => \in_array($event, \explode(',', (string) ($request['headers']['X-Appwrite-Webhook-Events'] ?? '')), true),
        ));
    }

    private function identifier(): string
    {
        return 'io.appwrite.managed' . \bin2hex(\random_bytes(6));
    }
}
