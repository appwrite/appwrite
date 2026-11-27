<?php

declare(strict_types=1);

namespace Tests\Unit\Distribution;

use Appwrite\Distribution\Stores;
use Appwrite\Distribution\Submission\Checkpoint;
use Appwrite\Distribution\Submission\Step;
use Appwrite\Distribution\Submissions;
use Appwrite\Event\Publisher\DistributionSubmission as DistributionSubmissionPublisher;
use Appwrite\Extend\Exception;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Distribution\Fakes\RecordingSchedules;
use Tests\Unit\Distribution\Fakes\ScriptedStore;
use Tests\Unit\Distribution\Fakes\ScriptedStores;
use Tests\Unit\Distribution\Fakes\SubmissionDatabase;
use Tests\Unit\Event\MockPublisher;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Distribution\Exception as StoreException;
use Utopia\Distribution\Exception\Conflict;
use Utopia\Distribution\Exception\Invalid;
use Utopia\Distribution\Exception\NotFound;
use Utopia\Distribution\Exception\RateLimited;
use Utopia\Distribution\Exception\Unauthorized;
use Utopia\Distribution\Exception\Unavailable;
use Utopia\Distribution\Issue;
use Utopia\Distribution\State;
use Utopia\Distribution\Tests\Transport;
use Utopia\Lock\Exception\Contention;
use Utopia\Psr7\Response;
use Utopia\Queue\Queue;
use Utopia\Storage\Device\Local;

final class SubmissionsTest extends TestCase
{
    private const string SECRET = 'submissions-test-secret-key-0123456789';

    private const string QUEUE = 'v1-distribution-submissions';

    private const string PACKAGE = 'io.example.app';

    private const string GOOGLE = 'https://play.test/v1/mock/distribution/google';

    private const string EDIT = '06155296137469138127';

    private const string SESSION = self::GOOGLE . '/resumable/upload/androidpublisher/v3/applications/io.example.app/edits/' . self::EDIT . '/bundles?uploadType=resumable&upload_id=session-secret';

    private const int SIZE = 4096;

    private static string $privateKey;

    private SubmissionDatabase $database;

    private ScriptedStore $store;

    private RecordingSchedules $schedules;

    private MockPublisher $publisher;

    private Document $project;

    private Document $app;

    private Document $release;

    private Document $target;

    private Document $connection;

    private Document $artifact;

    private string $directory = '';

    private string|false $key = false;

    private string|false $version = false;

    public static function setUpBeforeClass(): void
    {
        $key = \openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
        self::assertNotFalse($key);
        self::assertTrue(\openssl_pkey_export($key, $privateKey));

        self::$privateKey = $privateKey;
    }

    #[\Override]
    protected function setUp(): void
    {
        $this->key = \getenv('_APP_OPENSSL_KEY_V1');
        $this->version = \getenv('_APP_OPENSSL_KEY_CURRENT');
        \putenv('_APP_OPENSSL_KEY_V1=' . self::SECRET);
        \putenv('_APP_OPENSSL_KEY_CURRENT');

        $this->directory = \sys_get_temp_dir() . '/distribution-submissions-' . \bin2hex(\random_bytes(6));
        \mkdir($this->directory, 0o700, true);
        \file_put_contents($this->directory . '/artifact.aab', \random_bytes(self::SIZE));

        $this->database = new SubmissionDatabase();
        $this->store = new ScriptedStore();
        $this->schedules = new RecordingSchedules();
        $this->publisher = new MockPublisher();
        $this->project = new Document(['$id' => 'project', '$sequence' => '7']);

        $this->app = $this->database->seed('distributionApps', ['$id' => 'app', 'name' => 'Example']);
        $this->connection = $this->database->seed('distributionConnections', [
            '$id' => 'connection',
            'provider' => 'google',
            'status' => 'active',
            'credentials' => [
                'clientEmail' => 'play@appwrite-test.iam.gserviceaccount.com',
                'privateKey' => self::$privateKey,
                'projectId' => 'appwrite-test',
            ],
        ]);
        $this->target = $this->database->seed('distributionTargets', [
            '$id' => 'target',
            'appId' => 'app',
            'appInternalId' => $this->app->getSequence(),
            'platform' => 'android',
            'identifier' => self::PACKAGE,
            'connectionId' => 'connection',
            'connectionInternalId' => $this->connection->getSequence(),
        ]);
        $this->artifact = $this->database->seed('distributionArtifacts', [
            '$id' => 'artifact',
            'appId' => 'app',
            'appInternalId' => $this->app->getSequence(),
            'platform' => 'android',
            'type' => 'aab',
            'status' => 'ready',
            'path' => $this->directory . '/artifact.aab',
            'name' => 'artifact.aab',
            'size' => self::SIZE,
            'identifier' => self::PACKAGE,
            'versionName' => '1.2.0',
            'versionCode' => 88,
            'signed' => true,
        ]);
        $this->release = $this->database->seed('distributionReleases', [
            '$id' => 'release',
            'appId' => 'app',
            'appInternalId' => $this->app->getSequence(),
            'version' => '1.2.0',
            'notes' => ['en-US' => 'Bug fixes'],
            'status' => 'draft',
        ]);
    }

    #[\Override]
    protected function tearDown(): void
    {
        \putenv($this->key === false ? '_APP_OPENSSL_KEY_V1' : '_APP_OPENSSL_KEY_V1=' . $this->key);
        \putenv($this->version === false ? '_APP_OPENSSL_KEY_CURRENT' : '_APP_OPENSSL_KEY_CURRENT=' . $this->version);

        foreach (\glob($this->directory . '/*') ?: [] as $file) {
            \unlink($file);
        }

        \rmdir($this->directory);
    }

    public function testCreateSchedulesEnqueuesAndActivatesRelease(): void
    {
        $submission = $this->submissions()->create($this->app, $this->release, $this->target, $this->artifact, 'internal', true, 'key-1');
        $this->assertInstanceOf(Document::class, $submission);

        $this->assertSame('waiting', $submission->getAttribute('status'));
        $this->assertSame('google', $submission->getAttribute('provider'));
        $this->assertSame('android', $submission->getAttribute('platform'));
        $this->assertSame($this->artifact->getSequence(), $submission->getAttribute('artifactInternalId'));
        $this->assertSame([['write', $submission->getId(), 2]], $this->schedules->calls->getArrayCopy());

        $events = $this->publisher->getEvents(self::QUEUE);
        $this->assertCount(1, $events);
        $this->assertSame('advance', $events[0]['action']);
        $this->assertSame($submission->getId(), $events[0]['id']);

        $this->assertSame('active', $this->stored('distributionReleases', 'release')->getAttribute('status'));
    }

    public function testCreateReportsADuplicateKey(): void
    {
        $submissions = $this->submissions();

        $first = $submissions->create($this->app, $this->release, $this->target, $this->artifact, 'internal', true, 'key-1');
        $second = $submissions->create($this->app, $this->release, $this->target, $this->artifact, 'internal', true, 'key-1');

        $this->assertInstanceOf(Document::class, $first);
        $this->assertNull($second);
        $this->assertCount(1, $this->database->writes('distributionSubmissions'));
        $this->assertCount(1, $this->publisher->getEvents(self::QUEUE));
        $this->assertCount(1, $this->schedules->calls);
    }

    /**
     * @return \Iterator<string, array{array<string, mixed>, string}>
     */
    public static function earlierSubmissions(): \Iterator
    {
        yield 'older failure' => [['status' => 'failed', 'track' => 'production'], 'attention'];
        yield 'older rejection' => [['status' => 'rejected', 'track' => 'production'], 'attention'];
        yield 'older production release' => [['status' => 'published', 'track' => 'production', 'step' => 'settled'], 'published'];
        yield 'older beta release' => [['status' => 'published', 'track' => 'beta', 'step' => 'settled'], 'active'];
    }

    /**
     * @param array<string, mixed> $earlier
     */
    #[DataProvider('earlierSubmissions')]
    public function testReleaseStatusDerivesFromEverySubmissionOfTheRelease(array $earlier, string $status): void
    {
        $this->submission(['$id' => 'earlier', 'key' => 'earlier', ...$earlier]);

        $this->submissions()->create($this->app, $this->release, $this->target, $this->artifact, 'beta', true, 'key-1');

        $this->assertSame($status, $this->stored('distributionReleases', 'release')->getAttribute('status'));
    }

    public function testStepsAdvanceInOrder(): void
    {
        $submissions = $this->submissions();
        $this->store->latest = 41;
        $submission = $submissions->create($this->app, $this->release, $this->target, $this->artifact, 'internal', true, 'key-1');
        $this->assertInstanceOf(Document::class, $submission);

        $submission = $submissions->validate($submission, $this->target, $this->artifact);
        $this->assertStep('validated', 'validating', $submission);

        $submission = $submissions->locate($submission, $this->target, $this->connection);
        $this->assertStep('located', 'validating', $submission);
        $this->assertSame('store-app', $this->stored('distributionTargets', 'target')->getAttribute('storeAppId'));

        $submission = $submissions->claim($submission);
        $this->assertInstanceOf(Document::class, $submission);
        $this->assertStep('located', 'uploading', $submission);

        $submission = $this->recorded($submission, $submissions);
        $this->assertStep('uploaded', 'processing', $submission);
        $this->assertSame([...$this->store->uploaded], $submission->getAttribute('external'));

        $submission = $submissions->process($submission, $this->connection);
        $this->assertStep('processed', 'processing', $submission);

        $submission = $submissions->release($submission, $this->release, $this->target, $this->artifact, $this->connection);
        $this->assertStep('released', 'processing', $submission);
        $this->assertNotEmpty($submission->getAttribute('submittedAt'));
        $this->assertSame('internal', $this->store->release?->track);
        $this->assertSame('88', $this->store->release->build);
        $this->assertSame(['en-US' => 'Bug fixes'], $this->store->release->notes);

        $submission = $submissions->settle($submission, $this->connection);
        $this->assertStep('released', 'reviewing', $submission);
        $this->assertSame('IN_REVIEW', $submission->getAttribute('storeStatus'));

        $this->assertSame(['latestVersionCode', 'app', 'latestVersionCode', 'upload', 'processed', 'release', 'state'], $this->store->calls);
        $this->assertSame([['write', $submission->getId(), 2], ['write', $submission->getId(), 15]], $this->schedules->calls->getArrayCopy());
    }

    public function testProcessWaitsWhileStoreIsProcessing(): void
    {
        $this->store->processed = null;
        $submission = $this->submission(['step' => 'uploaded', 'status' => 'processing']);

        $processed = $this->submissions()->process($submission, $this->connection);

        $this->assertStep('uploaded', 'processing', $processed);
    }

