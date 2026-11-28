<?php

declare(strict_types=1);

namespace Tests\Unit\Distribution;

use Appwrite\Distribution\Builds;
use Appwrite\Distribution\Lease\Status;
use Appwrite\Distribution\Lease\Token;
use Appwrite\Distribution\Leases;
use Appwrite\Distribution\Runners;
use Appwrite\Extend\Exception;
use OpenRuntimes\Orchestrator\Enum\CallbackEvent;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Distribution\Fakes\LeaseDatabase;
use Utopia\Database\DateTime;
use Utopia\Database\Document;

final class LeasesTest extends TestCase
{
    private const string KEY = '_APP_OPENSSL_KEY_V1';

    private const string ENDPOINT = 'https://cloud.appwrite.test/v1';

    private const string UUID = '0192f0c5-7a1b-7c3d-8e4f-5a6b7c8d9e0f';

    private string|false $key = false;

    private LeaseDatabase $dbForPlatform;

    private Leases $leases;

    private Document $project;

    private Document $runner;

    protected function setUp(): void
    {
        $this->key = \getenv(self::KEY);
        \putenv(self::KEY . '=test-openssl-key');

        $this->dbForPlatform = new LeaseDatabase();
        $this->leases = new Leases($this->dbForPlatform);
        $this->project = new Document(['$id' => 'project1', '$sequence' => '7', 'region' => 'fra']);
        $this->runner = $this->dbForPlatform->seed('distributionRunners', [
            '$id' => 'runner1',
            'platform' => 'macos',
            'capacity' => 1,
            'labels' => ['xcode-16.2'],
        ]);
    }

    protected function tearDown(): void
    {
        \putenv($this->key === false ? self::KEY : self::KEY . '=' . $this->key);
    }

    public function testIdIsDeterministicAnd36HexCharacters(): void
    {
        $id = Leases::id($this->project, $this->build(), 1);

        $this->assertSame(\substr(\hash('sha256', '7:build1:lease:1'), 0, 36), $id);
        $this->assertSame(36, \strlen($id));
        $this->assertTrue(\ctype_xdigit($id));
        $this->assertNotSame($id, Leases::id($this->project, $this->build(), 2));
        $this->assertSame(
            \substr(\hash('sha256', self::UUID . ':build1:lease:1'), 0, 36),
            Leases::id(new Document(['$id' => 'project1', '$sequence' => self::UUID]), $this->build(), 1),
        );
    }

    public function testArtifactIdIsDerivedFromTheLease(): void
    {
        $this->assertSame(\substr(\hash('sha256', 'lease1:artifact'), 0, 36), Leases::artifact('lease1'));
        $this->assertNotSame(Leases::artifact('lease1'), Leases::artifact('lease2'));
    }

    public function testSecretIsDerivedFromTheKeyAndEmptyWithoutIt(): void
    {
        $this->assertSame(\hash_hmac('sha256', 'distribution:lease', 'test-openssl-key'), Leases::secret());
        $this->assertNotSame(Runners::secret(), Leases::secret());

        \putenv(self::KEY . '=');

        $this->assertSame('', Leases::secret());
    }

    public function testQueueCreatesAQueuedLease(): void
    {
        $lease = $this->leases->queue($this->project, $this->build(attempt: 2), ['xcode-16.2', 'xcode-16.2'], self::ENDPOINT);

        $this->assertSame(Leases::id($this->project, $this->build(), 2), $lease->getId());
        $this->assertSame([
            'projectId' => 'project1',
            'projectInternalId' => '7',
            'buildId' => 'build1',
            'platform' => 'ios',
            'runnerPlatform' => 'macos',
            'labels' => ['xcode-16.2'],
            'region' => 'fra',
            'endpoint' => self::ENDPOINT,
            'status' => Status::Queued->value,
            'attempt' => 2,
        ], $this->fields($lease, ['projectId', 'projectInternalId', 'buildId', 'platform', 'runnerPlatform', 'labels', 'region', 'endpoint', 'status', 'attempt']));
        $this->assertNotEmpty($lease->getAttribute('queuedAt'));
    }

    public function testQueueIsIdempotent(): void
    {
        $first = $this->leases->queue($this->project, $this->build(), [], self::ENDPOINT);
        $second = $this->leases->queue($this->project, $this->build(), [], self::ENDPOINT);

        $this->assertSame($first->getId(), $second->getId());
        $this->assertSame($first->getUpdatedAt(), $second->getUpdatedAt());
        $this->assertCount(1, $this->dbForPlatform->documents['distributionLeases']);
        $this->assertSame(1, $this->dbForPlatform->writes);
    }

