<?php

declare(strict_types=1);

namespace Tests\Unit\Distribution;

use Appwrite\Distribution\Platform;
use Appwrite\Distribution\Provider;
use Appwrite\Distribution\Stores;
use Appwrite\Extend\Exception;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Utopia\Database\Document;
use Utopia\Distribution\Exception\NotFound;
use Utopia\Distribution\Issue;
use Utopia\Distribution\Store\Apple;
use Utopia\Distribution\Store\Google;
use Utopia\Distribution\Tests\Transport;
use Utopia\Psr7\Response;
use Utopia\Storage\Device\Local;

final class StoresTest extends TestCase
{
    private const string APPLE_OVERRIDE = 'http://appwrite/v1/mock/distribution/apple';

    private const string GOOGLE_OVERRIDE = 'http://appwrite/v1/mock/distribution/google';

    private static string $applePrivateKey;

    private static string $googlePrivateKey;

    public static function setUpBeforeClass(): void
    {
        $apple = \openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        $google = \openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
        self::assertNotFalse($apple);
        self::assertNotFalse($google);
        self::assertTrue(\openssl_pkey_export($apple, $applePrivateKey));
        self::assertTrue(\openssl_pkey_export($google, $googlePrivateKey));

        self::$applePrivateKey = $applePrivateKey;
        self::$googlePrivateKey = $googlePrivateKey;
    }

    /**
     * @return \Iterator<string, array{string, string}>
     */
    public static function overrides(): \Iterator
    {
        yield 'apple' => [self::APPLE_OVERRIDE, ''];
        yield 'google' => ['', self::GOOGLE_OVERRIDE];
        yield 'both' => [self::APPLE_OVERRIDE, self::GOOGLE_OVERRIDE];
    }

    #[DataProvider('overrides')]
    public function testOverrideIsRefusedInProduction(string $apple, string $google): void
    {
        try {
            new Stores($apple, $google, true, new Transport());
            $this->fail('An endpoint override must not be accepted in production.');
        } catch (Exception $exception) {
            $this->assertSame(Exception::GENERAL_SERVER_ERROR, $exception->getType());
        }
    }

    public function testProductionWithoutOverridesUsesStoreEndpoints(): void
    {
        $transport = new Transport(Transport::fixture('Apple/apps'));
        $stores = new Stores(production: true, client: $transport);

        $stores->verify($stores->adapter(Provider::Apple, $this->apple()));

        $this->assertStringStartsWith(Apple::ENDPOINT . '/v1/apps', (string) $this->first($transport)->getUri());
    }

    public function testProductionConstructsDefaultClient(): void
    {
        $stores = new Stores();

        $this->assertInstanceOf(Google::class, $stores->adapter(Provider::Google, $this->google()));
    }

    public function testGoogleOverrideSendsTokenRequestToOverrideWithGoogleAudience(): void
    {
        $transport = new Transport(Transport::fixture('Google/token'));
        $stores = new Stores(googleEndpoint: self::GOOGLE_OVERRIDE, production: false, client: $transport);

        $account = $stores->verify($stores->adapter(Provider::Google, $this->google()));

        $request = $this->first($transport);
        $this->assertSame(self::GOOGLE_OVERRIDE . '/token', (string) $request->getUri());
        $this->assertSame(Google::TOKEN, $this->claims($request)['aud']);
        $this->assertSame('play@appwrite-test.iam.gserviceaccount.com', $account->id);
    }

    public function testGoogleDefaultsToGoogleTokenEndpoint(): void
    {
        $transport = new Transport(Transport::fixture('Google/token'));
        $stores = new Stores(production: false, client: $transport);

        $stores->verify($stores->adapter(Provider::Google, $this->google()));

        $this->assertSame(Google::TOKEN, (string) $this->first($transport)->getUri());
    }