    /**
     * @return \Iterator<string, array{array<string, mixed>, array<string, mixed>, string}>
     */
    public static function invalidInputs(): \Iterator
    {
        yield 'artifact not ready' => [['status' => 'processing'], [], Exception::DISTRIBUTION_ARTIFACT_NOT_READY];
        yield 'artifact for another identifier' => [['identifier' => 'io.example.other'], [], Exception::DISTRIBUTION_CHECK_FAILED];
        yield 'artifact for another platform' => [['platform' => 'ios'], [], Exception::DISTRIBUTION_CHECK_FAILED];
        yield 'target without connection' => [[], ['connectionId' => ''], Exception::DISTRIBUTION_CHECK_FAILED];
    }

    /**
     * @param array<string, mixed> $artifact
     * @param array<string, mixed> $target
     */
    #[DataProvider('invalidInputs')]
    public function testValidateFailsInvalidInput(array $artifact, array $target, string $code): void
    {
        $submission = $this->submission();

        $validated = $this->submissions()->validate(
            $submission,
            new Document([...$this->target->getArrayCopy(), ...$target]),
            new Document([...$this->artifact->getArrayCopy(), ...$artifact]),
        );

        $this->assertSame('failed', $validated->getAttribute('status'));
        $this->assertSame($code, $validated->getAttribute('errorCode'));
        $this->assertNull($validated->getAttribute('step'));
        $this->assertSame([['stop', $submission->getId(), 0]], $this->schedules->calls->getArrayCopy());
        $this->assertSame('attention', $this->stored('distributionReleases', 'release')->getAttribute('status'));
    }

    /**
     * @return \Iterator<string, array{array<string, mixed>, array<string, mixed>, int, string}>
     */
    public static function failedChecks(): \Iterator
    {
        yield 'version the store already has' => [[], [], 88, 'Build 88 is not higher than 88'];
        yield 'unsigned artifact without a signing identity' => [['signed' => false], [], 0, 'The target has no signing identity.'];
        yield 'target SDK below the Play requirement' => [['manifest' => ['targetSdk' => 34]], [], 0, 'Google Play requires API level 35'];
        yield 'connection the store rejected' => [[], ['connectionId' => 'invalid-connection'], 0, 'The store rejected the credentials of the connection.'];
    }

    /**
     * @param array<string, mixed> $artifact
     * @param array<string, mixed> $target
     */
    #[DataProvider('failedChecks')]
    public function testValidateFailsOnACheckError(array $artifact, array $target, int $latest, string $message): void
    {
        $this->database->seed('distributionConnections', ['$id' => 'invalid-connection', 'provider' => 'google', 'status' => 'invalid', 'error' => 'invalid_grant']);
        $this->store->latest = $latest;
        $submission = $this->submission();

        $validated = $this->submissions()->validate(
            $submission,
            new Document([...$this->target->getArrayCopy(), ...$target]),
            new Document([...$this->artifact->getArrayCopy(), ...$artifact]),
        );

        $this->assertSame('failed', $validated->getAttribute('status'));
        $this->assertSame(Exception::DISTRIBUTION_CHECK_FAILED, $validated->getAttribute('errorCode'));
        $this->assertStringContainsString($message, (string) $validated->getAttribute('errorMessage'));
        $this->assertNull($validated->getAttribute('step'));
        $this->assertSame('attention', $this->stored('distributionReleases', 'release')->getAttribute('status'));
    }

    public function testValidateRefusesATrackOfAnotherStore(): void
    {
        $submission = $this->submission(['track' => 'testflight']);

        try {
            $this->submissions()->validate($submission, $this->target, $this->artifact);
            $this->fail('A track of another store must be refused.');
        } catch (Exception $exception) {
            $this->assertSame(Exception::DISTRIBUTION_TRACK_INVALID, $exception->getType());
        }

        $this->assertSame([], $this->store->calls);
    }

    public function testValidateWarningsDoNotFailTheSubmission(): void
    {
        $this->database->seed('distributionSigningIdentities', [
            '$id' => 'expiring',
            'platform' => 'android',
            'status' => 'expiring',
            'identifiers' => [],
        ]);
        $submission = $this->submission();

        $validated = $this->submissions()->validate(
            $submission,
            new Document([...$this->target->getArrayCopy(), 'signingIdentityId' => 'expiring']),
            new Document([...$this->artifact->getArrayCopy(), 'signed' => false]),
        );

        $this->assertStep('validated', 'validating', $validated);
    }

    /**
     * @return \Iterator<string, array{int, int|null}>
     */
    public static function backoffs(): \Iterator
    {
        yield 'below the first rung' => [0, 60];
        yield 'first' => [1, 60];
        yield 'second' => [2, 300];
        yield 'third' => [3, 900];
        yield 'fourth' => [4, 3600];
        yield 'hourly' => [5, 3600];
        yield 'last within a day' => [26, 3600];
        yield 'past a day' => [27, null];
    }

    #[DataProvider('backoffs')]
    public function testBackoff(int $attempt, ?int $seconds): void
    {
        $this->assertSame($seconds, Submissions::backoff($attempt));
    }

    /**
     * @return \Iterator<string, array{string, float, int, bool}>
     */
    public static function intervals(): \Iterator
    {
        yield 'waiting' => ['waiting', 1.0, 2, true];
        yield 'validating' => ['validating', 1.0, 2, true];
        yield 'uploading' => ['uploading', 1.0, 2, true];
        yield 'processing' => ['processing', 1.0, 2, true];
        yield 'pending' => ['pending', 1.0, 15, true];
        yield 'reviewing' => ['reviewing', 1.0, 15, true];
        yield 'approved' => ['approved', 1.0, 15, true];
        yield 'halted' => ['halted', 0.2, 15, true];
        yield 'staged rollout' => ['published', 0.2, 60, true];
        yield 'full rollout' => ['published', 1.0, 0, false];
        yield 'rejected' => ['rejected', 1.0, 0, false];
        yield 'failed' => ['failed', 1.0, 0, false];
        yield 'canceled' => ['canceled', 1.0, 0, false];
    }

    #[DataProvider('intervals')]
    public function testIntervalAndActivity(string $status, float $rollout, int $minutes, bool $active): void
    {
        $this->assertSame($minutes, Submissions::interval($status, $rollout));
        $this->assertSame($active, Submissions::isActive($status, $rollout));
    }

    public function testStoreLockIsPerServiceAccountAndPackageForGoogleAndPerIdentifierForApple(): void
    {
        $email = 'play@appwrite-test.iam.gserviceaccount.com';
        $google = new Document(['$id' => 'connection', '$sequence' => '3', 'provider' => 'google', 'account' => ['email' => $email]]);
        $shared = new Document(['$id' => 'shared', '$sequence' => '9', 'provider' => 'google', 'account' => ['email' => $email]]);
        $separate = new Document(['$id' => 'separate', '$sequence' => '4', 'provider' => 'google', 'account' => ['email' => 'other@appwrite-test.iam.gserviceaccount.com']]);
        $unverified = new Document(['$id' => 'unverified', '$sequence' => '5', 'provider' => 'google']);
        $apple = new Document(['$id' => 'connection', '$sequence' => '3', 'provider' => 'apple']);

        $this->assertSame(Submissions::storeLock($google, 'io.example.one'), Submissions::storeLock($shared, 'io.example.one'), 'Connections of one service account share the lock in any project.');
        $this->assertNotSame(Submissions::storeLock($google, 'io.example.one'), Submissions::storeLock($google, 'io.example.two'));
        $this->assertNotSame(Submissions::storeLock($google, 'io.example.one'), Submissions::storeLock($separate, 'io.example.one'));
        $this->assertNotSame(Submissions::storeLock($google, 'io.example.one'), Submissions::storeLock($unverified, 'io.example.one'));
        $this->assertStringNotContainsString($email, Submissions::storeLock($google, 'io.example.one'));
        $this->assertNotSame(Submissions::storeLock($apple, 'io.example.one'), Submissions::storeLock($apple, 'io.example.two'));
        $this->assertSame('distribution:submission:7:submission', Submissions::lock('7', 'submission'));
    }