    public function testQueueAndClaimWithAUuidProjectSequence(): void
    {
        $project = new Document(['$id' => 'project1', '$sequence' => self::UUID, 'region' => 'fra']);

        $lease = $this->leases->queue($project, $this->build(), [], self::ENDPOINT);
        $claimed = $this->leases->claim($this->runner, [], Leases::TRIES);

        $this->assertSame(\substr(\hash('sha256', self::UUID . ':build1:lease:1'), 0, 36), $lease->getId());
        $this->assertSame(self::UUID, $lease->getAttribute('projectInternalId'));
        $this->assertNotNull($claimed);
        $verified = Token::verify($claimed['token'], Leases::secret());
        $this->assertInstanceOf(Token::class, $verified);
        $this->assertSame(self::UUID, $verified->projectInternalId);
        $this->assertSame($lease->getId(), $this->leases->held($verified, $claimed['token'])->getId());
    }

    public function testClaimWithAUuidRunnerSequence(): void
    {
        $this->dbForPlatform->uuid = true;
        $runner = $this->dbForPlatform->seed('distributionRunners', ['$id' => 'runner2', 'platform' => 'macos', 'capacity' => 1]);
        $this->queued('lease-a', '2026-01-01 00:00:00.000');

        $claimed = $this->leases->claim($runner, [], Leases::TRIES);

        $this->assertNotNull($claimed);
        $this->assertSame($runner->getSequence(), $claimed['lease']->getAttribute('runnerInternalId'));
        $this->assertSame(36, \strlen((string) $runner->getSequence()));
    }

    public function testClaimReturnsNullOnAnEmptyQueue(): void
    {
        $this->assertNull($this->leases->claim($this->runner, ['xcode-16.2'], Leases::TRIES));
        $this->assertSame([], $this->dbForPlatform->purged);
    }

    public function testClaimTakesTheOldestMatchingLease(): void
    {
        $this->queued('lease-c', '2026-01-01 00:00:02.000');
        $this->queued('lease-a', '2026-01-01 00:00:00.000');
        $this->queued('lease-b', '2026-01-01 00:00:01.000');

        $claimed = $this->leases->claim($this->runner, ['xcode-16.2'], Leases::TRIES);

        $this->assertNotNull($claimed);
        $lease = $claimed['lease'];
        $this->assertSame($this->id('lease-a'), $lease->getId());
        $this->assertSame(1, $claimed['tries']);
        $this->assertSame(Status::Leased->value, $lease->getAttribute('status'));
        $this->assertSame('runner1', $lease->getAttribute('runnerId'));
        $this->assertSame($this->runner->getSequence(), $lease->getAttribute('runnerInternalId'));
        $this->assertFalse(Leases::isExpired($lease, \time()));
        $this->assertTrue(Leases::isExpired($lease, \time() + Leases::TTL + 1));
        $this->assertSame(Status::Queued->value, $this->row('lease-b')->getAttribute('status'));
        $this->assertSame(Status::Queued->value, $this->row('lease-c')->getAttribute('status'));
    }

    public function testClaimStoresOnlyTheTokenHash(): void
    {
        $this->queued('lease-a', '2026-01-01 00:00:00.000');

        $claimed = $this->leases->claim($this->runner, [], Leases::TRIES);

        $this->assertNotNull($claimed);
        $stored = $this->row('lease-a')->getAttribute('token');
        $this->assertSame(\hash('sha256', $claimed['token']), $stored);
        $this->assertNotSame($claimed['token'], $stored);
        $verified = Token::verify($claimed['token'], Leases::secret());
        $this->assertInstanceOf(Token::class, $verified);
        $this->assertSame($this->id('lease-a'), $verified->leaseId);
        $this->assertSame('runner1', $verified->runnerId);
        $this->assertSame('7', $verified->projectInternalId);
    }

    public function testClaimSkipsLeasesTheRunnerLacksLabelsFor(): void
    {
        $this->queued('lease-a', '2026-01-01 00:00:00.000', ['xcode-15.4']);
        $this->queued('lease-b', '2026-01-01 00:00:01.000', ['xcode-16.2', 'arm64']);
        $this->queued('lease-c', '2026-01-01 00:00:02.000', ['xcode-16.2']);

        $claimed = $this->leases->claim($this->runner, ['xcode-16.2'], Leases::TRIES);

        $this->assertNotNull($claimed);
        $this->assertSame($this->id('lease-c'), $claimed['lease']->getId());
        $this->assertSame(1, $claimed['tries']);
    }