    public function testAppleOverrideSendsRequestsToOverride(): void
    {
        $transport = new Transport(Transport::fixture('Apple/apps'));
        $stores = new Stores(appleEndpoint: self::APPLE_OVERRIDE, production: false, client: $transport);

        $account = $stores->verify($stores->adapter(Provider::Apple, $this->apple()));

        $this->assertStringStartsWith(self::APPLE_OVERRIDE . '/v1/apps', (string) $this->first($transport)->getUri());
        $this->assertSame('69a6de7e-0000-47e3-e053-5b8c7c11a4d1', $account->id);
    }

    public function testAppleOverrideWithTrailingSlashSendsRequestsToOverride(): void
    {
        $transport = new Transport(Transport::fixture('Apple/apps'));
        $stores = new Stores(appleEndpoint: self::APPLE_OVERRIDE . '/', production: false, client: $transport);

        $stores->verify($stores->adapter(Provider::Apple, $this->apple()));

        $this->assertStringStartsWith(self::APPLE_OVERRIDE . '/v1/apps', (string) $this->first($transport)->getUri());
    }

    public function testGoogleOverrideWithTrailingSlashSendsTokenRequestToOverride(): void
    {
        $transport = new Transport(Transport::fixture('Google/token'));
        $stores = new Stores(googleEndpoint: self::GOOGLE_OVERRIDE . '/', production: false, client: $transport);

        $stores->verify($stores->adapter(Provider::Google, $this->google()));

        $this->assertSame(self::GOOGLE_OVERRIDE . '/token', (string) $this->first($transport)->getUri());
    }

    public function testConnectionBuildsAdapterFromStoredCredentials(): void
    {
        $transport = new Transport(Transport::fixture('Google/token'));
        $stores = new Stores(googleEndpoint: self::GOOGLE_OVERRIDE, production: false, client: $transport);

        $store = $stores->connection(new Document([
            'provider' => Provider::Google->value,
            'credentials' => $this->google(),
        ]));

        $this->assertInstanceOf(Google::class, $store);
        $this->assertSame('play@appwrite-test.iam.gserviceaccount.com', $stores->verify($store)->id);
    }

    /**
     * @return \Iterator<string, array{Provider, array<string, mixed>}>
     */
    public static function incomplete(): \Iterator
    {
        yield 'apple without key id' => [Provider::Apple, ['issuerId' => 'issuer', 'privateKey' => '-----BEGIN PRIVATE KEY-----']];
        yield 'apple without private key' => [Provider::Apple, ['keyId' => 'ABC123DEFG', 'issuerId' => 'issuer']];
        yield 'apple with a key path' => [Provider::Apple, ['keyId' => 'ABC123DEFG', 'issuerId' => 'issuer', 'privateKey' => 'file:///etc/key.p8']];
        yield 'google without email' => [Provider::Google, ['privateKey' => '-----BEGIN PRIVATE KEY-----']];
        yield 'google without private key' => [Provider::Google, ['clientEmail' => 'play@appwrite-test.iam.gserviceaccount.com']];
        yield 'google with a numeric project' => [Provider::Google, ['clientEmail' => 'play@appwrite-test.iam.gserviceaccount.com', 'privateKey' => '-----BEGIN PRIVATE KEY-----', 'projectId' => 7]];
    }

    /**
     * @param array<string, mixed> $credentials
     */
    #[DataProvider('incomplete')]
    public function testIncompleteCredentialsAreInvalid(Provider $provider, array $credentials): void
    {
        $stores = new Stores(production: false, client: new Transport());

        try {
            $stores->adapter($provider, $credentials);
            $this->fail('Incomplete credentials must be rejected.');
        } catch (Exception $exception) {
            $this->assertSame(Exception::DISTRIBUTION_CONNECTION_INVALID, $exception->getType());
            $this->assertSame(400, $exception->getCode());
        }
    }

    public function testConnectionWithoutProviderIsInvalid(): void
    {
        $stores = new Stores(production: false, client: new Transport());

        $this->expectExceptionObject(new Exception(Exception::DISTRIBUTION_CONNECTION_INVALID, 'The connection has no usable store credentials.'));

        $stores->connection(new Document(['provider' => 'amazon', 'credentials' => []]));
    }