    /**
     * @return \Iterator<string, array{\Throwable, array<string, mixed>, array<string, mixed>}>
     */
    public static function failures(): \Iterator
    {
        $retry = ['status' => 'uploading', 'attempt' => 1, 'step' => 'located', 'connection' => 'active'];
        $failed = ['status' => 'failed', 'attempt' => 0, 'step' => 'located', 'connection' => 'active'];
        $editing = ['external' => ['packageName' => self::PACKAGE, 'editId' => self::EDIT, 'expiryTimeSeconds' => 1, 'checkpoint' => ['offset' => 0]]];

        yield 'rate limited' => [new RateLimited('Slow down'), [], $retry];
        yield 'unavailable' => [new Unavailable('Down'), [], $retry];
        yield 'network or database' => [new \RuntimeException('Connection reset'), [], $retry];
        yield 'invalid with issue' => [new Invalid('Bad notes', [new Issue('releaseNotesTooLong', 'Too long')]), [], [...$failed, 'errorCode' => 'releaseNotesTooLong']];
        yield 'invalid without issue' => [new Invalid('Bad'), [], [...$failed, 'errorCode' => Submissions::ERROR_REJECTED]];
        yield 'permission denied' => [new Unauthorized('Google Play returned 403', [new Issue('permissionDenied', 'No access')]), [], [...$failed, 'errorCode' => 'permissionDenied']];
        yield 'rejected credential' => [new Unauthorized('Bad key', credential: true), [], [...$failed, 'errorCode' => Exception::DISTRIBUTION_CONNECTION_INVALID, 'connection' => 'invalid']];
        yield 'invalid grant' => [new Unauthorized('Grant', [new Issue('invalid_grant', 'Invalid JWT')]), [], [...$failed, 'errorCode' => Exception::DISTRIBUTION_CONNECTION_INVALID, 'connection' => 'invalid']];
        yield 'unauthenticated after refresh' => [new Unauthorized('Google Play returned 401', [new Issue('authError', 'Invalid Credentials')]), [], [...$failed, 'errorCode' => Exception::DISTRIBUTION_CONNECTION_INVALID, 'connection' => 'invalid']];
        yield 'version code conflict' => [new Unauthorized('Used', [new Issue('apkUpgradeVersionConflict', 'Version code used')]), [], [...$failed, 'errorCode' => Exception::DISTRIBUTION_VERSION_INVALID]];
        yield 'application not found' => [new NotFound('Missing', [new Issue('applicationNotFound', 'Package not found')]), [], [...$failed, 'errorCode' => 'store_app_missing']];
        yield 'other not found' => [new NotFound('Gone'), [], [...$failed, 'errorCode' => Submissions::ERROR_REJECTED]];
        yield 'edit expired' => [new NotFound('Expired', [new Issue('editExpired', 'This Edit has expired.')]), ['step' => 'processed', ...$editing], [...$retry, 'external' => ['packageName' => self::PACKAGE]]];
        yield 'edit not found' => [new NotFound('Gone', [new Issue('editNotFound', 'Edit not found')]), $editing, [...$retry, 'external' => ['packageName' => self::PACKAGE]]];
        yield 'upload session ended' => [new NotFound('Ended', [new Issue('uploadSessionEnded', 'Ended')]), $editing, [...$retry, 'external' => ['packageName' => self::PACKAGE]]];
        yield 'edit committed while uploading' => [new NotFound('Committed', [new Issue('editAlreadyCommitted', 'Committed')]), $editing, [...$retry, 'external' => ['packageName' => self::PACKAGE]]];
        yield 'edit committed while releasing' => [new NotFound('Committed', [new Issue('editAlreadyCommitted', 'Committed')]), ['step' => 'processed', 'external' => ['editId' => self::EDIT]], ['status' => 'uploading', 'attempt' => 0, 'step' => 'released', 'connection' => 'active', 'external' => ['editId' => self::EDIT, 'committed' => true]]];
        yield 'changes in review before edit expiry' => [new Conflict('In review', [new Issue('CHANGES_ALREADY_IN_REVIEW', 'In review')]), ['external' => ['editId' => self::EDIT, 'expiryTimeSeconds' => \time() + 3600]], $retry];
        yield 'changes in review past edit expiry' => [new Conflict('In review', [new Issue('CHANGES_ALREADY_IN_REVIEW', 'In review')]), ['external' => ['editId' => self::EDIT, 'expiryTimeSeconds' => \time() - 1]], [...$failed, 'errorCode' => 'store_review_in_progress']];
        yield 'other conflict' => [new Conflict('Conflict', [new Issue('FAILED_PRECONDITION', 'Precondition')]), [], [...$failed, 'errorCode' => 'FAILED_PRECONDITION']];
        yield 'release deleted' => [new Exception(Exception::DISTRIBUTION_RELEASE_NOT_FOUND), [], [...$failed, 'errorCode' => Exception::DISTRIBUTION_RELEASE_NOT_FOUND]];
        yield 'target deleted' => [new Exception(Exception::DISTRIBUTION_TARGET_NOT_FOUND), [], [...$failed, 'errorCode' => Exception::DISTRIBUTION_TARGET_NOT_FOUND]];
        yield 'artifact deleted' => [new Exception(Exception::DISTRIBUTION_ARTIFACT_NOT_FOUND), [], [...$failed, 'errorCode' => Exception::DISTRIBUTION_ARTIFACT_NOT_FOUND]];
        yield 'connection deleted' => [new Exception(Exception::DISTRIBUTION_CONNECTION_NOT_FOUND), [], [...$failed, 'errorCode' => Exception::DISTRIBUTION_CONNECTION_NOT_FOUND]];
        yield 'invalid track' => [new Exception(Exception::DISTRIBUTION_TRACK_INVALID), [], [...$failed, 'errorCode' => Exception::DISTRIBUTION_TRACK_INVALID]];
        yield 'unusable credentials' => [new Exception(Exception::DISTRIBUTION_CONNECTION_INVALID), [], [...$failed, 'errorCode' => Exception::DISTRIBUTION_CONNECTION_INVALID, 'connection' => 'invalid']];
    }

    /**
     * @param array<string, mixed> $initial
     * @param array<string, mixed> $expected
     */
    #[DataProvider('failures')]
    public function testFailClassifiesStoreErrors(\Throwable $error, array $initial, array $expected): void
    {
        $submission = $this->submission(['status' => 'uploading', 'step' => 'located', ...$initial]);
        $before = \time();

        $failed = $this->submissions()->fail($submission, $error);

        $this->assertSame($expected['status'], $failed->getAttribute('status'));
        $this->assertSame($expected['attempt'], $failed->getAttribute('attempt'));
        $this->assertSame($expected['step'], $failed->getAttribute('step'));
        $this->assertSame($expected['connection'], $this->stored('distributionConnections', 'connection')->getAttribute('status'));
        $this->assertArrayNotHasKey('claim', $failed->getAttribute('external'));

        if (\array_key_exists('errorCode', $expected)) {
            $this->assertSame($expected['errorCode'], $failed->getAttribute('errorCode'));
        }

        if (\array_key_exists('external', $expected)) {
            $this->assertSame($expected['external'], $failed->getAttribute('external'));
        }

        if ($expected['attempt'] === 1) {
            $retryAt = (new \DateTime((string) $failed->getAttribute('retryAt')))->getTimestamp();
            $this->assertGreaterThanOrEqual($before + 60, $retryAt);
            $this->assertLessThanOrEqual(\time() + 60, $retryAt);
        }
    }

    public function testRetriesStopAfterADay(): void
    {
        $submission = $this->submission(['status' => 'uploading', 'step' => 'located', 'attempt' => 26]);

        $failed = $this->submissions()->fail($submission, new Unavailable('Still down'));

        $this->assertSame('failed', $failed->getAttribute('status'));
        $this->assertSame(Exception::DISTRIBUTION_STORE_UNAVAILABLE, $failed->getAttribute('errorCode'));
        $this->assertSame('Still down', $failed->getAttribute('errorMessage'));
    }

    public function testFailLeavesFinishedSubmission(): void
    {
        $submission = $this->submission(['status' => 'canceled']);

        $this->assertSame($submission, $this->submissions()->fail($submission, new Invalid('Late')));
        $this->assertSame([], $this->database->writes('distributionSubmissions'));
    }

    public function testClaimIsRefusedWhileLive(): void
    {
        $submission = $this->submission(['status' => 'validating', 'step' => 'located', 'external' => ['claim' => ['id' => 'other', 'expiresAt' => $this->at(60)]]]);

        $this->assertNull($this->submissions()->claim($submission));
    }

    /**
     * @return \Iterator<string, array{string|null, string}>
     */
    public static function otherSteps(): \Iterator
    {
        yield 'not validated' => [null, 'waiting'];
        yield 'validated' => ['validated', 'validating'];
        yield 'uploaded' => ['uploaded', 'processing'];
        yield 'processed' => ['processed', 'processing'];
        yield 'released' => ['released', 'reviewing'];
        yield 'settled' => ['settled', 'halted'];
    }

    #[DataProvider('otherSteps')]
    public function testClaimIsRefusedAtAnotherStep(?string $step, string $status): void
    {
        $submission = $this->submission(['status' => $status, 'step' => $step, 'external' => ['packageName' => self::PACKAGE, 'editId' => self::EDIT, 'versionCode' => 88]]);

        $this->assertNull($this->submissions()->claim($submission));
        $this->assertSame([], $this->database->writes('distributionSubmissions'));
    }

    #[DataProvider('otherSteps')]
    public function testRecordIsRefusedAtAnotherStep(?string $step, string $status): void
    {
        $claimed = $this->claimed($this->submission(['status' => 'validating', 'step' => 'located']));
        $result = $this->submissions()->upload($claimed, $this->artifact, $this->connection);
        $this->assertIsArray($result);
        $this->database->updateDocument('distributionSubmissions', $claimed->getId(), new Document(['step' => $step, 'status' => $status]));
        $writes = \count($this->database->writes('distributionSubmissions'));

        $recorded = $this->submissions()->record($this->stored('distributionSubmissions', $claimed->getId()), Submissions::claimOf($claimed), $result);

        $this->assertSame($step, $recorded->getAttribute('step'));
        $this->assertSame($status, $recorded->getAttribute('status'));
        $this->assertCount($writes, $this->database->writes('distributionSubmissions'));
    }

    /**
     * @return \Iterator<string, array{string|null, Step|null, bool}>
     */
    public static function positions(): \Iterator
    {
        yield 'not validated, expecting none' => [null, null, true];
        yield 'not validated, expecting validated' => [null, Step::Validated, false];
        yield 'located, expecting located' => ['located', Step::Located, true];
        yield 'uploaded, expecting located' => ['uploaded', Step::Located, false];
        yield 'processed, expecting processed' => ['processed', Step::Processed, true];
        yield 'released, expecting processed' => ['released', Step::Processed, false];
        yield 'released, expecting none' => ['released', null, false];
    }

    #[DataProvider('positions')]
    public function testIsAtComparesTheStoredStep(?string $stored, ?Step $step, bool $at): void
    {
        $this->assertSame($at, Submissions::isAt(new Document(['step' => $stored]), $step));
    }

    public function testExpiredClaimIsTakenOver(): void
    {
        $submission = $this->submission(['status' => 'uploading', 'step' => 'located', 'external' => ['claim' => ['id' => 'killed', 'expiresAt' => $this->at(-1)]]]);

        $claimed = $this->submissions()->claim($submission);

        $this->assertInstanceOf(Document::class, $claimed);
        $claim = $claimed->getAttribute('external')['claim'];
        $this->assertNotSame('killed', $claim['id']);
        $expiresAt = (new \DateTime($claim['expiresAt']))->getTimestamp();
        $this->assertEqualsWithDelta(\time() + Submissions::CLAIM, $expiresAt, 2);
    }

    /**
     * @return \Iterator<string, array{array<string, mixed>, bool}>
     */
    public static function siblings(): \Iterator
    {
        yield 'sibling uploading' => [['claim' => ['id' => 'sibling', 'expiresAt' => '2999-01-01 00:00:00.000']], false];
        yield 'sibling holding an open edit' => [['editId' => self::EDIT], false];
        yield 'sibling with a killed upload' => [['checkpoint' => ['offset' => 0]], false];
        yield 'sibling with a committed edit' => [['editId' => self::EDIT, 'committed' => true], true];
        yield 'sibling with an expired claim' => [['claim' => ['id' => 'sibling', 'expiresAt' => '2000-01-01 00:00:00.000']], true];
    }

    /**
     * @param array<string, mixed> $external
     */
    #[DataProvider('siblings')]
    public function testGoogleClaimWaitsForOtherSubmissionsOfTheConnection(array $external, bool $claimed): void
    {
        $this->sibling($external);
        $submission = $this->submission(['status' => 'validating', 'step' => 'located']);

        $this->assertSame($claimed, $this->submissions()->claim($submission) !== null);

        $this->submissions()->locate($submission, $this->target, $this->connection);

        $this->assertSame($claimed ? ['app'] : [], $this->store->calls);
    }

    public function testGoogleClaimIgnoresEditsOfAnotherPackage(): void
    {
        $this->sibling(['editId' => self::EDIT], 'io.example.other');
        $submission = $this->submission(['status' => 'validating', 'step' => 'located']);

        $this->assertInstanceOf(Document::class, $this->submissions()->claim($submission));
    }

    public function testGoogleClaimWaitsForAnotherConnectionOfTheServiceAccount(): void
    {
        $account = ['email' => 'play@appwrite-test.iam.gserviceaccount.com'];
        $this->connection = $this->database->seed('distributionConnections', [...$this->connection->getArrayCopy(), 'account' => $account]);
        $this->database->seed('distributionConnections', ['$id' => 'shared', 'provider' => 'google', 'status' => 'active', 'credentials' => [], 'account' => $account]);
        $this->sibling(['editId' => self::EDIT], connectionId: 'shared');
        $submission = $this->submission(['status' => 'validating', 'step' => 'located']);

        $this->assertNull($this->submissions()->claim($submission));

        $this->submissions()->locate($submission, $this->target, $this->connection);

        $this->assertSame([], $this->store->calls);
    }