    public function testClaimSkipsOtherPlatformsAndStates(): void
    {
        $this->queued('lease-a', '2026-01-01 00:00:00.000', [], ['runnerPlatform' => 'linux']);
        $this->queued('lease-b', '2026-01-01 00:00:01.000', [], ['status' => Status::Released->value]);

        $this->assertNull($this->leases->claim($this->runner, [], Leases::TRIES));
    }

    public function testClaimScansOnlyTheOldestRows(): void
    {
        for ($row = 1; $row <= Leases::SCAN; $row++) {
            $this->queued("lease-{$row}", \sprintf('2026-01-01 00:00:%02d.000', $row), ['xcode-15.4']);
        }
        $this->queued('lease-26', '2026-01-01 00:00:59.000');

        $this->assertNull($this->leases->claim($this->runner, ['xcode-16.2'], Leases::TRIES));
        $this->assertSame(Status::Queued->value, $this->row('lease-26')->getAttribute('status'));
    }

    public function testClaimMovesOnAfterAConflict(): void
    {
        $this->queued('lease-a', '2026-01-01 00:00:00.000');
        $this->queued('lease-b', '2026-01-01 00:00:01.000');
        $this->dbForPlatform->beforeUpdate = function (string $collection, string $id): void {
            $this->dbForPlatform->beforeUpdate = null;
            $this->dbForPlatform->bump($collection, $id);
        };

        $claimed = $this->leases->claim($this->runner, [], Leases::TRIES);

        $this->assertNotNull($claimed);
        $this->assertSame($this->id('lease-b'), $claimed['lease']->getId());
        $this->assertSame(2, $claimed['tries']);
        $this->assertSame(Status::Queued->value, $this->row('lease-a')->getAttribute('status'));
        $this->assertSame([
            'distributionLeases:' . $this->id('lease-a'),
            'distributionLeases:' . $this->id('lease-b'),
        ], $this->dbForPlatform->purged);
    }

    public function testClaimStopsAfterItsTries(): void
    {
        $this->queued('lease-a', '2026-01-01 00:00:00.000');
        $this->queued('lease-b', '2026-01-01 00:00:01.000');
        $this->queued('lease-c', '2026-01-01 00:00:02.000');
        $this->dbForPlatform->beforeUpdate = fn (string $collection, string $id) => $this->dbForPlatform->bump($collection, $id);

        $this->assertNull($this->leases->claim($this->runner, [], 2));

        $this->assertSame(0, $this->dbForPlatform->writes);
        $this->assertSame([
            'distributionLeases:' . $this->id('lease-a'),
            'distributionLeases:' . $this->id('lease-b'),
        ], $this->dbForPlatform->purged);
        foreach (['lease-a', 'lease-b', 'lease-c'] as $lease) {
            $this->assertSame(Status::Queued->value, $this->row($lease)->getAttribute('status'));
        }
    }

    public function testClaimRefusesARunnerAtCapacity(): void
    {
        $this->leased('lease-held', 'runner1', (string) $this->runner->getSequence(), DateTime::addSeconds(new \DateTime(), 60));
        $this->queued('lease-a', '2026-01-01 00:00:00.000');

        $this->assertNull($this->leases->claim($this->runner, [], Leases::TRIES));
        $this->assertSame(Status::Queued->value, $this->row('lease-a')->getAttribute('status'));
    }

    public function testClaimFillsASecondSlot(): void
    {
        $runner = $this->dbForPlatform->seed('distributionRunners', ['$id' => 'runner2', 'platform' => 'macos', 'capacity' => 2]);
        $this->leased('lease-held', 'runner2', (string) $runner->getSequence(), DateTime::addSeconds(new \DateTime(), 60));
        $this->queued('lease-a', '2026-01-01 00:00:00.000');

        $this->assertNotNull($this->leases->claim($runner, [], Leases::TRIES));
    }

    public function testAnExpiredLeaseFreesTheRunner(): void
    {
        $this->leased('lease-held', 'runner1', (string) $this->runner->getSequence(), DateTime::addSeconds(new \DateTime(), -1));
        $this->queued('lease-a', '2026-01-01 00:00:00.000');

        $claimed = $this->leases->claim($this->runner, [], Leases::TRIES);

        $this->assertNotNull($claimed);
        $this->assertSame($this->id('lease-a'), $claimed['lease']->getId());
    }