    public function testRejectedCredentialsAreInvalid(): void
    {
        $transport = new Transport(Transport::fixture('Apple/error-unauthorized', 401), Transport::fixture('Apple/error-unauthorized', 401));
        $stores = new Stores(production: false, client: $transport);

        try {
            $stores->verify($stores->adapter(Provider::Apple, $this->apple()));
            $this->fail('Credentials the store rejects must be reported as invalid.');
        } catch (Exception $exception) {
            $this->assertSame(Exception::DISTRIBUTION_CONNECTION_INVALID, $exception->getType());
            $this->assertSame(400, $exception->getCode());
        }
    }

    public function testUnavailableStoreIsReportedAsUnavailable(): void
    {
        $transport = new Transport(new Response(503));
        $stores = new Stores(production: false, client: $transport);

        try {
            $stores->verify($stores->adapter(Provider::Google, $this->google()));
            $this->fail('A store that does not answer must be reported as unavailable.');
        } catch (Exception $exception) {
            $this->assertSame(Exception::DISTRIBUTION_STORE_UNAVAILABLE, $exception->getType());
            $this->assertSame(502, $exception->getCode());
        }
    }

    public function testRateLimitedStoreIsReportedAsUnavailable(): void
    {
        $transport = new Transport(new Response(429));
        $stores = new Stores(production: false, client: $transport);

        try {
            $stores->verify($stores->adapter(Provider::Google, $this->google()));
            $this->fail('A rate limited store must be reported as unavailable.');
        } catch (Exception $exception) {
            $this->assertSame(Exception::DISTRIBUTION_STORE_UNAVAILABLE, $exception->getType());
        }
    }

    public function testTracksFollowTheProvider(): void
    {
        $stores = new Stores(production: false, client: new Transport());

        $this->assertSame(['testflight', 'testflight-external', 'production'], $stores->tracks(Provider::Apple));
        $this->assertSame(['internal', 'alpha', 'beta', 'production'], $stores->tracks(Provider::Google));
    }

    public function testProviderFollowsThePlatform(): void
    {
        $this->assertSame(Provider::Apple, Stores::provider(Platform::Ios));
        $this->assertSame(Provider::Google, Stores::provider(Platform::Android));
    }

    /**
     * @return \Iterator<string, array{string, bool}>
     */
    public static function exclusives(): \Iterator
    {
        yield 'google' => ['google', true];
        yield 'apple' => ['apple', false];
        yield 'unknown' => ['', false];
    }

    #[DataProvider('exclusives')]
    public function testOnlyGoogleConnectionsAreExclusive(string $provider, bool $exclusive): void
    {
        $this->assertSame($exclusive, Stores::isExclusive(new Document(['$id' => 'connection', 'provider' => $provider])));
    }

    /**
     * @return \Iterator<string, array{array<string, mixed>, string}>
     */
    public static function identities(): \Iterator
    {
        yield 'verified service account' => [['account' => ['email' => 'play@example.iam.gserviceaccount.com', 'projectId' => 'example']], 'play@example.iam.gserviceaccount.com'];
        yield 'no account' => [[], '3:connection'];
        yield 'account without an email' => [['account' => ['projectId' => 'example']], '3:connection'];
        yield 'malformed account' => [['account' => 'play@example.iam.gserviceaccount.com'], '3:connection'];
    }

    /**
     * @param array<string, mixed> $attributes
     */
    #[DataProvider('identities')]
    public function testIdentityIsTheServiceAccountOrTheConnection(array $attributes, string $identity): void
    {
        $this->assertSame($identity, Stores::identity(new Document(['$id' => 'connection', '$sequence' => '3', 'provider' => 'google', ...$attributes])));
    }

