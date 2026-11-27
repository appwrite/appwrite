<?php

declare(strict_types=1);

namespace Tests\Unit\Distribution;

use Appwrite\Distribution\Provisioning;
use Appwrite\Distribution\Signing\Status;
use Appwrite\Extend\Exception;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Distribution\Fakes\LeaseRedis;
use Tests\Unit\Distribution\Fakes\ProvisioningApple;
use Tests\Unit\Distribution\Fakes\ProvisioningDatabase;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Database\Exception as DatabaseException;
use Utopia\Distribution\Exception as StoreException;
use Utopia\Distribution\Exception\Conflict;
use Utopia\Distribution\Exception\Invalid;
use Utopia\Distribution\Exception\RateLimited;
use Utopia\Distribution\Exception\Unauthorized;
use Utopia\Distribution\Exception\Unavailable;
use Utopia\Distribution\Signing\Certificate;
use Utopia\Distribution\Signing\Profile;
use Utopia\Lock\Distributed;
use Utopia\Lock\Exception\Contention;

final class ProvisioningTest extends TestCase
{
    private const string COLLECTION = 'distributionSigningIdentities';

    private const string APP = 'io.example.app';

    private const string WIDGET = 'io.example.app.widget';

    private const string NOW = '2026-11-28 00:00:00';

    private ProvisioningDatabase $database;

    private ProvisioningApple $store;

    private Provisioning $provisioning;

    private Document $connection;

    private LeaseRedis $redis;

    private Distributed $lock;

    #[\Override]
    protected function setUp(): void
    {
        $this->database = new ProvisioningDatabase();
        $this->store = new ProvisioningApple();
        $this->provisioning = new Provisioning($this->database);
        $this->connection = new Document(['$id' => 'connection', '$sequence' => '3']);
        $this->redis = new LeaseRedis();
        $this->lock = new Distributed($this->redis, Provisioning::lock(ProvisioningApple::TEAM), 120);
        $this->assertTrue($this->lock->tryAcquire());
    }

    public function testLockIsKeyedByTeam(): void
    {
        $this->assertSame('distribution:apple:team:ABCDE12345', Provisioning::lock(ProvisioningApple::TEAM));
    }

    public function testCreateIssuesCertificateFromRequest(): void
    {
        $identity = $this->create('identity', [self::APP, self::WIDGET]);

        $this->assertCount(1, \array_keys($this->store->calls, 'createCertificate', true));
        $this->assertStringStartsWith('-----BEGIN CERTIFICATE REQUEST-----', $this->store->requests[0]);
        $this->assertSame('Appwrite Distribution', (\openssl_csr_get_subject($this->store->requests[0]) ?: [])['CN'] ?? null);

        $issued = \array_values($this->store->certificates)[0];
        $this->assertSame('ios', $identity->getAttribute('platform'));
        $this->assertSame('managed', $identity->getAttribute('mode'));
        $this->assertSame(ProvisioningApple::TEAM, $identity->getAttribute('teamId'));
        $this->assertSame([self::APP, self::WIDGET], $identity->getAttribute('identifiers'));
        $this->assertSame('connection', $identity->getAttribute('connectionId'));
        $this->assertSame('3', $identity->getAttribute('connectionInternalId'));
        $this->assertSame($issued->id, $identity->getAttribute('externalId'));
        $this->assertSame($issued->certificate->fingerprint(), $identity->getAttribute('fingerprint'));
        $this->assertSame(Status::Active->value, $identity->getAttribute('status'));

        $certificate = $this->certificate($identity);
        $this->assertSame($issued->certificate->fingerprint(), $certificate->fingerprint());

        $secret = $identity->getAttribute('secret');
        $this->assertSame([self::APP, self::WIDGET], \array_keys($secret['profiles']));
        $this->assertSame([self::APP, self::WIDGET], \array_keys($secret['managed']['bundles']));
        $this->assertSame(\array_keys($this->store->profiles), \array_values($secret['managed']['profiles']));

        $expiresAt = $certificate->expiresAt();
        foreach ($secret['profiles'] as $identifier => $content) {
            $profile = Profile::parse((string) \base64_decode($content, true));
            $this->assertSame($identifier, $profile->identifier);
            $this->assertTrue($profile->contains($certificate));
            $this->assertSame(ProvisioningApple::TEAM, $profile->team);
            $expiresAt = \min($expiresAt, $profile->expiresAt);
        }

        $this->assertSame(DateTime::format(\DateTime::createFromImmutable($expiresAt)), $identity->getAttribute('expiresAt'));
        $this->assertSame($identity->getArrayCopy(), $this->database->getDocument(self::COLLECTION, 'identity')->getArrayCopy());
    }

    public function testCreateReusesTheTeamCertificate(): void
    {
        $first = $this->create('first', [self::APP]);
        $second = $this->create('second', [self::WIDGET]);

        $this->assertCount(1, \array_keys($this->store->calls, 'createCertificate', true));
        $this->assertCount(1, $this->store->certificates);
        $this->assertSame($first->getAttribute('fingerprint'), $second->getAttribute('fingerprint'));
        $this->assertSame($first->getAttribute('externalId'), $second->getAttribute('externalId'));
        $this->assertSame($first->getAttribute('secret')['certificate'], $second->getAttribute('secret')['certificate']);
        $this->assertTrue(Profile::parse((string) \base64_decode($second->getAttribute('secret')['profiles'][self::WIDGET], true))->contains($this->certificate($first)));
    }

