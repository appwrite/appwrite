<?php

declare(strict_types=1);

namespace Utopia\Distribution\Tests\Store;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Utopia\Distribution\Credential;
use Utopia\Distribution\Exception\Conflict;
use Utopia\Distribution\Exception\Invalid;
use Utopia\Distribution\Issue;
use Utopia\Distribution\Release;
use Utopia\Distribution\State;
use Utopia\Distribution\Tests\Store\Apple\Frozen;
use Utopia\Distribution\Tests\Transport;
use Utopia\Psr7\Response;
use Utopia\Psr7\Stream;

final class AppleReleaseTest extends TestCase
{
    private const string KEY_ID = 'ABC123DEFG';

    private const string ISSUER_ID = '57246542-96fe-1a63-e053-0824d011072a';

    private const string ENDPOINT = 'https://store.test/apple';

    private const int NOW = 1_795_000_000;

    private const string IDENTIFIER = 'io.example.app';

    private const string APP_ID = '6450000001';

    private const string BUILD_ID = 'a1b2c3d4-0000-4000-8000-000000000001';

    private const string VERSION_ID = 'b7c1d2e3-1111-4111-8111-000000000001';

    private const string EDITABLE_ID = 'b7c1d2e3-1111-4111-8111-000000000002';

    private const string PHASED_RELEASE_ID = 'c8d2e3f4-2222-4222-8222-000000000001';

    private const string SUBMISSION_ID = 'd9e3f4a5-3333-4333-8333-000000000001';

    private const string READY_SUBMISSION_ID = 'd9e3f4a5-3333-4333-8333-000000000002';

    private const string EN_LOCALIZATION_ID = 'f1a5b6c7-5555-4555-8555-000000000001';

    private const array EXTERNAL = [
        'appId' => self::APP_ID,
        'bundleId' => self::IDENTIFIER,
        'version' => '1.4.0',
        'build' => '42',
        'buildId' => self::BUILD_ID,
    ];

    private static string $privateKey = '';

    #[\Override]
    public static function setUpBeforeClass(): void
    {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        self::assertInstanceOf(\OpenSSLAsymmetricKey::class, $key);
        self::assertTrue(openssl_pkey_export($key, $private));
        self::assertIsString($private);

        self::$privateKey = $private;
    }

