<?php

declare(strict_types=1);

namespace Tests\Unit\Distribution;

use Appwrite\Distribution\Check\Code;
use Appwrite\Distribution\Check\Severity;
use Appwrite\Distribution\Checks;
use Appwrite\Distribution\Connection\Status as ConnectionStatus;
use Appwrite\Distribution\Platform;
use Appwrite\Distribution\Provider;
use Appwrite\Distribution\Signing\Status as SigningStatus;
use Appwrite\Distribution\Stores;
use Appwrite\Extend\Exception;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Distribution\Fakes\ScriptedStore;
use Tests\Unit\Distribution\Fakes\ScriptedStores;
use Tests\Unit\Distribution\Fakes\SubmissionDatabase;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Distribution\Exception\Invalid;
use Utopia\Distribution\Exception\NotFound;
use Utopia\Distribution\Exception\RateLimited;
use Utopia\Distribution\Exception\Unauthorized;
use Utopia\Distribution\Exception\Unavailable;
use Utopia\Distribution\Issue;
use Utopia\Distribution\Tests\Transport;

final class ChecksTest extends TestCase
{
    private const string PACKAGE = 'io.example.app';

    private const string TRACK = 'internal';

    private const string RELEASED_ARTIFACT = '41';

    private SubmissionDatabase $database;

    private ScriptedStore $store;

    private Checks $checks;

    private Document $app;

    protected function setUp(): void
    {
        $this->database = new SubmissionDatabase();
        $this->store = new ScriptedStore();
        $this->checks = new Checks($this->database, new ScriptedStores($this->store));
        $this->app = $this->database->seed('distributionApps', ['$id' => 'app', 'name' => 'App']);
        $this->database->seed('distributionConnections', [
            '$id' => 'connection',
            'provider' => Provider::Google->value,
            'status' => ConnectionStatus::Active->value,
            'credentials' => ['clientEmail' => 'checks@example.iam.gserviceaccount.com', 'privateKey' => 'key'],
        ]);
        $this->database->seed('distributionSigningIdentities', [
            '$id' => 'keystore',
            'platform' => Platform::Android->value,
            'status' => SigningStatus::Active->value,
            'identifiers' => [],
            'expiresAt' => DateTime::addSeconds(new \DateTime(), 86400 * 365),
        ]);
    }

    public function testCleanTargetIsReady(): void
    {
        $check = $this->checks->run($this->app, $this->target(), self::TRACK);

        $this->assertTrue($check['ready']);
        $this->assertSame([], $check['issues']);
        $this->assertSame(['app'], $this->store->calls, 'Without an artifact only the store app is looked up.');
    }

    public function testHigherVersionPasses(): void
    {
        $this->store->latest = 4;

        $check = $this->checks->run($this->app, $this->target(), self::TRACK, $this->artifact(['versionCode' => 5]));

        $this->assertTrue($check['ready']);
        $this->assertSame(['latestVersionCode'], $this->store->calls, 'The version lookup also answers a missing app, so no second edit is opened.');
    }

