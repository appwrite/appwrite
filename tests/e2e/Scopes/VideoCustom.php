<?php

namespace Tests\E2E\Scopes;

use Tests\E2E\Client;
use Utopia\Database\Helpers\Permission;
use Utopia\Database\Helpers\Role;

/**
 * Fixtures shared by the Videos e2e suites: a bucket, a source video file and a
 * caption file.
 *
 * Each is uploaded once per test class and cached statically — the video is the
 * 23 MB `large-file.mp4` fixture and has to be chunk-uploaded, so re-uploading
 * per test would dominate the suite runtime.
 */
trait VideoCustom
{
    use ProjectCustom;

    protected static array $videoBucket = [];
    protected static array $videoFile = [];
    protected static array $captionFile = [];
    protected static array $videoFileWithCaptions = [];
    protected static array $videoFileWithUndeterminedCaptions = [];
    protected static array $videoFileWithTwoCaptions = [];
    protected static array $overrideCaptionFile = [];
    protected static array $audioOnlyFile = [];
    protected static array $invalidVideoFile = [];

    /**
     * Bucket holding the source media, readable by anyone so the client-side
     * suite can exercise access with a plain session.
     */
    public function getVideoBucket(): array
    {
        if (!empty(self::$videoBucket)) {
            return self::$videoBucket;
        }

        $bucket = $this->client->call(Client::METHOD_POST, '/storage/buckets', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'bucketId' => 'unique()',
            'name' => 'Videos source bucket',
            'fileSecurity' => false,
            'permissions' => [
                Permission::read(Role::any()),
                Permission::create(Role::any()),
                Permission::update(Role::any()),
                Permission::delete(Role::any()),
            ],
        ]);

        $this->assertEquals(201, $bucket['headers']['status-code']);

        self::$videoBucket = ['$id' => $bucket['body']['$id']];