    public function testReleaseCreatesTheFirstVersionWithoutNotesOrPhasedRelease(): void
    {
        $transport = new Transport(
            $this->reply('app-store-versions-empty'),
            $this->reply('app-store-versions-empty'),
            $this->reply('app-store-version-created', 201),
            $this->page(204, ''),
            $this->reply('app-store-versions-empty'),
            $this->reply('review-submissions-empty'),
            $this->reply('review-submission', 201),
            $this->reply('review-submission-item', 201),
            $this->reply('review-submission-submitted'),
        );

        $external = $this->store($transport)->release($this->release(fraction: 0.5, notes: ['en-US' => 'Faster sync.']), self::EXTERNAL);

        $this->assertSame([...self::EXTERNAL, 'track' => 'production', 'versionId' => self::VERSION_ID, 'reviewSubmissionId' => self::SUBMISSION_ID], $external);
        $this->assertSame([
            'GET /v1/apps/' . self::APP_ID . '/appStoreVersions',
            'GET /v1/apps/' . self::APP_ID . '/appStoreVersions',
            'POST /v1/appStoreVersions',
            'PATCH /v1/appStoreVersions/' . self::VERSION_ID . '/relationships/build',
            'GET /v1/apps/' . self::APP_ID . '/appStoreVersions',
            'GET /v1/reviewSubmissions',
            'POST /v1/reviewSubmissions',
            'POST /v1/reviewSubmissionItems',
            'PATCH /v1/reviewSubmissions/' . self::SUBMISSION_ID,
        ], $this->calls($transport));

        $requests = $transport->requests();
        $this->assertSame([
            'filter' => ['platform' => 'IOS', 'versionString' => '1.4.0'],
            'include' => 'build,appStoreVersionPhasedRelease',
            'limit' => '5',
        ], $this->query($requests[0]));
        $this->assertSame([
            'filter' => ['platform' => 'IOS', 'appVersionState' => 'PREPARE_FOR_SUBMISSION,DEVELOPER_REJECTED,REJECTED,METADATA_REJECTED,INVALID_BINARY'],
            'include' => 'build,appStoreVersionPhasedRelease',
            'limit' => '5',
        ], $this->query($requests[1]));
        $this->assertSame([
            'data' => [
                'type' => 'appStoreVersions',
                'attributes' => ['platform' => 'IOS', 'versionString' => '1.4.0'],
                'relationships' => ['app' => ['data' => ['type' => 'apps', 'id' => self::APP_ID]]],
            ],
        ], $this->body($requests[2]));
        $this->assertSame(['data' => ['type' => 'builds', 'id' => self::BUILD_ID]], $this->body($requests[3]));
        $this->assertSame([
            'filter' => ['platform' => 'IOS', 'appVersionState' => 'READY_FOR_DISTRIBUTION,REPLACED_WITH_NEW_VERSION'],
            'limit' => '1',
        ], $this->query($requests[4]));
        $this->assertSame([
            'filter' => [
                'app' => self::APP_ID,
                'platform' => 'IOS',
                'state' => 'READY_FOR_REVIEW,WAITING_FOR_REVIEW,IN_REVIEW,UNRESOLVED_ISSUES,CANCELING,COMPLETING',
            ],
            'include' => 'items,appStoreVersionForReview',
            'limit' => '5',
        ], $this->query($requests[5]));
        $this->assertSame([
            'data' => [
                'type' => 'reviewSubmissions',
                'attributes' => ['platform' => 'IOS'],
                'relationships' => ['app' => ['data' => ['type' => 'apps', 'id' => self::APP_ID]]],
            ],
        ], $this->body($requests[6]));
        $this->assertSame([
            'data' => [
                'type' => 'reviewSubmissionItems',
                'relationships' => [
                    'reviewSubmission' => ['data' => ['type' => 'reviewSubmissions', 'id' => self::SUBMISSION_ID]],
                    'appStoreVersion' => ['data' => ['type' => 'appStoreVersions', 'id' => self::VERSION_ID]],
                ],
            ],
        ], $this->body($requests[7]));
        $this->assertSame([
            'data' => ['type' => 'reviewSubmissions', 'id' => self::SUBMISSION_ID, 'attributes' => ['submitted' => true]],
        ], $this->body($requests[8]));
    }