    public function testCreateDoesNotReuseAnotherTeamsCertificate(): void
    {
        $this->create('first', [self::APP]);
        $this->database->updateDocument(self::COLLECTION, 'first', new Document(['teamId' => 'ZZZZZ99999']));

        $this->create('second', [self::WIDGET]);

        $this->assertCount(2, \array_keys($this->store->calls, 'createCertificate', true));
    }

    public function testCreateOnlyCreatesMissingBundles(): void
    {
        $this->store->seedBundle(self::APP);

        $identity = $this->create('identity', [self::APP, self::WIDGET]);

        $this->assertSame([self::WIDGET], $this->store->createdBundles);
        $this->assertSame($this->store->bundles[self::APP]->id, $identity->getAttribute('secret')['managed']['bundles'][self::APP]);
    }

    public function testCreateRereadsADuplicateBundle(): void
    {
        $this->store->duplicateBundle = true;

        $identity = $this->create('identity', [self::APP]);

        $this->assertSame(['getBundle', 'createBundle', 'getBundle', 'createProfile'], \array_values(\array_filter($this->store->calls, static fn (string $call): bool => $call !== 'createCertificate')));
        $this->assertSame($this->store->bundles[self::APP]->id, $identity->getAttribute('secret')['managed']['bundles'][self::APP]);
    }

    public function testCreateGivesEveryProfileAUniqueName(): void
    {
        $this->create('first', [self::APP, self::WIDGET]);
        $this->create('second', [self::APP]);

        $this->assertSame([
            'Appwrite io.example.app App Store first',
            'Appwrite io.example.app.widget App Store first',
            'Appwrite io.example.app App Store second',
        ], \array_values(\array_map(static fn ($profile): string => $profile->name, $this->store->profiles)));
    }

    public function testCreateRefusesABundleOfAnotherTeam(): void
    {
        $this->store->seedBundle(self::APP, 'ZZZZZ99999');

        try {
            $this->create('identity', [self::APP]);
            $this->fail('A bundle of another team must be refused');
        } catch (Invalid $error) {
            $this->assertSame('Bundle ID "io.example.app" belongs to team ZZZZZ99999, not ABCDE12345.', $error->getMessage());
        }

        $this->assertSame([], $this->store->certificates, 'The certificate issued for the identity must be revoked');
        $this->assertSame([], $this->database->documents);
    }

    public function testCertificateLimitIsRefusedWithRemediation(): void
    {
        $this->store->certificateLimit = true;

        try {
            $this->create('identity', [self::APP]);
            $this->fail('The certificate limit must be reported');
        } catch (Exception $error) {
            $this->assertSame(Exception::DISTRIBUTION_SIGNING_IDENTITY_INVALID, $error->getType());
            $this->assertSame(400, $error->getCode());
            $this->assertStringContainsString('Import an existing certificate instead, or revoke one in your Apple Developer account.', $error->getMessage());
            $this->assertInstanceOf(Conflict::class, $error->getPrevious());
        }

        $this->assertSame([], $this->store->profiles);
        $this->assertSame([], $this->database->documents);
    }

    public function testCertificateLimitIsRefusedWhenRotating(): void
    {
        $identity = $this->create('identity', [self::APP]);
        $this->store->certificateLimit = true;

        try {
            $this->provisioning->rotate($this->store, $this->lock, $identity);
            $this->fail('The certificate limit must be reported');
        } catch (Exception $error) {
            $this->assertSame(Exception::DISTRIBUTION_SIGNING_IDENTITY_INVALID, $error->getType());
            $this->assertStringContainsString('Import an existing certificate instead', $error->getMessage());
        }
    }

    /**
     * @return iterable<string, array{StoreException, string}>
     */
    public static function failures(): iterable
    {
        yield 'unauthorized' => [new Unauthorized('Forbidden'), Exception::DISTRIBUTION_CONNECTION_INVALID];
        yield 'unavailable' => [new Unavailable('Down'), Exception::DISTRIBUTION_STORE_UNAVAILABLE];
        yield 'rate limited' => [new RateLimited('Spent'), Exception::DISTRIBUTION_STORE_UNAVAILABLE];
        yield 'invalid' => [new Invalid('Refused'), Exception::DISTRIBUTION_SIGNING_IDENTITY_INVALID];
        yield 'conflict other than the certificate limit' => [new Conflict('A profile with this name already exists'), Exception::DISTRIBUTION_SIGNING_IDENTITY_INVALID];
    }

    #[DataProvider('failures')]
    public function testFailureMapsStoreErrors(StoreException $error, string $type): void
    {
        $failure = Provisioning::failure($error);

        $this->assertSame($type, $failure->getType());
        $this->assertSame($error->getMessage(), $failure->getMessage());
        $this->assertSame($error, $failure->getPrevious());
    }

    public function testCreateFailureRemovesWhatItCreated(): void
    {
        $this->store->failProfileAfter = 2;

        try {
            $this->create('identity', [self::APP, self::WIDGET]);
            $this->fail('A failed profile must fail the create');
        } catch (Unavailable $error) {
            $this->assertSame('App Store Connect returned 500', $error->getMessage());
        }

        $this->assertSame([], $this->store->certificates);
        $this->assertSame([], $this->store->profiles);
        $this->assertSame([], $this->database->documents);
    }