    public function testAnotherRunnersLeaseDoesNotCount(): void
    {
        $this->leased('lease-held', 'runner9', '99', DateTime::addSeconds(new \DateTime(), 60));
        $this->queued('lease-a', '2026-01-01 00:00:00.000');

        $this->assertNotNull($this->leases->claim($this->runner, [], Leases::TRIES));
    }

    public function testClaimWithoutTheKeyLeavesTheQueue(): void
    {
        $this->queued('lease-a', '2026-01-01 00:00:00.000');
        \putenv(self::KEY . '=');

        try {
            $this->leases->claim($this->runner, [], Leases::TRIES);
            $this->fail('Claiming without the key must throw.');
        } catch (\LogicException $error) {
            $this->assertSame(Token::KEY_REQUIRED, $error->getMessage());
        }

        $this->assertSame(0, $this->dbForPlatform->writes);
        $this->assertSame(Status::Queued->value, $this->row('lease-a')->getAttribute('status'));
    }

    public function testHeldReturnsTheClaimedLease(): void
    {
        [$verified, $token] = $this->claimed();

        $this->assertSame($this->id('lease-a'), $this->leases->held($verified, $token)->getId());
    }

    public function testHeldRejectsARotatedToken(): void
    {
        [$verified] = $this->claimed();
        $rotated = Token::issue($verified->leaseId, $verified->runnerId, $verified->projectInternalId, Leases::secret());

        $this->assertHeldRefused($verified, $rotated, Exception::USER_UNAUTHORIZED);
    }

    public function testHeldRejectsAnotherRunner(): void
    {
        [$verified, $token] = $this->claimed();

        $this->assertHeldRefused(new Token($verified->leaseId, 'runner2', $verified->projectInternalId), $token, Exception::USER_UNAUTHORIZED);
    }

    public function testHeldRejectsAnotherProject(): void
    {
        [$verified, $token] = $this->claimed();

        $this->assertHeldRefused(new Token($verified->leaseId, $verified->runnerId, '8'), $token, Exception::USER_UNAUTHORIZED);
    }

    public function testHeldRejectsAnUnknownLease(): void
    {
        [$verified, $token] = $this->claimed();

        $this->assertHeldRefused(new Token($this->id('lease-z'), $verified->runnerId, $verified->projectInternalId), $token, Exception::DISTRIBUTION_LEASE_NOT_FOUND);
    }

    public function testRenewMovesTheExpiry(): void
    {
        $lease = $this->leased('lease-a', 'runner1', '1', DateTime::addSeconds(new \DateTime(), 10));

        $renewed = $this->leases->renew($lease, $this->current($lease));

        $this->assertInstanceOf(Document::class, $renewed);
        $this->assertGreaterThan($lease->getAttribute('expiresAt'), $renewed->getAttribute('expiresAt'));
        $this->assertFalse(Leases::isExpired($renewed, \time() + Leases::TTL - 5));
        $this->assertSame($renewed->getAttribute('expiresAt'), $this->row('lease-a')->getAttribute('expiresAt'));
        $this->assertSame(['distributionLeases:' . $this->id('lease-a')], $this->dbForPlatform->purged);
    }

    public function testRenewStopsAReleasedLease(): void
    {
        $lease = $this->leased('lease-a', 'runner1', '1', DateTime::addSeconds(new \DateTime(), 10), ['status' => Status::Released->value]);

        $this->assertNull($this->leases->renew($lease, $this->current($lease)));
        $this->assertSame(0, $this->dbForPlatform->writes);
    }

    /**
     * @return \Iterator<string, array{array<string, mixed>|null}>
     */
    public static function stale(): \Iterator
    {
        yield 'build gone' => [null];
        yield 'another attempt is current' => [['jobId' => 'lease-b', 'attempt' => 2]];
        yield 'another attempt number' => [['attempt' => 2]];
        yield 'build ended' => [['status' => 'ready']];
        yield 'build canceled' => [['status' => 'canceled']];
    }

