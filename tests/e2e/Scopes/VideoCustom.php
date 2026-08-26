<?php

namespace Tests\E2E\Scopes;

use Tests\E2E\Client;
use Utopia\Database\Helpers\Permission;
use Utopia\Database\Helpers\Role;

/**
 * Fixtures shared by the Videos e2e suites: a bucket, a source video file and a
 * subtitle file.
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
    protected static array $subtitleFile = [];
    protected static array $videoFileWithSubtitles = [];
    protected static array $videoFileWithTwoSubtitles = [];
    protected static array $overrideSubtitleFile = [];

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
    public function getSubtitleFile(): array
    {
        if (!empty(self::$subtitleFile)) {
            return self::$subtitleFile;
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

        self::$subtitleFile = ['$id' => $file['body']['$id']];

        return self::$subtitleFile;
    }

    /**
     * Uploads the short MP4 that carries a soft `mov_text` English track with the
     * cue text `EMBEDDED CUE` (see `video-with-subs.mp4`).
     */
    public function getVideoFileWithSubtitles(): array
    {
        if (!empty(self::$videoFileWithSubtitles)) {
            return self::$videoFileWithSubtitles;
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

        self::$videoFileWithSubtitles = [
            '$id' => $file['body']['$id'],
            'sizeOriginal' => $file['body']['sizeOriginal'],
        ];

        return self::$videoFileWithSubtitles;
    }

    /**
     * Uploads the short MP4 with two soft `mov_text` tracks: English
     * (`EMBEDDED CUE EN`) and French (`EMBEDDED CUE FR`).
     */
    public function getVideoFileWithTwoSubtitles(): array
    {
        if (!empty(self::$videoFileWithTwoSubtitles)) {
            return self::$videoFileWithTwoSubtitles;
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

        self::$videoFileWithTwoSubtitles = [
            '$id' => $file['body']['$id'],
            'sizeOriginal' => $file['body']['sizeOriginal'],
        ];

        return self::$videoFileWithTwoSubtitles;
    }

    /**
     * Uploads the SubRip fixture whose single cue is `OVERRIDE CUE`.
     */
    public function getOverrideSubtitleFile(): array
    {
        if (!empty(self::$overrideSubtitleFile)) {
            return self::$overrideSubtitleFile;
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

        self::$overrideSubtitleFile = ['$id' => $file['body']['$id']];

        return self::$overrideSubtitleFile;
    }

    /**
     * Polls until at least one ready subtitle with an empty fileId appears
     * (auto-extracted from the source), or the timeout elapses.
     *
     * @return array<string, mixed>|null
     */
    public function waitForEmbeddedSubtitle(string $videoId, int $timeout = 300): ?array
    {
        $deadline = \time() + $timeout;

        while (\time() < $deadline) {
            $response = $this->client->call(Client::METHOD_GET, '/videos/' . $videoId . '/subtitles', \array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
            ], $this->getHeaders()));

            foreach ($response['body']['subtitles'] ?? [] as $subtitle) {
                $fileId = $subtitle['fileId'] ?? '';
                if (($subtitle['status'] ?? '') === 'ready' && ($fileId === null || $fileId === '')) {
                    return $subtitle;
                }
            }

            \usleep(500000);
        }

        return null;
    }

    /**
     * Polls until at least $count ready embedded subtitles (empty fileId) exist.
     *
     * @return list<array<string, mixed>>
     */
    public function waitForEmbeddedSubtitles(string $videoId, int $count = 1, int $timeout = 300): array
    {
        $deadline = \time() + $timeout;
        $embedded = [];

        while (\time() < $deadline) {
            $response = $this->client->call(Client::METHOD_GET, '/videos/' . $videoId . '/subtitles', \array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
            ], $this->getHeaders()));

            $embedded = [];
            foreach ($response['body']['subtitles'] ?? [] as $subtitle) {
                $fileId = $subtitle['fileId'] ?? '';
                if (($subtitle['status'] ?? '') === 'ready' && ($fileId === null || $fileId === '')) {
                    $embedded[] = $subtitle;
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
     * Polls a video until its source download leaves `waiting`/`started` and
     * settles on `ready` or `error`.
     */
    public function waitForVideoReady(string $videoId, int $timeout = 120): array
    {
        $pending = ['waiting', 'started'];
        $deadline = \time() + $timeout;
        $body = [];

        while (\time() < $deadline) {
            $response = $this->client->call(Client::METHOD_GET, '/videos/' . $videoId, \array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
            ], $this->getHeaders()));

            $body = $response['body'];

            if (!\in_array($body['status'] ?? '', $pending, true)) {
                return $body;
            }

            \usleep(500000);
        }

        return $body;
    }

    public function tmpSourcePath(string $videoId): string
    {
        $root = \defined('APP_STORAGE_VIDEOS_TMP') ? APP_STORAGE_VIDEOS_TMP : '/storage/videos-tmp';

        return \rtrim($root, '/') . '/app-' . $this->getProject()['$id'] . '/' . $videoId . '/source';
    }

    public function waitUntilTmpSourceExists(string $videoId, int $timeout = 60): string
    {
        $path = $this->tmpSourcePath($videoId);
        $deadline = \time() + $timeout;

        while (\time() < $deadline) {
            \clearstatcache(true, $path);
            if (\is_file($path)) {
                return $path;
            }
            \usleep(100000);
        }

        $this->fail(
            'Tmp source never appeared at ' . $path
            . '. Is appwrite-videos-tmp mounted on the appwrite container?'
        );

        return $path;
    }

    public function waitUntilTmpSourceGone(string $videoId, int $timeout = 60): void
    {
        $path = $this->tmpSourcePath($videoId);
        $deadline = \time() + $timeout;

        while (\time() < $deadline) {
            \clearstatcache(true, $path);
            if (!\is_file($path)) {
                return;
            }
            \usleep(100000);
        }

        $this->fail('Tmp source was still present at ' . $path . ' after ' . $timeout . 's');
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

    public function subtitleStoragePath(string $videoId, string $subtitleId): string
    {
        return $this->videoStoragePath($videoId, 'subtitles/' . $subtitleId . '.vtt');
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
     * Polls a rendition until it leaves the queue-side states (`waiting`,
     * `started`, `ended`, `uploading`) and settles on `ready` or `error`.
     *
     * Encoding a multi-megabyte source can take minutes, so the default timeout
     * is deliberately generous.
     */
    public function waitForRenditionTerminalState(string $videoId, string $renditionId, int $timeout = 300): array
    {
        $pending = ['waiting', 'started', 'ended', 'uploading'];
        $deadline = \time() + $timeout;
        $body = [];

        while (\time() < $deadline) {
            $response = $this->client->call(Client::METHOD_GET, '/videos/' . $videoId . '/renditions/' . $renditionId, \array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
            ], $this->getHeaders()));

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
     * Polls a subtitle until it leaves `waiting`/`started` and settles.
     */
    public function waitForSubtitleTerminalState(string $videoId, string $subtitleId, int $timeout = 120): array
    {
        $pending = ['waiting', 'started'];
        $deadline = \time() + $timeout;
        $body = [];

        while (\time() < $deadline) {
            $response = $this->client->call(Client::METHOD_GET, '/videos/' . $videoId . '/subtitles', \array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
            ], $this->getHeaders()));

            foreach ($response['body']['subtitles'] ?? [] as $subtitle) {
                if (($subtitle['$id'] ?? '') === $subtitleId) {
                    $body = $subtitle;
                    if (!\in_array($subtitle['status'] ?? '', $pending, true)) {
                        return $subtitle;
                    }
                    break;
                }
            }

            \usleep(500000);
        }

        return $body;
    }
}