    public function testUploadChecksTheStoreVersionOnlyBeforeAnEditExists(): void
    {
        $this->store->latest = 87;
        $submission = $this->claimed($this->submission(['status' => 'validating', 'step' => 'located']));

        $result = $this->submissions()->upload($submission, $this->artifact, $this->connection);

        $this->assertSame(['latestVersionCode', 'upload'], $this->store->calls);
        $this->assertSame($this->store->uploaded, $result);
        $this->assertSame('located', $this->stored('distributionSubmissions', $submission->getId())->getAttribute('step'));
    }

    public function testUploadRefusesAVersionTheStoreAlreadyHas(): void
    {
        $this->store->latest = 88;
        $submission = $this->claimed($this->submission(['status' => 'validating', 'step' => 'located']));

        $refused = null;

        try {
            $this->submissions()->upload($submission, $this->artifact, $this->connection);
        } catch (Exception $exception) {
            $refused = $exception;
        }

        $this->assertInstanceOf(Exception::class, $refused);
        $this->assertSame(Exception::DISTRIBUTION_VERSION_INVALID, $refused->getType());
        $this->assertSame(['latestVersionCode'], $this->store->calls);

        $failed = $this->submissions()->fail($this->stored('distributionSubmissions', $submission->getId()), $refused);

        $this->assertSame('failed', $failed->getAttribute('status'));
        $this->assertSame(Exception::DISTRIBUTION_VERSION_INVALID, $failed->getAttribute('errorCode'));
        $this->assertArrayNotHasKey('claim', $failed->getAttribute('external'));
    }

    /**
     * @return \Iterator<string, array{array<string, mixed>}>
     */
    public static function resumes(): \Iterator
    {
        yield 'open edit' => [['editId' => self::EDIT]];
        yield 'checkpoint' => [['checkpoint' => ['packageName' => self::PACKAGE, 'editId' => self::EDIT, 'offset' => 1024]]];
    }

    /**
     * @param array<string, mixed> $external
     */
    #[DataProvider('resumes')]
    public function testUploadNeverOpensAnotherEditWhileOneIsHeld(array $external): void
    {
        $this->store->latest = 99;
        $submission = $this->claimed($this->submission(['status' => 'validating', 'step' => 'located', 'external' => $external]));

        $result = $this->submissions()->upload($submission, $this->artifact, $this->connection);

        $this->assertSame(['upload'], $this->store->calls);
        $this->assertSame($this->store->uploaded, $result);
    }

    public function testUploadResumesThroughTheCheckpoint(): void
    {
        $submission = $this->claimed($this->submission(['status' => 'validating', 'step' => 'located']));
        $claim = $submission->getAttribute('external')['claim']['id'];
        (new Checkpoint($this->database, $submission->getId(), $claim))->save(['packageName' => self::PACKAGE, 'editId' => self::EDIT, 'session' => self::SESSION, 'offset' => 2048]);

        $uploaded = $this->recorded($this->stored('distributionSubmissions', $submission->getId()));

        $this->assertSame(['packageName' => self::PACKAGE, 'editId' => self::EDIT, 'session' => self::SESSION, 'offset' => 2048], $this->store->checkpoint);
        $this->assertSame(['upload'], $this->store->calls);
        $this->assertSame($this->store->uploaded, $uploaded->getAttribute('external'));
    }

    public function testRecordAdvancesTheClaimedUpload(): void
    {
        $submission = $this->claimed($this->submission(['status' => 'validating', 'step' => 'located', 'attempt' => 2]));
        $result = $this->submissions()->upload($submission, $this->artifact, $this->connection);

        $recorded = $this->submissions()->record($this->stored('distributionSubmissions', $submission->getId()), Submissions::claimOf($submission), $result);

        $this->assertStep('uploaded', 'processing', $recorded);
        $this->assertSame(0, $recorded->getAttribute('attempt'));
        $this->assertSame($this->store->uploaded, $recorded->getAttribute('external'));
    }

    public function testUploadTakenOverElsewhereIsNotRecorded(): void
    {
        $submission = $this->claimed($this->submission(['status' => 'validating', 'step' => 'located']));
        $result = $this->submissions()->upload($submission, $this->artifact, $this->connection);
        $this->database->updateDocument('distributionSubmissions', $submission->getId(), new Document([
            'external' => ['claim' => ['id' => 'successor', 'expiresAt' => $this->at(60)]],
        ]));
        $writes = \count($this->database->writes('distributionSubmissions'));

        $recorded = $this->submissions()->record($this->stored('distributionSubmissions', $submission->getId()), Submissions::claimOf($submission), $result);

        $this->assertSame('located', $recorded->getAttribute('step'));
        $this->assertSame('successor', $recorded->getAttribute('external')['claim']['id']);
        $this->assertCount($writes, $this->database->writes('distributionSubmissions'));
    }

    public function testUploadSupersededMidTransferIsAbandoned(): void
    {
        $claimed = $this->claimed($this->submission(['status' => 'validating', 'step' => 'located']));
        $this->database->updateDocument('distributionSubmissions', $claimed->getId(), new Document([
            'external' => ['claim' => ['id' => 'successor', 'expiresAt' => $this->at(60)]],
        ]));
        $writes = \count($this->database->writes('distributionSubmissions'));
        $this->store->progress = [['packageName' => self::PACKAGE, 'editId' => self::EDIT, 'offset' => 1024]];

        $result = $this->submissions()->upload($claimed, $this->artifact, $this->connection);

        $this->assertNull($result);
        $this->assertNotContains('upload', $this->store->calls);
        $this->assertCount($writes, $this->database->writes('distributionSubmissions'));
        $external = $this->stored('distributionSubmissions', $claimed->getId())->getAttribute('external');
        $this->assertSame('successor', $external['claim']['id']);
        $this->assertArrayNotHasKey('checkpoint', $external);
    }

    /**
     * @return \Iterator<string, array{array<string, mixed>, State, list<string>, bool}>
     */
    public static function releaseRetries(): \Iterator
    {
        $absent = new State(State::STATUS_PENDING, 'NOT_FOUND');
        $reviewing = new State(State::STATUS_REVIEWING, 'RELEASE_LIFECYCLE_STATE_IN_REVIEW');

        yield 'first attempt' => [[], $reviewing, ['release'], false];
        yield 'retry the store already shows' => [['attempt' => 1], $reviewing, ['state'], true];
        yield 'retry of a draft the store already shows' => [['attempt' => 2], new State(State::STATUS_PENDING, 'RELEASE_LIFECYCLE_STATE_DRAFT'), ['state'], true];
        yield 'retry the store does not show' => [['attempt' => 1], $absent, ['state', 'release'], false];
        yield 'retry while the store is still processing' => [['attempt' => 1], new State(State::STATUS_PROCESSING, 'PROCESSING'), ['state', 'release'], false];
        yield 'retry of an App Store version not yet submitted' => [['attempt' => 1], new State(State::STATUS_PENDING, 'PREPARE_FOR_SUBMISSION'), ['state', 'release'], false];
    }

    /**
     * @param array<string, mixed> $initial
     * @param list<string> $calls
     */
    #[DataProvider('releaseRetries')]
    public function testReleaseRetryChecksTheStoreFirst(array $initial, State $state, array $calls, bool $recorded): void
    {
        $this->store->state = $state;
        $submission = $this->submission(['status' => 'processing', 'step' => 'processed', 'external' => [...$this->store->uploaded], ...$initial]);

        $released = $this->submissions()->release($submission, $this->release, $this->target, $this->artifact, $this->connection);

        $this->assertSame($calls, $this->store->calls);
        $this->assertStep('released', 'processing', $released);
        $this->assertTrue($released->getAttribute('external')['committed']);
        $this->assertSame(0, $released->getAttribute('attempt'));
        $this->assertNotEmpty($released->getAttribute('submittedAt'));
        $this->assertSame($recorded ? null : 'internal', $this->store->release?->track);
    }

    /**
     * @return \Iterator<string, array{string, array<string, mixed>}>
     */
    public static function appleReleaseRetries(): \Iterator
    {
        yield 'testflight' => ['testflight', ['appId' => 'store-app', 'buildId' => 'build-1']];
        yield 'external testflight' => ['testflight-external', ['appId' => 'store-app', 'buildId' => 'build-1']];
        yield 'production without a version' => ['production', ['appId' => 'store-app', 'buildId' => 'build-1']];
        yield 'production with a version' => ['production', ['appId' => 'store-app', 'buildId' => 'build-1', 'versionId' => 'version-1']];
    }

    /**
     * @param array<string, mixed> $external
     */
    #[DataProvider('appleReleaseRetries')]
    public function testAppleReleaseRetryCallsTheStoreReleaseAgain(string $track, array $external): void
    {
        $this->store->state = new State(State::STATUS_REVIEWING, 'WAITING_FOR_REVIEW');
        $target = new Document([...$this->target->getArrayCopy(), 'platform' => 'ios', 'configuration' => ['betaGroups' => 'group-1']]);
        $artifact = new Document(['platform' => 'ios', 'identifier' => self::PACKAGE, 'versionName' => '1.2.0', 'buildNumber' => '88']);
        $submission = $this->submission(['platform' => 'ios', 'provider' => 'apple', 'track' => $track, 'status' => 'processing', 'step' => 'processed', 'attempt' => 1, 'external' => $external]);

        $released = $this->submissions()->release($submission, $this->release, $target, $artifact, $this->connection);

        $this->assertSame(['release'], $this->store->calls);
        $this->assertSame($track, $this->store->release?->track);
        $this->assertStep('released', 'processing', $released);
        $this->assertSame($track, $released->getAttribute('external')['track']);
    }

    public function testAppleReleaseRetryKeepsTheFirstSubmittedAt(): void
    {
        $submittedAt = '2026-01-02T03:04:05.000+00:00';
        $target = new Document([...$this->target->getArrayCopy(), 'platform' => 'ios']);
        $artifact = new Document(['platform' => 'ios', 'identifier' => self::PACKAGE, 'versionName' => '1.2.0', 'buildNumber' => '88']);
        $submission = $this->submission(['platform' => 'ios', 'provider' => 'apple', 'track' => 'production', 'status' => 'pending', 'step' => 'processed', 'submittedAt' => $submittedAt, 'external' => ['appId' => 'store-app', 'buildId' => 'build-1']]);

        $released = $this->submissions()->release($submission, $this->release, $target, $artifact, $this->connection);

        $this->assertStep('released', 'pending', $released);
        $this->assertSame($submittedAt, $released->getAttribute('submittedAt'));
    }