    public function testReleaseReusesTheVersionWritesNotesAndEnablesPhasedRelease(): void
    {
        $transport = new Transport(
            $this->reply('app-store-versions'),
            $this->reply('app-store-version'),
            $this->reply('app-store-versions-released'),
            $this->reply('app-store-version-localizations'),
            $this->reply('app-store-version-localization'),
            $this->reply('app-store-version-localization', 201),
            $this->reply('phased-release', 201),
            $this->reply('review-submissions-ready'),
            $this->reply('review-submission-item', 201),
            $this->reply('review-submission-submitted'),
        );

        $release = $this->release(fraction: 0.2, automatic: false, notes: [
            'en-US' => 'Faster sync.',
            'fr-FR' => 'Corrections de bugs et améliorations.',
            'de-DE' => 'Fehlerbehebungen.',
        ]);

        $external = $this->store($transport)->release($release, self::EXTERNAL);

        $this->assertSame([
            ...self::EXTERNAL,
            'track' => 'production',
            'versionId' => self::VERSION_ID,
            'reviewSubmissionId' => self::READY_SUBMISSION_ID,
            'phasedReleaseId' => self::PHASED_RELEASE_ID,
        ], $external);
        $this->assertSame([
            'GET /v1/apps/' . self::APP_ID . '/appStoreVersions',
            'PATCH /v1/appStoreVersions/' . self::VERSION_ID,
            'GET /v1/apps/' . self::APP_ID . '/appStoreVersions',
            'GET /v1/appStoreVersions/' . self::VERSION_ID . '/appStoreVersionLocalizations',
            'PATCH /v1/appStoreVersionLocalizations/' . self::EN_LOCALIZATION_ID,
            'POST /v1/appStoreVersionLocalizations',
            'POST /v1/appStoreVersionPhasedReleases',
            'GET /v1/reviewSubmissions',
            'POST /v1/reviewSubmissionItems',
            'PATCH /v1/reviewSubmissions/' . self::READY_SUBMISSION_ID,
        ], $this->calls($transport));

        $requests = $transport->requests();
        $this->assertSame([
            'data' => ['type' => 'appStoreVersions', 'id' => self::VERSION_ID, 'attributes' => ['releaseType' => 'MANUAL']],
        ], $this->body($requests[1]));
        $this->assertSame(['limit' => '200'], $this->query($requests[3]));
        $this->assertSame([
            'data' => ['type' => 'appStoreVersionLocalizations', 'id' => self::EN_LOCALIZATION_ID, 'attributes' => ['whatsNew' => 'Faster sync.']],
        ], $this->body($requests[4]));
        $this->assertSame([
            'data' => [
                'type' => 'appStoreVersionLocalizations',
                'attributes' => ['locale' => 'de-DE', 'whatsNew' => 'Fehlerbehebungen.'],
                'relationships' => ['appStoreVersion' => ['data' => ['type' => 'appStoreVersions', 'id' => self::VERSION_ID]]],
            ],
        ], $this->body($requests[5]));
        $this->assertSame([
            'data' => [
                'type' => 'appStoreVersionPhasedReleases',
                'attributes' => ['phasedReleaseState' => 'INACTIVE'],
                'relationships' => ['appStoreVersion' => ['data' => ['type' => 'appStoreVersions', 'id' => self::VERSION_ID]]],
            ],
        ], $this->body($requests[6]));
        $item = $this->body($requests[8])['data'] ?? null;
        $this->assertIsArray($item);
        $this->assertSame([
            'reviewSubmission' => ['data' => ['type' => 'reviewSubmissions', 'id' => self::READY_SUBMISSION_ID]],
            'appStoreVersion' => ['data' => ['type' => 'appStoreVersions', 'id' => self::VERSION_ID]],
        ], $item['relationships'] ?? null);
    }

    public function testReleaseReversionsAnEditableVersionAndDropsItsPhasedRelease(): void
    {
        $transport = new Transport(
            $this->reply('app-store-versions-empty'),
            $this->reply('app-store-versions-editable'),
            $this->reply('app-store-version-updated'),
            $this->page(204, ''),
            $this->page(204, ''),
            $this->reply('review-submissions-empty'),
            $this->reply('review-submission', 201),
            $this->reply('review-submission-item', 201),
            $this->reply('review-submission-submitted'),
        );

        $external = $this->store($transport)->release($this->release(), [...self::EXTERNAL, 'phasedReleaseId' => 'stale']);

        $this->assertSame([...self::EXTERNAL, 'track' => 'production', 'versionId' => self::EDITABLE_ID, 'reviewSubmissionId' => self::SUBMISSION_ID], $external);
        $this->assertSame([
            'GET /v1/apps/' . self::APP_ID . '/appStoreVersions',
            'GET /v1/apps/' . self::APP_ID . '/appStoreVersions',
            'PATCH /v1/appStoreVersions/' . self::EDITABLE_ID,
            'PATCH /v1/appStoreVersions/' . self::EDITABLE_ID . '/relationships/build',
            'DELETE /v1/appStoreVersionPhasedReleases/' . self::PHASED_RELEASE_ID,
            'GET /v1/reviewSubmissions',
            'POST /v1/reviewSubmissions',
            'POST /v1/reviewSubmissionItems',
            'PATCH /v1/reviewSubmissions/' . self::SUBMISSION_ID,
        ], $this->calls($transport));
        $this->assertSame([
            'data' => ['type' => 'appStoreVersions', 'id' => self::EDITABLE_ID, 'attributes' => ['versionString' => '1.4.0']],
        ], $this->body($transport->requests()[2]));
    }