    /**
     * @param array<string, mixed>|null $overrides Null for a build that is gone
     */
    #[DataProvider('stale')]
    public function testRenewStopsALeaseItsBuildNoLongerHolds(?array $overrides): void
    {
        $lease = $this->leased('lease-a', 'runner1', '1', DateTime::addSeconds(new \DateTime(), 10));
        $build = $overrides === null ? new Document() : new Document([...$this->current($lease)->getArrayCopy(), ...$overrides]);

        $this->assertNull($this->leases->renew($lease, $build));
        $this->assertSame(0, $this->dbForPlatform->writes);
    }

    public function testRenewRefusesAnExpiredLeaseWithoutStoppingTheBuild(): void
    {
        $lease = $this->leased('lease-a', 'runner1', '1', DateTime::addSeconds(new \DateTime(), -1));

        $this->assertRenewRefused($lease, $this->current($lease));
        $this->assertSame(0, $this->dbForPlatform->writes);
        $this->assertSame(Status::Leased->value, $this->row('lease-a')->getAttribute('status'));
    }

    public function testRenewAnswersAConcurrentExtension(): void
    {
        $lease = $this->leased('lease-a', 'runner1', '1', DateTime::addSeconds(new \DateTime(), 10));
        $build = $this->current($lease);

        $first = $this->leases->renew($lease, $build);
        $second = $this->leases->renew($lease, $build);

        $this->assertInstanceOf(Document::class, $first);
        $this->assertInstanceOf(Document::class, $second, 'A renewal that lost the compare-and-set to another renewal keeps the lease.');
        $this->assertSame($first->getAttribute('expiresAt'), $second->getAttribute('expiresAt'));
        $this->assertSame(1, $this->dbForPlatform->writes);
        $this->assertSame(Status::Leased->value, $this->row('lease-a')->getAttribute('status'));
        $this->assertSame($first->getAttribute('expiresAt'), $this->row('lease-a')->getAttribute('expiresAt'));
    }

    public function testRenewStopsALeaseReleasedConcurrently(): void
    {
        $lease = $this->leased('lease-a', 'runner1', '1', DateTime::addSeconds(new \DateTime(), 10));
        $this->leases->release($lease);

        $this->assertNull($this->leases->renew($lease, $this->current($lease)));
        $this->assertSame(1, $this->dbForPlatform->writes);
    }

    public function testRenewRefusesALeaseExpiredConcurrently(): void
    {
        $lease = $this->leased('lease-a', 'runner1', '1', DateTime::addSeconds(new \DateTime(), 10));
        $this->assertTrue($this->leases->expire($lease));

        $this->assertRenewRefused($lease, $this->current($lease));
        $this->assertSame(1, $this->dbForPlatform->writes);
    }

    public function testExpireEndsALiveLease(): void
    {
        $lease = $this->leased('lease-a', 'runner1', '1', DateTime::addSeconds(new \DateTime(), 60));

        $this->assertTrue($this->leases->expire($lease));

        $this->assertTrue(Leases::isExpired($this->row('lease-a'), \time()));
        $this->assertSame(['distributionLeases:' . $this->id('lease-a')], $this->dbForPlatform->purged);
    }

    public function testExpireReturnsFalseWhenNotLive(): void
    {
        $queued = $this->queued('lease-a', '2026-01-01 00:00:00.000');
        $released = $this->leased('lease-b', 'runner1', '1', DateTime::addSeconds(new \DateTime(), 60), ['status' => Status::Released->value]);
        $expired = $this->leased('lease-c', 'runner1', '1', DateTime::addSeconds(new \DateTime(), -1));

        $this->assertFalse($this->leases->expire($queued));
        $this->assertFalse($this->leases->expire($released));
        $this->assertFalse($this->leases->expire($expired));
        $this->assertSame(0, $this->dbForPlatform->writes);
    }

    public function testReleaseMarksTheLeaseReleased(): void
    {
        $lease = $this->leased('lease-a', 'runner1', '1', DateTime::addSeconds(new \DateTime(), 60));

        $this->leases->release($lease);

        $this->assertSame(Status::Released->value, $this->row('lease-a')->getAttribute('status'));
        $this->assertSame(['distributionLeases:' . $this->id('lease-a')], $this->dbForPlatform->purged);
    }

    public function testCurrentFindsTheBuildsLease(): void
    {
        $lease = $this->leases->queue($this->project, $this->build(), [], self::ENDPOINT);
        $build = $this->build()->setAttribute('jobId', $lease->getId());

        $this->assertSame($lease->getId(), $this->leases->current($this->project, $build)->getId());
        $this->assertTrue($this->leases->current(new Document(['$id' => 'project2', '$sequence' => '8']), $build)->isEmpty());
        $this->assertTrue($this->leases->current($this->project, $this->build())->isEmpty());
    }