    /**
     * @return \Iterator<string, array{StoreException}>
     */
    public static function unansweredCompliance(): \Iterator
    {
        $required = new Issue('ENTITY_ERROR.ATTRIBUTE.REQUIRED', "You must provide a value for the attribute 'usesNonExemptEncryption' with this request", '/data/attributes/usesNonExemptEncryption');

        yield 'review item refused' => [new Invalid('App Store Connect returned 409 ENTITY_ERROR.ATTRIBUTE.REQUIRED', [$required])];
        yield 'submit for review refused' => [new Conflict('App Store Connect returned 409 STATE_ERROR', [new Issue('STATE_ERROR', 'Submit for review errors found.'), $required])];
    }

    #[DataProvider('unansweredCompliance')]
    public function testAppleReleaseWithoutExportComplianceWaitsForAnAnswer(StoreException $refused): void
    {
        $target = new Document([...$this->target->getArrayCopy(), 'platform' => 'ios']);
        $artifact = new Document(['platform' => 'ios', 'identifier' => self::PACKAGE, 'versionName' => '1.2.0', 'buildNumber' => '88']);
        $submission = $this->submission(['platform' => 'ios', 'provider' => 'apple', 'track' => 'production', 'status' => 'processing', 'step' => 'processed', 'attempt' => 2, 'external' => ['appId' => 'store-app', 'buildId' => 'build-1']]);
        $this->store->failures['release'] = $refused;

        try {
            $this->submissions()->release($submission, $this->release, $target, $artifact, $this->connection);
            $this->fail('The store must refuse a release without an export compliance answer.');
        } catch (StoreException $error) {
            $waiting = $this->submissions()->fail($submission, $error);
        }

        $this->assertStep('processed', 'pending', $waiting);
        $this->assertSame(Submissions::ERROR_COMPLIANCE, $waiting->getAttribute('errorCode'));
        $this->assertSame(Submissions::COMPLIANCE_MISSING, $waiting->getAttribute('errorMessage'));
        $this->assertSame(0, $waiting->getAttribute('attempt'));
        $this->assertNull($waiting->getAttribute('retryAt'));
        $this->assertSame(15, Submissions::interval((string) $waiting->getAttribute('status'), 1.0));

        unset($this->store->failures['release']);

        $released = $this->submissions()->release($waiting, $this->release, $target, $artifact, $this->connection);

        $this->assertSame(['release', 'release'], $this->store->calls);
        $this->assertStep('released', 'pending', $released);
        $this->assertNull($released->getAttribute('errorCode'));
        $this->assertNull($released->getAttribute('errorMessage'));
        $this->assertNotEmpty($released->getAttribute('submittedAt'));
    }

    public function testRequiredAttributeOtherThanExportComplianceStillFails(): void
    {
        $submission = $this->submission(['platform' => 'ios', 'provider' => 'apple', 'track' => 'production', 'status' => 'processing', 'step' => 'processed', 'external' => ['appId' => 'store-app', 'buildId' => 'build-1']]);

        $failed = $this->submissions()->fail($submission, new Invalid('App Store Connect returned 409', [new Issue('ENTITY_ERROR.ATTRIBUTE.REQUIRED', 'A copyright is required', '/data/attributes/copyright')]));

        $this->assertSame('failed', $failed->getAttribute('status'));
        $this->assertSame('ENTITY_ERROR.ATTRIBUTE.REQUIRED', $failed->getAttribute('errorCode'));
    }

    /**
     * @return \Iterator<string, array{string, array<string, mixed>, list<string>}>
     */
    public static function unversionedAppleOperations(): \Iterator
    {
        yield 'reconcile' => ['reconcile', [], []];
        yield 'settle' => ['settle', [], []];
        yield 'control' => ['control', [], ['halt']];
        yield 'replayed control' => ['reconcile', ['control' => ['status' => 'halted', 'attempt' => 1]], ['halt']];
    }

    /**
     * @param array<string, mixed> $pending
     * @param list<string> $calls
     */
    #[DataProvider('unversionedAppleOperations')]
    public function testAppleProductionWithoutAVersionIsNotObserved(string $operation, array $pending, array $calls): void
    {
        $this->store->failures['state'] = new Invalid('The release has no App Store version');
        $submission = $this->submission(['platform' => 'ios', 'provider' => 'apple', 'track' => 'production', 'status' => 'processing', 'step' => 'released', 'external' => ['appId' => 'store-app', 'buildId' => 'build-1', 'track' => 'production', ...$pending]]);

        $observed = $this->operate($operation, $this->submissions(), $submission, $this->connection);

        $this->assertSame($calls, $this->store->calls);
        $this->assertStep('released', 'processing', $observed);
        $this->assertSame(['appId' => 'store-app', 'buildId' => 'build-1', 'track' => 'production'], $observed->getAttribute('external'));
        $this->assertEmpty($observed->getAttribute('errorCode'));
    }

    public function testUploadOfACanceledSubmissionIsNotRecorded(): void
    {
        $submission = $this->claimed($this->submission(['status' => 'validating', 'step' => 'located']));
        $result = $this->submissions()->upload($submission, $this->artifact, $this->connection);
        $this->database->updateDocument('distributionSubmissions', $submission->getId(), new Document(['status' => 'canceled']));

        $recorded = $this->submissions()->record($this->stored('distributionSubmissions', $submission->getId()), Submissions::claimOf($submission), $result);

        $this->assertStep('located', 'canceled', $recorded);
    }

    public function testCheckpointRefreshesTheClaimAndEncryptsTheSession(): void
    {
        $submission = $this->submission(['status' => 'uploading', 'step' => 'located', 'external' => ['claim' => ['id' => 'claim', 'expiresAt' => $this->at(5)]]]);

        (new Checkpoint($this->database, $submission->getId(), 'claim'))->save(['editId' => self::EDIT, 'session' => self::SESSION, 'offset' => 0]);

        $external = $this->stored('distributionSubmissions', $submission->getId())->getAttribute('external');
        $this->assertEqualsWithDelta(\time() + Submissions::CLAIM, (new \DateTime($external['claim']['expiresAt']))->getTimestamp(), 2);
        $this->assertSame(['data', 'method', 'iv', 'tag', 'version'], \array_keys($external['checkpoint']['session']));
        $this->assertSame('1', $external['checkpoint']['session']['version']);
        $this->assertStringNotContainsString('session-secret', (string) \json_encode($external));
        $this->assertSame(self::SESSION, Checkpoint::restore($external)['session'] ?? null);
    }

    public function testCheckpointOfAClaimTakenOverAbortsTheTransfer(): void
    {
        $submission = $this->submission(['external' => ['claim' => ['id' => 'successor', 'expiresAt' => $this->at(60)]]]);

        try {
            (new Checkpoint($this->database, $submission->getId(), 'predecessor'))->save(['editId' => self::EDIT, 'session' => self::SESSION, 'offset' => 0]);
            $this->fail('A checkpoint of a claim taken over must abort the transfer.');
        } catch (Contention) {
        }

        $this->assertSame([], $this->database->writes('distributionSubmissions'));
    }

    public function testCheckpointEncryptedWithAnotherKeyIsNotRestored(): void
    {
        $submission = $this->claimed($this->submission(['step' => 'located']));
        (new Checkpoint($this->database, $submission->getId(), $submission->getAttribute('external')['claim']['id']))->save(['session' => self::SESSION]);
        \putenv('_APP_OPENSSL_KEY_V1=rotated-' . self::SECRET);

        $this->assertNull(Checkpoint::restore($this->stored('distributionSubmissions', $submission->getId())->getAttribute('external')));
    }

    public function testGoogleSessionUrlNeverReachesTheStoredSubmission(): void
    {
        $transport = new Transport(
            Transport::fixture('Google/token'),
            Transport::fixture('Google/edit'),
            Transport::fixture('Google/tracks'),
            new Response(204),
            Transport::fixture('Google/edit'),
            new Response(200)->withHeader('Location', self::SESSION),
            Transport::fixture('Google/bundle'),
        );
        $submissions = $this->submissions(new Stores(googleEndpoint: self::GOOGLE, production: false, client: $transport));
        $submission = $this->claimed($this->submission(['status' => 'validating', 'step' => 'located']), $submissions);

        $uploaded = $this->recorded($submission, $submissions);

        $this->assertSame('uploaded', $uploaded->getAttribute('step'));
        $this->assertSame(self::EDIT, $uploaded->getAttribute('external')['editId']);
        $this->assertSame(88, $uploaded->getAttribute('external')['versionCode']);
        $this->assertSame(self::SESSION, (string) $transport->requests()[6]->getUri());

        $writes = $this->database->writes('distributionSubmissions');
        $this->assertTrue(\array_any($writes, static fn (array $write): bool => isset($write['attributes']['external']['checkpoint']['session']['data'])));
        $this->assertStringNotContainsString('session-secret', (string) \json_encode($writes));
        $this->assertStringNotContainsString('session-secret', (string) \json_encode($this->stored('distributionSubmissions', $submission->getId())->getArrayCopy()));
    }

    public function testGoogleUploadKilledAfterTheLastPartResumesWithoutANewSession(): void
    {
        $submission = $this->claimed($this->submission(['status' => 'validating', 'step' => 'located']));
        $claim = $submission->getAttribute('external')['claim']['id'];
        (new Checkpoint($this->database, $submission->getId(), $claim))->save(['packageName' => self::PACKAGE, 'editId' => self::EDIT, 'expiryTimeSeconds' => \time() + 3600, 'session' => self::SESSION, 'offset' => 0]);
        $this->expire($submission->getId());

        $transport = new Transport(
            Transport::fixture('Google/token'),
            Transport::fixture('Google/edit'),
            Transport::fixture('Google/bundle'),
        );
        $submissions = $this->submissions(new Stores(googleEndpoint: self::GOOGLE, production: false, client: $transport));
        $successor = $submissions->claim($this->stored('distributionSubmissions', $submission->getId()));
        $this->assertInstanceOf(Document::class, $successor);

        $uploaded = $this->recorded($successor, $submissions);

        $requests = $transport->requests();
        $this->assertSame(['POST', 'GET', 'PUT'], \array_map(static fn ($request): string => $request->getMethod(), $requests));
        $this->assertSame(self::SESSION, (string) $requests[2]->getUri());
        $this->assertSame('bytes */' . self::SIZE, $requests[2]->getHeaderLine('Content-Range'));
        $this->assertSame('uploaded', $uploaded->getAttribute('step'));
        $this->assertArrayNotHasKey('checkpoint', $uploaded->getAttribute('external'));
    }

