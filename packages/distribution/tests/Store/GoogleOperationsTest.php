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
use Utopia\Distribution\Exception\NotFound;
use Utopia\Distribution\Exception\Unauthorized;
use Utopia\Distribution\Exception\Unavailable;
use Utopia\Distribution\Release;
use Utopia\Distribution\State;
use Utopia\Distribution\Tests\Checkpoints;
use Utopia\Distribution\Tests\Store\Google\Frozen;
use Utopia\Distribution\Tests\Transport;
use Utopia\Distribution\Upload;
use Utopia\Psr7\Response;
use Utopia\Psr7\Stream;
use Utopia\Storage\Device\Local;

final class GoogleOperationsTest extends TestCase
{
    private const string EMAIL = 'publisher@example-project.iam.gserviceaccount.com';

    private const string PACKAGE = 'io.example.app';

    private const string ENDPOINT = 'https://play.test/v1/mock/distribution/google';

    private const string TOKEN = self::ENDPOINT . '/token';

    private const string APPLICATION = self::ENDPOINT . '/androidpublisher/v3/applications/io.example.app';

    private const string EDITS = self::APPLICATION . '/edits';

    private const string EDIT = '06155296137469138127';

    private const string STALE_EDIT = '07700000000000000001';

    private const string RESUMABLE = self::ENDPOINT . '/resumable/upload/androidpublisher/v3/applications/io.example.app/edits/' . self::EDIT . '/bundles?uploadType=resumable';

    private const string SESSION = self::RESUMABLE . '&upload_id=session-new';

    private const string STALE_SESSION = self::ENDPOINT . '/resumable/upload/androidpublisher/v3/applications/io.example.app/edits/' . self::STALE_EDIT . '/bundles?uploadType=resumable&upload_id=session-stale';

    private const string COMMIT = self::EDITS . '/' . self::EDIT . ':commit?changesInReviewBehavior=ERROR_IF_IN_REVIEW';

    private const string SHA256 = '9f86d081884c7d659a2feaa0c55ad015a3bf4f1b2b0b822cd15d6c15b0f00a08';

    private const int SIZE = 614400;

    private const int CHUNK = 262144;

    private const int NOW = 1_800_000_000;

    private const int EXPIRY = 1_795_000_000;

    private const array EXTERNAL = [
        'packageName' => self::PACKAGE,
        'editId' => self::EDIT,
        'expiryTimeSeconds' => self::EXPIRY,
        'versionCode' => 88,
        'sha256' => self::SHA256,
    ];

    private const array RELEASED = [...self::EXTERNAL, 'editId' => self::STALE_EDIT, 'track' => 'production', 'committed' => true];

    private static ?string $key = null;

    private string $directory = '';

    private string $path = '';

    private string $contents = '';

    #[\Override]
    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/distribution-google-' . bin2hex(random_bytes(6));
        $this->path = $this->directory . '/app.aab';
        $this->contents = random_bytes(self::SIZE);