    public function testCreateFailureKeepsTheSharedCertificate(): void
    {
        $first = $this->create('first', [self::APP]);
        $this->store->failProfileAfter = $this->store->profileCalls + 2;

        try {
            $this->create('second', [self::APP, self::WIDGET]);
            $this->fail('A failed profile must fail the create');
        } catch (Unavailable $error) {
            $this->assertSame('App Store Connect returned 500', $error->getMessage());
        }

        $this->assertSame([$first->getAttribute('externalId')], \array_keys($this->store->certificates));
        $this->assertSame(\array_values($first->getAttribute('secret')['managed']['profiles']), \array_keys($this->store->profiles));
    }

    public function testCreateStopsWhenTheLeaseIsLost(): void
    {
        $this->redis->refreshes = 2;

        try {
            $this->create('identity', [self::APP, self::WIDGET]);
            $this->fail('A lost lease must stop the create');
        } catch (Contention $error) {
            $this->assertSame('The Apple team lock expired before the signing change completed.', $error->getMessage());
        }

        $this->assertSame(['createCertificate', 'createProfile', 'deleteProfile', 'deleteCertificate'], \array_values(\array_filter($this->store->calls, static fn (string $call): bool => !\str_ends_with($call, 'Bundle'))));
        $this->assertSame([], $this->store->certificates);
        $this->assertSame([], $this->store->profiles);
        $this->assertSame([], $this->database->documents);
    }

    public function testCreateRefusesSigningFilesTooLargeToStore(): void
    {
        $this->store->profileBytes = 400_000;

        try {
            $this->create('identity', [self::APP, self::WIDGET]);
            $this->fail('Signing files beyond the secret size must be refused');
        } catch (Exception $error) {
            $this->assertSame(Exception::DISTRIBUTION_SIGNING_IDENTITY_INVALID, $error->getType());
            $this->assertStringContainsString('of 2 bundle IDs are too large', $error->getMessage());
        }

        $this->assertSame([], $this->store->certificates);
        $this->assertSame([], $this->store->profiles);
        $this->assertSame([], $this->database->documents);
    }

    /**
     * @return iterable<string, array{string, Status}>
     */
    public static function statuses(): iterable
    {
        yield 'expired' => ['2026-11-28 00:00:00', Status::Expired];
        yield 'past' => ['2026-11-01 00:00:00', Status::Expired];
        yield 'expiring' => ['2026-12-08 00:00:00', Status::Expiring];
        yield 'renewal boundary' => ['2026-12-28 00:00:00', Status::Expiring];
        yield 'active' => ['2026-12-28 00:00:01', Status::Active];
    }

    #[DataProvider('statuses')]
    public function testStatus(string $expiresAt, Status $status): void
    {
        $this->assertSame($status, Provisioning::status(new \DateTimeImmutable($expiresAt . ' UTC'), new \DateTimeImmutable(self::NOW . ' UTC')));
    }

