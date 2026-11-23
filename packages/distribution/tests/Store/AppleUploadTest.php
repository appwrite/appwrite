<?php

declare(strict_types=1);

namespace Utopia\Distribution\Tests\Store;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Utopia\Distribution\Credential;
use Utopia\Distribution\Exception\Invalid;
use Utopia\Distribution\Exception\Unavailable;
use Utopia\Distribution\Tests\Checkpoints;
use Utopia\Distribution\Tests\Failure;
use Utopia\Distribution\Tests\Store\Apple\Frozen;
use Utopia\Distribution\Tests\Transport;
use Utopia\Distribution\Upload;
use Utopia\Psr7\Response;
use Utopia\Psr7\Stream;
use Utopia\Storage\Device\Local;

final class AppleUploadTest extends TestCase
{
    private const string KEY_ID = 'ABC123DEFG';

    private const string ISSUER_ID = '57246542-96fe-1a63-e053-0824d011072a';

    private const string APP_ID = '6450000001';

    private const string BUNDLE_ID = 'io.example.app';

    private const string VERSION = '1.4.0';

    private const string BUILD = '42';

    private const string BUILD_UPLOAD_ID = 'b7d0e2a1-5c3f-4e8a-9b1d-2f6c8a0e4d17';

    private const string BUILD_UPLOAD_FILE_ID = 'f2a9c4e6-1b3d-4f7a-8c5e-9d0b2a4c6e18';

    private const string BUILD_ID = 'a1b2c3d4-0000-4000-8000-000000000042';

    private const string STORAGE = 'https://storage.test/apple/upload/' . self::BUILD_UPLOAD_FILE_ID;

    private const int SIZE = 10;

    private const int NOW = 1_795_000_000;

    private const string ENDPOINT = 'https://store.test/apple';

    private const array RESERVED = [
        'appId' => self::APP_ID,
        'bundleId' => self::BUNDLE_ID,
        'version' => self::VERSION,
        'build' => self::BUILD,
        'buildUploadId' => self::BUILD_UPLOAD_ID,
    ];

    private const array EXTERNAL = [
        ...self::RESERVED,
        'buildUploadFileId' => self::BUILD_UPLOAD_FILE_ID,
    ];

    private static string $privateKey = '';

    private string $directory = '';

    private string $path = '';

    private string $contents = '';

    #[\Override]
    public static function setUpBeforeClass(): void
    {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        self::assertInstanceOf(\OpenSSLAsymmetricKey::class, $key);
        self::assertTrue(openssl_pkey_export($key, $private));
        self::assertIsString($private);

        self::$privateKey = $private;
    }

    #[\Override]
    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/distribution-apple-' . bin2hex(random_bytes(6));
        $this->path = $this->directory . '/App.ipa';
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