    /**
     * @return \Iterator<string, array{array<string, mixed>, State, string, string, string, int|null}>
     */
    public static function states(): \Iterator
    {
        $processing = ['status' => 'processing', 'track' => 'production'];
        $staged = ['status' => 'published', 'track' => 'production', 'rollout' => 0.2];

        yield 'processing' => [$processing, new State(State::STATUS_PROCESSING, 'PROCESSING'), 'processing', 'released', 'active', null];
        yield 'pending' => [$processing, new State(State::STATUS_PENDING, 'RELEASE_LIFECYCLE_STATE_NOT_SENT_FOR_REVIEW'), 'pending', 'released', 'active', 15];
        yield 'reviewing' => [$processing, new State(State::STATUS_REVIEWING, 'RELEASE_LIFECYCLE_STATE_IN_REVIEW'), 'reviewing', 'released', 'active', 15];
        yield 'approved' => [$processing, new State(State::STATUS_APPROVED, 'RELEASE_LIFECYCLE_STATE_APPROVED_NOT_PUBLISHED'), 'approved', 'released', 'active', 15];
        yield 'published' => [$processing, new State(State::STATUS_PUBLISHED, 'RELEASE_LIFECYCLE_STATE_PUBLISHED'), 'published', 'settled', 'published', 0];
        yield 'staged rollout' => [$processing, new State(State::STATUS_PUBLISHED, 'RELEASE_LIFECYCLE_STATE_PUBLISHED', 0.2), 'published', 'released', 'published', 60];
        yield 'staged rollout completed' => [$staged, new State(State::STATUS_PUBLISHED, 'RELEASE_LIFECYCLE_STATE_PUBLISHED'), 'published', 'settled', 'published', 0];
        yield 'halted' => [$staged, new State(State::STATUS_HALTED, 'RELEASE_LIFECYCLE_STATE_PUBLISHED', 0.2), 'halted', 'released', 'active', 15];
        yield 'rejected' => [$processing, new State(State::STATUS_REJECTED, 'RELEASE_LIFECYCLE_STATE_NOT_APPROVED'), 'rejected', 'settled', 'attention', 0];
        yield 'withdrawn by the developer' => [$processing, new State(State::STATUS_REJECTED, 'DEVELOPER_REJECTED', issues: [new Issue('DEVELOPER_REJECTED', 'Withdrawn')]), 'canceled', 'settled', 'active', 0];
        yield 'replaced by a newer version' => [$processing, new State(State::STATUS_REJECTED, 'REPLACED_WITH_NEW_VERSION'), 'canceled', 'settled', 'active', 0];
        yield 'processing exception' => [$processing, new State(State::STATUS_REJECTED, 'PROCESSING_EXCEPTION'), 'failed', 'settled', 'attention', 0];
        yield 'expired build' => [$processing, new State(State::STATUS_REJECTED, 'EXPIRED'), 'canceled', 'settled', 'active', 0];
    }

    /**
     * @param array<string, mixed> $initial
     */
    #[DataProvider('states')]
    public function testReconcileMapsStoreState(array $initial, State $state, string $status, string $step, string $release, ?int $minutes): void
    {
        $this->store->state = $state;
        $submission = $this->submission(['step' => 'released', ...$initial]);

        $reconciled = $this->submissions()->reconcile($submission, $this->release, $this->connection);

        $this->assertStep($step, $status, $reconciled);
        $this->assertSame($state->raw, $reconciled->getAttribute('storeStatus'));
        $this->assertSame($state->fraction, $reconciled->getAttribute('rollout'));
        $this->assertSame($release, $this->stored('distributionReleases', 'release')->getAttribute('status'));

        $expected = match ($minutes) {
            null => [],
            0 => [['stop', $submission->getId(), 0]],
            default => [['write', $submission->getId(), $minutes]],
        };
        $this->assertSame($expected, $this->schedules->calls->getArrayCopy());
    }

    public function testReconcileResetsTheRetryLadder(): void
    {
        $submission = $this->submission(['status' => 'reviewing', 'step' => 'released', 'attempt' => 3, 'retryAt' => $this->at(-1)]);

        $reconciled = $this->submissions()->reconcile($submission, $this->release, $this->connection);

        $this->assertSame(0, $reconciled->getAttribute('attempt'));
        $this->assertNull($reconciled->getAttribute('retryAt'));
        $this->assertSame(['state'], $this->store->calls);
    }

    public function testReconcileOfAFinishedSubmissionStopsItsSchedule(): void
    {
        $submission = $this->submission(['status' => 'published', 'step' => 'settled', 'rollout' => 1.0]);

        $this->submissions()->reconcile($submission, $this->release, $this->connection);

        $this->assertSame([], $this->store->calls);
        $this->assertSame([['stop', $submission->getId(), 0]], $this->schedules->calls->getArrayCopy());
    }

    public function testReconcileKeepsTheRecordWhenTheStoreReportsAnImpossibleTransition(): void
    {
        $this->store->state = new State(State::STATUS_PROCESSING, 'PROCESSING');
        $submission = $this->submission(['status' => 'reviewing', 'step' => 'released']);

        $reconciled = $this->submissions()->reconcile($submission, $this->release, $this->connection);

        $this->assertSame('reviewing', $reconciled->getAttribute('status'));
        $this->assertSame('PROCESSING', $reconciled->getAttribute('storeStatus'));
    }

    /**
     * @return \Iterator<string, array{string}>
     */
    public static function reconciles(): \Iterator
    {
        yield 'reconcile' => ['reconcile'];
        yield 'settle' => ['settle'];
    }

    /**
     * @return \Iterator<string, array{string}>
     */
    public static function storeOperations(): \Iterator
    {
        yield from self::reconciles();
        yield 'control' => ['control'];
    }

    #[DataProvider('reconciles')]
    public function testGoogleStoreIsLeftAloneWhileAnotherSubmissionHoldsAnEdit(string $operation): void
    {
        $this->sibling(['editId' => self::EDIT]);
        $submission = $this->submission(['status' => 'published', 'step' => 'released', 'rollout' => 0.2]);

        $result = $this->operate($operation, $this->submissions(), $submission, $this->connection);

        $this->assertSame($submission->getArrayCopy(), $result->getArrayCopy());
        $this->assertSame([], $this->store->calls);
        $this->assertSame([], $this->database->writes('distributionSubmissions'));
    }

    #[DataProvider('storeOperations')]
    public function testGoogleStoreIsReachedOnceTheOtherEditIsCommitted(string $operation): void
    {
        $this->sibling(['editId' => self::EDIT, 'committed' => true]);
        $submission = $this->submission(['status' => 'published', 'step' => 'released', 'rollout' => 0.2]);

        $this->operate($operation, $this->submissions(), $submission, $this->connection);

        $this->assertContains('state', $this->store->calls);
    }

    #[DataProvider('storeOperations')]
    public function testAppleStoreIsNeverContended(string $operation): void
    {
        $this->sibling(['claim' => ['id' => 'sibling', 'expiresAt' => '2999-01-01 00:00:00.000']]);
        $connection = new Document([...$this->connection->getArrayCopy(), 'provider' => 'apple']);
        $submission = $this->submission(['status' => 'published', 'step' => 'released', 'rollout' => 0.2]);

        $this->operate($operation, $this->submissions(), $submission, $connection);

        $this->assertContains('state', $this->store->calls);
    }

    /**
     * @return \Iterator<string, array{array<string, mixed>, bool, bool}>
     */
    public static function externals(): \Iterator
    {
        yield 'nothing' => [[], false, false];
        yield 'live claim' => [['claim' => ['id' => 'claim', 'expiresAt' => '2999-01-01 00:00:00.000']], true, false];
        yield 'expired claim' => [['claim' => ['id' => 'claim', 'expiresAt' => '2000-01-01 00:00:00.000']], false, false];
        yield 'claim without expiry' => [['claim' => ['id' => 'claim']], false, false];
        yield 'checkpoint' => [['checkpoint' => ['offset' => 0]], false, true];
        yield 'open edit' => [['editId' => self::EDIT], false, true];
        yield 'committed edit' => [['editId' => self::EDIT, 'committed' => true], false, false];
    }

    /**
     * @param array<string, mixed> $external
     */
    #[DataProvider('externals')]
    public function testClaimAndEditAreReadFromExternal(array $external, bool $claimed, bool $editing): void
    {
        $this->assertSame($claimed, Submissions::isClaimed($external));
        $this->assertSame($editing, Submissions::isEditing($external));
    }

    /**
     * @return \Iterator<string, array{array<string, mixed>, string, list<string>, string}>
     */
    public static function controls(): \Iterator
    {
        yield 'cancel before release' => [['status' => 'uploading', 'step' => 'located'], 'canceled', [], 'canceled'];
        yield 'halt before release' => [['status' => 'uploading', 'step' => 'located'], 'halted', [], 'uploading'];
        yield 'withdraw from review' => [['status' => 'reviewing', 'step' => 'released'], 'canceled', ['withdraw', 'state'], 'reviewing'];
        yield 'halt a staged rollout' => [['status' => 'published', 'step' => 'released', 'rollout' => 0.2], 'halted', ['halt', 'state'], 'halted'];
        yield 'resume a halted rollout' => [['status' => 'halted', 'step' => 'released', 'rollout' => 0.2], 'published', ['resume', 'state'], 'published'];
        yield 'release an approved version' => [['status' => 'approved', 'step' => 'released'], 'published', ['rollout', 'state'], 'published'];
    }

    /**
     * @param array<string, mixed> $initial
     * @param list<string> $calls
     */
    #[DataProvider('controls')]
    public function testControl(array $initial, string $requested, array $calls, string $status): void
    {
        $this->store->state = match ($requested) {
            'halted' => new State(State::STATUS_HALTED, 'RELEASE_LIFECYCLE_STATE_PUBLISHED', 0.2),
            'published' => new State(State::STATUS_PUBLISHED, 'RELEASE_LIFECYCLE_STATE_PUBLISHED'),
            default => new State(State::STATUS_REVIEWING, 'RELEASE_LIFECYCLE_STATE_IN_REVIEW'),
        };
        $submission = $this->submission($initial);

        $controlled = $this->submissions()->control($submission, $this->connection, $requested);

        $this->assertSame($calls, $this->store->calls);
        $this->assertSame($status, $controlled->getAttribute('status'));
    }

    /**
     * @return \Iterator<string, array{array<string, mixed>}>
     */
    public static function manualAppleReleases(): \Iterator
    {
        yield 'without a phased release' => [['appId' => 'store-app', 'track' => 'production', 'versionId' => 'version-1', 'reviewSubmissionId' => 'review-1']];
        yield 'with an inactive phased release' => [['appId' => 'store-app', 'track' => 'production', 'versionId' => 'version-1', 'reviewSubmissionId' => 'review-1', 'phasedReleaseId' => 'phased-1']];
    }