    /**
     * @return \Iterator<string, array{list<string>, bool}>
     */
    public static function reuploads(): \Iterator
    {
        yield 'edit expired' => [['editExpired'], true];
        yield 'edit already committed' => [['editAlreadyCommitted'], true];
        yield 'edit not found' => [['editNotFound'], true];
        yield 'upload session ended' => [['uploadSessionEnded'], true];
        yield 'among other issues' => [['applicationNotFound', 'editExpired'], true];
        yield 'application not found' => [['applicationNotFound'], false];
        yield 'no issue' => [[], false];
    }

    /**
     * @param list<string> $codes
     */
    #[DataProvider('reuploads')]
    public function testIsReuploadReadsTheGoogleIssueCodes(array $codes, bool $reupload): void
    {
        $exception = new NotFound('Not found', \array_map(static fn (string $code): Issue => new Issue($code, $code), $codes));

        $this->assertSame($reupload, Stores::isReupload($exception));
    }

    /**
     * @return \Iterator<string, array{string, string, string, string}>
     */
    public static function releases(): \Iterator
    {
        yield 'google' => ['google', 'android', 'internal', '88'];
        yield 'apple' => ['apple', 'ios', 'testflight', '88.1'];
    }

    #[DataProvider('releases')]
    public function testReleaseDescribesTheSubmission(string $provider, string $platform, string $track, string $build): void
    {
        $stores = new Stores(production: false, client: new Transport());

        $release = $stores->release(
            new Document(['provider' => $provider, 'track' => $track, 'rollout' => 0.5, 'automatic' => false]),
            new Document(['version' => '1.2.0', 'notes' => ['en-US' => 'Bug fixes', 'de-DE' => '', 'fr-FR' => ['nested']]]),
            new Document(['identifier' => 'io.example.app']),
            new Document(['platform' => $platform, 'versionName' => '1.2.1', 'versionCode' => 88, 'buildNumber' => '88.1']),
        );

        $this->assertSame('io.example.app', $release->identifier);
        $this->assertSame($track, $release->track);
        $this->assertSame('1.2.1', $release->version);
        $this->assertSame($build, $release->build);
        $this->assertEqualsWithDelta(0.5, $release->fraction, PHP_FLOAT_EPSILON);
        $this->assertSame(['en-US' => 'Bug fixes'], $release->notes);
        $this->assertFalse($release->automatic);
    }

    public function testReleaseFallsBackToTheReleaseVersion(): void
    {
        $stores = new Stores(production: false, client: new Transport());

        $release = $stores->release(
            new Document(['provider' => 'google', 'track' => 'production']),
            new Document(['version' => '1.2.0']),
            new Document(['identifier' => 'io.example.app']),
            new Document(['platform' => 'android', 'versionCode' => 88]),
        );

        $this->assertSame('1.2.0', $release->version);
        $this->assertEqualsWithDelta(1.0, $release->fraction, PHP_FLOAT_EPSILON);
        $this->assertSame([], $release->notes);
        $this->assertTrue($release->automatic);
    }

    /**
     * @return \Iterator<string, array{array<string, mixed>, list<string>}>
     */
    public static function betaGroups(): \Iterator
    {
        yield 'no key' => [[], []];
        yield 'empty' => [['betaGroups' => ''], []];
        yield 'only separators and spaces' => [['betaGroups' => ' , ,'], []];
        yield 'one group' => [['betaGroups' => 'group-1'], ['group-1']];
        yield 'spaces around groups' => [['betaGroups' => ' group-1 ,  group-2 '], ['group-1', 'group-2']];
        yield 'duplicates' => [['betaGroups' => 'group-1,group-2,group-1'], ['group-1', 'group-2']];
        yield 'trailing comma' => [['betaGroups' => 'group-1,group-2,'], ['group-1', 'group-2']];
        yield 'not a string' => [['betaGroups' => ['group-1']], []];
    }

    /**
     * @param array<string, mixed> $configuration
     * @param list<string> $groups
     */
    #[DataProvider('betaGroups')]
    public function testGroupsParsesTheCommaSeparatedConfiguration(array $configuration, array $groups): void
    {
        $this->assertSame($groups, Stores::groups($configuration));
    }