    /**
     * @return iterable<string, array{array<string, mixed>, int}>
     */
    public static function intervals(): iterable
    {
        $managed = ['platform' => 'ios', 'mode' => 'managed', 'status' => 'expiring', 'expiresAt' => '2026-12-08 00:00:00.000'];
        $secret = static fn (?string $renewedAt, string $certificateExpiresAt = '2027-11-01 00:00:00.000', ?string $attemptedAt = null, ?string $failure = null): array => ['secret' => ['managed' => [
            'certificateExpiresAt' => $certificateExpiresAt,
            'renewedAt' => $renewedAt,
            'attemptedAt' => $attemptedAt,
            'failure' => $failure,
        ]]];
        $expired = [...$managed, 'status' => 'expired', 'expiresAt' => '2026-11-27 00:00:00.000'];

        yield 'managed ios with expiring profiles' => [[...$managed, ...$secret(null)], Provisioning::RENEWING_MINUTES];
        yield 'renewed more than a day ago' => [[...$managed, ...$secret('2026-11-26 23:00:00.000')], Provisioning::RENEWING_MINUTES];
        yield 'renewed within a day' => [[...$managed, ...$secret('2026-11-27 01:00:00.000')], Provisioning::ACTIVE_MINUTES];
        yield 'expiring certificate' => [[...$managed, ...$secret(null, '2026-12-08 00:00:00.000')], Provisioning::ACTIVE_MINUTES];
        yield 'managed ios active' => [[...$managed, 'status' => 'active', 'expiresAt' => '2027-06-01 00:00:00.000', ...$secret(null)], Provisioning::ACTIVE_MINUTES];
        yield 'expired profiles with a valid certificate' => [[...$expired, ...$secret(null)], Provisioning::RENEWING_MINUTES];
        yield 'expired profiles renewed within a day' => [[...$expired, ...$secret('2026-11-27 01:00:00.000')], Provisioning::ACTIVE_MINUTES];
        yield 'expired profiles with an expiring certificate' => [[...$expired, ...$secret(null, '2026-12-08 00:00:00.000')], Provisioning::ACTIVE_MINUTES];
        yield 'expired certificate' => [[...$expired, ...$secret(null, '2026-11-27 00:00:00.000')], 0];
        yield 'expired without a certificate expiry' => [$expired, 0];
        yield 'store unavailable within the retry' => [[...$managed, ...$secret(null, attemptedAt: '2026-11-27 23:50:00.000', failure: Exception::DISTRIBUTION_STORE_UNAVAILABLE)], Provisioning::RETRY_MINUTES];
        yield 'store unavailable after the retry' => [[...$managed, ...$secret(null, attemptedAt: '2026-11-27 23:45:00.000', failure: Exception::DISTRIBUTION_STORE_UNAVAILABLE)], Provisioning::RENEWING_MINUTES];
        yield 'connection invalid within a day' => [[...$managed, ...$secret(null, attemptedAt: '2026-11-27 01:00:00.000', failure: Exception::DISTRIBUTION_CONNECTION_INVALID)], Provisioning::ACTIVE_MINUTES];
        yield 'connection missing within a day' => [[...$managed, ...$secret(null, attemptedAt: '2026-11-27 01:00:00.000', failure: Exception::DISTRIBUTION_CONNECTION_NOT_FOUND)], Provisioning::ACTIVE_MINUTES];
        yield 'signing refused within a day' => [[...$managed, ...$secret(null, attemptedAt: '2026-11-27 01:00:00.000', failure: Exception::DISTRIBUTION_SIGNING_IDENTITY_INVALID)], Provisioning::ACTIVE_MINUTES];
        yield 'connection invalid more than a day ago' => [[...$managed, ...$secret(null, attemptedAt: '2026-11-26 23:00:00.000', failure: Exception::DISTRIBUTION_CONNECTION_INVALID)], Provisioning::RENEWING_MINUTES];
        yield 'expired profiles after a failure' => [[...$expired, ...$secret(null, attemptedAt: '2026-11-27 23:50:00.000', failure: Exception::DISTRIBUTION_STORE_UNAVAILABLE)], Provisioning::RETRY_MINUTES];
        yield 'managed android' => [[...$managed, 'platform' => 'android'], Provisioning::ACTIVE_MINUTES];
        yield 'managed android expired' => [[...$expired, 'platform' => 'android'], 0];
        yield 'imported ios' => [[...$managed, 'mode' => 'imported'], Provisioning::ACTIVE_MINUTES];
        yield 'imported ios expired' => [[...$expired, 'mode' => 'imported', ...$secret(null)], 0];
        yield 'no expiry' => [[...$managed, 'expiresAt' => null], 0];
        yield 'revoked' => [[...$managed, 'status' => 'revoked', ...$secret(null)], 0];
    }

    /**
     * @param array<string, mixed> $attributes
     */
    #[DataProvider('intervals')]
    public function testInterval(array $attributes, int $interval): void
    {
        $this->assertSame($interval, Provisioning::interval(new Document($attributes), new \DateTimeImmutable(self::NOW . ' UTC')));
    }

    public function testCreatedIdentityIsCheckedDaily(): void
    {
        $identity = $this->create('identity', [self::APP]);

        $this->assertSame(Provisioning::ACTIVE_MINUTES, Provisioning::interval($identity, new \DateTimeImmutable()));
    }

    public function testRenewRegeneratesOnlyExpiringProfiles(): void
    {
        $this->store->profileDays[self::APP] = 10;
        $identity = $this->create('identity', [self::APP, self::WIDGET]);
        $before = $identity->getAttribute('secret');
        $this->assertSame(Status::Expiring->value, $identity->getAttribute('status'));
        $this->assertSame(Provisioning::RENEWING_MINUTES, Provisioning::interval($identity, new \DateTimeImmutable()));
        $this->store->calls = [];

        $renewed = $this->provisioning->renew($this->store, $this->lock, $identity);

        $this->assertSame(['createProfile', 'deleteProfile'], $this->store->calls, 'The old profile is removed only once the identity holds the new one');
        $after = $renewed->getAttribute('secret');
        $this->assertSame($before['profiles'][self::WIDGET], $after['profiles'][self::WIDGET]);
        $this->assertSame($before['managed']['profiles'][self::WIDGET], $after['managed']['profiles'][self::WIDGET]);
        $this->assertNotSame($before['profiles'][self::APP], $after['profiles'][self::APP]);
        $this->assertArrayNotHasKey($before['managed']['profiles'][self::APP], $this->store->profiles);
        $this->assertArrayHasKey($after['managed']['profiles'][self::APP], $this->store->profiles);
        $name = $this->store->profiles[$after['managed']['profiles'][self::APP]]->name;
        $this->assertStringStartsWith('Appwrite io.example.app App Store identity ', $name);
        $this->assertTrue(\ctype_xdigit(\substr($name, -8)));
        $this->assertTrue(Profile::parse((string) \base64_decode($after['profiles'][self::APP], true))->contains($this->certificate($identity)));
        $this->assertSame(Status::Expiring->value, $renewed->getAttribute('status'));
        $this->assertNotNull($after['managed']['renewedAt']);
        $this->assertSame(Provisioning::ACTIVE_MINUTES, Provisioning::interval($renewed, new \DateTimeImmutable()), 'A renewed identity cools down for a day');
        $this->assertSame($renewed->getArrayCopy(), $this->database->getDocument(self::COLLECTION, 'identity')->getArrayCopy());
    }