    /**
     * @return \Iterator<string, array{string|null, int, string|null}>
     */
    public static function reasons(): \Iterator
    {
        yield 'timeout' => ['timeout', 137, Builds::ERROR_BUILD_TIMEOUT];
        yield 'build exit 2' => ['build', 2, Builds::ERROR_BUILD_INVALID];
        yield 'build exit 1' => ['build', 1, Builds::ERROR_BUILD_FAILED];
        yield 'build killed' => ['build', 137, Builds::ERROR_BUILD_FAILED];
        yield 'source' => ['source', -1, Builds::ERROR_BUILD_SOURCE_FAILED];
        yield 'output' => ['output', -1, Builds::ERROR_BUILD_OUTPUT_INVALID];
        yield 'verification' => ['verification', -1, Builds::ERROR_BUILD_OUTPUT_INVALID];
        yield 'lease' => ['lease', -1, Builds::ERROR_BUILD_INVALID];
        yield 'canceled' => ['canceled', -1, null];
        yield 'vm' => ['vm', -1, null];
        yield 'agent' => ['agent', -1, null];
        yield 'stopped' => ['stopped', -1, null];
        yield 'lost' => ['lost', -1, null];
        yield 'upload' => ['upload', -1, null];
        yield 'no reason' => [null, -1, null];
        yield 'unknown' => ['meteor', -1, null];
    }

    #[DataProvider('reasons')]
    public function testErrorMapsExitReasons(?string $reason, int $exitCode, ?string $expected): void
    {
        $this->assertSame($expected, Leases::error($reason, $exitCode));
    }

    public function testEventBecomesTheBuildJobEvent(): void
    {
        $lease = $this->lease();

        $event = Leases::event($lease, [
            'id' => $lease->getId() . '-1732780800000000000',
            'type' => 'log',
            'time' => '2026-11-28T10:00:00Z',
            'data' => [
                'lines' => ['Compiling'],
                'stream' => 'stdout',
                'jobId' => 'dist-forged',
                'meta' => ['projectId' => 'project9', 'stage' => 'sign'],
            ],
        ]);

        $this->assertSame([
            'event' => CallbackEvent::Log,
            'id' => $lease->getId() . '-1732780800000000000',
            'data' => [
                'lines' => ['Compiling'],
                'stream' => 'stdout',
                'jobId' => $lease->getId(),
                'meta' => ['projectId' => 'project1', 'buildId' => 'build1', 'stage' => Builds::STAGE_BUILD, 'attempt' => '2'],
            ],
        ], $event);
    }

    /**
     * @return \Iterator<string, array{string, array<string, mixed>, CallbackEvent}>
     */
    public static function events(): \Iterator
    {
        yield 'start' => ['start', [], CallbackEvent::Start];
        yield 'complete' => ['complete', [], CallbackEvent::Complete];
        yield 'artifact' => ['artifact', ['artifactId' => 'result', 'artifactType' => 'read', 'status' => 'success', 'content' => ['type' => 'ipa'], 'format' => 'json'], CallbackEvent::Artifact];
        yield 'failed artifact' => ['artifact', ['artifactId' => 'source', 'artifactType' => 'download', 'status' => 'failed', 'error' => ['code' => 'download_failed', 'message' => 'Not found.']], CallbackEvent::Artifact];
        yield 'exit' => ['exit', ['exitCode' => 0, 'image' => 'macos-15', 'durationSeconds' => 12.5], CallbackEvent::Exit];
    }

    /**
     * @param array<string, mixed> $data
     */
    #[DataProvider('events')]
    public function testEventAcceptsEveryJobEvent(string $type, array $data, CallbackEvent $expected): void
    {
        $lease = $this->lease();

        $event = Leases::event($lease, ['id' => $lease->getId() . '-1', 'type' => $type, 'data' => $data]);

        $this->assertNotNull($event);
        $this->assertSame($expected, $event['event']);
        $this->assertSame($lease->getId(), $event['data']['jobId']);
    }

    public function testEventDropsANullError(): void
    {
        $lease = $this->lease();

        $event = Leases::event($lease, ['id' => $lease->getId() . '-1', 'type' => 'exit', 'data' => ['exitCode' => 0, 'image' => 'macos-15', 'error' => null]]);

        $this->assertNotNull($event);
        $this->assertArrayNotHasKey('error', $event['data']);
    }