    public function testReleaseWithOurSubmissionInProgressIsIdempotent(): void
    {
        $transport = new Transport($this->reply('app-store-versions-submitted'), $this->reply('review-submissions-ours'));

        $external = $this->store($transport)->release($this->release(fraction: 0.5, notes: ['en-US' => 'Faster sync.']), self::EXTERNAL);

        $this->assertSame(self::SUBMISSION_ID, $external['reviewSubmissionId']);
        $this->assertSame(self::VERSION_ID, $external['versionId']);
        $this->assertSame(['GET /v1/apps/' . self::APP_ID . '/appStoreVersions', 'GET /v1/reviewSubmissions'], $this->calls($transport));
    }

    public function testReleaseSubmitsOurReadySubmissionWithoutAddingTheItemAgain(): void
    {
        $transport = new Transport($this->reply('app-store-versions'), $this->reply('review-submissions-ready-ours'), $this->reply('review-submission-submitted'));

        $external = $this->store($transport)->release($this->release(), self::EXTERNAL);

        $this->assertSame(self::READY_SUBMISSION_ID, $external['reviewSubmissionId']);
        $this->assertSame([
            'GET /v1/apps/' . self::APP_ID . '/appStoreVersions',
            'GET /v1/reviewSubmissions',
            'PATCH /v1/reviewSubmissions/' . self::READY_SUBMISSION_ID,
        ], $this->calls($transport));
    }

    public function testReleaseIgnoresADuplicateSubmissionItem(): void
    {
        $transport = new Transport(
            $this->reply('app-store-versions'),
            $this->reply('review-submissions-empty'),
            $this->reply('review-submission', 201),
            $this->reply('review-submission-error-duplicate', 409),
            $this->reply('review-submission-submitted'),
        );

        $external = $this->store($transport)->release($this->release(), self::EXTERNAL);

        $this->assertSame(self::SUBMISSION_ID, $external['reviewSubmissionId']);
        $this->assertCount(5, $transport->requests());
    }

    /**
     * @return iterable<string, array{string, string, int}>
     */
    public static function conflicts(): iterable
    {
        yield 'foreign submission in progress' => ['app-store-versions', 'review-submissions-progress', 2];
        yield 'ready submission with foreign items' => ['app-store-versions', 'review-submissions-ready-foreign', 2];
        yield 'our submission canceling' => ['app-store-versions', 'review-submissions-canceling', 2];
        yield 'our submission open while the version is editable again' => ['app-store-versions', 'review-submissions-ours', 2];
        yield 'version past review without a submission' => ['app-store-versions-submitted', 'review-submissions-empty', 2];
    }

    #[DataProvider('conflicts')]
    public function testReleaseRefusesASubmissionItCannotOwn(string $versions, string $submissions, int $requests): void
    {
        $transport = new Transport($this->reply($versions), $this->reply($submissions));

        try {
            $this->store($transport)->release($this->release(), self::EXTERNAL);
            $this->fail('release should throw');
        } catch (Conflict) {
            $this->assertCount($requests, $transport->requests());
        }
    }