    public function testRenewRecreatesAProfileAlreadyGoneAtApple(): void
    {
        $this->store->profileDays[self::APP] = 10;
        $identity = $this->create('identity', [self::APP]);
        unset($this->store->profiles[$identity->getAttribute('secret')['managed']['profiles'][self::APP]]);

        $renewed = $this->provisioning->renew($this->store, $this->lock, $identity);

        $this->assertArrayHasKey($renewed->getAttribute('secret')['managed']['profiles'][self::APP], $this->store->profiles);
    }

    public function testRenewOnlyFlagsAnExpiringCertificate(): void
    {
        $this->store->certificateDays = 20;
        $identity = $this->create('identity', [self::APP]);
        $identity = $this->database->updateDocument(self::COLLECTION, 'identity', new Document(['status' => Status::Active->value]));
        $this->store->calls = [];
        $writes = $this->database->updates;

        $renewed = $this->provisioning->renew($this->store, $this->lock, $identity);

        $this->assertSame([], $this->store->calls);
        $this->assertSame(Status::Expiring->value, $renewed->getAttribute('status'));
        $this->assertSame($identity->getAttribute('secret'), $renewed->getAttribute('secret'));
        $this->assertSame($writes + 1, $this->database->updates);
        $this->assertSame(Provisioning::ACTIVE_MINUTES, Provisioning::interval($renewed, new \DateTimeImmutable()));

        $this->assertSame($renewed, $this->provisioning->renew($this->store, $this->lock, $renewed), 'An unchanged status is not written again');
        $this->assertSame($writes + 1, $this->database->updates);
    }

    public function testRenewWithoutExpiryDoesNothing(): void
    {
        $identity = new Document(['$id' => 'identity', 'platform' => 'ios', 'mode' => 'managed', 'expiresAt' => null]);

        $this->assertSame($identity, $this->provisioning->renew($this->store, $this->lock, $identity));
        $this->assertSame([], $this->store->calls);
        $this->assertSame(0, $this->database->updates);
    }

    public function testRenewFailureKeepsTheOldProfile(): void
    {
        $this->store->profileDays[self::APP] = 10;
        $identity = $this->create('identity', [self::APP]);
        $old = $identity->getAttribute('secret')['managed']['profiles'][self::APP];
        $documents = $this->snapshot();
        $this->store->failProfileAfter = $this->store->profileCalls + 1;

        try {
            $this->provisioning->renew($this->store, $this->lock, $identity);
            $this->fail('A failed profile must fail the renewal');
        } catch (Unavailable $error) {
            $this->assertSame('App Store Connect returned 500', $error->getMessage());
        }

        $this->assertSame([$old], $this->store->activeProfiles(), 'The identity keeps a profile Apple still honours');
        $this->assertSame($documents, $this->snapshot());
    }

    public function testRenewFailureRemovesTheProfilesItCreated(): void
    {
        $this->store->profileDays[self::APP] = 10;
        $this->store->profileDays[self::WIDGET] = 10;
        $identity = $this->create('identity', [self::APP, self::WIDGET]);
        $profiles = \array_keys($this->store->profiles);
        $documents = $this->snapshot();
        $this->store->failProfileAfter = $this->store->profileCalls + 2;
        $this->store->calls = [];

        try {
            $this->provisioning->renew($this->store, $this->lock, $identity);
            $this->fail('A failed profile must fail the renewal');
        } catch (Unavailable) {
            $this->assertSame(['createProfile', 'createProfile', 'deleteProfile'], $this->store->calls);
        }

        $this->assertSame($profiles, \array_keys($this->store->profiles));
        $this->assertSame($documents, $this->snapshot());
    }

    public function testRenewStopsWhenTheLeaseIsLost(): void
    {
        $this->store->profileDays[self::APP] = 10;
        $identity = $this->create('identity', [self::APP]);
        $profiles = \array_keys($this->store->profiles);
        $documents = $this->snapshot();
        $this->redis->refreshes = $this->redis->calls + 1;
        $this->store->calls = [];

        try {
            $this->provisioning->renew($this->store, $this->lock, $identity);
            $this->fail('A lost lease must stop the renewal');
        } catch (Contention) {
            $this->assertSame(['createProfile', 'deleteProfile'], $this->store->calls, 'The profile created before the lease was lost is removed');
        }

        $this->assertSame($profiles, \array_keys($this->store->profiles));
        $this->assertSame($documents, $this->snapshot());
    }

    public function testRenewRefusesABundleOfAnotherTeam(): void
    {
        $this->store->profileDays[self::APP] = 10;
        $identity = $this->create('identity', [self::APP]);
        $secret = $identity->getAttribute('secret');
        unset($secret['managed']['bundles'][self::APP]);
        $identity = $this->database->updateDocument(self::COLLECTION, 'identity', new Document(['secret' => $secret]));
        $this->store->seedBundle(self::APP, 'ZZZZZ99999');
        $documents = $this->snapshot();

        try {
            $this->provisioning->renew($this->store, $this->lock, $identity);
            $this->fail('A bundle of another team must be refused');
        } catch (Invalid $error) {
            $this->assertSame('Bundle ID "io.example.app" belongs to team ZZZZZ99999, not ABCDE12345.', $error->getMessage());
        }

        $this->assertSame($documents, $this->snapshot());
    }