    public function testEventDropsStage(): void
    {
        $lease = $this->lease();

        $this->assertNull(Leases::event($lease, ['id' => $lease->getId() . '-1', 'type' => 'stage', 'data' => ['stage' => 'build']]));
    }

    /**
     * @return \Iterator<string, array{array<string, mixed>}>
     */
    public static function invalidEvents(): \Iterator
    {
        yield 'missing id' => [['type' => 'start']];
        yield 'another lease' => [['id' => 'lease2-1', 'type' => 'start']];
        yield 'no digits' => [['id' => '{lease}-', 'type' => 'start']];
        yield 'not digits' => [['id' => '{lease}-12a', 'type' => 'start']];
        yield 'negative' => [['id' => '{lease}--1', 'type' => 'start']];
        yield 'missing type' => [['id' => '{lease}-1']];
        yield 'unknown type' => [['id' => '{lease}-1', 'type' => 'deployment']];
        yield 'prefixed type' => [['id' => '{lease}-1', 'type' => 'orchestrator.job.start']];
        yield 'data not an object' => [['id' => '{lease}-1', 'type' => 'start', 'data' => 'start']];
        yield 'log lines not strings' => [['id' => '{lease}-1', 'type' => 'log', 'data' => ['lines' => [1], 'stream' => 'stdout']]];
        yield 'exit without an image' => [['id' => '{lease}-1', 'type' => 'exit', 'data' => ['exitCode' => 0]]];
        yield 'unknown error code' => [['id' => '{lease}-1', 'type' => 'exit', 'data' => ['exitCode' => 1, 'image' => 'macos-15', 'error' => ['code' => 'meteor', 'message' => 'Boom.']]]];
        yield 'artifact without a status' => [['id' => '{lease}-1', 'type' => 'artifact', 'data' => ['artifactId' => 'result', 'artifactType' => 'read']]];
    }

    /**
     * @param array<string, mixed> $event
     */
    #[DataProvider('invalidEvents')]
    public function testEventRejectsAnInvalidEvent(array $event): void
    {
        $lease = $this->lease();
        if (\is_string($event['id'] ?? null)) {
            $event['id'] = \str_replace('{lease}', $lease->getId(), $event['id']);
        }

        $this->expectException(\InvalidArgumentException::class);

        Leases::event($lease, $event);
    }

    public function testIsExpired(): void
    {
        $now = \time();
        $at = fn (int $offset): string => DateTime::format((new \DateTime())->setTimestamp($now + $offset));

        $this->assertTrue(Leases::isExpired(new Document(['status' => Status::Leased->value, 'expiresAt' => $at(0)]), $now));
        $this->assertTrue(Leases::isExpired(new Document(['status' => Status::Leased->value, 'expiresAt' => null]), $now));
        $this->assertFalse(Leases::isExpired(new Document(['status' => Status::Leased->value, 'expiresAt' => $at(1)]), $now));
        $this->assertFalse(Leases::isExpired(new Document(['status' => Status::Released->value, 'expiresAt' => $at(-60)]), $now));
        $this->assertFalse(Leases::isExpired(new Document(['status' => Status::Queued->value]), $now));
    }

    public function testIsStale(): void
    {
        $now = \time();
        $at = fn (int $offset): string => DateTime::format((new \DateTime())->setTimestamp($now + $offset));

        $this->assertTrue(Leases::isStale(new Document(['status' => Status::Queued->value, 'queuedAt' => $at(-Runners::SEEN)]), $now));
        $this->assertFalse(Leases::isStale(new Document(['status' => Status::Queued->value, 'queuedAt' => $at(-Runners::SEEN + 1)]), $now));
        $this->assertFalse(Leases::isStale(new Document(['status' => Status::Leased->value, 'queuedAt' => $at(-3600)]), $now));
        $this->assertFalse(Leases::isStale(new Document(['status' => Status::Released->value, 'queuedAt' => $at(-3600)]), $now));
    }

    public function testLabelsComeFromTheXcodeConfiguration(): void
    {
        $this->assertSame(['xcode-16.2'], Leases::labels(new Document(['configuration' => ['xcode' => '16.2', 'scheme' => 'App']])));
        $this->assertSame([], Leases::labels(new Document(['configuration' => ['scheme' => 'App']])));
        $this->assertSame([], Leases::labels(new Document(['configuration' => ['xcode' => '']])));
        $this->assertSame([], Leases::labels(new Document(['configuration' => ['xcode' => 16]])));
        $this->assertSame([], Leases::labels(new Document(['configuration' => null])));
        $this->assertSame([], Leases::labels(new Document()));
    }