    public function testReleaseSurfacesIncompleteSubmissionAsConflict(): void
    {
        $transport = new Transport(
            $this->reply('app-store-versions'),
            $this->reply('review-submissions-empty'),
            $this->reply('review-submission', 201),
            $this->reply('review-submission-error-state', 409),
        );

        try {
            $this->store($transport)->release($this->release(), self::EXTERNAL);
            $this->fail('release should throw');
        } catch (Conflict $conflict) {
            $this->assertSame(['STATE_ERROR', 'STATE_ERROR.SCREENSHOT_REQUIRED.APP_IPHONE_67'], array_map(static fn (Issue $issue): string => $issue->code, $conflict->getIssues()));
        }

        $this->assertCount(4, $transport->requests());
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function unreleasable(): iterable
    {
        $external = self::EXTERNAL;

        unset($external['appId']);
        yield 'no app id' => [$external];

        $external = self::EXTERNAL;
        unset($external['buildId']);
        yield 'no build id' => [$external];
    }

    /**
     * @param array<string, mixed> $external
     */
    #[DataProvider('unreleasable')]
    public function testReleaseWithoutProcessedBuildThrowsInvalid(array $external): void
    {
        $transport = new Transport();

        try {
            $this->store($transport)->release($this->release(), $external);
            $this->fail('release should throw');
        } catch (Invalid) {
            $this->assertSame([], $transport->requests());
        }
    }

    /**
     * @return iterable<string, array{string, string|null, int|null, string, float, string|null}>
     */
    public static function states(): iterable
    {
        yield 'prepare for submission' => ['PREPARE_FOR_SUBMISSION', null, null, State::STATUS_PENDING, 1.0, null];
        yield 'ready for review' => ['READY_FOR_REVIEW', null, null, State::STATUS_PENDING, 1.0, null];
        yield 'waiting for review' => ['WAITING_FOR_REVIEW', null, null, State::STATUS_PENDING, 1.0, null];
        yield 'waiting for export compliance' => ['WAITING_FOR_EXPORT_COMPLIANCE', null, null, State::STATUS_PENDING, 1.0, null];
        yield 'in review' => ['IN_REVIEW', null, null, State::STATUS_REVIEWING, 1.0, null];
        yield 'accepted' => ['ACCEPTED', null, null, State::STATUS_REVIEWING, 1.0, null];
        yield 'pending developer release' => ['PENDING_DEVELOPER_RELEASE', 'INACTIVE', null, State::STATUS_APPROVED, 1.0, null];
        yield 'pending apple release' => ['PENDING_APPLE_RELEASE', null, null, State::STATUS_APPROVED, 1.0, null];
        yield 'processing for distribution' => ['PROCESSING_FOR_DISTRIBUTION', null, null, State::STATUS_APPROVED, 1.0, null];
        yield 'ready for distribution' => ['READY_FOR_DISTRIBUTION', null, null, State::STATUS_PUBLISHED, 1.0, null];
        yield 'rejected' => ['REJECTED', null, null, State::STATUS_REJECTED, 1.0, null];
        yield 'metadata rejected' => ['METADATA_REJECTED', null, null, State::STATUS_REJECTED, 1.0, null];
        yield 'invalid binary' => ['INVALID_BINARY', null, null, State::STATUS_REJECTED, 1.0, null];
        yield 'developer rejected' => ['DEVELOPER_REJECTED', null, null, State::STATUS_REJECTED, 1.0, 'DEVELOPER_REJECTED'];
        yield 'replaced with new version' => ['REPLACED_WITH_NEW_VERSION', null, null, State::STATUS_REJECTED, 1.0, 'REPLACED_WITH_NEW_VERSION'];
        yield 'unknown' => ['NOT_YET_DOCUMENTED', null, null, State::STATUS_PENDING, 1.0, null];

        foreach ([1 => 0.01, 2 => 0.02, 3 => 0.05, 4 => 0.10, 5 => 0.20, 6 => 0.50, 7 => 1.0] as $day => $fraction) {
            yield "phased day {$day}" => ['READY_FOR_DISTRIBUTION', 'ACTIVE', $day, State::STATUS_PUBLISHED, $fraction, null];
        }

        yield 'phased paused' => ['READY_FOR_DISTRIBUTION', 'PAUSED', 4, State::STATUS_HALTED, 0.10, null];
        yield 'phased complete' => ['READY_FOR_DISTRIBUTION', 'COMPLETE', 7, State::STATUS_PUBLISHED, 1.0, null];
        yield 'phased beyond schedule' => ['READY_FOR_DISTRIBUTION', 'ACTIVE', 9, State::STATUS_PUBLISHED, 1.0, null];
        yield 'phased without day' => ['READY_FOR_DISTRIBUTION', 'ACTIVE', null, State::STATUS_PUBLISHED, 0.01, null];
    }

    #[DataProvider('states')]
    public function testStateMapsTheVersionState(string $value, ?string $phase, ?int $day, string $status, float $fraction, ?string $issue): void
    {
        $transport = new Transport($this->version($value, $phase, $day));

        $state = $this->store($transport)->state(['track' => 'production', 'versionId' => self::VERSION_ID], Release::TRACK_PRODUCTION);

        $this->assertSame($status, $state->status);
        $this->assertSame($value, $state->raw);
        $this->assertSame($fraction, $state->fraction);
        $this->assertSame($issue, ($state->issues[0] ?? null)?->code);

        $request = $this->only($transport);
        $this->assertSame('GET', $request->getMethod());
        $this->assertSame(self::ENDPOINT . '/v1/appStoreVersions/' . self::VERSION_ID, $this->path($request));
        $this->assertSame(['include' => 'appStoreVersionPhasedRelease'], $this->query($request));
    }

    public function testStateWithoutVersionThrowsInvalid(): void
    {
        $this->expectException(Invalid::class);

        $this->store(new Transport())->state(['track' => 'production'], Release::TRACK_PRODUCTION);
    }

    public function testRolloutBelowFullThrowsInvalid(): void
    {
        $transport = new Transport();

        try {
            $this->store($transport)->rollout(['versionId' => self::VERSION_ID], Release::TRACK_PRODUCTION, 0.5);
            $this->fail('rollout should throw');
        } catch (Invalid) {
            $this->assertSame([], $transport->requests());
        }
    }

    public function testRolloutReleasesAManualVersion(): void
    {
        $transport = new Transport($this->version('PENDING_DEVELOPER_RELEASE', null, null), $this->reply('app-store-version-release-request', 201));

        $this->store($transport)->rollout(['versionId' => self::VERSION_ID], Release::TRACK_PRODUCTION, 1.0);

        $this->assertSame(['GET /v1/appStoreVersions/' . self::VERSION_ID, 'POST /v1/appStoreVersionReleaseRequests'], $this->calls($transport));
        $this->assertSame([
            'data' => [
                'type' => 'appStoreVersionReleaseRequests',
                'relationships' => ['appStoreVersion' => ['data' => ['type' => 'appStoreVersions', 'id' => self::VERSION_ID]]],
            ],
        ], $this->body($transport->requests()[1]));
    }

    public function testRolloutOfAManualPhasedVersionDropsThePhasedReleaseFirst(): void
    {
        $transport = new Transport(
            $this->version('PENDING_DEVELOPER_RELEASE', 'INACTIVE', null),
            $this->page(204, ''),
            $this->reply('app-store-version-release-request', 201),
        );

        $this->store($transport)->rollout(['versionId' => self::VERSION_ID], Release::TRACK_PRODUCTION, 1.0);

        $this->assertSame([
            'GET /v1/appStoreVersions/' . self::VERSION_ID,
            'DELETE /v1/appStoreVersionPhasedReleases/' . self::PHASED_RELEASE_ID,
            'POST /v1/appStoreVersionReleaseRequests',
        ], $this->calls($transport));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function rolling(): iterable
    {
        yield 'active' => ['ACTIVE'];
        yield 'paused' => ['PAUSED'];
    }

    #[DataProvider('rolling')]
    public function testRolloutCompletesAPhasedRelease(string $phase): void
    {
        $transport = new Transport($this->version('READY_FOR_DISTRIBUTION', $phase, 3), $this->reply('phased-release-complete'));

        $this->store($transport)->rollout(['versionId' => self::VERSION_ID], Release::TRACK_PRODUCTION, 1.0);

        $this->assertSame(['GET /v1/appStoreVersions/' . self::VERSION_ID, 'PATCH /v1/appStoreVersionPhasedReleases/' . self::PHASED_RELEASE_ID], $this->calls($transport));
        $this->assertSame([
            'data' => ['type' => 'appStoreVersionPhasedReleases', 'id' => self::PHASED_RELEASE_ID, 'attributes' => ['phasedReleaseState' => 'COMPLETE']],
        ], $this->body($transport->requests()[1]));
    }

    public function testRolloutOfAFullyReleasedVersionDoesNothing(): void
    {
        $transport = new Transport($this->version('READY_FOR_DISTRIBUTION', 'COMPLETE', 7));

        $this->store($transport)->rollout(['versionId' => self::VERSION_ID], Release::TRACK_PRODUCTION, 1.0);

        $this->assertCount(1, $transport->requests());
    }

    public function testRolloutDuringReviewThrowsConflict(): void
    {
        $transport = new Transport($this->version('IN_REVIEW', null, null));

        $this->expectException(Conflict::class);

        $this->store($transport)->rollout(['versionId' => self::VERSION_ID], Release::TRACK_PRODUCTION, 1.0);
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function paces(): iterable
    {
        yield 'halt' => ['halt', 'phased-release-active', 'PAUSED'];
        yield 'resume' => ['resume', 'phased-release-paused', 'ACTIVE'];
    }

    #[DataProvider('paces')]
    public function testHaltAndResumeChangeThePhasedRelease(string $operation, string $current, string $phase): void
    {
        $transport = new Transport($this->reply($current), $this->reply($current));

        $this->pace($operation, $this->store($transport));

        $this->assertSame([
            'GET /v1/appStoreVersions/' . self::VERSION_ID . '/appStoreVersionPhasedRelease',
            'PATCH /v1/appStoreVersionPhasedReleases/' . self::PHASED_RELEASE_ID,
        ], $this->calls($transport));
        $this->assertSame([
            'data' => ['type' => 'appStoreVersionPhasedReleases', 'id' => self::PHASED_RELEASE_ID, 'attributes' => ['phasedReleaseState' => $phase]],
        ], $this->body($transport->requests()[1]));
    }

    #[DataProvider('paces')]
    public function testHaltAndResumeInTheTargetPhaseDoNothing(string $operation, string $current, string $phase): void
    {
        $transport = new Transport($this->reply($phase === 'PAUSED' ? 'phased-release-paused' : 'phased-release-active'));

        $this->pace($operation, $this->store($transport));

        $this->assertCount(1, $transport->requests());
    }

    /**
     * @return iterable<string, array{string, ResponseInterface}>
     */
    public static function unphased(): iterable
    {
        foreach (['halt', 'resume'] as $operation) {
            yield "{$operation} without phased release" => [$operation, Transport::fixture('Apple/phased-release-none')];
            yield "{$operation} with missing phased release" => [$operation, Transport::fixture('Apple/error-not-found', 404)];
        }
    }

    #[DataProvider('unphased')]
    public function testHaltAndResumeWithoutPhasedReleaseThrowInvalid(string $operation, ResponseInterface $reply): void
    {
        $this->expectException(Invalid::class);
        $this->expectExceptionMessage('The App Store version has no phased release');

        $this->pace($operation, $this->store(new Transport($reply)));
    }

    public function testHaltOfACompletedPhasedReleaseThrowsConflict(): void
    {
        $transport = new Transport($this->reply('phased-release-complete'));

        try {
            $this->store($transport)->halt(['versionId' => self::VERSION_ID], Release::TRACK_PRODUCTION);
            $this->fail('halt should throw');
        } catch (Conflict) {
            $this->assertCount(1, $transport->requests());
        }
    }

    public function testHaltRefusedByTheStoreThrowsConflict(): void
    {
        $transport = new Transport($this->reply('phased-release-active'), $this->reply('phased-release-error-state', 409));

        $this->expectException(Conflict::class);

        $this->store($transport)->halt(['versionId' => self::VERSION_ID], Release::TRACK_PRODUCTION);
    }

    public function testWithdrawCancelsTheReviewSubmission(): void
    {
        $transport = new Transport($this->reply('review-submission-canceled'));

        $this->store($transport)->withdraw(['track' => 'production', 'reviewSubmissionId' => self::SUBMISSION_ID]);

        $request = $this->only($transport);
        $this->assertSame('PATCH', $request->getMethod());
        $this->assertSame(self::ENDPOINT . '/v1/reviewSubmissions/' . self::SUBMISSION_ID, (string) $request->getUri());
        $this->assertSame([
            'data' => ['type' => 'reviewSubmissions', 'id' => self::SUBMISSION_ID, 'attributes' => ['canceled' => true]],
        ], $this->body($request));
    }

    public function testWithdrawWithoutSubmissionThrowsInvalid(): void
    {
        $transport = new Transport();

        try {
            $this->store($transport)->withdraw(['track' => 'production', 'versionId' => self::VERSION_ID]);
            $this->fail('withdraw should throw');
        } catch (Invalid) {
            $this->assertSame([], $transport->requests());
        }
    }

    private function pace(string $operation, Frozen $store): void
    {
        $external = ['track' => 'production', 'versionId' => self::VERSION_ID];

        if ($operation === 'halt') {
            $store->halt($external, Release::TRACK_PRODUCTION);
        } else {
            $store->resume($external, Release::TRACK_PRODUCTION);
        }
    }

    /**
     * @param array<string, string> $notes
     */
    private function release(float $fraction = 1.0, bool $automatic = true, array $notes = []): Release
    {
        return new Release(self::IDENTIFIER, Release::TRACK_PRODUCTION, '1.4.0', '42', $fraction, $notes, $automatic);
    }

    private function version(string $state, ?string $phase, ?int $day): ResponseInterface
    {
        $body = [
            'data' => [
                'type' => 'appStoreVersions',
                'id' => self::VERSION_ID,
                'attributes' => ['platform' => 'IOS', 'versionString' => '1.4.0', 'appVersionState' => $state],
                'relationships' => [
                    'appStoreVersionPhasedRelease' => [
                        'data' => $phase === null ? null : ['type' => 'appStoreVersionPhasedReleases', 'id' => self::PHASED_RELEASE_ID],
                    ],
                ],
            ],
        ];

        if ($phase !== null) {
            $body['included'] = [[
                'type' => 'appStoreVersionPhasedReleases',
                'id' => self::PHASED_RELEASE_ID,
                'attributes' => ['phasedReleaseState' => $phase, 'totalPauseDuration' => 0, 'currentDayNumber' => $day],
            ]];
        }

        return $this->page(200, json_encode($body, JSON_THROW_ON_ERROR));
    }

    private function store(Transport $transport): Frozen
    {
        return new Frozen(new Credential\Apple(self::KEY_ID, self::ISSUER_ID, self::$privateKey), $transport, self::NOW, self::ENDPOINT);
    }

    private function reply(string $fixture, int $status = 200): ResponseInterface
    {
        return Transport::fixture("Apple/{$fixture}", $status, ['X-Rate-Limit' => 'user-hour-lim:3500;user-hour-rem:3499;']);
    }

    private function page(int $status, string $body): ResponseInterface
    {
        return new Response($status, body: new Stream\Factory()->createStream($body));
    }

    /**
     * @return list<string>
     */
    private function calls(Transport $transport): array
    {
        return array_map(
            fn (RequestInterface $request): string => $request->getMethod() . ' ' . substr($this->path($request), \strlen(self::ENDPOINT)),
            $transport->requests(),
        );
    }

    private function only(Transport $transport): RequestInterface
    {
        $requests = $transport->requests();
        $this->assertCount(1, $requests);

        return $requests[0];
    }

    private function path(RequestInterface $request): string
    {
        return (string) $request->getUri()->withQuery('');
    }

    /**
     * @return array<mixed>
     */
    private function query(RequestInterface $request): array
    {
        parse_str($request->getUri()->getQuery(), $query);

        return $query;
    }

    /**
     * @return array<mixed>
     */
    private function body(RequestInterface $request): array
    {
        $body = json_decode((string) $request->getBody(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertIsArray($body);

        return $body;
    }
}