    /**
     * @param array<string, mixed> $target
     * @param array<string, mixed>|null $connection
     * @param array<string, mixed>|null $signingIdentity
     * @param array<string, \Throwable> $failures
     * @param array<string, mixed>|null $artifact
     */
    #[DataProvider('issues')]
    public function testReportsIssue(
        array $target,
        ?array $connection,
        ?array $signingIdentity,
        array $failures,
        int $latest,
        ?array $artifact,
        Code $code,
        Severity $severity,
        string $field,
        bool $manual,
    ): void {
        if ($connection !== null) {
            $this->database->seed('distributionConnections', ['$id' => 'other-connection', 'provider' => Provider::Google->value, ...$connection]);
        }

        if ($signingIdentity !== null) {
            $this->database->seed('distributionSigningIdentities', ['$id' => 'other-keystore', 'platform' => Platform::Android->value, ...$signingIdentity]);
        }

        $this->store->failures = $failures;
        $this->store->latest = $latest;

        $check = $this->checks->run($this->app, $this->target($target), self::TRACK, $artifact === null ? null : $this->artifact($artifact));

        $this->assertCount(1, $check['issues'], 'Exactly the scripted issue is reported: ' . \json_encode($check['issues']));
        $issue = $check['issues'][0];
        $this->assertSame($code->value, $issue['code']);
        $this->assertSame($severity->value, $issue['severity']);
        $this->assertSame(Platform::Android->value, $issue['platform']);
        $this->assertSame($field, $issue['field']);
        $this->assertSame($manual, $issue['manualActionRequired']);
        $this->assertNotSame('', $issue['message']);
        $this->assertNotSame('', $issue['remediation']);
        $this->assertSame($severity !== Severity::Error, $check['ready']);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, array<string, mixed>|null, array<string, mixed>|null, array<string, \Throwable>, int, array<string, mixed>|null, Code, Severity, string, bool}>
     */
    public static function issues(): iterable
    {
        $expired = DateTime::addSeconds(new \DateTime(), -60);
        $expiring = DateTime::addSeconds(new \DateTime(), 86400);
        $agreements = new Issue('FORBIDDEN.REQUIRED_AGREEMENTS_MISSING_OR_EXPIRED', 'Agreements are missing', manual: true);

        yield 'connection_missing without a connection' => [['connectionId' => ''], null, null, [], 0, null, Code::ConnectionMissing, Severity::Error, 'connectionId', false];
        yield 'connection_missing for a deleted connection' => [['connectionId' => 'deleted'], null, null, [], 0, null, Code::ConnectionMissing, Severity::Error, 'connectionId', false];
        yield 'connection_unauthorized for an invalid connection' => [['connectionId' => 'other-connection'], ['status' => ConnectionStatus::Invalid->value, 'error' => 'invalid_grant'], null, [], 0, null, Code::ConnectionUnauthorized, Severity::Error, 'connectionId', false];
        yield 'connection_unauthorized for rejected credentials' => [[], null, null, ['app' => new Unauthorized('Google rejected the service account assertion', credential: true)], 0, null, Code::ConnectionUnauthorized, Severity::Error, 'connectionId', false];
        yield 'connection_unauthorized for an invalid grant' => [[], null, null, ['app' => new Unauthorized('Google rejected the service account assertion', [new Issue('invalid_grant', 'Invalid JWT')])], 0, null, Code::ConnectionUnauthorized, Severity::Error, 'connectionId', false];
        yield 'connection_role_insufficient' => [[], null, null, ['app' => new Unauthorized('Google Play returned 403: The caller does not have permission', [new Issue('permissionDenied', 'The caller does not have permission')])], 0, null, Code::ConnectionRoleInsufficient, Severity::Error, 'connectionId', true];
        yield 'store_agreement_required' => [[], null, null, ['app' => new Unauthorized('App Store Connect returned 403', [$agreements])], 0, null, Code::StoreAgreementRequired, Severity::Error, 'connectionId', true];
        yield 'store_app_missing' => [[], null, null, ['app' => new NotFound('Google Play returned 404', [new Issue('applicationNotFound', 'No application was found')])], 0, null, Code::StoreAppMissing, Severity::Error, 'identifier', true];
        yield 'store_app_missing on the version lookup' => [[], null, null, ['latestVersionCode' => new NotFound('Google Play returned 404')], 0, ['versionCode' => 2], Code::StoreAppMissing, Severity::Error, 'identifier', true];
        yield 'signing_missing without an identity' => [['signingIdentityId' => ''], null, null, [], 0, null, Code::SigningMissing, Severity::Error, 'signingIdentityId', false];
        yield 'signing_missing for a deleted identity' => [['signingIdentityId' => 'deleted'], null, null, [], 0, null, Code::SigningMissing, Severity::Error, 'signingIdentityId', false];
        yield 'signing_expired past its expiry' => [['signingIdentityId' => 'other-keystore'], null, ['status' => SigningStatus::Active->value, 'expiresAt' => $expired], [], 0, null, Code::SigningExpired, Severity::Error, 'signingIdentityId', false];
        yield 'signing_expired by status' => [['signingIdentityId' => 'other-keystore'], null, ['status' => SigningStatus::Expired->value], [], 0, null, Code::SigningExpired, Severity::Error, 'signingIdentityId', false];
        yield 'signing_expired when revoked' => [['signingIdentityId' => 'other-keystore'], null, ['status' => SigningStatus::Revoked->value], [], 0, null, Code::SigningExpired, Severity::Error, 'signingIdentityId', false];
        yield 'signing_expired warning within a week' => [['signingIdentityId' => 'other-keystore'], null, ['status' => SigningStatus::Active->value, 'expiresAt' => $expiring], [], 0, null, Code::SigningExpired, Severity::Warning, 'signingIdentityId', false];
        yield 'signing_expired warning by status' => [['signingIdentityId' => 'other-keystore'], null, ['status' => SigningStatus::Expiring->value], [], 0, null, Code::SigningExpired, Severity::Warning, 'signingIdentityId', false];
        yield 'signing_identifier_mismatch' => [['signingIdentityId' => 'other-keystore'], null, ['status' => SigningStatus::Active->value, 'identifiers' => ['io.example.other', 'io.other.*']], [], 0, null, Code::SigningIdentifierMismatch, Severity::Error, 'identifier', false];
        yield 'version_not_increasing for an equal version code' => [[], null, null, [], 7, ['versionCode' => 7], Code::VersionNotIncreasing, Severity::Error, 'versionCode', false];
        yield 'version_not_increasing for a lower version code' => [[], null, null, [], 9, ['versionCode' => 1], Code::VersionNotIncreasing, Severity::Error, 'versionCode', false];
        yield 'target_sdk_too_low' => [[], null, null, [], 0, ['manifest' => ['targetSdk' => Checks::TARGET_SDK - 1]], Code::TargetSdkTooLow, Severity::Error, 'targetSdk', false];
        yield 'signing_missing once for an unsigned artifact without an identity' => [['signingIdentityId' => ''], null, null, [], 0, ['versionCode' => 2, 'signed' => false], Code::SigningMissing, Severity::Error, 'artifactId', false];
    }