    /**
     * @return \Iterator<string, array{string, array<string, mixed>, bool}>
     */
    public static function missingGroups(): \Iterator
    {
        yield 'external without groups' => ['testflight-external', [], true];
        yield 'external with blank groups' => ['testflight-external', ['betaGroups' => ' , '], true];
        yield 'external with a group' => ['testflight-external', ['betaGroups' => 'group-1'], false];
        yield 'internal without groups' => ['testflight', [], false];
        yield 'production without groups' => ['production', [], false];
    }

    /**
     * @param array<string, mixed> $configuration
     */
    #[DataProvider('missingGroups')]
    public function testIsMissingGroupsOnlyForExternalTestFlight(string $track, array $configuration, bool $missing): void
    {
        $this->assertSame($missing, Stores::isMissingGroups($track, $configuration));
    }

    /**
     * @return \Iterator<string, array{mixed, ?bool}>
     */
    public static function encryptions(): \Iterator
    {
        yield 'declared exempt' => [['usesNonExemptEncryption' => 'false', 'betaGroups' => 'group-1'], false];
        yield 'not declared' => [['betaGroups' => 'group-1'], null];
        yield 'no configuration' => [[], null];
        yield 'malformed configuration' => ['{', null];
    }

    #[DataProvider('encryptions')]
    public function testReleaseMapsTheExportComplianceConfiguration(mixed $configuration, ?bool $encryption): void
    {
        $stores = new Stores(production: false, client: new Transport());

        $release = $stores->release(
            new Document(['provider' => 'apple', 'track' => 'production']),
            new Document(['version' => '1.2.0']),
            new Document(['identifier' => 'io.example.app', 'configuration' => $configuration]),
            new Document(['platform' => 'ios', 'buildNumber' => '88']),
        );

        $this->assertSame($encryption, $release->encryption);
    }

    public function testAppleReleaseCarriesTheGroupsAndExportCompliance(): void
    {
        $stores = new Stores(production: false, client: new Transport());

        $release = $stores->release(
            new Document(['provider' => 'apple', 'track' => 'testflight-external']),
            new Document(['version' => '1.2.0']),
            new Document(['identifier' => 'io.example.app', 'configuration' => ['betaGroups' => 'group-2, group-1,group-2', 'usesNonExemptEncryption' => 'false']]),
            new Document(['platform' => 'ios', 'versionName' => '1.2.0', 'buildNumber' => '88']),
        );

        $this->assertSame('testflight-external', $release->track);
        $this->assertSame(['group-2', 'group-1'], $release->groups);
        $this->assertFalse($release->encryption);
    }

    public function testReleaseWithoutConfigurationHasNoGroupsOrExportCompliance(): void
    {
        $stores = new Stores(production: false, client: new Transport());

        $release = $stores->release(
            new Document(['provider' => 'apple', 'track' => 'testflight']),
            new Document(['version' => '1.2.0']),
            new Document(['identifier' => 'io.example.app']),
            new Document(['platform' => 'ios', 'buildNumber' => '88']),
        );

        $this->assertSame([], $release->groups);
        $this->assertNull($release->encryption);
    }

    /**
     * @return \Iterator<string, array{string, string}>
     */
    public static function foreignTracks(): \Iterator
    {
        yield 'apple track on google' => ['google', 'testflight'];
        yield 'google track on apple' => ['apple', 'internal'];
        yield 'unknown provider' => ['amazon', 'production'];
        yield 'no track' => ['google', ''];
    }

    #[DataProvider('foreignTracks')]
    public function testReleaseRefusesATrackOfAnotherStore(string $provider, string $track): void
    {
        $stores = new Stores(production: false, client: new Transport());

        try {
            $stores->release(
                new Document(['provider' => $provider, 'track' => $track]),
                new Document(['version' => '1.2.0']),
                new Document(['identifier' => 'io.example.app']),
                new Document(['platform' => 'android', 'versionCode' => 88]),
            );
            $this->fail('A track the store does not have must be refused.');
        } catch (Exception $exception) {
            $this->assertSame(Exception::DISTRIBUTION_TRACK_INVALID, $exception->getType());
        }
    }