    public function testFailRecordsTheAttemptAndRenewClearsIt(): void
    {
        $this->store->profileDays[self::APP] = 10;
        $identity = $this->create('identity', [self::APP]);
        $now = new \DateTimeImmutable();

        $failed = $this->provisioning->fail($this->lock, $identity, Exception::DISTRIBUTION_STORE_UNAVAILABLE);

        $managed = $failed->getAttribute('secret')['managed'];
        $this->assertSame(Exception::DISTRIBUTION_STORE_UNAVAILABLE, $managed['failure']);
        $this->assertNotNull($managed['attemptedAt']);
        $this->assertSame($identity->getAttribute('secret')['profiles'], $failed->getAttribute('secret')['profiles']);
        $this->assertSame($identity->getAttribute('status'), $failed->getAttribute('status'));
        $this->assertSame(Provisioning::RETRY_MINUTES, Provisioning::interval($failed, $now));
        $this->assertSame(Provisioning::RENEWING_MINUTES, Provisioning::interval($failed, $now->modify('+16 minutes')));

        $refused = $this->provisioning->fail($this->lock, $failed, Exception::DISTRIBUTION_CONNECTION_INVALID);

        $this->assertSame(Provisioning::ACTIVE_MINUTES, Provisioning::interval($refused, $now->modify('+1 hour')));
        $this->assertSame(Provisioning::RENEWING_MINUTES, Provisioning::interval($refused, $now->modify('+25 hours')));

        $renewed = $this->provisioning->renew($this->store, $this->lock, $refused);

        $this->assertNull($renewed->getAttribute('secret')['managed']['attemptedAt']);
        $this->assertNull($renewed->getAttribute('secret')['managed']['failure']);
        $this->assertNotNull($renewed->getAttribute('secret')['managed']['renewedAt']);
    }

    public function testFailWithoutTheLeaseWritesNothing(): void
    {
        $identity = $this->create('identity', [self::APP]);
        $writes = $this->database->updates;
        $this->redis->refreshes = $this->redis->calls;

        $this->assertSame($identity, $this->provisioning->fail($this->lock, $identity, Exception::DISTRIBUTION_STORE_UNAVAILABLE));
        $this->assertSame($writes, $this->database->updates);
    }

    public function testTrackWritesOnlyAChangedStatus(): void
    {
        $now = new \DateTimeImmutable(self::NOW . ' UTC');
        $identity = $this->database->createDocument(self::COLLECTION, new Document(['$id' => 'identity', 'status' => Status::Active->value, 'expiresAt' => '2026-11-27 00:00:00.000']));

        $tracked = $this->provisioning->track($identity, $now);

        $this->assertSame(Status::Expired->value, $tracked->getAttribute('status'));
        $this->assertSame(1, $this->database->updates);
        $this->assertSame($tracked, $this->provisioning->track($tracked, $now));
        $this->assertSame(1, $this->database->updates);

        $revoked = new Document(['$id' => 'revoked', 'status' => Status::Revoked->value, 'expiresAt' => '2026-11-27 00:00:00.000']);
        $this->assertSame($revoked, $this->provisioning->track($revoked, $now));
        $this->assertSame(1, $this->database->updates);
    }

    public function testRotateReissuesTheCertificateForTheWholeTeam(): void
    {
        $first = $this->create('first', [self::APP, self::WIDGET]);
        $second = $this->create('second', ['io.example.other']);
        $other = $this->database->createDocument(self::COLLECTION, new Document([
            '$id' => 'other',
            'platform' => 'ios',
            'mode' => 'managed',
            'teamId' => 'ZZZZZ99999',
            'externalId' => 'certificate-other',
        ]));
        $oldCertificate = $first->getAttribute('externalId');
        $oldProfiles = \array_keys($this->store->profiles);
        $this->store->calls = [];
        $this->database->onUpdate = function (): void {
            $this->store->calls[] = 'updateDocument';
        };

        $rotated = $this->provisioning->rotate($this->store, $this->lock, $second);

        $this->assertSame([
            'createCertificate',
            'createProfile',
            'createProfile',
            'createProfile',
            'updateDocument',
            'updateDocument',
            'deleteProfile',
            'deleteProfile',
            'deleteProfile',
            'deleteCertificate',
        ], $this->store->calls);

        $this->assertCount(1, $this->store->certificates);
        $issued = \array_values($this->store->certificates)[0];
        $this->assertNotSame($oldCertificate, $issued->id);
        $this->assertSame([], \array_intersect($oldProfiles, \array_keys($this->store->profiles)));
        $this->assertSame(['first', 'second'], \array_map(static fn (Document $identity): string => $identity->getId(), $rotated));

        $names = \array_map(static fn ($profile): string => $profile->name, \array_values($this->store->profiles));
        $suffix = \substr($names[0], -8);
        $this->assertTrue(\ctype_xdigit($suffix));
        $this->assertSame([
            "Appwrite io.example.app App Store first {$suffix}",
            "Appwrite io.example.app.widget App Store first {$suffix}",
            "Appwrite io.example.other App Store second {$suffix}",
        ], $names);

        foreach ($rotated as $identity) {
            $stored = $this->database->getDocument(self::COLLECTION, $identity->getId());
            $this->assertSame($identity->getArrayCopy(), $stored->getArrayCopy());
            $this->assertSame($issued->id, $stored->getAttribute('externalId'));
            $this->assertSame($issued->certificate->fingerprint(), $stored->getAttribute('fingerprint'));
            $this->assertSame($issued->certificate->fingerprint(), $this->certificate($stored)->fingerprint());

            foreach ($stored->getAttribute('secret')['profiles'] as $content) {
                $this->assertTrue(Profile::parse((string) \base64_decode($content, true))->contains($issued->certificate));
            }

            $this->assertSame(\array_values($stored->getAttribute('secret')['managed']['profiles']), \array_values(\array_intersect(\array_values($stored->getAttribute('secret')['managed']['profiles']), $this->store->activeProfiles())));
        }

        $this->assertSame($other->getArrayCopy(), $this->database->getDocument(self::COLLECTION, 'other')->getArrayCopy());
    }