    /**
     * @param list<string> $identifiers
     */
    #[DataProvider('coveringIdentifiers')]
    public function testSigningIdentityCoversIdentifier(array $identifiers): void
    {
        $this->database->seed('distributionSigningIdentities', ['$id' => 'profile', 'platform' => Platform::Android->value, 'identifiers' => $identifiers]);

        $check = $this->checks->run($this->app, $this->target(['signingIdentityId' => 'profile']), self::TRACK);

        $this->assertTrue($check['ready'], \json_encode($check['issues']) ?: '');
    }

    /**
     * @return iterable<string, array{list<string>}>
     */
    public static function coveringIdentifiers(): iterable
    {
        yield 'exact' => [['io.example.other', self::PACKAGE]];
        yield 'wildcard' => [['*']];
        yield 'prefix wildcard' => [['io.example.*']];
    }

    public function testUnusableCredentialsAreUnauthorized(): void
    {
        $this->database->seed('distributionConnections', ['$id' => 'unusable', 'provider' => Provider::Google->value, 'status' => ConnectionStatus::Active->value, 'credentials' => ['clientEmail' => 'checks@example.iam.gserviceaccount.com']]);
        $checks = new Checks($this->database, new Stores(production: false, client: new Transport()));

        $check = $checks->run($this->app, $this->target(['connectionId' => 'unusable']), self::TRACK);

        $this->assertFalse($check['ready']);
        $this->assertSame([Code::ConnectionUnauthorized->value], \array_column($check['issues'], 'code'));
        $this->assertSame('connectionId', $check['issues'][0]['field']);
    }

    public function testNoStoreSkipsStoreCalls(): void
    {
        $this->store->latest = 99;

        $check = $this->checks->run($this->app, $this->target(), self::TRACK, $this->artifact(['versionCode' => 1]), store: false);

        $this->assertTrue($check['ready']);
        $this->assertSame([], $this->store->calls, 'No store call may open a Google edit while an upload holds one.');
    }

    public function testNoStoreStillReportsLocalIssues(): void
    {
        $check = $this->checks->run($this->app, $this->target(['connectionId' => '', 'signingIdentityId' => '']), self::TRACK, store: false);

        $this->assertFalse($check['ready']);
        $this->assertSame([Code::ConnectionMissing->value, Code::SigningMissing->value], \array_column($check['issues'], 'code'));
    }

    public function testVersionIsCheckedOnlyWithAnArtifact(): void
    {
        $this->store->latest = 99;

        $check = $this->checks->run($this->app, $this->target(), self::TRACK);

        $this->assertTrue($check['ready']);
        $this->assertNotContains('latestVersionCode', $this->store->calls);
    }