        return self::$videoBucket;
    }

    /**
     * Chunk-uploads the source video. Mirrors the pattern in
     * `tests/e2e/Services/Storage/StorageBase.php`.
     */
    public function getVideoFile(): array
    {
        if (!empty(self::$videoFile)) {
            return self::$videoFile;
        }

        $file = $this->uploadVideoTo($this->getVideoBucket()['$id']);

        self::$videoFile = [
            '$id' => $file['$id'],
            'sizeOriginal' => $file['sizeOriginal'],
        ];

        return self::$videoFile;
    }

    /**
     * Chunk-uploads the source video into an arbitrary bucket, so tests can put
     * one in a bucket with different permissions.
     *
     * @param array<string> $permissions file-level permissions, when the bucket
     *                                   has fileSecurity enabled
     * @param array<string, string>|null $auth overrides the suite's auth headers,
     *                                         needed to upload into a bucket the
     *                                         current side cannot write to
     */
    public function uploadVideoTo(string $bucketId, array $permissions = [], ?array $auth = null): array
    {
        $source = __DIR__ . '/../../resources/disk-a/large-file.mp4';
        $chunkSize = 5 * 1024 * 1024;
        $size = \filesize($source);
        $mimeType = \mime_content_type($source);
        $handle = @\fopen($source, 'rb');
        $counter = 0;
        $id = '';
        $file = null;

        $headers = [
            'content-type' => 'multipart/form-data',
            'x-appwrite-project' => $this->getProject()['$id'],
        ];

        while (!\feof($handle)) {
            $curlFile = new \CURLFile('data://' . $mimeType . ';base64,' . \base64_encode(@\fread($handle, $chunkSize)), $mimeType, 'large-file.mp4');
            $headers['content-range'] = 'bytes ' . ($counter * $chunkSize) . '-' . \min((($counter * $chunkSize) + $chunkSize) - 1, $size - 1) . '/' . $size;

            if (!empty($id)) {
                $headers['x-appwrite-id'] = $id;
            }

            $params = [
                'fileId' => $counter === 0 ? 'unique()' : $id,
                'file' => $curlFile,
            ];

            if (!empty($permissions)) {
                $params['permissions'] = $permissions;
            }

            $file = $this->client->call(Client::METHOD_POST, '/storage/buckets/' . $bucketId . '/files', \array_merge($headers, $auth ?? $this->getHeaders()), $params);

            $counter++;
            $id = $file['body']['$id'] ?? '';
        }

        @\fclose($handle);

        $this->assertEquals(201, $file['headers']['status-code']);
        $this->assertEquals('video/mp4', $file['body']['mimeType']);

        return [
            '$id' => $file['body']['$id'],
            'sizeOriginal' => $file['body']['sizeOriginal'],
        ];
    }

    /**
     * Uploads the SubRip fixture. Sent as `text/plain`, which is what the mime
     * detector reports for `.srt`.
     */
    public function getCaptionFile(): array
    {
        if (!empty(self::$captionFile)) {
            return self::$captionFile;
        }

        $source = \realpath(__DIR__ . '/../../resources/disk-a/video-srt.srt');

        $file = $this->client->call(Client::METHOD_POST, '/storage/buckets/' . $this->getVideoBucket()['$id'] . '/files', \array_merge([
            'content-type' => 'multipart/form-data',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'fileId' => 'unique()',
            'file' => new \CURLFile($source, 'text/plain', 'video-srt.srt'),
            'permissions' => [
                Permission::read(Role::any()),
            ],
        ]);

        $this->assertEquals(201, $file['headers']['status-code']);

        self::$captionFile = ['$id' => $file['body']['$id']];

        return self::$captionFile;
    }

    /**
     * Uploads the audio-only AAC fixture used to prove timeline create rejects
     * sources with no video track.
     */
    public function getAudioOnlyFile(): array
    {
        if (!empty(self::$audioOnlyFile)) {
            return self::$audioOnlyFile;
        }

        $source = \realpath(__DIR__ . '/../../resources/disk-a/audio-only.m4a');
        $this->assertNotFalse($source);

        $file = $this->client->call(Client::METHOD_POST, '/storage/buckets/' . $this->getVideoBucket()['$id'] . '/files', \array_merge([
            'content-type' => 'multipart/form-data',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'fileId' => 'unique()',
            'file' => new \CURLFile($source, 'audio/mp4', 'audio-only.m4a'),
            'permissions' => [
                Permission::read(Role::any()),
            ],
        ]);

        $this->assertEquals(201, $file['headers']['status-code']);

        self::$audioOnlyFile = [
            '$id' => $file['body']['$id'],
            'sizeOriginal' => $file['body']['sizeOriginal'],
        ];

        return self::$audioOnlyFile;
    }

    /**
     * Uploads a file with a video MIME type that is not a valid media container,
     * so create succeeds and the download worker settles on `error`.
     */
    public function getInvalidVideoFile(): array
    {
        if (!empty(self::$invalidVideoFile)) {
            return self::$invalidVideoFile;
        }

        $source = \realpath(__DIR__ . '/../../resources/disk-a/not-a-video.mp4');
        $this->assertNotFalse($source);

        $file = $this->client->call(Client::METHOD_POST, '/storage/buckets/' . $this->getVideoBucket()['$id'] . '/files', \array_merge([
            'content-type' => 'multipart/form-data',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'fileId' => 'unique()',
            'file' => new \CURLFile($source, 'video/mp4', 'not-a-video.mp4'),
            'permissions' => [
                Permission::read(Role::any()),
            ],
        ]);

        $this->assertEquals(201, $file['headers']['status-code']);

        self::$invalidVideoFile = [
            '$id' => $file['body']['$id'],
            'sizeOriginal' => $file['body']['sizeOriginal'],
        ];

        return self::$invalidVideoFile;
    }

    /**
     * Uploads the short MP4 that carries a soft `mov_text` English track with the
     * cue text `EMBEDDED CUE` (see `video-with-subs.mp4`).
     */
    public function getVideoFileWithCaptions(): array
    {
        if (!empty(self::$videoFileWithCaptions)) {
            return self::$videoFileWithCaptions;
        }

        $source = \realpath(__DIR__ . '/../../resources/disk-a/video-with-subs.mp4');
        $this->assertNotFalse($source);

        $file = $this->client->call(Client::METHOD_POST, '/storage/buckets/' . $this->getVideoBucket()['$id'] . '/files', \array_merge([
            'content-type' => 'multipart/form-data',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'fileId' => 'unique()',
            'file' => new \CURLFile($source, 'video/mp4', 'video-with-subs.mp4'),
            'permissions' => [
                Permission::read(Role::any()),
            ],
        ]);

        $this->assertEquals(201, $file['headers']['status-code']);

        self::$videoFileWithCaptions = [
            '$id' => $file['body']['$id'],
            'sizeOriginal' => $file['body']['sizeOriginal'],
        ];

        return self::$videoFileWithCaptions;
    }

    /**
     * Uploads the short MP4 whose `mov_text` track is tagged `und` (no real
     * language in the container), so extract stores `code=und`.
     */
    public function getVideoFileWithUndeterminedCaptions(): array
    {
        if (!empty(self::$videoFileWithUndeterminedCaptions)) {
            return self::$videoFileWithUndeterminedCaptions;
        }

        $source = \realpath(__DIR__ . '/../../resources/disk-a/video-with-und-subs.mp4');
        $this->assertNotFalse($source);

        $file = $this->client->call(Client::METHOD_POST, '/storage/buckets/' . $this->getVideoBucket()['$id'] . '/files', \array_merge([
            'content-type' => 'multipart/form-data',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'fileId' => 'unique()',
            'file' => new \CURLFile($source, 'video/mp4', 'video-with-und-subs.mp4'),
            'permissions' => [
                Permission::read(Role::any()),
            ],
        ]);

        $this->assertEquals(201, $file['headers']['status-code']);

        self::$videoFileWithUndeterminedCaptions = [
            '$id' => $file['body']['$id'],
            'sizeOriginal' => $file['body']['sizeOriginal'],
        ];

        return self::$videoFileWithUndeterminedCaptions;
    }

    /**
     * Uploads the short MP4 with two soft `mov_text` tracks: English
     * (`EMBEDDED CUE EN`) and French (`EMBEDDED CUE FR`).
     */
    public function getVideoFileWithTwoCaptions(): array
    {
        if (!empty(self::$videoFileWithTwoCaptions)) {
            return self::$videoFileWithTwoCaptions;
        }

        $source = \realpath(__DIR__ . '/../../resources/disk-a/video-with-2-subs.mp4');
        $this->assertNotFalse($source);

        $file = $this->client->call(Client::METHOD_POST, '/storage/buckets/' . $this->getVideoBucket()['$id'] . '/files', \array_merge([
            'content-type' => 'multipart/form-data',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'fileId' => 'unique()',
            'file' => new \CURLFile($source, 'video/mp4', 'video-with-2-subs.mp4'),
            'permissions' => [
                Permission::read(Role::any()),
            ],
        ]);

        $this->assertEquals(201, $file['headers']['status-code']);

        self::$videoFileWithTwoCaptions = [
            '$id' => $file['body']['$id'],
            'sizeOriginal' => $file['body']['sizeOriginal'],
        ];

        return self::$videoFileWithTwoCaptions;
    }

    /**
     * Uploads the SubRip fixture whose single cue is `OVERRIDE CUE`.
     */
    public function getOverrideCaptionFile(): array
    {
        if (!empty(self::$overrideCaptionFile)) {
            return self::$overrideCaptionFile;
        }

        $source = \realpath(__DIR__ . '/../../resources/disk-a/video-override.srt');
        $this->assertNotFalse($source);

        $file = $this->client->call(Client::METHOD_POST, '/storage/buckets/' . $this->getVideoBucket()['$id'] . '/files', \array_merge([
            'content-type' => 'multipart/form-data',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'fileId' => 'unique()',
            'file' => new \CURLFile($source, 'text/plain', 'video-override.srt'),
            'permissions' => [
                Permission::read(Role::any()),
            ],
        ]);

        $this->assertEquals(201, $file['headers']['status-code']);

        self::$overrideCaptionFile = ['$id' => $file['body']['$id']];

        return self::$overrideCaptionFile;
    }

    /**
     * Polls until at least one ready caption with an empty fileId appears
     * (auto-extracted from the source), or the timeout elapses.
     *
     * @return array<string, mixed>|null
     */
    public function waitForEmbeddedCaption(string $videoId, int $timeout = 300): ?array
    {
        $deadline = \time() + $timeout;

        while (\time() < $deadline) {
            $response = $this->client->call(Client::METHOD_GET, '/videos/' . $videoId . '/captions', \array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
            ], $this->getHeaders()));

            foreach ($response['body']['captions'] ?? [] as $caption) {
                $fileId = $caption['fileId'] ?? '';
                if (($caption['status'] ?? '') === 'ready' && ($fileId === null || $fileId === '')) {
                    return $caption;
                }
            }

            \usleep(500000);
        }

        return null;
    }

    /**
     * Polls until at least $count ready embedded captions (empty fileId) exist.
     *
     * @return list<array<string, mixed>>
     */
    public function waitForEmbeddedCaptions(string $videoId, int $count = 1, int $timeout = 300): array
    {
        $deadline = \time() + $timeout;
        $embedded = [];

        while (\time() < $deadline) {
            $response = $this->client->call(Client::METHOD_GET, '/videos/' . $videoId . '/captions', \array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
            ], $this->getHeaders()));

            $embedded = [];
            foreach ($response['body']['captions'] ?? [] as $caption) {
                $fileId = $caption['fileId'] ?? '';
                if (($caption['status'] ?? '') === 'ready' && ($fileId === null || $fileId === '')) {
                    $embedded[] = $caption;
                }
            }

            if (\count($embedded) >= $count) {
                return $embedded;
            }

            \usleep(500000);
        }

        return $embedded;
    }

    /**
     * Create a video and wait until the timeline job has probed its metadata
     * (duration > 0). Creating a video queues that job, so callers do not
     * enqueue it themselves.
     *
     * @return array<string, mixed> video document after probe
     */
    public function createReadyVideo(?array $file = null, string $name = '', array $headers = []): array
    {
        $file ??= $this->getVideoFile();
        $payload = [
            'bucketId' => $this->getVideoBucket()['$id'],
            'fileId' => $file['$id'],
        ];
        if ($name !== '') {
            $payload['name'] = $name;
        }

        $create = $this->client->call(Client::METHOD_POST, '/videos', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $headers !== [] ? $headers : $this->getHeaders()), $payload);

        $this->assertEquals(201, $create['headers']['status-code']);
        $videoId = $create['body']['$id'];
        $this->assertArrayNotHasKey('status', $create['body']);
        $this->assertSame(0, (int) ($create['body']['duration'] ?? 0));

        $ready = $this->waitForVideoProbed($videoId);
        $this->assertGreaterThan(0, (int) $ready['duration'], 'Video was not probed');

        return $ready;
    }

    /**
     * Poll until the video document has probed duration (written by the first
     * rendition or timeline job).
     */
    public function waitForVideoProbed(string $videoId, int $timeout = 120): array
    {
        $deadline = \time() + $timeout;
        $body = [];

        while (\time() < $deadline) {
            $response = $this->client->call(Client::METHOD_GET, '/videos/' . $videoId, [
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
                'x-appwrite-key' => $this->getProject()['apiKey'],
            ]);

            $body = $response['body'];

            if ((int) ($body['duration'] ?? 0) > 0) {
                return $body;
            }

            \usleep(500000);
        }

        return $body;
    }

    public function tmpJobPath(string $videoId, string $renditionId): string
    {
        $root = \defined('APP_STORAGE_VIDEOS_TMP') ? APP_STORAGE_VIDEOS_TMP : '/storage/videos-tmp';

        return \rtrim($root, '/')
            . '/app-' . $this->getProject()['$id']
            . '/' . $videoId
            . '/jobs/' . $renditionId;
    }

    public function tmpSourcePath(string $videoId): string
    {
        $root = \defined('APP_STORAGE_VIDEOS_TMP') ? APP_STORAGE_VIDEOS_TMP : '/storage/videos-tmp';

        return \rtrim($root, '/') . '/app-' . $this->getProject()['$id'] . '/' . $videoId . '/source';
    }

    public function videoStoragePath(string $videoId, string $suffix = ''): string
    {
        $root = \defined('APP_STORAGE_VIDEOS') ? APP_STORAGE_VIDEOS : '/storage/videos';
        $path = \rtrim($root, '/') . '/app-' . $this->getProject()['$id'] . '/' . $videoId;

        if ($suffix !== '') {
            $path .= '/' . \ltrim($suffix, '/');
        }

        return $path;
    }

    public function renditionStoragePath(string $videoId, string $name, string $renditionId): string
    {
        return $this->videoStoragePath($videoId, $name . '-' . $renditionId);
    }

    public function captionStoragePath(string $videoId, string $captionId): string
    {
        return $this->videoStoragePath($videoId, 'captions/' . $captionId . '.vtt');
    }

    public function waitUntilPathExists(string $path, int $timeout = 30): void
    {
        $deadline = \time() + $timeout;
        $normalized = \rtrim($path, '/');

        while (\time() < $deadline) {
            \clearstatcache(true, $normalized);
            if (\is_file($normalized) || \is_dir($normalized)) {
                return;
            }
            \usleep(100000);
        }

        $this->fail('Path never appeared: ' . $path);
    }

    public function waitUntilPathGone(string $path, int $timeout = 60): void
    {
        $deadline = \time() + $timeout;
        $normalized = \rtrim($path, '/');

        while (\time() < $deadline) {
            \clearstatcache(true, $normalized);
            if (!\is_file($normalized) && !\is_dir($normalized)) {
                return;
            }
            \usleep(100000);
        }

        $this->fail('Path still present: ' . $path);
    }

    /**
     * @return list<string>
     */
    public function timelinePreviewIds(string $vtt): array
    {
        \preg_match_all('#previews/([A-Za-z0-9]+)#', $vtt, $matches);

        return $matches[1] ?? [];
    }

    /**
     * Polls until the sprite timeline exists and its preview ids differ from
     * `$previousPreviewIds`, so a source update is not mistaken for the old sheet.
     */
    public function waitForTimelineRegenerated(string $videoId, array $previousPreviewIds, int $timeout = 300): array
    {
        $deadline = \time() + $timeout;
        $response = [];

        while (\time() < $deadline) {
            $response = $this->client->call(Client::METHOD_GET, '/videos/' . $videoId . '/timeline', \array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
            ], $this->getHeaders()));

            if (($response['headers']['status-code'] ?? 0) === 200) {
                $ids = $this->timelinePreviewIds((string) $response['body']);
                if ($ids !== [] && $ids !== $previousPreviewIds) {
                    return $response;
                }
            }

            \usleep(500000);
        }

        $this->fail('Timeline did not regenerate for video ' . $videoId);
    }

    /**
     * Polls a rendition until it leaves the queue-side states (`pending`,
     * `started`, `ended`, `uploading`) and settles on `ready` or `error`.
     *
     * Encoding a multi-megabyte source can take minutes, so the default timeout
     * is deliberately generous.
     */
    public function waitForRenditionTerminalState(string $videoId, string $renditionId, int $timeout = 300): array
    {
        $pending = ['pending', 'started', 'ended', 'uploading'];
        $deadline = \time() + $timeout;
        $body = [];

        while (\time() < $deadline) {
            $response = $this->client->call(Client::METHOD_GET, '/videos/' . $videoId . '/renditions/' . $renditionId, [
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
                'x-appwrite-key' => $this->getProject()['apiKey'],
            ]);

            $body = $response['body'];

            if (!\in_array($body['status'] ?? '', $pending, true)) {
                return $body;
            }

            \usleep(500000);
        }

        return $body;
    }

    /**
     * Polls until the sprite timeline WebVTT is available for a video.
     */
    public function waitForTimeline(string $videoId, int $timeout = 300): array
    {
        $deadline = \time() + $timeout;
        $response = [];

        while (\time() < $deadline) {
            $response = $this->client->call(Client::METHOD_GET, '/videos/' . $videoId . '/timeline', \array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
            ], $this->getHeaders()));

            if (($response['headers']['status-code'] ?? 0) === 200) {
                return $response;
            }

            \usleep(500000);
        }

        return $response;
    }

    /**
     * Polls a caption until it leaves `pending`/`started` and settles.
     */
    public function waitForCaptionTerminalState(string $videoId, string $captionId, int $timeout = 120): array
    {
        $pending = ['pending', 'started'];
        $deadline = \time() + $timeout;
        $body = [];

        while (\time() < $deadline) {
            $response = $this->client->call(Client::METHOD_GET, '/videos/' . $videoId . '/captions', \array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
            ], $this->getHeaders()));

            foreach ($response['body']['captions'] ?? [] as $caption) {
                if (($caption['$id'] ?? '') === $captionId) {
                    $body = $caption;
                    if (!\in_array($caption['status'] ?? '', $pending, true)) {
                        return $caption;
                    }
                    break;
                }
            }

            \usleep(500000);
        }

        return $body;
    }
}