    public function testRotateRefusesAnImportedIdentity(): void
    {
        try {
            $this->provisioning->rotate($this->store, $this->lock, new Document(['$id' => 'imported', 'platform' => 'ios', 'mode' => 'imported', 'teamId' => ProvisioningApple::TEAM]));
            $this->fail('An imported identity must not be rotated');
        } catch (Exception $error) {
            $this->assertSame(Exception::DISTRIBUTION_SIGNING_IDENTITY_INVALID, $error->getType());
        }

        $this->assertSame([], $this->store->calls);
    }

    public function testRotationFailureLeavesTheOldStateIntact(): void
    {
        $this->create('first', [self::APP]);
        $this->create('second', [self::WIDGET]);
        $documents = $this->snapshot();
        $certificates = \array_keys($this->store->certificates);
        $profiles = \array_keys($this->store->profiles);
        $this->store->failProfileAfter = $this->store->profileCalls + 2;
        $this->store->calls = [];

        try {
            $this->provisioning->rotate($this->store, $this->lock, $this->database->getDocument(self::COLLECTION, 'first'));
            $this->fail('A failed profile must fail the rotation');
        } catch (Exception $error) {
            $this->assertSame(Exception::DISTRIBUTION_STORE_UNAVAILABLE, $error->getType());
            $this->assertInstanceOf(Unavailable::class, $error->getPrevious());
        }

        $this->assertSame(['createCertificate', 'createProfile', 'createProfile', 'deleteProfile', 'deleteCertificate'], $this->store->calls);
        $this->assertSame($documents, $this->snapshot());
        $this->assertSame(0, $this->database->updates);
        $this->assertSame($certificates, \array_keys($this->store->certificates));
        $this->assertSame($profiles, \array_keys($this->store->profiles));
        $this->assertSame($profiles, $this->store->activeProfiles());
    }

    public function testRotationWriteFailureRollsBackAndConverges(): void
    {
        $this->create('first', [self::APP]);
        $this->create('second', [self::WIDGET]);
        $documents = $this->snapshot();
        $certificates = \array_keys($this->store->certificates);
        $profiles = \array_keys($this->store->profiles);
        $this->database->failUpdateAfter = 2;
        $this->store->calls = [];

        try {
            $this->provisioning->rotate($this->store, $this->lock, $this->database->getDocument(self::COLLECTION, 'first'));
            $this->fail('A failed write must fail the rotation');
        } catch (DatabaseException $error) {
            $this->assertSame('Write failed', $error->getMessage());
        }

        $this->assertSame(['createCertificate', 'createProfile', 'createProfile', 'deleteProfile', 'deleteProfile', 'deleteCertificate'], $this->store->calls, 'A failed write removes what the rotation created');
        $this->assertSame($documents, $this->snapshot(), 'The writes of every identity roll back together');
        $this->assertSame($certificates, \array_keys($this->store->certificates));
        $this->assertSame($profiles, \array_keys($this->store->profiles));
        $this->assertSame($profiles, $this->store->activeProfiles());

        $this->database->failUpdateAfter = null;

        $this->provisioning->rotate($this->store, $this->lock, $this->database->getDocument(self::COLLECTION, 'first'));

        $this->assertCount(1, $this->store->certificates, 'A later rotation leaves only its own certificate');
        $current = \array_key_first($this->store->certificates);
        $stored = [];

        foreach ($this->snapshot() as $identity) {
            $this->assertSame($current, $identity['externalId']);
            \array_push($stored, ...\array_values($identity['secret']['managed']['profiles']));
        }

        $this->assertSame($stored, $this->store->activeProfiles());
    }

    public function testRotationCommitReportedAsFailedKeepsWhatTheIdentitiesHold(): void
    {
        $this->create('first', [self::APP]);
        $this->create('second', [self::WIDGET]);
        $certificates = \array_keys($this->store->certificates);
        $this->database->failCommit = true;
        $this->store->calls = [];

        try {
            $this->provisioning->rotate($this->store, $this->lock, $this->database->getDocument(self::COLLECTION, 'first'));
            $this->fail('A commit reported as failed must fail the rotation');
        } catch (DatabaseException $error) {
            $this->assertSame('Commit failed', $error->getMessage());
        }

        $this->assertSame(['createCertificate', 'createProfile', 'createProfile'], $this->store->calls, 'Nothing an identity holds is removed');
        $issued = \array_key_last($this->store->certificates);
        $this->assertSame([...$certificates, $issued], \array_keys($this->store->certificates));

        foreach ($this->snapshot() as $identity) {
            $this->assertSame($issued, $identity['externalId']);
            $this->assertSame([], \array_diff(\array_values($identity['secret']['managed']['profiles']), $this->store->activeProfiles()), 'Every identity holds profiles Apple honours');
        }
    }