    public function testVersionIsSkippedForANonNumericBuild(): void
    {
        $this->store->latest = 99;

        $check = $this->checks->run($this->app, $this->target(), self::TRACK, $this->artifact(['platform' => Platform::Ios->value, 'buildNumber' => '1.2.3']));

        $this->assertTrue($check['ready']);
        $this->assertSame(['app'], $this->store->calls);
    }

    public function testArtifactLooksUpOnlyTheVersion(): void
    {
        $this->store->failures = ['latestVersionCode' => new NotFound('Google Play returned 404')];

        $check = $this->checks->run($this->app, $this->target(), self::TRACK, $this->artifact(['versionCode' => 1]));

        $this->assertSame([Code::StoreAppMissing->value], \array_column($check['issues'], 'code'));
        $this->assertSame(['latestVersionCode'], $this->store->calls);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function tracks(): iterable
    {
        yield 'internal' => [Platform::Android->value, 'internal'];
        yield 'alpha' => [Platform::Android->value, 'alpha'];
        yield 'beta' => [Platform::Android->value, 'beta'];
        yield 'android production' => [Platform::Android->value, 'production'];
        yield 'testflight' => [Platform::Ios->value, 'testflight'];
        yield 'external testflight' => [Platform::Ios->value, 'testflight-external'];
        yield 'ios production' => [Platform::Ios->value, 'production'];
    }

    #[DataProvider('tracks')]
    public function testProviderTrackPasses(string $platform, string $track): void
    {
        $check = $this->checks->run($this->app, $this->target(['platform' => $platform, 'configuration' => ['betaGroups' => 'group-1']]), $track);

        $this->assertTrue($check['ready'], \json_encode($check['issues']) ?: '');
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function groupless(): iterable
    {
        yield 'no configuration' => [[]];
        yield 'no betaGroups' => [['configuration' => ['scheme' => 'App']]];
        yield 'only separators' => [['configuration' => ['betaGroups' => ' , ']]];
    }

    /**
     * @param array<string, mixed> $attributes
     */
    #[DataProvider('groupless')]
    public function testExternalTestFlightWithoutGroupsIsRefusedBeforeAnyStoreCall(array $attributes): void
    {
        try {
            $this->checks->run($this->app, $this->target(['platform' => Platform::Ios->value, ...$attributes]), 'testflight-external');
            $this->fail('An external TestFlight track without beta groups must be refused.');
        } catch (Exception $exception) {
            $this->assertSame(Exception::DISTRIBUTION_TRACK_INVALID, $exception->getType());
            $this->assertSame(Checks::GROUPS_REQUIRED, $exception->getMessage());
        }

        $this->assertSame([], $this->store->calls);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalidTracks(): iterable
    {
        yield 'apple track on android' => [Platform::Android->value, 'testflight'];
        yield 'google track on ios' => [Platform::Ios->value, 'internal'];
        yield 'unknown track' => [Platform::Android->value, 'nightly'];
        yield 'empty track' => [Platform::Android->value, ''];
    }

    #[DataProvider('invalidTracks')]
    public function testTrackOfAnotherProviderIsRefusedBeforeAnyStoreCall(string $platform, string $track): void
    {
        try {
            $this->checks->run($this->app, $this->target(['platform' => $platform]), $track);
            $this->fail("Track '{$track}' must be refused for {$platform}.");
        } catch (Exception $exception) {
            $this->assertSame(Exception::DISTRIBUTION_TRACK_INVALID, $exception->getType());
        }

        $this->assertSame([], $this->store->calls);
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function supportedArtifacts(): iterable
    {
        yield 'target SDK at the requirement' => [['manifest' => ['targetSdk' => Checks::TARGET_SDK]]];
        yield 'target SDK above the requirement' => [['manifest' => ['targetSdk' => Checks::TARGET_SDK + 1]]];
        yield 'target SDK not inspected' => [['manifest' => []]];
        yield 'no manifest' => [[]];
    }

    /**
     * @param array<string, mixed> $artifact
     */
    #[DataProvider('supportedArtifacts')]
    public function testArtifactMeetingThePlayRequirementPasses(array $artifact): void
    {
        $check = $this->checks->run($this->app, $this->target(), self::TRACK, $this->artifact($artifact));

        $this->assertTrue($check['ready'], \json_encode($check['issues']) ?: '');
    }

    public function testTargetSdkIsCheckedOnlyForAndroid(): void
    {
        $check = $this->checks->run(
            $this->app,
            $this->target(['platform' => Platform::Ios->value]),
            'testflight',
            $this->artifact(['platform' => Platform::Ios->value, 'signed' => true, 'manifest' => ['targetSdk' => 21]]),
        );

        $this->assertTrue($check['ready'], \json_encode($check['issues']) ?: '');
    }

    /**
     * @return \Iterator<string, array{int, list<array{string, string}>, bool}>
     */
    public static function profiles(): \Iterator
    {
        yield 'expired' => [-60, [[Code::SigningExpired->value, Severity::Error->value]], false];
        yield 'expiring within a week' => [86400 * 3, [[Code::SigningExpired->value, Severity::Warning->value]], true];
        yield 'valid for longer than a week' => [86400 * 30, [], true];
    }

    /**
     * @param list<array{string, string}> $issues
     */
    #[DataProvider('profiles')]
    public function testProvisioningProfileOfAnIosArtifactIsChecked(int $seconds, array $issues, bool $ready): void
    {
        $check = $this->checks->run(
            $this->app,
            $this->target(['platform' => Platform::Ios->value]),
            'testflight',
            $this->artifact(['platform' => Platform::Ios->value, 'manifest' => ['profileExpiresAt' => DateTime::addSeconds(new \DateTime(), $seconds)]]),
        );

        $this->assertSame($issues, \array_map(static fn (array $issue): array => [$issue['code'], $issue['severity']], $check['issues']));
        $this->assertSame($ready, $check['ready']);
        $this->assertSame(\array_fill(0, \count($issues), 'profileExpiresAt'), \array_column($check['issues'], 'field'));
    }

    public function testProvisioningProfileIsCheckedOnlyForIos(): void
    {
        $check = $this->checks->run($this->app, $this->target(), self::TRACK, $this->artifact(['manifest' => ['profileExpiresAt' => DateTime::addSeconds(new \DateTime(), -60)]]));

        $this->assertTrue($check['ready'], \json_encode($check['issues']) ?: '');
    }

    public function testUnsignedIosArtifactIsNotReady(): void
    {
        $check = $this->checks->run(
            $this->app,
            $this->target(['platform' => Platform::Ios->value]),
            'testflight',
            $this->artifact(['platform' => Platform::Ios->value, 'signed' => false]),
        );

        $this->assertFalse($check['ready']);
        $this->assertSame([Code::SigningMissing->value], \array_column($check['issues'], 'code'));
        $this->assertSame('artifactId', $check['issues'][0]['field']);
        $this->assertSame(Platform::Ios->value, $check['issues'][0]['platform']);
    }

    public function testUnsignedAndroidArtifactIsNotReady(): void
    {
        $check = $this->checks->run($this->app, $this->target(), self::TRACK, $this->artifact(['signed' => false]));

        $this->assertFalse($check['ready']);
        $this->assertSame([Code::SigningMissing->value], \array_column($check['issues'], 'code'));
        $this->assertSame('artifactId', $check['issues'][0]['field']);
        $this->assertSame(Platform::Android->value, $check['issues'][0]['platform']);
        $this->assertSame('The artifact is not signed.', $check['issues'][0]['message']);
        $this->assertSame('Set a signing identity on the target and build again, or upload a bundle signed with your upload key.', $check['issues'][0]['remediation']);
    }

    public function testArtifactCheckSkipsTheTargetIdentity(): void
    {
        $this->database->seed('distributionSigningIdentities', ['$id' => 'revoked', 'platform' => Platform::Android->value, 'status' => SigningStatus::Revoked->value, 'identifiers' => ['io.example.other']]);

        $missing = $this->checks->run($this->app, $this->target(['signingIdentityId' => '']), self::TRACK, $this->artifact(['versionCode' => 1]));
        $revoked = $this->checks->run($this->app, $this->target(['signingIdentityId' => 'revoked']), self::TRACK, $this->artifact(['versionCode' => 1]));

        $this->assertTrue($missing['ready']);
        $this->assertSame([], $missing['issues'], 'A signed artifact is already signed, so the identity of the target is not needed.');
        $this->assertTrue($revoked['ready']);
        $this->assertSame([], $revoked['issues']);
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function heldEdits(): iterable
    {
        yield 'live upload claim' => [['claim' => ['id' => 'claim', 'expiresAt' => '2999-01-01 00:00:00.000']]];
        yield 'upload checkpoint' => [['checkpoint' => ['offset' => 0]]];
        yield 'open edit' => [['editId' => 'edit-1']];
    }

    /**
     * @param array<string, mixed> $external
     */
    #[DataProvider('heldEdits')]
    public function testGoogleStoreIsLeftAloneWhileAnotherSubmissionHoldsAnEdit(array $external): void
    {
        $this->sibling('connection', $external);
        $this->store->latest = 99;

        $check = $this->checks->run($this->app, $this->target(['signingIdentityId' => '']), self::TRACK, $this->artifact(['versionCode' => 1, 'signed' => false]));

        $this->assertSame([], $this->store->calls, 'Opening an edit would invalidate the edit the other submission holds.');
        $this->assertSame([Code::SigningMissing->value], \array_column($check['issues'], 'code'), 'Local issues are still reported.');
        $this->assertSame('artifactId', $check['issues'][0]['field']);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function freeEdits(): iterable
    {
        yield 'expired upload claim' => [['claim' => ['id' => 'claim', 'expiresAt' => '2000-01-01 00:00:00.000']], 'uploading'];
        yield 'committed edit' => [['editId' => 'edit-1', 'committed' => true], 'reviewing'];
        yield 'finished submission' => [['editId' => 'edit-1'], 'failed'];
    }

    /**
     * @param array<string, mixed> $external
     */
    #[DataProvider('freeEdits')]
    public function testGoogleStoreIsReachedWhenNoEditIsHeld(array $external, string $status): void
    {
        $this->sibling('connection', $external, $status);

        $this->checks->run($this->app, $this->target(), self::TRACK);

        $this->assertSame(['app'], $this->store->calls);
    }

    public function testGoogleStoreIsReachedWhileAnotherPackageHoldsAnEdit(): void
    {
        $this->sibling('connection', ['editId' => 'edit-1'], identifier: 'io.example.other');

        $this->checks->run($this->app, $this->target(), self::TRACK);

        $this->assertSame(['app'], $this->store->calls, 'Google invalidates open edits per service account and package.');
    }

    public function testGoogleStoreIsLeftAloneWhileAnotherConnectionOfTheServiceAccountHoldsAnEdit(): void
    {
        $account = ['email' => 'checks@example.iam.gserviceaccount.com'];
        $this->database->seed('distributionConnections', [...$this->database->getDocument('distributionConnections', 'connection')->getArrayCopy(), 'account' => $account]);
        $this->database->seed('distributionConnections', ['$id' => 'shared', 'provider' => Provider::Google->value, 'status' => ConnectionStatus::Active->value, 'credentials' => [], 'account' => $account]);
        $this->sibling('shared', ['editId' => 'edit-1']);

        $this->checks->run($this->app, $this->target(), self::TRACK);

        $this->assertSame([], $this->store->calls, 'A connection sharing the service account would invalidate the other edit.');
    }

    public function testGoogleStoreIsReachedWhileAnotherServiceAccountHoldsAnEdit(): void
    {
        $this->database->seed('distributionConnections', ['$id' => 'separate', 'provider' => Provider::Google->value, 'status' => ConnectionStatus::Active->value, 'credentials' => [], 'account' => ['email' => 'other@example.iam.gserviceaccount.com']]);
        $this->sibling('separate', ['editId' => 'edit-1']);

        $this->checks->run($this->app, $this->target(), self::TRACK);

        $this->assertSame(['app'], $this->store->calls);
    }

    public function testAppleStoreIsNeverContended(): void
    {
        $this->database->seed('distributionConnections', ['$id' => 'apple', 'provider' => Provider::Apple->value, 'status' => ConnectionStatus::Active->value, 'credentials' => []]);
        $this->sibling('apple', ['claim' => ['id' => 'claim', 'expiresAt' => '2999-01-01 00:00:00.000']]);

        $this->checks->run($this->app, $this->target(['connectionId' => 'apple']), self::TRACK);

        $this->assertSame(['app'], $this->store->calls);
    }

    public function testArtifactReleasedThroughAppwriteIsAPromotion(): void
    {
        $this->store->latest = 7;
        $this->released(self::RELEASED_ARTIFACT, 7);

        $check = $this->checks->run($this->app, $this->target(), 'production', $this->artifact(['$sequence' => self::RELEASED_ARTIFACT, 'versionCode' => 7]));

        $this->assertTrue($check['ready']);
        $this->assertSame([], $check['issues']);
        $this->assertSame(['latestVersionCode'], $this->store->calls);
    }

    /**
     * @return \Iterator<string, array{string, int}>
     */
    public static function unpromoted(): \Iterator
    {
        yield 'another version code reached the store elsewhere' => [self::RELEASED_ARTIFACT, 6];
        yield 'another artifact with the same version code' => ['99', 7];
    }

    #[DataProvider('unpromoted')]
    public function testVersionNotReleasedFromThisArtifactIsNotAPromotion(string $releasedArtifact, int $versionCode): void
    {
        $this->store->latest = 7;
        $this->released($releasedArtifact, $versionCode);

        $check = $this->checks->run($this->app, $this->target(), 'production', $this->artifact(['$sequence' => self::RELEASED_ARTIFACT, 'versionCode' => 7]));

        $this->assertFalse($check['ready']);
        $this->assertSame([Code::VersionNotIncreasing->value], \array_column($check['issues'], 'code'));
    }

    public function testSignedArtifactNeedsNoSigningIdentity(): void
    {
        $check = $this->checks->run($this->app, $this->target(['signingIdentityId' => '']), self::TRACK, $this->artifact(['versionCode' => 1, 'signed' => true]));

        $this->assertTrue($check['ready']);
        $this->assertSame([], $check['issues']);
    }

    public function testIssuesAcrossAreasAccumulate(): void
    {
        $this->store->failures = ['app' => new NotFound('Google Play returned 404')];

        $check = $this->checks->run($this->app, $this->target(['signingIdentityId' => '']), self::TRACK);

        $this->assertFalse($check['ready']);
        $this->assertSame([Code::StoreAppMissing->value, Code::SigningMissing->value], \array_column($check['issues'], 'code'));
    }

    /**
     * @return iterable<string, array{\Throwable}>
     */
    public static function storeFailures(): iterable
    {
        yield 'rate limited' => [new RateLimited('Google Play returned 429')];
        yield 'unavailable' => [new Unavailable('Google Play returned 503')];
        yield 'invalid' => [new Invalid('Google Play returned 400')];
    }

    #[DataProvider('storeFailures')]
    public function testStoreFailuresPropagate(\Throwable $failure): void
    {
        $this->store->failures = ['app' => $failure];

        $this->expectExceptionObject($failure);

        $this->checks->run($this->app, $this->target(), self::TRACK);
    }

    /**
     * @param array<string, mixed> $external
     */
    private function sibling(string $connectionId, array $external, string $status = 'uploading', string $identifier = self::PACKAGE): void
    {
        $connection = $this->database->getDocument('distributionConnections', $connectionId);
        $sibling = $this->database->seed('distributionTargets', [
            '$id' => 'sibling-target',
            'platform' => Platform::Android->value,
            'identifier' => $identifier,
            'connectionId' => $connectionId,
            'connectionInternalId' => $connection->getSequence(),
        ]);
        $this->database->seed('distributionSubmissions', [
            '$id' => 'sibling',
            'targetId' => 'sibling-target',
            'targetInternalId' => $sibling->getSequence(),
            'status' => $status,
            'external' => $external,
        ]);
    }

    private function released(string $artifactInternalId, int $versionCode): void
    {
        $this->sibling('connection', ['packageName' => self::PACKAGE, 'editId' => 'edit-1', 'versionCode' => $versionCode, 'committed' => true], 'published');
        $this->database->updateDocument('distributionSubmissions', 'sibling', new Document(['step' => 'settled', 'artifactInternalId' => $artifactInternalId]));
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private function target(array $attributes = []): Document
    {
        return new Document([
            '$id' => 'target',
            'appId' => 'app',
            'platform' => Platform::Android->value,
            'identifier' => self::PACKAGE,
            'connectionId' => 'connection',
            'signingIdentityId' => 'keystore',
            'track' => self::TRACK,
            ...$attributes,
        ]);
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private function artifact(array $attributes): Document
    {
        return new Document([
            '$id' => 'artifact',
            'platform' => Platform::Android->value,
            'identifier' => self::PACKAGE,
            'signed' => true,
            ...$attributes,
        ]);
    }
}