    public function testUploadReservesSendsPartsWithoutAuthorizationAndCommits(): void
    {
        $transport = new Transport(
            $this->reply('apps'),
            $this->reply('build-upload', 201),
            $this->reply('build-upload-file', 201),
            $this->stored(),
            $this->stored(),
            $this->reply('build-upload-file-committed'),
        );
        $checkpoints = new Checkpoints();

        $external = $this->store($transport)->upload($this->upload(), null, $checkpoints);

        $this->assertSame(self::EXTERNAL, $external);

        [$apps, $buildUpload, $file, $first, $second, $commit] = $transport->requests();
        $this->assertSame('GET', $apps->getMethod());
        $this->assertSame(self::ENDPOINT . '/v1/buildUploads', (string) $buildUpload->getUri());
        $this->assertSame('POST', $buildUpload->getMethod());
        $this->assertSame([
            'data' => [
                'type' => 'buildUploads',
                'attributes' => [
                    'cfBundleShortVersionString' => self::VERSION,
                    'cfBundleVersion' => self::BUILD,
                    'platform' => 'IOS',
                ],
                'relationships' => [
                    'app' => ['data' => ['type' => 'apps', 'id' => self::APP_ID]],
                ],
            ],
        ], $this->body($buildUpload));
        $this->assertSame(self::ENDPOINT . '/v1/buildUploadFiles', (string) $file->getUri());
        $this->assertSame([
            'data' => [
                'type' => 'buildUploadFiles',
                'attributes' => [
                    'assetType' => 'ASSET',
                    'fileName' => 'App.ipa',
                    'fileSize' => self::SIZE,
                    'uti' => 'com.apple.ipa',
                ],
                'relationships' => [
                    'buildUpload' => ['data' => ['type' => 'buildUploads', 'id' => self::BUILD_UPLOAD_ID]],
                ],
            ],
        ], $this->body($file));

        $this->assertPart($first, 1, 0, 6);
        $this->assertPart($second, 2, 6, 4);

        $this->assertSame('PATCH', $commit->getMethod());
        $this->assertSame(self::ENDPOINT . '/v1/buildUploadFiles/' . self::BUILD_UPLOAD_FILE_ID, (string) $commit->getUri());
        $this->assertStringStartsWith('Bearer ', $commit->getHeaderLine('Authorization'));
        $this->assertSame([
            'data' => [
                'type' => 'buildUploadFiles',
                'id' => self::BUILD_UPLOAD_FILE_ID,
                'attributes' => [
                    'uploaded' => true,
                    'sourceFileChecksums' => [
                        'file' => ['hash' => hash('sha256', $this->contents), 'algorithm' => 'SHA_256'],
                    ],
                ],
            ],
        ], $this->body($commit));

        $this->assertSame([
            self::RESERVED,
            [...self::EXTERNAL, 'parts' => []],
            [...self::EXTERNAL, 'parts' => [1]],
            [...self::EXTERNAL, 'parts' => [1, 2]],
        ], $checkpoints->saved());
    }

    public function testUploadWithoutProgressStillCompletes(): void
    {
        $transport = new Transport(
            $this->reply('apps'),
            $this->reply('build-upload', 201),
            $this->reply('build-upload-file', 201),
            $this->stored(),
            $this->stored(),
            $this->reply('build-upload-file-committed'),
        );

        $this->assertSame(self::EXTERNAL, $this->store($transport)->upload($this->upload(), null));
    }

    public function testUploadResumeSkipsDoneParts(): void
    {
        $transport = new Transport(
            $this->reply('build-upload'),
            $this->reply('build-upload-file'),
            $this->stored(),
            $this->reply('build-upload-file-committed'),
        );
        $checkpoints = new Checkpoints();

        $external = $this->store($transport)->upload($this->upload(), [...self::EXTERNAL, 'parts' => [1]], $checkpoints);

        $this->assertSame(self::EXTERNAL, $external);

        [$buildUpload, $file, $part, $commit] = $transport->requests();
        $this->assertSame('GET', $buildUpload->getMethod());
        $this->assertSame(self::ENDPOINT . '/v1/buildUploads/' . self::BUILD_UPLOAD_ID, (string) $buildUpload->getUri());
        $this->assertSame('GET', $file->getMethod());
        $this->assertSame(self::ENDPOINT . '/v1/buildUploadFiles/' . self::BUILD_UPLOAD_FILE_ID, (string) $file->getUri());
        $this->assertPart($part, 2, 6, 4);
        $this->assertSame('PATCH', $commit->getMethod());
        $this->assertSame([[...self::EXTERNAL, 'parts' => [1, 2]]], $checkpoints->saved());
    }