    public function testIsSubset(): void
    {
        $this->assertTrue(Leases::isSubset([], []));
        $this->assertTrue(Leases::isSubset([], ['xcode-16.2']));
        $this->assertTrue(Leases::isSubset(['xcode-16.2'], ['arm64', 'xcode-16.2']));
        $this->assertFalse(Leases::isSubset(['xcode-16.2'], []));
        $this->assertFalse(Leases::isSubset(['xcode-16.2', 'arm64'], ['xcode-16.2']));
    }

    public function testMetaNamesTheBuildAttempt(): void
    {
        $this->assertSame(
            ['projectId' => 'project1', 'buildId' => 'build1', 'stage' => Builds::STAGE_BUILD, 'attempt' => '2'],
            Leases::meta($this->lease()),
        );
    }

    private function build(int $attempt = 1): Document
    {
        return new Document(['$id' => 'build1', 'platform' => 'ios', 'attempt' => $attempt]);
    }

    private function lease(): Document
    {
        return new Document([
            '$id' => $this->id('lease-a'),
            'projectId' => 'project1',
            'projectInternalId' => '7',
            'buildId' => 'build1',
            'attempt' => 2,
        ]);
    }

    private function id(string $name): string
    {
        return \substr(\hash('sha256', $name), 0, 36);
    }

    /**
     * @param list<string> $labels
     * @param array<string, mixed> $overrides
     */
    private function queued(string $name, string $queuedAt, array $labels = [], array $overrides = []): Document
    {
        return $this->dbForPlatform->seed('distributionLeases', [
            '$id' => $this->id($name),
            'projectId' => 'project1',
            'projectInternalId' => '7',
            'buildId' => $name,
            'platform' => 'ios',
            'runnerPlatform' => 'macos',
            'labels' => $labels,
            'status' => Status::Queued->value,
            'attempt' => 1,
            'queuedAt' => $queuedAt,
            ...$overrides,
        ]);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function leased(string $name, string $runnerId, string $runnerInternalId, string $expiresAt, array $overrides = []): Document
    {
        return $this->queued($name, '2025-12-31 00:00:00.000', [], [
            'status' => Status::Leased->value,
            'runnerId' => $runnerId,
            'runnerInternalId' => $runnerInternalId,
            'expiresAt' => $expiresAt,
            'token' => \hash('sha256', 'token'),
            ...$overrides,
        ]);
    }

    /**
     * @return array{Token, string}
     */
    private function claimed(): array
    {
        $this->queued('lease-a', '2026-01-01 00:00:00.000');
        $claimed = $this->leases->claim($this->runner, [], Leases::TRIES);
        $this->assertNotNull($claimed);
        $verified = Token::verify($claimed['token'], Leases::secret());
        $this->assertInstanceOf(Token::class, $verified);

        return [$verified, $claimed['token']];
    }

    /**
     * The build whose current attempt the lease is.
     */
    private function current(Document $lease): Document
    {
        return new Document([
            '$id' => (string) $lease->getAttribute('buildId', ''),
            'jobId' => $lease->getId(),
            'attempt' => (int) $lease->getAttribute('attempt', 1),
            'status' => 'processing',
        ]);
    }

    private function assertRenewRefused(Document $lease, Document $build): void
    {
        try {
            $this->leases->renew($lease, $build);
            $this->fail('An expired lease must be refused.');
        } catch (Exception $error) {
            $this->assertSame(Exception::DISTRIBUTION_LEASE_NOT_FOUND, $error->getType());
        }
    }

    private function assertHeldRefused(Token $verified, string $token, string $type): void
    {
        try {
            $this->leases->held($verified, $token);
            $this->fail('The lease must be refused.');
        } catch (Exception $error) {
            $this->assertSame($type, $error->getType());
        }
    }

    private function row(string $name): Document
    {
        return $this->dbForPlatform->getDocument('distributionLeases', $this->id($name));
    }

    /**
     * @param list<string> $keys
     * @return array<string, mixed>
     */
    private function fields(Document $document, array $keys): array
    {
        return \array_intersect_key($document->getArrayCopy(), \array_flip($keys));
    }
}