        mkdir($this->directory, 0o700, true);
        file_put_contents($this->path, $this->contents);
    }

    #[\Override]
    protected function tearDown(): void
    {
        if (is_file($this->path)) {
            unlink($this->path);
        }

        if (is_dir($this->directory)) {
            rmdir($this->directory);
        }
    }

    public function testUploadStreamsChunksThroughResumableSession(): void
    {
        $transport = new Transport(
            Transport::fixture('Google/token'),
            Transport::fixture('Google/edit'),
            $this->session(),
            $this->incomplete(self::CHUNK - 1),
            $this->incomplete(2 * self::CHUNK - 1),
            Transport::fixture('Google/bundle'),
        );
        $checkpoints = new Checkpoints();

        $external = $this->store($transport, self::CHUNK)->upload($this->upload(), null, $checkpoints);

        $this->assertSame(self::EXTERNAL, $external);

        $requests = $transport->requests();
        $this->assertSame(['POST', 'POST', 'POST', 'PUT', 'PUT', 'PUT'], $this->methods($requests));
        $this->assertSame([self::TOKEN, self::EDITS, self::RESUMABLE, self::SESSION, self::SESSION, self::SESSION], $this->uris($requests));

        $start = $requests[2];
        $this->assertSame('application/octet-stream', $start->getHeaderLine('X-Upload-Content-Type'));
        $this->assertSame((string) self::SIZE, $start->getHeaderLine('X-Upload-Content-Length'));
        $this->assertSame('0', $start->getHeaderLine('Content-Length'));
        $this->assertSame('Bearer ya29.c.fixture-access-token', $start->getHeaderLine('Authorization'));
        $this->assertSame('', (string) $start->getBody());

        $this->assertPart($requests[3], 0, self::CHUNK);
        $this->assertPart($requests[4], self::CHUNK, self::CHUNK);
        $this->assertPart($requests[5], 2 * self::CHUNK, self::SIZE - 2 * self::CHUNK);

        $this->assertSame([
            $this->checkpoint(self::EDIT, self::SESSION, 0),
            $this->checkpoint(self::EDIT, self::SESSION, self::CHUNK),
            $this->checkpoint(self::EDIT, self::SESSION, 2 * self::CHUNK),
        ], $checkpoints->saved());
    }

    public function testUploadResendsFromShortRange(): void
    {
        $transport = new Transport(
            Transport::fixture('Google/token'),
            Transport::fixture('Google/edit'),
            $this->session(),
            $this->incomplete(199999),
            $this->incomplete(200000 + self::CHUNK - 1),
            Transport::fixture('Google/bundle', 201),
        );
        $checkpoints = new Checkpoints();

        $external = $this->store($transport, self::CHUNK)->upload($this->upload(), null, $checkpoints);

        $this->assertSame(self::EXTERNAL, $external);

        $requests = $transport->requests();
        $this->assertCount(6, $requests);
        $this->assertPart($requests[3], 0, self::CHUNK);
        $this->assertPart($requests[4], 200000, self::CHUNK);
        $this->assertPart($requests[5], 200000 + self::CHUNK, self::SIZE - 200000 - self::CHUNK);

        $this->assertSame([
            $this->checkpoint(self::EDIT, self::SESSION, 0),
            $this->checkpoint(self::EDIT, self::SESSION, 200000),
            $this->checkpoint(self::EDIT, self::SESSION, 200000 + self::CHUNK),
        ], $checkpoints->saved());
    }

    public function testUploadResumesFromCheckpoint(): void
    {
        $transport = new Transport(
            Transport::fixture('Google/token'),
            Transport::fixture('Google/edit'),
            $this->incomplete(self::CHUNK - 1),
            $this->incomplete(2 * self::CHUNK - 1),
            Transport::fixture('Google/bundle'),
        );
        $checkpoints = new Checkpoints();

        $external = $this->store($transport, self::CHUNK)->upload(
            $this->upload(),
            $this->checkpoint(self::STALE_EDIT, self::STALE_SESSION, self::CHUNK),
            $checkpoints,
        );

        $this->assertSame([...self::EXTERNAL, 'editId' => self::STALE_EDIT], $external);

        $requests = $transport->requests();
        $this->assertSame(['POST', 'GET', 'PUT', 'PUT', 'PUT'], $this->methods($requests));
        $this->assertSame([self::TOKEN, self::EDITS . '/' . self::STALE_EDIT, self::STALE_SESSION, self::STALE_SESSION, self::STALE_SESSION], $this->uris($requests));

        $this->assertProbe($requests[2]);
        $this->assertPart($requests[3], self::CHUNK, self::CHUNK);
        $this->assertPart($requests[4], 2 * self::CHUNK, self::SIZE - 2 * self::CHUNK);

        $this->assertSame([$this->checkpoint(self::STALE_EDIT, self::STALE_SESSION, 2 * self::CHUNK)], $checkpoints->saved());
    }

    public function testUploadResumeOfFinishedSessionReturnsBundle(): void
    {
        $transport = new Transport(
            Transport::fixture('Google/token'),
            Transport::fixture('Google/edit'),
            Transport::fixture('Google/bundle'),
        );

        $external = $this->store($transport)->upload($this->upload(), $this->checkpoint(self::STALE_EDIT, self::STALE_SESSION, self::SIZE));

        $this->assertSame([...self::EXTERNAL, 'editId' => self::STALE_EDIT], $external);
        $this->assertSame(['POST', 'GET', 'PUT'], $this->methods($transport->requests()));
        $this->assertProbe($transport->requests()[2]);
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function ended(): iterable
    {
        yield 'expired session' => [404];
        yield 'failed session' => [410];
    }

    #[DataProvider('ended')]
    public function testUploadRestartsWhenSessionEnds(int $status): void
    {
        $transport = new Transport(
            Transport::fixture('Google/token'),
            Transport::fixture('Google/edit'),
            new Response($status),
            Transport::fixture('Google/edit'),
            $this->session(),
            Transport::fixture('Google/bundle', 201),
        );
        $checkpoints = new Checkpoints();

        $external = $this->store($transport)->upload($this->upload(), $this->checkpoint(self::STALE_EDIT, self::STALE_SESSION, self::CHUNK), $checkpoints);

        $this->assertSame(self::EXTERNAL, $external);

        $requests = $transport->requests();
        $this->assertSame(['POST', 'GET', 'PUT', 'POST', 'POST', 'PUT'], $this->methods($requests));
        $this->assertSame(
            [self::TOKEN, self::EDITS . '/' . self::STALE_EDIT, self::STALE_SESSION, self::EDITS, self::RESUMABLE, self::SESSION],
            $this->uris($requests),
        );
        $this->assertProbe($requests[2]);
        $this->assertPart($requests[5], 0, self::SIZE);
        $this->assertSame([$this->checkpoint(self::EDIT, self::SESSION, 0)], $checkpoints->saved());
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function invalidEdits(): iterable
    {
        yield 'deleted edit' => ['Google/error-edit-not-found', 404];
        yield 'expired edit' => ['Google/error-edit-expired', 400];
    }

    #[DataProvider('invalidEdits')]
    public function testUploadRestartsWhenEditIsGone(string $fixture, int $status): void
    {
        $transport = new Transport(
            Transport::fixture('Google/token'),
            Transport::fixture($fixture, $status),
            Transport::fixture('Google/edit'),
            $this->session(),
            Transport::fixture('Google/bundle'),
        );

        $external = $this->store($transport)->upload($this->upload(), $this->checkpoint(self::STALE_EDIT, self::STALE_SESSION, self::CHUNK));

        $this->assertSame(self::EXTERNAL, $external);
        $this->assertSame(
            [self::TOKEN, self::EDITS . '/' . self::STALE_EDIT, self::EDITS, self::RESUMABLE, self::SESSION],
            $this->uris($transport->requests()),
        );
    }

    public function testUploadRefreshesEditExpiryOnResume(): void
    {
        $transport = new Transport(
            Transport::fixture('Google/token'),
            $this->json(['id' => self::STALE_EDIT, 'expiryTimeSeconds' => '1796000000']),
            Transport::fixture('Google/bundle'),
        );
        $checkpoint = [...$this->checkpoint(self::STALE_EDIT, self::STALE_SESSION, self::SIZE), 'expiryTimeSeconds' => 1_794_000_000];

        $external = $this->store($transport)->upload($this->upload(), $checkpoint);

        $this->assertSame([...self::EXTERNAL, 'editId' => self::STALE_EDIT, 'expiryTimeSeconds' => 1_796_000_000], $external);
    }

    public function testUploadKeepsCheckpointExpiryWhenEditOmitsIt(): void
    {
        $transport = new Transport(
            Transport::fixture('Google/token'),
            $this->json(['id' => self::STALE_EDIT]),
            Transport::fixture('Google/bundle'),
        );

        $external = $this->store($transport)->upload($this->upload(), $this->checkpoint(self::STALE_EDIT, self::STALE_SESSION, self::SIZE));

        $this->assertSame([...self::EXTERNAL, 'editId' => self::STALE_EDIT], $external);
    }

    public function testUploadWithoutEditExpiryOmitsIt(): void
    {
        $transport = new Transport(
            Transport::fixture('Google/token'),
            $this->json(['id' => self::EDIT]),
            $this->session(),
            Transport::fixture('Google/bundle'),
        );
        $checkpoints = new Checkpoints();

        $external = $this->store($transport)->upload($this->upload(), null, $checkpoints);

        $expected = self::EXTERNAL;
        unset($expected['expiryTimeSeconds']);

        $this->assertSame($expected, $external);
        $this->assertSame([['packageName' => self::PACKAGE, 'editId' => self::EDIT, 'session' => self::SESSION, 'offset' => 0]], $checkpoints->saved());
    }

    #[DataProvider('ended')]
    public function testUploadWhoseSessionEndsOnFinalProbeThrowsNotFound(int $status): void
    {
        $transport = new Transport(
            Transport::fixture('Google/token'),
            Transport::fixture('Google/edit'),
            $this->session(),
            $this->incomplete(self::SIZE - 1),
            new Response($status),
        );

        try {
            $this->store($transport)->upload($this->upload(), null);
            $this->fail('upload should throw');
        } catch (NotFound $notFound) {
            $this->assertSame('Google Play ended the upload session before returning the bundle; the next attempt starts a new upload', $notFound->getMessage());
            $this->assertSame('uploadSessionEnded', $notFound->getIssues()[0]->code);
        }

        $requests = $transport->requests();
        $this->assertSame(['POST', 'POST', 'POST', 'PUT', 'PUT'], $this->methods($requests));
        $this->assertPart($requests[3], 0, self::SIZE);
        $this->assertProbe($requests[4]);
    }

    public function testUploadIgnoresCheckpointForAnotherPackage(): void
    {
        $transport = new Transport(
            Transport::fixture('Google/token'),
            Transport::fixture('Google/edit'),
            $this->session(),
            Transport::fixture('Google/bundle'),
        );

        $this->store($transport)->upload($this->upload(), [...$this->checkpoint(self::STALE_EDIT, self::STALE_SESSION, 0), 'packageName' => 'io.example.other']);

        $this->assertSame([self::TOKEN, self::EDITS, self::RESUMABLE, self::SESSION], $this->uris($transport->requests()));
    }

    public function testUploadWithoutSessionLocationThrowsUnavailable(): void
    {
        $transport = new Transport(Transport::fixture('Google/token'), Transport::fixture('Google/edit'), new Response(200));

        $this->expectException(Unavailable::class);
        $this->expectExceptionMessage('Google Play did not return an upload session');

        $this->store($transport)->upload($this->upload(), null);
    }

    public function testUploadThatStopsAdvancingThrowsUnavailable(): void
    {
        $transport = new Transport(
            Transport::fixture('Google/token'),
            Transport::fixture('Google/edit'),
            $this->session(),
            $this->incomplete(self::CHUNK - 1),
            $this->incomplete(self::CHUNK - 1),
        );

        try {
            $this->store($transport, self::CHUNK)->upload($this->upload(), null);
            $this->fail('upload should throw');
        } catch (Unavailable $unavailable) {
            $this->assertSame('Google Play stopped accepting the upload at byte 262144', $unavailable->getMessage());
        }

        $this->assertCount(5, $transport->requests());
    }

    public function testUploadPartFailureIsMapped(): void
    {
        $transport = new Transport(
            Transport::fixture('Google/token'),
            Transport::fixture('Google/edit'),
            $this->session(),
            Transport::fixture('Google/error-version-conflict', 403),
        );

        try {
            $this->store($transport)->upload($this->upload(), null);
            $this->fail('upload should throw');
        } catch (Unauthorized $unauthorized) {
            $this->assertFalse($unauthorized->isCredential());
            $this->assertSame('apkUpgradeVersionConflict', $unauthorized->getIssues()[0]->code);
        }
    }

    public function testUploadRejectsIosBuilds(): void
    {
        $transport = new Transport();
        $upload = new Upload(new Local($this->directory), $this->path, 'App.ipa', self::SIZE, self::PACKAGE, '1.2.0', '88', Upload::PLATFORM_IOS);

        try {
            $this->store($transport)->upload($upload, null);
            $this->fail('upload should throw');
        } catch (Invalid $invalid) {
            $this->assertSame('Google Play does not accept ios uploads', $invalid->getMessage());
        }

        $this->assertSame([], $transport->requests());
    }

    public function testProcessedReturnsExternalUnchanged(): void
    {
        $transport = new Transport();

        $this->assertSame(self::EXTERNAL, $this->store($transport)->processed(self::EXTERNAL));
        $this->assertSame([], $transport->requests());
    }

    public function testReleaseCompletesTrackAndCommits(): void
    {
        $transport = new Transport(
            Transport::fixture('Google/token'),
            Transport::fixture('Google/tracks'),
            Transport::fixture('Google/track'),
            Transport::fixture('Google/commit'),
        );

        $external = $this->store($transport)->release($this->release(), self::EXTERNAL);

        $this->assertSame([...self::EXTERNAL, 'track' => 'production', 'committed' => true], $external);

        $requests = $transport->requests();
        $this->assertSame(['POST', 'GET', 'PUT', 'POST'], $this->methods($requests));
        $this->assertSame(
            [self::TOKEN, self::EDITS . '/' . self::EDIT . '/tracks', self::EDITS . '/' . self::EDIT . '/tracks/production', self::COMMIT],
            $this->uris($requests),
        );
        $this->assertSame('application/json', $requests[2]->getHeaderLine('Content-Type'));
        $this->assertSame(
            '{"track":"production","releases":[{"versionCodes":["88"],"status":"completed","releaseNotes":[{"language":"en-US","text":"Bug fixes"},{"language":"de-DE","text":"Fehlerbehebungen"}]}]}',
            (string) $requests[2]->getBody(),
        );
        $this->assertSame('', (string) $requests[3]->getBody());
    }

    public function testStagedReleaseKeepsCompletedRelease(): void
    {
        $transport = new Transport(
            Transport::fixture('Google/token'),
            Transport::fixture('Google/tracks'),
            Transport::fixture('Google/track'),
            Transport::fixture('Google/track'),
            Transport::fixture('Google/commit'),
        );

        $external = $this->store($transport)->release($this->release(fraction: 0.05, notes: []), self::EXTERNAL);

        $this->assertSame([...self::EXTERNAL, 'track' => 'production', 'committed' => true], $external);

        $requests = $transport->requests();
        $this->assertSame(['POST', 'GET', 'GET', 'PUT', 'POST'], $this->methods($requests));
        $this->assertSame(self::EDITS . '/' . self::EDIT . '/tracks/production', (string) $requests[2]->getUri());
        $this->assertSame(
            '{"track":"production","releases":[{"name":"1.1.0","versionCodes":["87"],"status":"completed","releaseNotes":[{"language":"en-US","text":"Faster sync."}]},{"versionCodes":["88"],"status":"inProgress","userFraction":0.05}]}',
            (string) $requests[3]->getBody(),
        );
    }

    public function testInternalTrackResolvesToQa(): void
    {
        $transport = new Transport(
            Transport::fixture('Google/token'),
            Transport::fixture('Google/tracks-qa'),
            Transport::fixture('Google/track'),
            Transport::fixture('Google/commit'),
        );

        $external = $this->store($transport)->release($this->release(Release::TRACK_INTERNAL, notes: []), self::EXTERNAL);

        $this->assertSame('qa', $external['track']);

        $requests = $transport->requests();
        $this->assertSame(self::EDITS . '/' . self::EDIT . '/tracks/qa', (string) $requests[2]->getUri());
        $this->assertSame('{"track":"qa","releases":[{"versionCodes":["88"],"status":"completed"}]}', (string) $requests[2]->getBody());
    }

    public function testInternalTrackIsKeptWhenListed(): void
    {
        $transport = new Transport(
            Transport::fixture('Google/token'),
            Transport::fixture('Google/tracks'),
            Transport::fixture('Google/track'),
            Transport::fixture('Google/commit'),
        );

        $external = $this->store($transport)->release($this->release(Release::TRACK_INTERNAL, notes: []), self::EXTERNAL);

        $this->assertSame('internal', $external['track']);
        $this->assertSame(self::EDITS . '/' . self::EDIT . '/tracks/internal', (string) $transport->requests()[2]->getUri());
    }

    public function testCommitWhileInReviewThrowsConflictAndKeepsEdit(): void
    {
        $transport = new Transport(
            Transport::fixture('Google/token'),
            Transport::fixture('Google/tracks'),
            Transport::fixture('Google/track'),
            Transport::fixture('Google/error-in-review', 400),
        );

        try {
            $this->store($transport)->release($this->release(), self::EXTERNAL);
            $this->fail('release should throw');
        } catch (Conflict $conflict) {
            $this->assertSame('CHANGES_ALREADY_IN_REVIEW', $conflict->getIssues()[0]->code);
        }

        $requests = $transport->requests();
        $this->assertSame(['POST', 'GET', 'PUT', 'POST'], $this->methods($requests));
        $this->assertNotContains('DELETE', $this->methods($requests));
    }

    public function testCommitRetriesWhenChangesMustNotBeSentForReview(): void
    {
        $transport = new Transport(
            Transport::fixture('Google/token'),
            Transport::fixture('Google/tracks'),
            Transport::fixture('Google/track'),
            Transport::fixture('Google/error-not-sent-for-review', 400),
            Transport::fixture('Google/commit'),
        );

        $external = $this->store($transport)->release($this->release(), [...self::EXTERNAL, 'draft' => true]);

        $this->assertSame([...self::EXTERNAL, 'track' => 'production', 'committed' => true, 'changesNotSentForReview' => true], $external);
        $this->assertSame(
            [self::COMMIT, self::COMMIT . '&changesNotSentForReview=true'],
            \array_slice($this->uris($transport->requests()), 3),
        );
    }

    public function testCommitFailureOtherThanReviewFlagIsThrown(): void
    {
        $transport = new Transport(
            Transport::fixture('Google/token'),
            Transport::fixture('Google/tracks'),
            Transport::fixture('Google/track'),
            Transport::fixture('Google/error-invalid', 400),
        );

        $this->expectException(Invalid::class);

        $this->store($transport)->release($this->release(), self::EXTERNAL);
    }

    public function testDraftAppRetriesReleaseAsDraft(): void
    {
        $transport = new Transport(
            Transport::fixture('Google/token'),
            Transport::fixture('Google/tracks'),
            Transport::fixture('Google/track'),
            Transport::fixture('Google/error-invalid', 400),
            Transport::fixture('Google/track'),
            Transport::fixture('Google/commit'),
        );

        $external = $this->store($transport)->release($this->release(fraction: 0.2, notes: ['en-US' => 'First build']), self::EXTERNAL);

        $this->assertSame([...self::EXTERNAL, 'track' => 'production', 'committed' => true, 'draft' => true], $external);

        $requests = $transport->requests();
        $this->assertSame(['POST', 'GET', 'GET', 'PUT', 'PUT', 'POST'], $this->methods($requests));
        $this->assertSame(
            '{"track":"production","releases":[{"versionCodes":["88"],"status":"draft","releaseNotes":[{"language":"en-US","text":"First build"}]}]}',
            (string) $requests[4]->getBody(),
        );
        $this->assertSame(self::COMMIT, (string) $requests[5]->getUri());
    }

    /**
     * @return iterable<string, array{ResponseInterface}>
     */
    public static function invalidated(): iterable
    {
        yield 'expired edit' => [Transport::fixture('Google/error-edit-expired', 400)];
        yield 'committed edit' => [new Response(400, body: new Stream\Factory()->createStream(json_encode(['error' => [
            'code' => 400,
            'message' => 'This Edit has already been committed.',
            'status' => 'FAILED_PRECONDITION',
            'errors' => [['reason' => 'editAlreadyCommitted', 'domain' => 'androidpublisher', 'message' => 'This Edit has already been committed.']],
        ]], JSON_THROW_ON_ERROR)))];
        yield 'deleted edit' => [Transport::fixture('Google/error-edit-not-found', 404)];
    }

    #[DataProvider('invalidated')]
    public function testReleaseIntoInvalidatedEditThrowsNotFound(ResponseInterface $response): void
    {
        $transport = new Transport(Transport::fixture('Google/token'), $response);

        try {
            $this->store($transport)->release($this->release(), self::EXTERNAL);
            $this->fail('release should throw');
        } catch (NotFound $notFound) {
            $this->assertContains($notFound->getIssues()[0]->code, ['editExpired', 'editAlreadyCommitted', 'editNotFound']);
        }

        $this->assertSame(['POST', 'GET'], $this->methods($transport->requests()));
    }

    public function testReleaseNotesOverLimitThrowInvalidBeforeSending(): void
    {
        $transport = new Transport();

        try {
            $this->store($transport)->release($this->release(notes: ['en-US' => str_repeat('a', 501)]), self::EXTERNAL);
            $this->fail('release should throw');
        } catch (Invalid $invalid) {
            $this->assertSame('Google Play release notes for en-US must be at most 500 characters', $invalid->getMessage());
        }

        $this->assertSame([], $transport->requests());
    }

    public function testReleaseNotesAreCountedInCharacters(): void
    {
        $transport = new Transport(
            Transport::fixture('Google/token'),
            Transport::fixture('Google/tracks'),
            Transport::fixture('Google/track'),
            Transport::fixture('Google/commit'),
        );

        $this->store($transport)->release($this->release(notes: ['ja-JP' => str_repeat('修', 500)]), self::EXTERNAL);

        $this->assertCount(4, $transport->requests());
    }

    public function testReleaseWithoutUploadIdsThrowsInvalid(): void
    {
        $this->expectException(Invalid::class);
        $this->expectExceptionMessage('Google Play external ids are missing editId');

        $this->store(new Transport())->release($this->release(), ['packageName' => self::PACKAGE, 'versionCode' => 88]);
    }

    public function testPromoteOpensAnEditForTheVersionCode(): void
    {
        $transport = new Transport(
            Transport::fixture('Google/token'),
            Transport::fixture('Google/edit'),
        );

        $external = $this->store($transport)->promote(self::PACKAGE, '88');

        $this->assertSame(['packageName' => self::PACKAGE, 'editId' => self::EDIT, 'expiryTimeSeconds' => self::EXPIRY, 'versionCode' => 88], $external);

        $requests = $transport->requests();
        $this->assertSame(['POST', 'POST'], $this->methods($requests));
        $this->assertSame([self::TOKEN, self::EDITS], $this->uris($requests));
    }

    public function testPromotedEditIsReleasedWithOneCommit(): void
    {
        $transport = new Transport(
            Transport::fixture('Google/token'),
            Transport::fixture('Google/edit'),
            Transport::fixture('Google/tracks'),
            Transport::fixture('Google/track'),
            Transport::fixture('Google/commit'),
        );
        $store = $this->store($transport);

        $external = $store->release($this->release(), $store->promote(self::PACKAGE, '88'));

        $this->assertSame(['packageName' => self::PACKAGE, 'editId' => self::EDIT, 'expiryTimeSeconds' => self::EXPIRY, 'versionCode' => 88, 'track' => 'production', 'committed' => true], $external);

        $requests = $transport->requests();
        $this->assertSame(['POST', 'POST', 'GET', 'PUT', 'POST'], $this->methods($requests));
        $this->assertSame(
            [self::TOKEN, self::EDITS, self::EDITS . '/' . self::EDIT . '/tracks', self::EDITS . '/' . self::EDIT . '/tracks/production', self::COMMIT],
            $this->uris($requests),
        );
        $this->assertSame(
            '{"track":"production","releases":[{"versionCodes":["88"],"status":"completed","releaseNotes":[{"language":"en-US","text":"Bug fixes"},{"language":"de-DE","text":"Fehlerbehebungen"}]}]}',
            (string) $requests[3]->getBody(),
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unpromotable(): iterable
    {
        yield 'empty' => [''];
        yield 'name' => ['1.2.0'];
        yield 'negative' => ['-88'];
        yield 'letters' => ['eighty'];
    }

    #[DataProvider('unpromotable')]
    public function testPromoteRefusesAVersionCodeThatIsNotAnInteger(string $build): void
    {
        $transport = new Transport();

        try {
            $this->store($transport)->promote(self::PACKAGE, $build);
            $this->fail('promote should throw');
        } catch (Invalid $invalid) {
            $this->assertSame("Google Play version code \"{$build}\" must be a whole number", $invalid->getMessage());
        }

        $this->assertSame([], $transport->requests());
    }

    /**
     * @return iterable<string, array{string, string, bool, string|null}>
     */
    public static function lifecycles(): iterable
    {
        yield 'draft' => ['RELEASE_LIFECYCLE_STATE_DRAFT', State::STATUS_PENDING, false, null];
        yield 'not sent for review' => ['RELEASE_LIFECYCLE_STATE_NOT_SENT_FOR_REVIEW', State::STATUS_PENDING, false, 'RELEASE_LIFECYCLE_STATE_NOT_SENT_FOR_REVIEW'];
        yield 'in review' => ['RELEASE_LIFECYCLE_STATE_IN_REVIEW', State::STATUS_REVIEWING, false, null];
        yield 'approved' => ['RELEASE_LIFECYCLE_STATE_APPROVED_NOT_PUBLISHED', State::STATUS_APPROVED, false, null];
        yield 'not approved' => ['RELEASE_LIFECYCLE_STATE_NOT_APPROVED', State::STATUS_REJECTED, false, null];
        yield 'unspecified' => ['RELEASE_LIFECYCLE_STATE_UNSPECIFIED', State::STATUS_PENDING, false, null];
        yield 'draft app' => ['RELEASE_LIFECYCLE_STATE_DRAFT', State::STATUS_PENDING, true, 'DRAFT_APP'];
    }

    #[DataProvider('lifecycles')]
    public function testStateMapsLifecycle(string $lifecycle, string $status, bool $draft, ?string $issue): void
    {
        $transport = new Transport(Transport::fixture('Google/token'), $this->summary('88', $lifecycle));
        $external = [...self::EXTERNAL, 'track' => 'production', 'committed' => true] + ($draft ? ['draft' => true] : []);

        $state = $this->store($transport)->state($external, Release::TRACK_PRODUCTION);

        $this->assertSame($status, $state->status);
        $this->assertSame($lifecycle, $state->raw);
        $this->assertSame(1.0, $state->fraction);

        if ($issue === null) {
            $this->assertSame([], $state->issues);
        } else {
            $this->assertCount(1, $state->issues);
            $this->assertSame($issue, $state->issues[0]->code);
            $this->assertTrue($state->issues[0]->manual);
        }

        $requests = $transport->requests();
        $this->assertSame(['POST', 'GET'], $this->methods($requests));
        $this->assertSame(self::APPLICATION . '/tracks/production/releases', (string) $requests[1]->getUri());
    }

    public function testStatePicksSummaryByVersionCode(): void
    {
        $transport = new Transport(Transport::fixture('Google/token'), Transport::fixture('Google/releases-review'));

        $state = $this->store($transport)->state(self::EXTERNAL, Release::TRACK_PRODUCTION);

        $this->assertSame(State::STATUS_REVIEWING, $state->status);
        $this->assertSame('RELEASE_LIFECYCLE_STATE_IN_REVIEW', $state->raw);
    }

    public function testStateWithoutMatchingSummaryIsPending(): void
    {
        $transport = new Transport(Transport::fixture('Google/token'), Transport::fixture('Google/releases-empty'));

        $state = $this->store($transport)->state(self::EXTERNAL, Release::TRACK_PRODUCTION);

        $this->assertSame(State::STATUS_PENDING, $state->status);
        $this->assertSame('NOT_FOUND', $state->raw);
        $this->assertSame([], $state->issues);
    }

    public function testStateReadsInternalReleaseFromQa(): void
    {
        $transport = new Transport(Transport::fixture('Google/token'), Transport::fixture('Google/releases-review'));

        $this->store($transport)->state([...self::EXTERNAL, 'track' => 'qa'], Release::TRACK_INTERNAL);

        $this->assertSame(self::APPLICATION . '/tracks/qa/releases', (string) $transport->requests()[1]->getUri());
    }

    /**
     * @return iterable<string, array{string, int, string, float}>
     */
    public static function published(): iterable
    {
        yield 'staged' => ['Google/track-staged', 88, State::STATUS_PUBLISHED, 0.1];
        yield 'halted' => ['Google/track-halted', 88, State::STATUS_HALTED, 0.1];
        yield 'completed' => ['Google/track', 87, State::STATUS_PUBLISHED, 1.0];
        yield 'superseded' => ['Google/track', 86, State::STATUS_PUBLISHED, 1.0];
    }

    #[DataProvider('published')]
    public function testPublishedStateReadsTrackThroughEdit(string $track, int $versionCode, string $status, float $fraction): void
    {
        $transport = new Transport(
            Transport::fixture('Google/token'),
            $this->summary((string) $versionCode, 'RELEASE_LIFECYCLE_STATE_PUBLISHED'),
            Transport::fixture('Google/edit'),
            Transport::fixture($track),
            new Response(204),
        );

        $state = $this->store($transport)->state([...self::EXTERNAL, 'versionCode' => $versionCode], Release::TRACK_PRODUCTION);

        $this->assertSame($status, $state->status);
        $this->assertSame('RELEASE_LIFECYCLE_STATE_PUBLISHED', $state->raw);
        $this->assertSame($fraction, $state->fraction);

        $requests = $transport->requests();
        $this->assertSame(['POST', 'GET', 'POST', 'GET', 'DELETE'], $this->methods($requests));
        $this->assertSame(
            [
                self::TOKEN,
                self::APPLICATION . '/tracks/production/releases',
                self::EDITS,
                self::EDITS . '/' . self::EDIT . '/tracks/production',
                self::EDITS . '/' . self::EDIT,
            ],
            $this->uris($requests),
        );
    }

    public function testFullReleaseLaterHaltedReportsHalted(): void
    {
        $transport = new Transport(
            Transport::fixture('Google/token'),
            Transport::fixture('Google/tracks'),
            Transport::fixture('Google/track'),
            Transport::fixture('Google/commit'),
            $this->summary('88', 'RELEASE_LIFECYCLE_STATE_PUBLISHED'),
            Transport::fixture('Google/edit'),
            $this->json(['track' => 'production', 'releases' => [['name' => '1.2.0', 'versionCodes' => ['88'], 'status' => 'halted']]]),
            new Response(204),
        );
        $store = $this->store($transport);

        $external = $store->release($this->release(), self::EXTERNAL);
        $state = $store->state($external, Release::TRACK_PRODUCTION);

        $this->assertSame(State::STATUS_HALTED, $state->status);
        $this->assertSame('RELEASE_LIFECYCLE_STATE_PUBLISHED', $state->raw);
        $this->assertSame(1.0, $state->fraction);
        $this->assertSame(['POST', 'GET', 'PUT', 'POST', 'GET', 'POST', 'GET', 'DELETE'], $this->methods($transport->requests()));
    }

    public function testPublishedStateOfStagedReleaseReadsTrackThroughEdit(): void
    {
        $transport = new Transport(
            Transport::fixture('Google/token'),
            $this->summary('88', 'RELEASE_LIFECYCLE_STATE_PUBLISHED'),
            Transport::fixture('Google/edit'),
            Transport::fixture('Google/track-staged'),
            new Response(204),
        );

        $state = $this->store($transport)->state(self::RELEASED, Release::TRACK_PRODUCTION);

        $this->assertSame(State::STATUS_PUBLISHED, $state->status);
        $this->assertSame(0.1, $state->fraction);
        $this->assertSame(['POST', 'GET', 'POST', 'GET', 'DELETE'], $this->methods($transport->requests()));
    }

    public function testPublishedStateDiscardsEditWhenTrackReadFails(): void
    {
        $transport = new Transport(
            Transport::fixture('Google/token'),
            Transport::fixture('Google/releases'),
            Transport::fixture('Google/edit'),
            Transport::fixture('Google/error-unavailable', 503),
            new Response(204),
        );

        try {
            $this->store($transport)->state(self::EXTERNAL, Release::TRACK_PRODUCTION);
            $this->fail('state should throw');
        } catch (Unavailable) {
        }

        $this->assertSame(['POST', 'GET', 'POST', 'GET', 'DELETE'], $this->methods($transport->requests()));
    }

    /**
     * @return iterable<string, array{string, \Closure(Frozen): void, string}>
     */
    public static function changes(): iterable
    {
        yield 'rollout increase' => [
            'Google/track-staged',
            static fn (Frozen $store) => $store->rollout(self::RELEASED, Release::TRACK_PRODUCTION, 0.5),
            '{"track":"production","releases":[{"name":"1.1.0","versionCodes":["87"],"status":"completed"},{"name":"1.2.0","versionCodes":["88"],"status":"inProgress","userFraction":0.5}]}',
        ];
        yield 'rollout complete' => [
            'Google/track-staged',
            static fn (Frozen $store) => $store->rollout(self::RELEASED, Release::TRACK_PRODUCTION, 1.0),
            '{"track":"production","releases":[{"name":"1.2.0","versionCodes":["88"],"status":"completed"}]}',
        ];
        yield 'halt' => [
            'Google/track-staged',
            static fn (Frozen $store) => $store->halt(self::RELEASED, Release::TRACK_PRODUCTION),
            '{"track":"production","releases":[{"name":"1.1.0","versionCodes":["87"],"status":"completed"},{"name":"1.2.0","versionCodes":["88"],"status":"halted","userFraction":0.1}]}',
        ];
        yield 'resume' => [
            'Google/track-halted',
            static fn (Frozen $store) => $store->resume(self::RELEASED, Release::TRACK_PRODUCTION),
            '{"track":"production","releases":[{"name":"1.1.0","versionCodes":["87"],"status":"completed"},{"name":"1.2.0","versionCodes":["88"],"status":"inProgress","userFraction":0.1}]}',
        ];
    }

    /**
     * @param \Closure(Frozen): void $operation
     */
    #[DataProvider('changes')]
    public function testTrackChangesAreCommittedInNewEdit(string $track, \Closure $operation, string $body): void
    {
        $transport = new Transport(
            Transport::fixture('Google/token'),
            Transport::fixture('Google/edit'),
            Transport::fixture($track),
            Transport::fixture($track),
            Transport::fixture('Google/commit'),
        );

        $operation($this->store($transport));

        $requests = $transport->requests();
        $this->assertSame(['POST', 'POST', 'GET', 'PUT', 'POST'], $this->methods($requests));
        $this->assertSame(
            [self::TOKEN, self::EDITS, self::EDITS . '/' . self::EDIT . '/tracks/production', self::EDITS . '/' . self::EDIT . '/tracks/production', self::COMMIT],
            $this->uris($requests),
        );
        $this->assertSame($body, (string) $requests[3]->getBody());
    }

    public function testResumeWithoutFractionCompletes(): void
    {
        $transport = new Transport(
            Transport::fixture('Google/token'),
            Transport::fixture('Google/edit'),
            $this->json(['track' => 'production', 'releases' => [
                ['versionCodes' => ['87'], 'status' => 'completed'],
                ['versionCodes' => ['88'], 'status' => 'halted'],
            ]]),
            Transport::fixture('Google/track'),
            Transport::fixture('Google/commit'),
        );

        $this->store($transport)->resume(self::EXTERNAL, Release::TRACK_PRODUCTION);

        $this->assertSame(
            '{"track":"production","releases":[{"versionCodes":["88"],"status":"completed"}]}',
            (string) $transport->requests()[3]->getBody(),
        );
    }

    public function testChangeOfMissingReleaseThrowsNotFoundAndDiscardsEdit(): void
    {
        $transport = new Transport(
            Transport::fixture('Google/token'),
            Transport::fixture('Google/edit'),
            Transport::fixture('Google/track'),
            new Response(204),
        );

        try {
            $this->store($transport)->halt(self::EXTERNAL, Release::TRACK_PRODUCTION);
            $this->fail('halt should throw');
        } catch (NotFound $notFound) {
            $this->assertSame('Version code 88 has no release on the production track', $notFound->getMessage());
        }

        $requests = $transport->requests();
        $this->assertSame(['POST', 'POST', 'GET', 'DELETE'], $this->methods($requests));
        $this->assertSame(self::EDITS . '/' . self::EDIT, (string) $requests[3]->getUri());
    }

    public function testChangeDiscardsEditWhenCommitIsRejected(): void
    {
        $transport = new Transport(
            Transport::fixture('Google/token'),
            Transport::fixture('Google/edit'),
            Transport::fixture('Google/track-staged'),
            Transport::fixture('Google/track-staged'),
            Transport::fixture('Google/error-in-review', 400),
            new Response(204),
        );

        $this->expectException(Conflict::class);

        try {
            $this->store($transport)->rollout(self::EXTERNAL, Release::TRACK_PRODUCTION, 0.5);
        } finally {
            $this->assertSame(['POST', 'POST', 'GET', 'PUT', 'POST', 'DELETE'], $this->methods($transport->requests()));
        }
    }

    public function testChangeCommitsWithoutReviewWhenRequired(): void
    {
        $transport = new Transport(
            Transport::fixture('Google/token'),
            Transport::fixture('Google/edit'),
            Transport::fixture('Google/track-staged'),
            Transport::fixture('Google/track-staged'),
            Transport::fixture('Google/error-not-sent-for-review', 400),
            Transport::fixture('Google/commit'),
        );

        $this->store($transport)->halt(self::EXTERNAL, Release::TRACK_PRODUCTION);

        $this->assertSame(
            [self::COMMIT, self::COMMIT . '&changesNotSentForReview=true'],
            \array_slice($this->uris($transport->requests()), 4),
        );
    }

    /**
     * @return iterable<string, array{float}>
     */
    public static function fractions(): iterable
    {
        yield 'zero' => [0.0];
        yield 'negative' => [-0.5];
        yield 'over one' => [1.5];
        yield 'not a number' => [NAN];
    }

    #[DataProvider('fractions')]
    public function testRolloutRejectsInvalidFraction(float $fraction): void
    {
        $transport = new Transport();

        try {
            $this->store($transport)->rollout(self::EXTERNAL, Release::TRACK_PRODUCTION, $fraction);
            $this->fail('rollout should throw');
        } catch (Invalid $invalid) {
            $this->assertSame('Rollout fraction must be greater than 0 and at most 1', $invalid->getMessage());
        }

        $this->assertSame([], $transport->requests());
    }

    public function testWithdrawIsUnsupported(): void
    {
        $this->expectException(Invalid::class);
        $this->expectExceptionMessage('withdraw is not supported by google');

        $this->store(new Transport())->withdraw(self::EXTERNAL);
    }

    /**
     * @param int<1, max>|null $chunk
     */
    private function store(Transport $transport, ?int $chunk = null): Frozen
    {
        return new Frozen(new Credential\Google(self::EMAIL, $this->key()), $transport, self::NOW, self::ENDPOINT, self::TOKEN, $chunk);
    }

    private function upload(): Upload
    {
        return new Upload(new Local($this->directory), $this->path, 'app.aab', self::SIZE, self::PACKAGE, '1.2.0', '88', Upload::PLATFORM_ANDROID);
    }

    /**
     * @param array<string, string> $notes
     */
    private function release(string $track = Release::TRACK_PRODUCTION, float $fraction = 1.0, array $notes = ['en-US' => 'Bug fixes', 'de-DE' => 'Fehlerbehebungen']): Release
    {
        return new Release(self::PACKAGE, $track, '1.2.0', '88', $fraction, $notes);
    }

    /**
     * @return array{packageName: string, editId: string, expiryTimeSeconds: int, session: string, offset: int}
     */
    private function checkpoint(string $editId, string $session, int $offset): array
    {
        return ['packageName' => self::PACKAGE, 'editId' => $editId, 'expiryTimeSeconds' => self::EXPIRY, 'session' => $session, 'offset' => $offset];
    }

    private function assertPart(RequestInterface $request, int $offset, int $length): void
    {
        $last = $offset + $length - 1;

        $this->assertSame('PUT', $request->getMethod());
        $this->assertSame("bytes {$offset}-{$last}/" . self::SIZE, $request->getHeaderLine('Content-Range'));
        $this->assertSame((string) $length, $request->getHeaderLine('Content-Length'));
        $this->assertSame(substr($this->contents, $offset, $length), (string) $request->getBody());
    }

    private function assertProbe(RequestInterface $request): void
    {
        $this->assertSame('PUT', $request->getMethod());
        $this->assertSame('bytes */' . self::SIZE, $request->getHeaderLine('Content-Range'));
        $this->assertSame('0', $request->getHeaderLine('Content-Length'));
        $this->assertSame('', (string) $request->getBody());
    }

    private function session(): ResponseInterface
    {
        return new Response(200)->withHeader('Location', self::SESSION);
    }

    private function incomplete(int $last): ResponseInterface
    {
        return new Response(308)->withHeader('Range', "bytes=0-{$last}");
    }

    private function summary(string $versionCode, string $lifecycle): ResponseInterface
    {
        return $this->json(['releases' => [[
            'releaseName' => '1.2.0',
            'track' => 'production',
            'activeArtifacts' => [['versionCode' => $versionCode]],
            'releaseLifecycleState' => $lifecycle,
        ]]]);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function json(array $body): ResponseInterface
    {
        return new Response(200, body: new Stream\Factory()->createStream(json_encode($body, JSON_THROW_ON_ERROR)))
            ->withHeader('Content-Type', 'application/json');
    }

    private function key(): string
    {
        if (self::$key !== null) {
            return self::$key;
        }

        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
        if ($key === false || !openssl_pkey_export($key, $private) || !\is_string($private)) {
            throw new \LogicException('RSA key generation failed');
        }

        return self::$key = $private;
    }

    /**
     * @param list<RequestInterface> $requests
     *
     * @return list<string>
     */
    private function methods(array $requests): array
    {
        return array_map(static fn (RequestInterface $request): string => $request->getMethod(), $requests);
    }

    /**
     * @param list<RequestInterface> $requests
     *
     * @return list<string>
     */
    private function uris(array $requests): array
    {
        return array_map(static fn (RequestInterface $request): string => (string) $request->getUri(), $requests);
    }
}