    public function testUploadResumeDropsPartsTheReservationDoesNotList(): void
    {
        $transport = new Transport(
            $this->reply('build-upload'),
            $this->reply('build-upload-file'),
            $this->stored(),
            $this->reply('build-upload-file-committed'),
        );

        $this->store($transport)->upload($this->upload(), [...self::EXTERNAL, 'parts' => [2, 7, '1']]);

        $this->assertPart($transport->requests()[2], 1, 0, 6);
        $this->assertCount(4, $transport->requests());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function landed(): iterable
    {
        yield 'processing' => ['build-upload-processing'];
        yield 'complete' => ['build-upload-complete'];
        yield 'failed' => ['build-upload-failed'];
    }

    #[DataProvider('landed')]
    public function testUploadResumeAfterCommitSendsNothing(string $fixture): void
    {
        $transport = new Transport($this->reply($fixture));
        $checkpoints = new Checkpoints();

        $external = $this->store($transport)->upload($this->upload(), [...self::EXTERNAL, 'parts' => [1, 2]], $checkpoints);

        $this->assertSame(self::EXTERNAL, $external);
        $this->assertCount(1, $transport->requests());
        $this->assertSame([], $checkpoints->saved());
    }

    public function testUploadResumeWhenTheFileIsAlreadyCommittedSendsNothing(): void
    {
        $transport = new Transport($this->reply('build-upload'), $this->reply('build-upload-file-committed'));

        $this->assertSame(self::EXTERNAL, $this->store($transport)->upload($this->upload(), [...self::EXTERNAL, 'parts' => [1, 2]]));
        $this->assertCount(2, $transport->requests());
    }

    public function testUploadResumeWithExpiredOperationRestarts(): void
    {
        $transport = new Transport(
            $this->reply('build-upload'),
            $this->reply('build-upload-file-expired'),
            $this->page(204, ''),
            ...$this->fresh(),
        );
        $checkpoints = new Checkpoints();

        $external = $this->store($transport)->upload($this->upload(), [...self::EXTERNAL, 'parts' => [1]], $checkpoints);

        $this->assertSame(self::EXTERNAL, $external);

        $requests = $transport->requests();
        $this->assertCount(9, $requests);
        $this->assertSame('DELETE', $requests[2]->getMethod());
        $this->assertSame(self::ENDPOINT . '/v1/buildUploads/' . self::BUILD_UPLOAD_ID, (string) $requests[2]->getUri());
        $this->assertSame('POST', $requests[4]->getMethod());
        $this->assertPart($requests[6], 1, 0, 6);
        $this->assertPart($requests[7], 2, 6, 4);
        $this->assertSame(self::RESERVED, $checkpoints->saved()[0]);
    }

    public function testUploadResumeWithMissingFileRestarts(): void
    {
        $transport = new Transport(
            $this->reply('build-upload'),
            $this->reply('error-not-found', 404),
            $this->reply('error-not-found', 404),
            ...$this->fresh(),
        );

        $this->assertSame(self::EXTERNAL, $this->store($transport)->upload($this->upload(), [...self::EXTERNAL, 'parts' => []]));

        $requests = $transport->requests();
        $this->assertCount(9, $requests);
        $this->assertSame('DELETE', $requests[2]->getMethod());
    }

    public function testUploadResumeWithMissingBuildUploadStartsFreshWithoutDeleting(): void
    {
        $transport = new Transport($this->reply('error-not-found', 404), ...$this->fresh());

        $this->assertSame(self::EXTERNAL, $this->store($transport)->upload($this->upload(), [...self::EXTERNAL, 'parts' => []]));

        $methods = array_map(fn (RequestInterface $request): string => $request->getMethod(), $transport->requests());
        $this->assertNotContains('DELETE', $methods);
        $this->assertCount(7, $methods);
    }

    public function testUploadResumeWithoutFileReservationRestarts(): void
    {
        $transport = new Transport($this->reply('build-upload'), $this->page(204, ''), ...$this->fresh());

        $this->assertSame(self::EXTERNAL, $this->store($transport)->upload($this->upload(), self::RESERVED));
        $this->assertSame('DELETE', $transport->requests()[1]->getMethod());
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function stale(): iterable
    {
        yield 'other build' => [[...self::EXTERNAL, 'build' => '41', 'parts' => [1]]];
        yield 'other version' => [[...self::EXTERNAL, 'version' => '1.3.0', 'parts' => [1]]];
        yield 'other bundle' => [[...self::EXTERNAL, 'bundleId' => 'io.example.other', 'parts' => [1]]];
        yield 'no build upload' => [[...self::EXTERNAL, 'buildUploadId' => '', 'parts' => [1]]];
        yield 'empty' => [[]];
    }

    /**
     * @param array<string, mixed> $checkpoint
     */
    #[DataProvider('stale')]
    public function testUploadStaleCheckpointStartsFresh(array $checkpoint): void
    {
        $transport = new Transport(...$this->fresh());

        $this->assertSame(self::EXTERNAL, $this->store($transport)->upload($this->upload(), $checkpoint));
        $this->assertSame('GET', $transport->requests()[0]->getMethod());
        $this->assertStringStartsWith(self::ENDPOINT . '/v1/apps?', (string) $transport->requests()[0]->getUri());
    }

    public function testUploadExpiredPartOnResumedReservationRestartsOnce(): void
    {
        $transport = new Transport(
            $this->reply('build-upload'),
            $this->reply('build-upload-file'),
            $this->page(403, '<Error><Code>AccessDenied</Code></Error>'),
            $this->page(204, ''),
            ...$this->fresh(),
        );

        $this->assertSame(self::EXTERNAL, $this->store($transport)->upload($this->upload(), [...self::EXTERNAL, 'parts' => []]));

        $requests = $transport->requests();
        $this->assertCount(10, $requests);
        $this->assertSame('DELETE', $requests[3]->getMethod());
    }

    public function testUploadExpiredPartOnFreshReservationThrowsUnavailable(): void
    {
        $transport = new Transport(
            $this->reply('apps'),
            $this->reply('build-upload', 201),
            $this->reply('build-upload-file', 201),
            $this->page(403, ''),
        );

        try {
            $this->store($transport)->upload($this->upload(), null);
            $this->fail('upload should throw');
        } catch (Unavailable $unavailable) {
            $this->assertSame('UPLOAD_EXPIRED', $unavailable->getIssues()[0]->code);
            $this->assertStringNotContainsString('storage.test', $unavailable->getMessage());
        }

        $this->assertCount(4, $transport->requests());
    }

    public function testUploadPartFailureThrowsUnavailableWithoutUrl(): void
    {
        $transport = new Transport(
            $this->reply('apps'),
            $this->reply('build-upload', 201),
            $this->reply('build-upload-file', 201),
            $this->page(500, ''),
        );
        $checkpoints = new Checkpoints();

        try {
            $this->store($transport)->upload($this->upload(), null, $checkpoints);
            $this->fail('upload should throw');
        } catch (Unavailable $unavailable) {
            $this->assertSame([], $unavailable->getIssues());
            $this->assertStringNotContainsString('storage.test', $unavailable->getMessage());
        }

        $this->assertSame([...self::EXTERNAL, 'parts' => []], $checkpoints->saved()[1]);
        $this->assertCount(2, $checkpoints->saved());
    }

    public function testUploadLostCommitIsReconciledThroughBuildUpload(): void
    {
        $transport = new Transport(
            $this->reply('apps'),
            $this->reply('build-upload', 201),
            $this->reply('build-upload-file', 201),
            $this->stored(),
            $this->stored(),
            new Failure('Connection reset by peer'),
            $this->reply('build-upload-processing'),
        );

        $this->assertSame(self::EXTERNAL, $this->store($transport)->upload($this->upload(), null));

        $reconcile = $transport->requests()[6];
        $this->assertSame('GET', $reconcile->getMethod());
        $this->assertSame(self::ENDPOINT . '/v1/buildUploads/' . self::BUILD_UPLOAD_ID, (string) $reconcile->getUri());
    }

    public function testUploadLostCommitStillAwaitingThrowsUnavailable(): void
    {
        $transport = new Transport(
            $this->reply('build-upload'),
            $this->reply('build-upload-file'),
            $this->reply('error-server', 500),
            $this->reply('build-upload'),
        );

        $this->expectException(Unavailable::class);

        $this->store($transport)->upload($this->upload(), [...self::EXTERNAL, 'parts' => [1, 2]]);
    }

    public function testUploadAndroidThrowsInvalidWithoutRequests(): void
    {
        $transport = new Transport();
        $upload = new Upload(new Local($this->directory), $this->path, 'app.aab', self::SIZE, self::BUNDLE_ID, self::VERSION, self::BUILD, Upload::PLATFORM_ANDROID);

        try {
            $this->store($transport)->upload($upload, null);
            $this->fail('upload should throw');
        } catch (Invalid) {
            $this->assertSame([], $transport->requests());
        }
    }

    public function testProcessedReadsBuildUploadWithIncludedBuild(): void
    {
        $transport = new Transport($this->reply('build-upload-complete'));

        $this->assertSame([...self::EXTERNAL, 'buildId' => self::BUILD_ID], $this->store($transport)->processed(self::EXTERNAL));

        $request = $transport->requests()[0];
        $this->assertSame('GET', $request->getMethod());
        $this->assertSame('/apple/v1/buildUploads/' . self::BUILD_UPLOAD_ID, $request->getUri()->getPath());
        parse_str($request->getUri()->getQuery(), $query);
        $this->assertSame([
            'include' => 'build',
            'fields' => [
                'buildUploads' => 'state,build',
                'builds' => 'version,processingState,usesNonExemptEncryption,buildAudienceType,expired',
            ],
        ], $query);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function pending(): iterable
    {
        yield 'awaiting upload' => ['build-upload'];
        yield 'processing upload' => ['build-upload-processing'];
        yield 'processing build' => ['build-upload-complete-processing'];
    }

    #[DataProvider('pending')]
    public function testProcessedWhilePendingReturnsNull(string $fixture): void
    {
        $transport = new Transport($this->reply($fixture));

        $this->assertNull($this->store($transport)->processed(self::EXTERNAL));
        $this->assertCount(1, $transport->requests());
    }

    public function testProcessedFailedUploadThrowsInvalidWithStateErrors(): void
    {
        $transport = new Transport($this->reply('build-upload-failed'));

        try {
            $this->store($transport)->processed(self::EXTERNAL);
            $this->fail('processed should throw');
        } catch (Invalid $invalid) {
            $issues = $invalid->getIssues();
            $this->assertCount(2, $issues);
            $this->assertSame('90062', $issues[0]->code);
            $this->assertStringStartsWith('The value for key CFBundleShortVersionString', $issues[0]->message);
            $this->assertSame('90189', $issues[1]->code);
            $this->assertStringContainsString('CFBundleShortVersionString', $invalid->getMessage());
        }
    }

    public function testProcessedInvalidBuildThrowsInvalid(): void
    {
        $transport = new Transport($this->reply('build-upload-complete-invalid'));

        try {
            $this->store($transport)->processed(self::EXTERNAL);
            $this->fail('processed should throw');
        } catch (Invalid $invalid) {
            $this->assertSame('INVALID', $invalid->getIssues()[0]->code);
        }
    }

    public function testProcessedCompleteWithoutBuildFallsBackToBuildsList(): void
    {
        $transport = new Transport($this->reply('build-upload-complete-unlinked'), $this->reply('build-upload-builds'));

        $this->assertSame([...self::EXTERNAL, 'buildId' => self::BUILD_ID], $this->store($transport)->processed(self::EXTERNAL));

        $request = $transport->requests()[1];
        $this->assertSame('/apple/v1/builds', $request->getUri()->getPath());
        parse_str($request->getUri()->getQuery(), $query);
        $this->assertSame([
            'filter' => [
                'app' => self::APP_ID,
                'version' => self::BUILD,
                'preReleaseVersion.version' => self::VERSION,
                'preReleaseVersion.platform' => 'IOS',
            ],
            'fields' => ['builds' => 'version,processingState,usesNonExemptEncryption,buildAudienceType,expired'],
            'limit' => '1',
        ], $query);
    }

    public function testProcessedCompleteWithoutListedBuildReturnsNull(): void
    {
        $transport = new Transport($this->reply('build-upload-complete-unlinked'), $this->reply('builds-empty'));

        $this->assertNull($this->store($transport)->processed(self::EXTERNAL));
    }

    public function testProcessedLinkedBuildMissingFromIncludedIsRead(): void
    {
        $transport = new Transport($this->reply('build-upload-complete-detached'), $this->reply('build-upload-build'));

        $this->assertSame([...self::EXTERNAL, 'buildId' => self::BUILD_ID], $this->store($transport)->processed(self::EXTERNAL));
        $this->assertSame(self::ENDPOINT . '/v1/builds/' . self::BUILD_ID, strstr((string) $transport->requests()[1]->getUri(), '?', true));
    }

    public function testProcessedWithoutBuildUploadSearchesBuilds(): void
    {
        $external = ['appId' => self::APP_ID, 'bundleId' => self::BUNDLE_ID, 'version' => self::VERSION, 'build' => self::BUILD];
        $transport = new Transport($this->reply('build-upload-builds'));

        $this->assertSame([...$external, 'buildId' => self::BUILD_ID], $this->store($transport)->processed($external));
        $this->assertSame('/apple/v1/builds', $transport->requests()[0]->getUri()->getPath());
    }

    public function testProcessedWithoutAppIdResolvesTheBundle(): void
    {
        $external = ['bundleId' => self::BUNDLE_ID, 'version' => self::VERSION, 'build' => self::BUILD];
        $transport = new Transport($this->reply('apps'), $this->reply('build-upload-builds'));

        $this->assertSame([...$external, 'buildId' => self::BUILD_ID], $this->store($transport)->processed($external));

        parse_str($transport->requests()[1]->getUri()->getQuery(), $query);
        $this->assertIsArray($query['filter'] ?? null);
        $this->assertSame(self::APP_ID, $query['filter']['app'] ?? null);
    }

    public function testProcessedWithoutBuildNumberThrowsInvalid(): void
    {
        $transport = new Transport();

        $this->expectException(Invalid::class);

        $this->store($transport)->processed(['appId' => self::APP_ID, 'version' => self::VERSION]);
    }

    private function assertPart(RequestInterface $request, int $number, int $offset, int $length): void
    {
        $this->assertSame('PUT', $request->getMethod());
        $this->assertSame(self::STORAGE . "/{$number}?X-Amz-Expires=604800&X-Amz-Signature=5e{$number}f", (string) $request->getUri());
        $this->assertFalse($request->hasHeader('Authorization'));
        $this->assertSame('application/octet-stream', $request->getHeaderLine('Content-Type'));
        $this->assertSame("part-{$number}", $request->getHeaderLine('x-apple-upload-part'));
        $this->assertSame((string) $length, $request->getHeaderLine('Content-Length'));
        $this->assertSame(substr($this->contents, $offset, $length), (string) $request->getBody());
    }

    /**
     * @return list<ResponseInterface>
     */
    private function fresh(): array
    {
        return [
            $this->reply('apps'),
            $this->reply('build-upload', 201),
            $this->reply('build-upload-file', 201),
            $this->stored(),
            $this->stored(),
            $this->reply('build-upload-file-committed'),
        ];
    }

    private function upload(): Upload
    {
        return new Upload(new Local($this->directory), $this->path, 'App.ipa', self::SIZE, self::BUNDLE_ID, self::VERSION, self::BUILD, Upload::PLATFORM_IOS);
    }

    private function store(Transport $transport): Frozen
    {
        return new Frozen(new Credential\Apple(self::KEY_ID, self::ISSUER_ID, self::$privateKey), $transport, self::NOW, self::ENDPOINT);
    }

    private function reply(string $fixture, int $status = 200): ResponseInterface
    {
        return Transport::fixture("Apple/{$fixture}", $status, ['X-Rate-Limit' => 'user-hour-lim:3500;user-hour-rem:3499;']);
    }

    private function stored(): ResponseInterface
    {
        return $this->page(200, '');
    }

    private function page(int $status, string $body): ResponseInterface
    {
        return new Response($status, body: new Stream\Factory()->createStream($body));
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