    /**
     * @param array<string, mixed> $external
     */
    #[DataProvider('manualAppleReleases')]
    public function testPublishingAManualAppleReleaseRollsOutToEveryone(array $external): void
    {
        $this->store->state = new State(State::STATUS_PUBLISHED, 'READY_FOR_DISTRIBUTION');
        $submission = $this->submission(['platform' => 'ios', 'provider' => 'apple', 'track' => 'production', 'automatic' => false, 'status' => 'approved', 'step' => 'released', 'external' => $external]);

        $controlled = $this->submissions()->control($submission, $this->connection, 'published');

        $this->assertSame(['rollout', 'state'], $this->store->calls);
        $this->assertSame([['external' => $external, 'track' => 'production', 'fraction' => 1.0]], $this->store->rollouts);
        $this->assertStep('settled', 'published', $controlled);
        $this->assertEqualsWithDelta(1.0, $controlled->getAttribute('rollout'), PHP_FLOAT_EPSILON);
        $this->assertNotEmpty($controlled->getAttribute('publishedAt'));
    }

    /**
     * @return \Iterator<string, array{array<string, mixed>, bool}>
     */
    public static function automaticSubmissions(): \Iterator
    {
        yield 'approved and automatic' => [['provider' => 'apple', 'status' => 'approved', 'automatic' => true], true];
        yield 'approved and manual' => [['provider' => 'apple', 'status' => 'approved', 'automatic' => false], false];
        yield 'halted and automatic' => [['provider' => 'apple', 'status' => 'halted', 'automatic' => true, 'rollout' => 0.2], false];
        yield 'staged and automatic' => [['provider' => 'apple', 'status' => 'published', 'automatic' => true, 'rollout' => 0.2], false];
        yield 'reviewing and automatic' => [['provider' => 'apple', 'status' => 'reviewing', 'automatic' => true], false];
        yield 'google approved and automatic' => [['provider' => 'google', 'status' => 'approved', 'automatic' => true], false];
    }

    /**
     * @param array<string, mixed> $attributes
     */
    #[DataProvider('automaticSubmissions')]
    public function testIsReleasedOnApproval(array $attributes, bool $automatic): void
    {
        $this->assertSame($automatic, Submissions::isReleasedOnApproval(new Document($attributes)));
    }

    public function testPublishingAnAutomaticApprovedAppleSubmissionIsRefused(): void
    {
        $external = ['appId' => 'store-app', 'track' => 'production', 'versionId' => 'version-1', 'reviewSubmissionId' => 'review-1'];
        $submission = $this->submission(['platform' => 'ios', 'provider' => 'apple', 'track' => 'production', 'automatic' => true, 'status' => 'approved', 'step' => 'released', 'external' => $external]);

        $controlled = $this->submissions()->control($submission, $this->connection, 'published');

        $this->assertSame([], $this->store->calls);
        $this->assertStep('released', 'approved', $controlled);
        $this->assertSame(Exception::DISTRIBUTION_SUBMISSION_TRANSITION_INVALID, $controlled->getAttribute('errorCode'));
        $this->assertSame(Submissions::RELEASED_ON_APPROVAL, $controlled->getAttribute('errorMessage'));
        $this->assertSame($external, $controlled->getAttribute('external'));
        $this->assertEmpty($controlled->getAttribute('publishedAt'));
    }

    public function testQueuedPublishOfAnAutomaticApprovedAppleSubmissionIsDropped(): void
    {
        $external = ['appId' => 'store-app', 'track' => 'production', 'versionId' => 'version-1', 'reviewSubmissionId' => 'review-1'];
        $submission = $this->submission(['platform' => 'ios', 'provider' => 'apple', 'track' => 'production', 'automatic' => true, 'status' => 'approved', 'step' => 'released', 'external' => [...$external, 'control' => ['status' => 'published', 'attempt' => 1]]]);

        $reconciled = $this->submissions()->reconcile($submission, $this->release, $this->connection);

        $this->assertSame([], $this->store->calls);
        $this->assertStep('released', 'approved', $reconciled);
        $this->assertSame(Exception::DISTRIBUTION_SUBMISSION_TRANSITION_INVALID, $reconciled->getAttribute('errorCode'));
        $this->assertSame(Submissions::RELEASED_ON_APPROVAL, $reconciled->getAttribute('errorMessage'));
        $this->assertSame($external, $reconciled->getAttribute('external'));
        $this->assertNull($reconciled->getAttribute('retryAt'));
    }

    public function testPublishingAnAutomaticApprovedGoogleSubmissionRollsOut(): void
    {
        $this->store->state = new State(State::STATUS_PUBLISHED, 'RELEASE_LIFECYCLE_STATE_PUBLISHED');
        $external = ['editId' => self::EDIT, 'committed' => true];
        $submission = $this->submission(['track' => 'production', 'automatic' => true, 'status' => 'approved', 'step' => 'released', 'external' => $external]);

        $controlled = $this->submissions()->control($submission, $this->connection, 'published');

        $this->assertSame(['rollout', 'state'], $this->store->calls);
        $this->assertSame([['external' => $external, 'track' => 'production', 'fraction' => 1.0]], $this->store->rollouts);
        $this->assertStep('settled', 'published', $controlled);
        $this->assertEmpty($controlled->getAttribute('errorCode'));
        $this->assertNotEmpty($controlled->getAttribute('publishedAt'));
    }

    public function testCancelingAReleasedAppleTestFlightBuildIsReportedAndDropped(): void
    {
        $this->store->failures['withdraw'] = new Invalid('A beta review cannot be withdrawn');
        $submission = $this->submission([
            'platform' => 'ios',
            'provider' => 'apple',
            'track' => 'testflight-external',
            'status' => 'reviewing',
            'step' => 'released',
            'external' => ['buildId' => 'build-1', 'track' => 'testflight-external', 'betaReviewSubmissionId' => 'beta-1', 'control' => ['status' => 'canceled', 'attempt' => 1]],
        ]);

        $controlled = $this->submissions()->control($submission, $this->connection, 'canceled');

        $this->assertSame(['withdraw'], $this->store->calls);
        $this->assertStep('released', 'reviewing', $controlled);
        $this->assertSame(Exception::DISTRIBUTION_SUBMISSION_TRANSITION_INVALID, $controlled->getAttribute('errorCode'));
        $this->assertSame('A beta review cannot be withdrawn', $controlled->getAttribute('errorMessage'));
        $this->assertArrayNotHasKey('control', $controlled->getAttribute('external'));
        $this->assertSame('beta-1', $controlled->getAttribute('external')['betaReviewSubmissionId']);
        $this->assertNull($controlled->getAttribute('retryAt'));
    }

    public function testCancelingAnAppleReviewWithdrawsIt(): void
    {
        $this->store->state = new State(State::STATUS_REJECTED, 'DEVELOPER_REJECTED', issues: [new Issue('DEVELOPER_REJECTED', 'The version was removed from review')]);
        $submission = $this->submission([
            'platform' => 'ios',
            'provider' => 'apple',
            'track' => 'production',
            'status' => 'reviewing',
            'step' => 'released',
            'external' => ['appId' => 'store-app', 'track' => 'production', 'versionId' => 'version-1', 'reviewSubmissionId' => 'review-1'],
        ]);

        $controlled = $this->submissions()->control($submission, $this->connection, 'canceled');

        $this->assertSame(['withdraw', 'state'], $this->store->calls);
        $this->assertStep('settled', 'canceled', $controlled);
        $this->assertSame('DEVELOPER_REJECTED', $controlled->getAttribute('storeStatus'));
        $this->assertSame('active', $this->stored('distributionReleases', 'release')->getAttribute('status'));
    }

    public function testControlTheStoreCannotPerformIsReported(): void
    {
        $this->store->failures['withdraw'] = new Invalid('withdraw is not supported by google');
        $submission = $this->submission(['status' => 'reviewing', 'step' => 'released']);

        $controlled = $this->submissions()->control($submission, $this->connection, 'canceled');

        $this->assertSame('reviewing', $controlled->getAttribute('status'));
        $this->assertSame(Exception::DISTRIBUTION_SUBMISSION_TRANSITION_INVALID, $controlled->getAttribute('errorCode'));
        $this->assertSame('withdraw is not supported by google', $controlled->getAttribute('errorMessage'));
    }

    /**
     * @return \Iterator<string, array{\Throwable, string}>
     */
    public static function refusedControls(): \Iterator
    {
        yield 'release not found' => [new NotFound('Gone', [new Issue('releaseNotFound', 'No release')]), 'releaseNotFound'];
        yield 'conflict' => [new Conflict('Conflict', [new Issue('FAILED_PRECONDITION', 'Precondition')]), 'FAILED_PRECONDITION'];
        yield 'permission denied' => [new Unauthorized('Google Play returned 403', [new Issue('permissionDenied', 'No access')]), 'permissionDenied'];
        yield 'rejected credential' => [new Unauthorized('Bad key', credential: true), Exception::DISTRIBUTION_CONNECTION_INVALID];
        yield 'not supported' => [new Invalid('halt is not supported'), Exception::DISTRIBUTION_SUBMISSION_TRANSITION_INVALID];
        yield 'issue without a code' => [new NotFound('Gone'), Submissions::ERROR_REJECTED];
    }

    #[DataProvider('refusedControls')]
    public function testControlTheStoreRefusesKeepsALiveRolloutRunning(\Throwable $error, string $code): void
    {
        $this->store->failures['halt'] = $error;
        $submission = $this->submission(['status' => 'published', 'step' => 'released', 'rollout' => 0.2]);

        $controlled = $this->submissions()->control($submission, $this->connection, 'halted');

        $this->assertStep('released', 'published', $controlled);
        $this->assertEqualsWithDelta(0.2, $controlled->getAttribute('rollout'), PHP_FLOAT_EPSILON);
        $this->assertSame($code, $controlled->getAttribute('errorCode'));
        $this->assertSame($error->getMessage(), $controlled->getAttribute('errorMessage'));
        $this->assertSame('active', $this->stored('distributionConnections', 'connection')->getAttribute('status'));
        $this->assertSame([], $this->schedules->calls->getArrayCopy(), 'The rollout keeps its schedule.');
        $this->assertNull($this->publisher->getEvents(self::QUEUE), 'Nothing is requeued.');
        $this->assertSame(['halt'], $this->store->calls);
    }

    /**
     * @return \Iterator<string, array{\Throwable}>
     */
    public static function unreachableControls(): \Iterator
    {
        yield 'rate limited' => [new RateLimited('Slow down')];
        yield 'unavailable' => [new Unavailable('Down')];
    }