    public function testUploadDescribesTheArtifact(): void
    {
        $stores = new Stores(production: false, client: new Transport());
        $device = new Local(\sys_get_temp_dir());

        $upload = $stores->upload(new Document([
            'platform' => 'android',
            'path' => $device->getPath('artifact.aab'),
            'name' => 'release.aab',
            'size' => 4096,
            'identifier' => 'io.example.app',
            'versionName' => '1.2.0',
            'versionCode' => 88,
        ]), $device);

        $this->assertSame($device, $upload->device);
        $this->assertSame($device->getPath('artifact.aab'), $upload->path);
        $this->assertSame('release.aab', $upload->name);
        $this->assertSame(4096, $upload->size);
        $this->assertSame('io.example.app', $upload->identifier);
        $this->assertSame('1.2.0', $upload->version);
        $this->assertSame('88', $upload->build);
        $this->assertSame('android', $upload->platform);
    }

    public function testUploadNamesAnUnnamedArtifactAfterItsFile(): void
    {
        $stores = new Stores(production: false, client: new Transport());
        $device = new Local(\sys_get_temp_dir());

        $upload = $stores->upload(new Document([
            'platform' => 'ios',
            'path' => $device->getPath('artifact.ipa'),
            'size' => 4096,
            'identifier' => 'io.example.app',
            'buildNumber' => '42',
        ]), $device);

        $this->assertSame('artifact.ipa', $upload->name);
        $this->assertSame('42', $upload->version);
        $this->assertSame('42', $upload->build);
    }

    /**
     * @return \Iterator<string, array{array<string, mixed>}>
     */
    public static function incompleteArtifacts(): \Iterator
    {
        $artifact = ['platform' => 'android', 'path' => '/storage/artifact.aab', 'size' => 4096, 'identifier' => 'io.example.app', 'versionCode' => 88];

        yield 'empty' => [[...$artifact, 'size' => 0]];
        yield 'without identifier' => [[...$artifact, 'identifier' => '']];
        yield 'without version code' => [[...$artifact, 'versionCode' => null]];
        yield 'without platform' => [[...$artifact, 'platform' => '']];
    }

    /**
     * @param array<string, mixed> $artifact
     */
    #[DataProvider('incompleteArtifacts')]
    public function testUploadRefusesAnIncompleteArtifact(array $artifact): void
    {
        $stores = new Stores(production: false, client: new Transport());

        try {
            $stores->upload(new Document($artifact), new Local(\sys_get_temp_dir()));
            $this->fail('An artifact the store cannot accept must be refused.');
        } catch (Exception $exception) {
            $this->assertSame(Exception::DISTRIBUTION_ARTIFACT_INVALID, $exception->getType());
        }
    }

    /**
     * @return array<string, string>
     */
    private function apple(): array
    {
        return [
            'keyId' => 'ABC123DEFG',
            'issuerId' => '69a6de7e-0000-47e3-e053-5b8c7c11a4d1',
            'privateKey' => self::$applePrivateKey,
        ];
    }

    /**
     * @return array<string, string>
     */
    private function google(): array
    {
        return [
            'clientEmail' => 'play@appwrite-test.iam.gserviceaccount.com',
            'privateKey' => self::$googlePrivateKey,
            'projectId' => 'appwrite-test',
        ];
    }

    private function first(Transport $transport): RequestInterface
    {
        $requests = $transport->requests();
        $this->assertNotEmpty($requests);

        return $requests[0];
    }

    /**
     * @return array<string, mixed>
     */
    private function claims(RequestInterface $request): array
    {
        \parse_str((string) $request->getBody(), $form);
        $assertion = $form['assertion'] ?? null;
        $this->assertIsString($assertion);

        $parts = \explode('.', $assertion);
        $this->assertCount(3, $parts);

        $claims = \json_decode((string) \base64_decode(\strtr($parts[1], '-_', '+/')), true);
        $this->assertIsArray($claims);

        return $claims;
    }
}