    public function testRotationStopsWhenTheLeaseIsLostBeforeWriting(): void
    {
        $this->create('first', [self::APP]);
        $this->create('second', [self::WIDGET]);
        $documents = $this->snapshot();
        $certificates = \array_keys($this->store->certificates);
        $profiles = \array_keys($this->store->profiles);
        $this->redis->refreshes = $this->redis->calls + 3;
        $this->store->calls = [];

        try {
            $this->provisioning->rotate($this->store, $this->lock, $this->database->getDocument(self::COLLECTION, 'first'));
            $this->fail('A lost lease must stop the rotation');
        } catch (Contention) {
            $this->assertSame(['createCertificate', 'createProfile', 'createProfile', 'deleteProfile', 'deleteProfile', 'deleteCertificate'], $this->store->calls);
        }

        $this->assertSame($documents, $this->snapshot());
        $this->assertSame(0, $this->database->updates);
        $this->assertSame($certificates, \array_keys($this->store->certificates));
        $this->assertSame($profiles, $this->store->activeProfiles());
    }

    public function testRotationRefusesSigningFilesTooLargeToStore(): void
    {
        $this->create('identity', [self::APP, self::WIDGET]);
        $documents = $this->snapshot();
        $certificates = \array_keys($this->store->certificates);
        $profiles = \array_keys($this->store->profiles);
        $this->store->profileBytes = 400_000;

        try {
            $this->provisioning->rotate($this->store, $this->lock, $this->database->getDocument(self::COLLECTION, 'identity'));
            $this->fail('Signing files beyond the secret size must be refused');
        } catch (Exception $error) {
            $this->assertSame(Exception::DISTRIBUTION_SIGNING_IDENTITY_INVALID, $error->getType());
            $this->assertStringContainsString('of 2 bundle IDs are too large', $error->getMessage());
        }

        $this->assertSame($documents, $this->snapshot());
        $this->assertSame($certificates, \array_keys($this->store->certificates));
        $this->assertSame($profiles, \array_keys($this->store->profiles));
    }

    public function testDeleteRemovesTheProfilesAndTheLastCertificate(): void
    {
        $identity = $this->forget($this->create('identity', [self::APP, self::WIDGET]));

        $this->assertTrue($this->provisioning->delete($this->store, $this->lock, $identity));

        $this->assertSame([], $this->store->profiles);
        $this->assertSame([], $this->store->certificates);
    }

    public function testDeleteKeepsTheCertificateAnotherIdentityHolds(): void
    {
        $kept = $this->create('kept', [self::APP]);
        $identity = $this->forget($this->create('identity', [self::WIDGET]));

        $this->assertTrue($this->provisioning->delete($this->store, $this->lock, $identity));

        $this->assertSame([$kept->getAttribute('externalId')], \array_keys($this->store->certificates));
        $this->assertSame(\array_values($kept->getAttribute('secret')['managed']['profiles']), $this->store->activeProfiles());
    }

    public function testDeleteIsBestEffort(): void
    {
        $identity = $this->forget($this->create('identity', [self::APP]));
        $this->store->failDeletes = true;

        $this->assertFalse($this->provisioning->delete($this->store, $this->lock, $identity));
        $this->assertSame(['deleteProfile', 'deleteCertificate'], \array_slice($this->store->calls, -2));
    }

    public function testDeleteWithoutTheLeaseRemovesNothing(): void
    {
        $identity = $this->forget($this->create('identity', [self::APP]));
        $this->redis->refreshes = $this->redis->calls;
        $this->store->calls = [];

        $this->assertFalse($this->provisioning->delete($this->store, $this->lock, $identity));
        $this->assertSame([], $this->store->calls);
        $this->assertCount(1, $this->store->certificates);
    }

    public function testDeleteLeavesAnImportedIdentityAtApple(): void
    {
        $this->assertTrue($this->provisioning->delete($this->store, $this->lock, new Document(['$id' => 'imported', 'platform' => 'ios', 'mode' => 'imported', 'externalId' => ''])));
        $this->assertSame([], $this->store->calls);
    }

    /**
     * @param list<string> $identifiers
     */
    private function create(string $signingIdentityId, array $identifiers): Document
    {
        return $this->provisioning->create($this->store, $this->lock, $this->connection, $signingIdentityId, 'Example', $identifiers, ProvisioningApple::TEAM);
    }

    /**
     * Removes the identity from the database, as the delete route does before it cleans up at Apple.
     */
    private function forget(Document $identity): Document
    {
        unset($this->database->documents[self::COLLECTION][$identity->getId()]);

        return $identity;
    }

    private function certificate(Document $identity): Certificate
    {
        $secret = $identity->getAttribute('secret');

        return Certificate::fromPkcs12((string) \base64_decode($secret['certificate'], true), $secret['password']);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function snapshot(): array
    {
        return \array_map(static fn (Document $identity): array => $identity->getArrayCopy(), $this->database->documents[self::COLLECTION] ?? []);
    }
}
