<?php

namespace Tests\E2E\Services\Distribution;

use Appwrite\ID;
use CURLFile;
use Tests\E2E\Client;
use Utopia\Client\Adapter\Curl\Client as CurlAdapter;
use Utopia\Client\Client as HttpClient;
use Utopia\Distribution\Credential;
use Utopia\Distribution\JWT;
use Utopia\Distribution\Signing\Keystore;
use Utopia\Distribution\Store;

trait MockStores
{
    abstract protected function headers(): array;

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    abstract protected function createApp(array $params = []): array;

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    abstract protected function createTarget(string $appId, array $params = []): array;

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    abstract protected function createRelease(string $appId, array $params = []): array;

    /**
     * @return array{type: string, project_id: string, private_key: string, client_email: string, token_uri: string}
     */
    protected function seedGoogleServiceAccount(): array
    {
        $key = \openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
            'private_key_bits' => 2048,
        ]);
        $this->assertNotFalse($key);
        $this->assertTrue(\openssl_pkey_export($key, $privateKey));

        $email = 'sa' . \uniqid() . '@appwrite-test.iam.gserviceaccount.com';

        $seeded = $this->client->call(Client::METHOD_POST, '/mock/distribution/google/seeds/service-accounts', $this->mockHeaders(), [
            'email' => $email,
            'publicKey' => \openssl_pkey_get_details($key)['key'],
        ]);
        $this->assertSame(201, $seeded['headers']['status-code'], 'Seeding a Google service account requires _APP_ENV=development.');

        return [
            'type' => 'service_account',
            'project_id' => 'appwrite-test',
            'private_key' => $privateKey,
            'client_email' => $email,
            'token_uri' => 'https://attacker.invalid/token',
        ];
    }

    /**
     * @return array{keyId: string, issuerId: string, privateKey: string}
     */
    protected function seedAppleKey(bool $agreements = true): array
    {
        $key = \openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_EC,
            'curve_name' => 'prime256v1',
        ]);
        $this->assertNotFalse($key);
        $this->assertTrue(\openssl_pkey_export($key, $privateKey));
        $this->assertStringStartsWith('-----BEGIN PRIVATE KEY-----', $privateKey);

        $keyId = \strtoupper(\substr(\bin2hex(\random_bytes(5)), 0, 10));
        $issuerId = \vsprintf('%s%s-%s-%s-%s-%s%s%s', \str_split(\bin2hex(\random_bytes(16)), 4));

        $seeded = $this->client->call(Client::METHOD_POST, '/mock/distribution/apple/seeds/keys', $this->mockHeaders(), [
            'keyId' => $keyId,
            'issuerId' => $issuerId,
            'publicKey' => \openssl_pkey_get_details($key)['key'],
            'agreements' => $agreements,
        ]);
        $this->assertSame(201, $seeded['headers']['status-code'], 'Seeding an App Store Connect key requires _APP_ENV=development.');

        return [
            'keyId' => $keyId,
            'issuerId' => $issuerId,
            'privateKey' => $privateKey,
        ];
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    protected function createGoogleConnection(array $params = []): array
    {
        return $this->client->call(Client::METHOD_POST, '/distribution/connections/google', $this->headers(), \array_merge([
            'connectionId' => ID::unique(),
            'name' => 'Play ' . \uniqid(),
            'serviceAccount' => $params['serviceAccount'] ?? $this->seedGoogleServiceAccount(),
        ], $params));
    }

    /**
     * @param array<string, mixed> $params
     * @param array{keyId: string, issuerId: string, privateKey: string}|null $key
     * @return array<string, mixed>
     */
    protected function createAppleConnection(array $params = [], ?array $key = null): array
    {
        $key ??= $this->seedAppleKey();

        return $this->client->call(Client::METHOD_POST, '/distribution/connections/apple', $this->headers(), \array_merge([
            'connectionId' => ID::unique(),
            'name' => 'App Store ' . \uniqid(),
            'keyId' => $key['keyId'],
            'issuerId' => $key['issuerId'],
            'privateKey' => $key['privateKey'],
        ], $params));
    }

    protected function seedGoogleApplication(string $packageName, string $email): void
    {
        $seeded = $this->client->call(Client::METHOD_POST, '/mock/distribution/google/seeds/applications', $this->mockHeaders(), [
            'packageName' => $packageName,
            'emails' => [$email],
            'tracks' => \array_map(fn (string $track): array => ['track' => $track, 'releases' => []], ['production', 'beta', 'alpha', 'internal']),
        ]);
        $this->assertSame(201, $seeded['headers']['status-code'], 'Seeding a Google Play application requires _APP_ENV=development.');
    }

    protected function seedAppleApp(string $issuerId, string $bundleId): string
    {
        $seeded = $this->client->call(Client::METHOD_POST, '/mock/distribution/apple/seeds/apps', $this->mockHeaders(), [
            'issuerId' => $issuerId,
            'bundleId' => $bundleId,
            'name' => 'App ' . \uniqid(),
        ]);
        $this->assertSame(201, $seeded['headers']['status-code'], 'Seeding an App Store Connect app requires _APP_ENV=development.');

        return $seeded['body']['data']['id'];
    }

    protected function seedAppleGroup(string $appId, bool $internal = false): string
    {
        $seeded = $this->client->call(Client::METHOD_POST, '/mock/distribution/apple/seeds/groups', $this->mockHeaders(), [
            'appId' => $appId,
            'name' => 'Testers ' . \uniqid(),
            'internal' => $internal,
        ]);
        $this->assertSame(201, $seeded['headers']['status-code'], 'Seeding an App Store Connect beta group requires _APP_ENV=development.');

        return $seeded['body']['data']['id'];
    }

    protected function seedAppleBetaReview(string $appId, string $build, string $state): void
    {
        $seeded = $this->client->call(Client::METHOD_POST, '/mock/distribution/apple/seeds/beta-reviews', $this->mockHeaders(), [
            'appId' => $appId,
            'build' => $build,
            'state' => $state,
        ]);
        $this->assertContains($seeded['headers']['status-code'], [201, 202], 'Seeding an App Store Connect beta review requires _APP_ENV=development.');
    }

    protected function seedAppleReview(string $appId, string $version, string $state): void
    {
        $seeded = $this->client->call(Client::METHOD_POST, '/mock/distribution/apple/seeds/reviews', $this->mockHeaders(), [
            'appId' => $appId,
            'version' => $version,
            'state' => $state,
        ]);
        $this->assertSame(201, $seeded['headers']['status-code'], 'Seeding an App Store review requires _APP_ENV=development.');
    }

    protected function seedAppleUpload(string $appId, string $build, string $state): void
    {
        $seeded = $this->client->call(Client::METHOD_POST, '/mock/distribution/apple/seeds/uploads', $this->mockHeaders(), [
            'appId' => $appId,
            'build' => $build,
            'state' => $state,
        ]);
        $this->assertSame(201, $seeded['headers']['status-code'], 'Seeding an App Store Connect upload outcome requires _APP_ENV=development.');
    }

    /**
     * @param array{keyId: string, issuerId: string, privateKey: string} $key
     */
    protected function answerAppleCompliance(array $key, string $buildId): void
    {
        $issued = \time() - 60;
        $token = (new JWT($key['privateKey'], JWT::ES256, $key['keyId']))->encode([
            'iss' => $key['issuerId'],
            'iat' => $issued,
            'exp' => $issued + Store\Apple::TOKEN_LIFETIME,
            'aud' => Store\Apple::AUDIENCE,
        ]);

        $answered = $this->client->call(Client::METHOD_PATCH, '/mock/distribution/apple/v1/builds/' . $buildId, [
            'content-type' => 'application/json',
            'authorization' => 'Bearer ' . $token,
        ], [
            'data' => ['type' => 'builds', 'id' => $buildId, 'attributes' => ['usesNonExemptEncryption' => false]],
        ]);
        $this->assertSame(200, $answered['headers']['status-code'], 'Answering export compliance on an App Store Connect build requires _APP_ENV=development.');
    }

    /**
     * @param array{keyId: string, issuerId: string, privateKey: string} $key
     */
    protected function appleStore(array $key): Store\Apple
    {
        return new Store\Apple(
            new Credential\Apple($key['keyId'], $key['issuerId'], $key['privateKey']),
            new HttpClient(new CurlAdapter()),
            $this->client->getEndpoint() . '/mock/distribution/apple',
        );
    }

    /**
     * @return array{sessions: int, completed: int, bytes: int}
     */
    protected function googleUploads(string $packageName): array
    {
        $uploads = $this->client->call(Client::METHOD_GET, '/mock/distribution/google/seeds/uploads', $this->mockHeaders(), [
            'packageName' => $packageName,
        ]);
        $this->assertSame(200, $uploads['headers']['status-code'], 'Reading Google Play upload counters requires _APP_ENV=development.');

        return [
            'sessions' => $uploads['body']['sessions'],
            'completed' => $uploads['body']['completed'],
            'bytes' => $uploads['body']['bytes'],
        ];
    }

    /**
     * @return array<string, array{title?: string, shortDescription?: string, fullDescription?: string, images: array<string, list<string>>}>
     */
    protected function googleListings(string $packageName): array
    {
        $listings = $this->client->call(Client::METHOD_GET, '/mock/distribution/google/seeds/listings', $this->mockHeaders(), [
            'packageName' => $packageName,
        ]);
        $this->assertSame(200, $listings['headers']['status-code'], 'Reading Google Play listings requires _APP_ENV=development.');

        return $listings['body']['listings'];
    }

    protected function stallGoogleUpload(string $packageName, int $seconds): void
    {
        $stalled = $this->client->call(Client::METHOD_POST, '/mock/distribution/google/seeds/stalls', $this->mockHeaders(), [
            'packageName' => $packageName,
            'seconds' => $seconds,
        ]);
        $this->assertSame(201, $stalled['headers']['status-code'], 'Seeding a Google Play upload stall requires _APP_ENV=development.');
    }

    protected function stageGoogleRollout(string $packageName, string $track, float $fraction): void
    {
        $staged = $this->client->call(Client::METHOD_POST, '/mock/distribution/google/seeds/rollouts', $this->mockHeaders(), [
            'packageName' => $packageName,
            'track' => $track,
            'fraction' => $fraction,
        ]);
        $this->assertSame(201, $staged['headers']['status-code'], 'Seeding a Google Play staged rollout requires _APP_ENV=development.');
    }

    /**
     * @return array{appId: string, targetId: string, artifactId: string, releaseId: string, identifier: string, size: int, signedSize: int}
     */
    protected function prepareGoogleRelease(int $variant): array
    {
        $identifier = 'io.appwrite.distribution.e2e' . $variant;
        $path = __DIR__ . '/../../../resources/distribution/' . $identifier . '.aab';
        $this->assertFileExists($path);

        $serviceAccount = $this->seedGoogleServiceAccount();
        $this->seedGoogleApplication($identifier, $serviceAccount['client_email']);
        $connection = $this->createGoogleConnection(['serviceAccount' => $serviceAccount]);
        $this->assertSame(201, $connection['headers']['status-code']);

        $keystore = Keystore::generate('upload', ['CN' => 'Appwrite Test']);
        $signingIdentity = $this->client->call(Client::METHOD_POST, '/distribution/signing-identities/android', $this->headers(), [
            'signingIdentityId' => ID::unique(),
            'name' => 'Keystore ' . \uniqid(),
            'keystore' => \base64_encode($keystore->archive),
            'storePassword' => $keystore->password,
            'keyAlias' => 'upload',
            'keyPassword' => $keystore->password,
        ]);
        $this->assertSame(201, $signingIdentity['headers']['status-code']);

        $app = $this->createApp(['framework' => 'android']);
        $this->assertSame(201, $app['headers']['status-code']);
        $appId = $app['body']['$id'];

        $target = $this->createTarget($appId, [
            'platform' => 'android',
            'identifier' => $identifier,
            'connectionId' => $connection['body']['$id'],
            'signingIdentityId' => $signingIdentity['body']['$id'],
            'track' => 'internal',
        ]);
        $this->assertSame(201, $target['headers']['status-code']);

        $artifact = $this->uploadBundle($appId, $path);
        $this->assertSame(202, $artifact['headers']['status-code']);
        $ready = $this->awaitBundle($appId, $artifact['body']['$id']);
        $this->assertSame('ready', $ready['status'], 'Artifact ' . $ready['$id'] . ' is ' . $ready['status'] . ': ' . $ready['errorCode'] . ' ' . $ready['errorMessage']);
        $this->assertTrue($ready['signed']);
        $this->assertSame($identifier, $ready['identifier']);
        $this->assertSame(1, $ready['versionCode']);

        $release = $this->createRelease($appId);
        $this->assertSame(201, $release['headers']['status-code']);

        return [
            'appId' => $appId,
            'targetId' => $target['body']['$id'],
            'artifactId' => $ready['$id'],
            'releaseId' => $release['body']['$id'],
            'identifier' => $identifier,
            'size' => (int) \filesize($path),
            'signedSize' => (int) $ready['size'],
        ];
    }

    /**
     * @param array<string, mixed> $target
     * @return array{appId: string, targetId: string, artifactId: string, releaseId: string, identifier: string, storeAppId: string, connectionId: string, key: array{keyId: string, issuerId: string, privateKey: string}}
     */
    protected function prepareAppleRelease(array $target = []): array
    {
        $identifier = 'io.appwrite.distribution.ios';
        $path = __DIR__ . '/../../../resources/distribution/' . $identifier . '.ipa';
        $this->assertFileExists($path);

        $key = $this->seedAppleKey();
        $storeAppId = $this->seedAppleApp($key['issuerId'], $identifier);
        $connection = $this->createAppleConnection([], $key);
        $this->assertSame(201, $connection['headers']['status-code']);

        $app = $this->createApp(['framework' => 'ios']);
        $this->assertSame(201, $app['headers']['status-code']);
        $appId = $app['body']['$id'];

        $created = $this->createTarget($appId, \array_merge([
            'platform' => 'ios',
            'identifier' => $identifier,
            'connectionId' => $connection['body']['$id'],
            'track' => 'testflight',
        ], $target));
        $this->assertSame(201, $created['headers']['status-code']);

        $artifact = $this->uploadBundle($appId, $path);
        $this->assertSame(202, $artifact['headers']['status-code']);
        $ready = $this->awaitBundle($appId, $artifact['body']['$id']);
        $this->assertSame('ready', $ready['status'], 'Artifact ' . $ready['$id'] . ' is ' . $ready['status'] . ': ' . $ready['errorCode'] . ' ' . $ready['errorMessage']);
        $this->assertSame('ipa', $ready['type']);
        $this->assertTrue($ready['signed']);
        $this->assertSame($identifier, $ready['identifier']);
        $this->assertSame('1', $ready['buildNumber']);
        $this->assertSame('1.0.0', $ready['versionName']);

        $release = $this->createRelease($appId);
        $this->assertSame(201, $release['headers']['status-code']);

        return [
            'appId' => $appId,
            'targetId' => $created['body']['$id'],
            'artifactId' => $ready['$id'],
            'releaseId' => $release['body']['$id'],
            'identifier' => $identifier,
            'storeAppId' => $storeAppId,
            'connectionId' => $connection['body']['$id'],
            'key' => $key,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function uploadBundle(string $appId, string $path): array
    {
        return $this->client->call(Client::METHOD_POST, '/distribution/apps/' . $appId . '/artifacts', \array_merge($this->headers(), [
            'content-type' => 'multipart/form-data',
        ]), [
            'file' => new CURLFile($path, 'application/octet-stream', \basename($path)),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function awaitBundle(string $appId, string $artifactId, int $timeoutMs = 180_000): array
    {
        $artifact = [];

        $this->assertEventually(function () use ($appId, $artifactId, &$artifact): void {
            $fetched = $this->client->call(Client::METHOD_GET, '/distribution/apps/' . $appId . '/artifacts/' . $artifactId, $this->headers());
            $this->assertSame(200, $fetched['headers']['status-code']);
            $artifact = $fetched['body'];
            $this->assertContains($artifact['status'], ['ready', 'invalid']);
        }, $timeoutMs, 2000);

        return $artifact;
    }

    /**
     * @return array<string, string>
     */
    private function mockHeaders(): array
    {
        return [
            'content-type' => 'application/json',
            'x-appwrite-project' => 'console',
        ];
    }
}