    #[DataProvider('unreachableControls')]
    public function testControlTheStoreCannotReachIsDeferred(\Throwable $error): void
    {
        $this->store->failures['halt'] = $error;
        $submission = $this->submission(['status' => 'published', 'step' => 'released', 'rollout' => 0.2, 'external' => ['editId' => self::EDIT, 'committed' => true, 'control' => ['status' => 'halted', 'attempt' => 2]]]);

        $controlled = $this->submissions()->control($submission, $this->connection, 'halted');

        $this->assertStep('released', 'published', $controlled);
        $this->assertSame(Exception::DISTRIBUTION_STORE_UNAVAILABLE, $controlled->getAttribute('errorCode'));
        $this->assertSame(0, $controlled->getAttribute('attempt'), 'A control retry never spends the submission\'s own retry budget.');
        $this->assertSame(['editId' => self::EDIT, 'committed' => true, 'control' => ['status' => 'halted', 'attempt' => 3]], $controlled->getAttribute('external'));
        $this->assertEqualsWithDelta(\strtotime($this->at(900)), \strtotime((string) $controlled->getAttribute('retryAt')), 5);
        $this->assertNull($this->publisher->getEvents(self::QUEUE), 'The retry waits for the schedule instead of the queue.');
        $this->assertSame(['halt'], $this->store->calls);
    }

    public function testControlRefusedByTheStoreSurvivesTheNextReconcile(): void
    {
        $this->store->failures['halt'] = new Invalid('halt is not supported');
        $this->store->state = new State(State::STATUS_PUBLISHED, 'RELEASE_LIFECYCLE_STATE_PUBLISHED', 0.2);
        $submission = $this->submission(['status' => 'published', 'step' => 'released', 'rollout' => 0.2]);
        $submissions = $this->submissions();

        $controlled = $submissions->control($submission, $this->connection, 'halted');
        $reconciled = $submissions->reconcile($controlled, $this->release, $this->connection);

        $this->assertStep('released', 'published', $reconciled);
        $this->assertSame(Exception::DISTRIBUTION_SUBMISSION_TRANSITION_INVALID, $reconciled->getAttribute('errorCode'));
        $this->assertSame('halt is not supported', $reconciled->getAttribute('errorMessage'));
        $this->assertArrayNotHasKey('control', $reconciled->getAttribute('external'));
        $this->assertSame(['halt', 'state'], $this->store->calls);
    }

    public function testReconcileReplacesAControlErrorOnceTheStoreMoves(): void
    {
        $this->store->state = new State(State::STATUS_HALTED, 'RELEASE_LIFECYCLE_STATE_PUBLISHED', 0.2);
        $submission = $this->submission(['status' => 'published', 'step' => 'released', 'rollout' => 0.2, 'errorCode' => Exception::DISTRIBUTION_SUBMISSION_TRANSITION_INVALID, 'errorMessage' => 'halt is not supported']);

        $reconciled = $this->submissions()->reconcile($submission, $this->release, $this->connection);

        $this->assertSame('halted', $reconciled->getAttribute('status'));
        $this->assertNull($reconciled->getAttribute('errorCode'));
        $this->assertNull($reconciled->getAttribute('errorMessage'));
    }

    public function testRateLimitedControlIsReplayedByTheNextReconcile(): void
    {
        $this->store->failures['halt'] = new RateLimited('Slow down');
        $this->store->state = new State(State::STATUS_HALTED, 'RELEASE_LIFECYCLE_STATE_PUBLISHED', 0.2);
        $submission = $this->submission(['status' => 'published', 'step' => 'released', 'rollout' => 0.2]);
        $submissions = $this->submissions();

        $deferred = $submissions->control($submission, $this->connection, 'halted');

        $this->assertSame(['control' => ['status' => 'halted', 'attempt' => 1]], $deferred->getAttribute('external'));
        $this->assertEqualsWithDelta(\strtotime($this->at(60)), \strtotime((string) $deferred->getAttribute('retryAt')), 5);
        $this->assertSame(['halt'], $this->store->calls);

        unset($this->store->failures['halt']);
        $reconciled = $submissions->reconcile($this->stored('distributionSubmissions', $submission->getId()), $this->release, $this->connection);

        $this->assertStep('released', 'halted', $reconciled);
        $this->assertSame([], $reconciled->getAttribute('external'));
        $this->assertNull($reconciled->getAttribute('retryAt'));
        $this->assertNull($reconciled->getAttribute('errorCode'));
        $this->assertNull($reconciled->getAttribute('errorMessage'));
        $this->assertSame(['halt', 'halt', 'state'], $this->store->calls);
        $this->assertNull($this->publisher->getEvents(self::QUEUE));
    }

    public function testContendedControlIsReplayedOnceTheStoreIsFree(): void
    {
        $this->sibling(['editId' => self::EDIT]);
        $this->store->state = new State(State::STATUS_HALTED, 'RELEASE_LIFECYCLE_STATE_PUBLISHED', 0.2);
        $submission = $this->submission(['status' => 'published', 'step' => 'released', 'rollout' => 0.2]);
        $submissions = $this->submissions();

        $deferred = $submissions->control($submission, $this->connection, 'halted');

        $this->assertStep('released', 'published', $deferred);
        $this->assertSame(['control' => ['status' => 'halted', 'attempt' => 0]], $deferred->getAttribute('external'));
        $this->assertNull($deferred->getAttribute('retryAt'), 'Contention is not a store failure, so the next tick replays it.');
        $this->assertSame([], $this->store->calls);

        $this->database->updateDocument('distributionSubmissions', 'sibling', new Document(['external' => ['editId' => self::EDIT, 'committed' => true]]));
        $reconciled = $submissions->reconcile($this->stored('distributionSubmissions', $submission->getId()), $this->release, $this->connection);

        $this->assertStep('released', 'halted', $reconciled);
        $this->assertSame([], $reconciled->getAttribute('external'));
        $this->assertSame(['halt', 'state'], $this->store->calls);
    }

    public function testNewerControlReplacesThePendingOne(): void
    {
        $this->store->state = new State(State::STATUS_PUBLISHED, 'RELEASE_LIFECYCLE_STATE_PUBLISHED');
        $submission = $this->submission(['status' => 'published', 'step' => 'released', 'rollout' => 0.2, 'external' => ['control' => ['status' => 'halted', 'attempt' => 3]]]);

        $controlled = $this->submissions()->control($submission, $this->connection, 'published');

        $this->assertStep('settled', 'published', $controlled);
        $this->assertSame([], $controlled->getAttribute('external'));
        $this->assertSame(['rollout', 'state'], $this->store->calls);
    }

    public function testControlRetriesStopAfterTheLastAttempt(): void
    {
        $this->store->failures['halt'] = new Unavailable('Still down');
        $submission = $this->submission(['status' => 'published', 'step' => 'released', 'rollout' => 0.2, 'retryAt' => $this->at(-1), 'external' => ['control' => ['status' => 'halted', 'attempt' => 4]]]);

        $reconciled = $this->submissions()->reconcile($submission, $this->release, $this->connection);

        $this->assertStep('released', 'published', $reconciled);
        $this->assertSame(Exception::DISTRIBUTION_STORE_UNAVAILABLE, $reconciled->getAttribute('errorCode'));
        $this->assertSame('Still down', $reconciled->getAttribute('errorMessage'));
        $this->assertSame([], $reconciled->getAttribute('external'));
        $this->assertNull($reconciled->getAttribute('retryAt'));
        $this->assertNull($this->publisher->getEvents(self::QUEUE), 'Nothing is requeued.');
        $this->assertSame(['halt'], $this->store->calls);

        $this->store->state = new State(State::STATUS_PUBLISHED, 'RELEASE_LIFECYCLE_STATE_PUBLISHED', 0.2);
        $later = $this->submissions()->reconcile($reconciled, $this->release, $this->connection);

        $this->assertSame(Exception::DISTRIBUTION_STORE_UNAVAILABLE, $later->getAttribute('errorCode'), 'The abandoned control stays reported.');
        $this->assertSame(['halt', 'state'], $this->store->calls);
    }

    private function submissions(?Stores $stores = null): Submissions
    {
        return new Submissions(
            $this->database,
            $this->project,
            $stores ?? new ScriptedStores($this->store),
            $this->schedules,
            new Local($this->directory),
            new DistributionSubmissionPublisher($this->publisher, new Queue(self::QUEUE)),
        );
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private function submission(array $attributes = []): Document
    {
        return $this->database->seed('distributionSubmissions', [
            '$id' => 'submission',
            'appId' => 'app',
            'appInternalId' => $this->app->getSequence(),
            'releaseId' => 'release',
            'releaseInternalId' => $this->release->getSequence(),
            'targetId' => 'target',
            'targetInternalId' => $this->target->getSequence(),
            'artifactId' => 'artifact',
            'artifactInternalId' => $this->artifact->getSequence(),
            'platform' => 'android',
            'provider' => 'google',
            'track' => 'internal',
            'rollout' => 1.0,
            'automatic' => true,
            'status' => 'waiting',
            'step' => null,
            'external' => [],
            'key' => 'submission',
            'attempt' => 0,
            ...$attributes,
        ]);
    }

    private function recorded(Document $claimed, ?Submissions $submissions = null): Document
    {
        $submissions ??= $this->submissions();
        $result = $submissions->upload($claimed, $this->artifact, $this->connection);

        return $submissions->record($this->stored('distributionSubmissions', $claimed->getId()), Submissions::claimOf($claimed), $result);
    }

    /**
     * @param array<string, mixed> $external
     */
    private function sibling(array $external, string $identifier = self::PACKAGE, string $connectionId = 'connection'): void
    {
        $other = $this->database->seed('distributionTargets', [
            '$id' => 'other-target',
            'platform' => 'android',
            'identifier' => $identifier,
            'connectionId' => $connectionId,
            'connectionInternalId' => $this->stored('distributionConnections', $connectionId)->getSequence(),
        ]);
        $this->submission(['$id' => 'sibling', 'key' => 'sibling', 'targetId' => 'other-target', 'targetInternalId' => $other->getSequence(), 'status' => 'uploading', 'external' => $external]);
    }

    private function operate(string $operation, Submissions $submissions, Document $submission, Document $connection): Document
    {
        return match ($operation) {
            'reconcile' => $submissions->reconcile($submission, $this->release, $connection),
            'settle' => $submissions->settle($submission, $connection),
            'control' => $submissions->control($submission, $connection, 'halted'),
            default => throw new \LogicException("Unknown store operation {$operation}"),
        };
    }

    private function claimed(Document $submission, ?Submissions $submissions = null): Document
    {
        $claimed = ($submissions ?? $this->submissions())->claim($submission);
        $this->assertInstanceOf(Document::class, $claimed);

        return $claimed;
    }

    private function expire(string $submissionId): void
    {
        $external = $this->stored('distributionSubmissions', $submissionId)->getAttribute('external');
        $external['claim']['expiresAt'] = $this->at(-1);

        $this->database->updateDocument('distributionSubmissions', $submissionId, new Document(['external' => $external]));
    }

    private function stored(string $collection, string $id): Document
    {
        return $this->database->getDocument($collection, $id);
    }

    private function at(int $seconds): string
    {
        return DateTime::addSeconds(new \DateTime(), $seconds);
    }

    private function assertStep(string $step, string $status, Document $submission): void
    {
        $this->assertSame($step, $submission->getAttribute('step'));
        $this->assertSame($status, $submission->getAttribute('status'));
    }
}
