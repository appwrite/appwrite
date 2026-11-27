<?php

namespace Tests\E2E\Services\Distribution;

use Appwrite\ID;
use Tests\E2E\Client;
use Utopia\Database\Document;
use Utopia\Database\Query;
use Utopia\Distribution\Signing\Keystore;

trait ArtifactsBase
{
    private const string FIXTURE = __DIR__ . '/../../../resources/distribution/app-unsigned.aab';

    private const string IDENTIFIER = 'io.appwrite.distribution.fixture';

    private const string ALIAS = 'upload';

    private const int CHUNK = 5 * 1024 * 1024;

    private const int OUTPUT_CHUNK = 10 * 1024 * 1024;

    private const int RETENTION = 89 * 86400;

    private const int INSPECTION = 180_000;

    public function testCreateArtifact(): void
    {
        $app = $this->createApp();
        $this->assertSame(201, $app['headers']['status-code']);
        $appId = $app['body']['$id'];
        $bundle = $this->fixture();

        /**
         * Test for SUCCESS
         */
        $created = $this->uploadArtifact($appId, $bundle, 'app-unsigned.aab')[0];

        $this->assertSame(202, $created['headers']['status-code']);
        $this->assertNotEmpty($created['body']['$id']);
        $this->assertSame($appId, $created['body']['appId']);
        $this->assertSame('upload', $created['body']['source']);
        $this->assertSame('aab', $created['body']['type']);
        $this->assertSame('processing', $created['body']['status']);
        $this->assertSame('app-unsigned.aab', $created['body']['name']);
        $this->assertSame(\strlen($bundle), $created['body']['size']);
        $this->assertSame(1, $created['body']['chunksTotal']);
        $this->assertSame(1, $created['body']['chunksUploaded']);
        $this->assertSame('', $created['body']['buildId']);
        $this->assertSame('', $created['body']['targetId']);
        $this->assertSame('', $created['body']['digest']);
        $this->assertFalse($created['body']['signed']);
        $this->assertSame('', $created['body']['errorCode']);
        $this->assertGreaterThan(\time() + self::RETENTION, \strtotime($created['body']['expiresAt']));

        $ipa = $this->uploadArtifact($appId, \random_bytes(2048), 'app.ipa')[0];
        $this->assertSame(202, $ipa['headers']['status-code']);
        $this->assertSame('ipa', $ipa['body']['type']);
        $this->assertSame('processing', $ipa['body']['status']);

        /**
         * Test for FAILURE
         */
        $extension = $this->createArtifact($appId, $bundle, 'app.zip');
        $this->assertSame(400, $extension['headers']['status-code']);
        $this->assertSame('storage_file_type_unsupported', $extension['body']['type']);

        $oversized = $this->createArtifact($appId, $bundle, 'app.aab', [
            'content-range' => 'bytes 0-' . (\strlen($bundle) - 1) . '/4000000001',
        ]);
        $this->assertSame(400, $oversized['headers']['status-code']);
        $this->assertSame('storage_invalid_file_size', $oversized['body']['type']);

        $range = $this->createArtifact($appId, $bundle, 'app.aab', [
            'content-range' => 'bytes 0-' . \strlen($bundle) . '/' . (\strlen($bundle) - 1),
        ]);
        $this->assertSame(400, $range['headers']['status-code']);
        $this->assertSame('storage_invalid_content_range', $range['body']['type']);

        $truncatedId = ID::unique();
        $truncated = $this->createArtifact($appId, $bundle, 'app.aab', [
            'content-range' => 'bytes 0-' . (\strlen($bundle) - 1) . '/' . (\strlen($bundle) + 20),
            'x-appwrite-id' => $truncatedId,
        ]);
        $this->assertSame(400, $truncated['headers']['status-code']);
        $this->assertSame('storage_invalid_content_range', $truncated['body']['type']);

        $refused = $this->getArtifact($appId, $truncatedId);
        $this->assertSame(200, $refused['headers']['status-code']);
        $this->assertSame('invalid', $refused['body']['status']);

        $empty = $this->client->call(Client::METHOD_POST, '/distribution/apps/' . $appId . '/artifacts', \array_merge($this->headers(), [
            'content-type' => 'multipart/form-data',
        ]), []);
        $this->assertSame(400, $empty['headers']['status-code']);

        $missing = $this->createArtifact(ID::unique(), $bundle, 'app.aab');
        $this->assertSame(404, $missing['headers']['status-code']);
        $this->assertSame('distribution_app_not_found', $missing['body']['type']);
    }

    public function testCreateArtifactInChunks(): void
    {
        $app = $this->createApp();
        $this->assertSame(201, $app['headers']['status-code']);
        $appId = $app['body']['$id'];
        $content = \random_bytes(2 * self::CHUNK + 1024 * 1024);

        /**
         * Test for SUCCESS
         */
        $chunks = $this->uploadArtifact($appId, $content, 'chunked.aab');
        $this->assertCount(3, $chunks);
        $artifactId = $chunks[0]['body']['$id'];
        $this->assertNotEmpty($artifactId);

        foreach ($chunks as $index => $chunk) {
            $this->assertSame(202, $chunk['headers']['status-code']);
            $this->assertSame($artifactId, $chunk['body']['$id']);
            $this->assertSame(3, $chunk['body']['chunksTotal']);
            $this->assertSame($index + 1, $chunk['body']['chunksUploaded']);
            $this->assertSame(\strlen($content), $chunk['body']['size']);
        }

        $this->assertSame('uploading', $chunks[0]['body']['status']);
        $this->assertSame('uploading', $chunks[1]['body']['status']);
        $this->assertSame('processing', $chunks[2]['body']['status']);

        $artifact = $this->getArtifact($appId, $artifactId);
        $this->assertSame(200, $artifact['headers']['status-code']);
        $this->assertSame(3, $artifact['body']['chunksUploaded']);
        $this->assertSame(\strlen($content), $artifact['body']['size']);

        $download = $this->downloadArtifact($appId, $artifactId);
        $this->assertSame(200, $download['headers']['status-code']);
        $this->assertSame(\md5($content), \md5($download['body']));

        $cut = $this->downloadArtifact($appId, $artifactId, 'bytes=0-');
        $this->assertSame(206, $cut['headers']['status-code']);
        $this->assertSame('bytes 0-' . (self::OUTPUT_CHUNK - 1) . '/' . \strlen($content), $cut['headers']['content-range']);
        $this->assertSame(self::OUTPUT_CHUNK, \strlen($cut['body']));
        $this->assertSame(\md5(\substr($content, 0, self::OUTPUT_CHUNK)), \md5($cut['body']));

        $boundary = self::CHUNK - 10;
        $span = $this->downloadArtifact($appId, $artifactId, 'bytes=' . $boundary . '-' . ($boundary + 19));
        $this->assertSame(206, $span['headers']['status-code']);
        $this->assertSame(\substr($content, $boundary, 20), $span['body']);

        /**
         * Test for FAILURE
         */
        $malformed = $this->createArtifact($appId, \substr($content, 0, self::CHUNK), 'chunked.aab', [
            'content-range' => 'bytes 0-' . (self::CHUNK - 1) . '/' . \strlen($content),
            'x-appwrite-id' => '!invalid',
        ]);
        $this->assertSame(400, $malformed['headers']['status-code']);
        $this->assertSame('storage_invalid_appwrite_id', $malformed['body']['type']);
    }

    public function testListArtifacts(): void
    {
        $app = $this->createApp();
        $this->assertSame(201, $app['headers']['status-code']);
        $appId = $app['body']['$id'];
        $bundle = $this->fixture();

        $first = $this->uploadArtifact($appId, $bundle, 'first.aab')[0];
        $second = $this->uploadArtifact($appId, $bundle, 'second.aab')[0];
        $this->assertSame(202, $first['headers']['status-code']);
        $this->assertSame(202, $second['headers']['status-code']);

        $other = $this->createApp();
        $this->assertSame(201, $other['headers']['status-code']);
        $foreign = $this->uploadArtifact($other['body']['$id'], $bundle, 'foreign.aab')[0];
        $this->assertSame(202, $foreign['headers']['status-code']);

        /**
         * Test for SUCCESS
         */
        $artifacts = $this->listArtifacts($appId);
        $this->assertSame(200, $artifacts['headers']['status-code']);
        $this->assertSame(2, $artifacts['body']['total']);
        $ids = \array_column($artifacts['body']['artifacts'], '$id');
        $this->assertCount(2, $ids);
        $this->assertContains($first['body']['$id'], $ids);
        $this->assertContains($second['body']['$id'], $ids);
        $this->assertNotContains($foreign['body']['$id'], $ids);

        $filtered = $this->listArtifacts($appId, [Query::equal('name', ['second.aab'])->toString()]);
        $this->assertSame(200, $filtered['headers']['status-code']);
        $this->assertSame(1, $filtered['body']['total']);
        $this->assertSame([$second['body']['$id']], \array_column($filtered['body']['artifacts'], '$id'));

        $paged = $this->listArtifacts($appId, [
            Query::orderAsc('$sequence')->toString(),
            Query::cursorAfter(new Document(['$id' => $first['body']['$id']]))->toString(),
        ]);
        $this->assertSame(200, $paged['headers']['status-code']);
        $this->assertSame([$second['body']['$id']], \array_column($paged['body']['artifacts'], '$id'));

        $untotaled = $this->client->call(Client::METHOD_GET, '/distribution/apps/' . $appId . '/artifacts', $this->headers(), [
            'total' => false,
        ]);
        $this->assertSame(200, $untotaled['headers']['status-code']);
        $this->assertSame(0, $untotaled['body']['total']);
        $this->assertCount(2, $untotaled['body']['artifacts']);

        /**
         * Test for FAILURE
         */
        $unknown = $this->listArtifacts($appId, [Query::equal('appInternalId', ['1'])->toString()]);
        $this->assertSame(400, $unknown['headers']['status-code']);

        $cursor = $this->listArtifacts($appId, [Query::cursorAfter(new Document(['$id' => $foreign['body']['$id']]))->toString()]);
        $this->assertSame(400, $cursor['headers']['status-code']);
        $this->assertSame('general_cursor_not_found', $cursor['body']['type']);

        $missing = $this->listArtifacts(ID::unique());
        $this->assertSame(404, $missing['headers']['status-code']);
        $this->assertSame('distribution_app_not_found', $missing['body']['type']);
    }

    public function testGetArtifact(): void
    {
        $app = $this->createApp();
        $this->assertSame(201, $app['headers']['status-code']);
        $appId = $app['body']['$id'];
        $bundle = $this->fixture();
        $created = $this->uploadArtifact($appId, $bundle, 'app-unsigned.aab')[0];
        $this->assertSame(202, $created['headers']['status-code']);
        $artifactId = $created['body']['$id'];

        /**
         * Test for SUCCESS
         */
        $artifact = $this->getArtifact($appId, $artifactId);
        $this->assertSame(200, $artifact['headers']['status-code']);
        $this->assertSame($artifactId, $artifact['body']['$id']);
        $this->assertSame($appId, $artifact['body']['appId']);
        $this->assertSame('app-unsigned.aab', $artifact['body']['name']);
        $this->assertSame('aab', $artifact['body']['type']);
        $this->assertSame('upload', $artifact['body']['source']);
        $this->assertSame(\strlen($bundle), $artifact['body']['size']);
        $this->assertSame($created['body']['expiresAt'], $artifact['body']['expiresAt']);
        $this->assertArrayNotHasKey('metadata', $artifact['body']);
        $this->assertArrayNotHasKey('path', $artifact['body']);

        /**
         * Test for FAILURE
         */
        $other = $this->createApp();
        $this->assertSame(201, $other['headers']['status-code']);

        $foreign = $this->getArtifact($other['body']['$id'], $artifactId);
        $this->assertSame(404, $foreign['headers']['status-code']);
        $this->assertSame('distribution_artifact_not_found', $foreign['body']['type']);

        $missing = $this->getArtifact($appId, ID::unique());
        $this->assertSame(404, $missing['headers']['status-code']);
        $this->assertSame('distribution_artifact_not_found', $missing['body']['type']);

        $app = $this->getArtifact(ID::unique(), $artifactId);
        $this->assertSame(404, $app['headers']['status-code']);
        $this->assertSame('distribution_app_not_found', $app['body']['type']);
    }

    public function testGetArtifactDownload(): void
    {
        $app = $this->createApp();
        $this->assertSame(201, $app['headers']['status-code']);
        $appId = $app['body']['$id'];
        $bundle = $this->fixture();
        $size = \strlen($bundle);
        $created = $this->uploadArtifact($appId, $bundle, 'app-unsigned.aab')[0];
        $this->assertSame(202, $created['headers']['status-code']);
        $artifactId = $created['body']['$id'];

        /**
         * Test for SUCCESS
         */
        $download = $this->downloadArtifact($appId, $artifactId);
        $this->assertSame(200, $download['headers']['status-code']);
        $this->assertSame($bundle, $download['body']);
        $this->assertStringContainsString('attachment', $download['headers']['content-disposition']);
        $this->assertStringContainsString('app-unsigned.aab', $download['headers']['content-disposition']);

        $head = $this->downloadArtifact($appId, $artifactId, 'bytes=0-99');
        $this->assertSame(206, $head['headers']['status-code']);
        $this->assertSame('bytes 0-99/' . $size, $head['headers']['content-range']);
        $this->assertSame(\substr($bundle, 0, 100), $head['body']);

        $tail = $this->downloadArtifact($appId, $artifactId, 'bytes=800-');
        $this->assertSame(206, $tail['headers']['status-code']);
        $this->assertSame('bytes 800-' . ($size - 1) . '/' . $size, $tail['headers']['content-range']);
        $this->assertSame(\substr($bundle, 800), $tail['body']);

        $clamped = $this->downloadArtifact($appId, $artifactId, 'bytes=0-' . ($size + 1000));
        $this->assertSame(206, $clamped['headers']['status-code']);
        $this->assertSame('bytes 0-' . ($size - 1) . '/' . $size, $clamped['headers']['content-range']);
        $this->assertSame($bundle, $clamped['body']);

        /**
         * Test for FAILURE
         */
        $beyond = $this->downloadArtifact($appId, $artifactId, 'bytes=' . ($size + 10) . '-');
        $this->assertSame(416, $beyond['headers']['status-code']);
        $this->assertSame('storage_invalid_range', $beyond['body']['type']);

        $other = $this->createApp();
        $this->assertSame(201, $other['headers']['status-code']);

        $foreign = $this->downloadArtifact($other['body']['$id'], $artifactId);
        $this->assertSame(404, $foreign['headers']['status-code']);
        $this->assertSame('distribution_artifact_not_found', $foreign['body']['type']);

        $missing = $this->downloadArtifact($appId, ID::unique());
        $this->assertSame(404, $missing['headers']['status-code']);
        $this->assertSame('distribution_artifact_not_found', $missing['body']['type']);
    }

    public function testDeleteArtifact(): void
    {
        $app = $this->createApp();
        $this->assertSame(201, $app['headers']['status-code']);
        $appId = $app['body']['$id'];
        $bundle = $this->fixture();
        $created = $this->uploadArtifact($appId, $bundle, 'app-unsigned.aab')[0];
        $kept = $this->uploadArtifact($appId, $bundle, 'kept.aab')[0];
        $this->assertSame(202, $created['headers']['status-code']);
        $this->assertSame(202, $kept['headers']['status-code']);
        $artifactId = $created['body']['$id'];

        /**
         * Test for SUCCESS
         */
        $deleted = $this->deleteArtifact($appId, $artifactId);
        $this->assertSame(204, $deleted['headers']['status-code']);
        $this->assertEmpty($deleted['body']);

        $artifact = $this->getArtifact($appId, $artifactId);
        $this->assertSame(404, $artifact['headers']['status-code']);
        $this->assertSame('distribution_artifact_not_found', $artifact['body']['type']);

        $download = $this->downloadArtifact($appId, $artifactId);
        $this->assertSame(404, $download['headers']['status-code']);

        $listed = $this->listArtifacts($appId);
        $this->assertSame(200, $listed['headers']['status-code']);
        $this->assertSame([$kept['body']['$id']], \array_column($listed['body']['artifacts'], '$id'));

        /**
         * Test for FAILURE
         */
        $again = $this->deleteArtifact($appId, $artifactId);
        $this->assertSame(404, $again['headers']['status-code']);
        $this->assertSame('distribution_artifact_not_found', $again['body']['type']);

        $missing = $this->deleteArtifact(ID::unique(), $kept['body']['$id']);
        $this->assertSame(404, $missing['headers']['status-code']);
        $this->assertSame('distribution_app_not_found', $missing['body']['type']);

        $this->assertSame(200, $this->getArtifact($appId, $kept['body']['$id'])['headers']['status-code']);
    }

    public function testInspectUploadedBundle(): void
    {
        $app = $this->createApp();
        $this->assertSame(201, $app['headers']['status-code']);
        $appId = $app['body']['$id'];
        $target = $this->createTarget($appId, ['platform' => 'android', 'identifier' => self::IDENTIFIER]);
        $this->assertSame(201, $target['headers']['status-code']);
        $bundle = $this->fixture();
        $created = $this->uploadArtifact($appId, $bundle, 'app-unsigned.aab')[0];
        $this->assertSame(202, $created['headers']['status-code']);

        /**
         * Test for SUCCESS
         */
        $artifact = $this->awaitArtifact($appId, $created['body']['$id']);
        $this->assertSame('ready', $artifact['status']);
        $this->assertSame('', $artifact['errorCode']);
        $this->assertSame($target['body']['$id'], $artifact['targetId']);
        $this->assertSame('android', $artifact['platform']);
        $this->assertSame(self::IDENTIFIER, $artifact['identifier']);
        $this->assertSame('1.0.0', $artifact['versionName']);
        $this->assertSame(1, $artifact['versionCode']);
        $this->assertFalse($artifact['signed']);
        $this->assertSame('', $artifact['fingerprint']);
        $this->assertSame(\hash('sha256', $bundle), $artifact['digest']);
        $this->assertSame(\strlen($bundle), $artifact['size']);

        $download = $this->downloadArtifact($appId, $artifact['$id']);
        $this->assertSame(200, $download['headers']['status-code']);
        $this->assertSame($bundle, $download['body']);
    }

    public function testSignUploadedBundle(): void
    {
        $keystore = Keystore::generate(self::ALIAS, ['CN' => 'Appwrite Artifacts']);
        $identity = $this->createKeystoreIdentity($keystore);
        $this->assertSame(201, $identity['headers']['status-code']);

        $app = $this->createApp();
        $this->assertSame(201, $app['headers']['status-code']);
        $appId = $app['body']['$id'];
        $target = $this->createTarget($appId, [
            'platform' => 'android',
            'identifier' => self::IDENTIFIER,
            'signingIdentityId' => $identity['body']['$id'],
        ]);
        $this->assertSame(201, $target['headers']['status-code']);
        $bundle = $this->fixture();
        $created = $this->uploadArtifact($appId, $bundle, 'app-unsigned.aab')[0];
        $this->assertSame(202, $created['headers']['status-code']);
        $this->assertFalse($created['body']['signed']);

        /**
         * Test for SUCCESS
         */
        $signed = $this->awaitArtifact($appId, $created['body']['$id']);
        $this->assertSame('ready', $signed['status']);
        $this->assertSame('', $signed['errorCode']);
        $this->assertTrue($signed['signed']);
        $this->assertSame($keystore->certificate->fingerprint(), $signed['fingerprint']);
        $this->assertSame($target['body']['$id'], $signed['targetId']);
        $this->assertSame(self::IDENTIFIER, $signed['identifier']);
        $this->assertSame(1, $signed['versionCode']);
        $this->assertSame(24, $signed['manifest']['minSdk']);
        $this->assertSame(36, $signed['manifest']['targetSdk']);
        $this->assertSame(64, \strlen($signed['digest']));
        $this->assertNotSame(\hash('sha256', $bundle), $signed['digest']);

        $download = $this->downloadArtifact($appId, $signed['$id']);
        $this->assertSame(200, $download['headers']['status-code']);
        $signedBundle = $download['body'];
        $this->assertSame($signed['digest'], \hash('sha256', $signedBundle));
        $this->assertSame($signed['size'], \strlen($signedBundle));

        $reuploaded = $this->uploadArtifact($appId, $signedBundle, 'app-signed.aab')[0];
        $this->assertSame(202, $reuploaded['headers']['status-code']);
        $this->assertSame('processing', $reuploaded['body']['status']);

        $inspected = $this->awaitArtifact($appId, $reuploaded['body']['$id']);
        $this->assertSame('ready', $inspected['status']);
        $this->assertSame('', $inspected['errorCode']);
        $this->assertTrue($inspected['signed']);
        $this->assertSame($keystore->certificate->fingerprint(), $inspected['fingerprint']);
        $this->assertSame(\hash('sha256', $signedBundle), $inspected['digest']);
        $this->assertSame(\strlen($signedBundle), $inspected['size']);
        $this->assertSame($target['body']['$id'], $inspected['targetId']);

        $unchanged = $this->downloadArtifact($appId, $inspected['$id']);
        $this->assertSame(200, $unchanged['headers']['status-code']);
        $this->assertSame($signedBundle, $unchanged['body']);
    }

    public function testInspectRefusesArtifacts(): void
    {
        $app = $this->createApp();
        $this->assertSame(201, $app['headers']['status-code']);
        $appId = $app['body']['$id'];

        $unknown = $this->uploadArtifact($appId, $this->fixture(), 'app-unsigned.aab')[0];
        $malformed = $this->uploadArtifact($appId, \random_bytes(4096), 'random.aab')[0];
        $this->assertSame(202, $unknown['headers']['status-code']);
        $this->assertSame(202, $malformed['headers']['status-code']);

        /**
         * Test for FAILURE
         */
        $unmatched = $this->awaitArtifact($appId, $unknown['body']['$id']);
        $this->assertSame('invalid', $unmatched['status']);
        $this->assertSame('artifact_identifier_unknown', $unmatched['errorCode']);
        $this->assertSame('', $unmatched['targetId']);
        $this->assertFalse($unmatched['signed']);

        $unreadable = $this->awaitArtifact($appId, $malformed['body']['$id']);
        $this->assertSame('invalid', $unreadable['status']);
        $this->assertSame('artifact_malformed', $unreadable['errorCode']);
        $this->assertFalse($unreadable['signed']);
    }

    protected function fixture(): string
    {
        $bundle = \file_get_contents(self::FIXTURE);
        $this->assertIsString($bundle);

        return $bundle;
    }

    /**
     * @param array<string, string> $headers
     * @return array<string, mixed>
     */
    protected function createArtifact(string $appId, string $content, string $name, array $headers = []): array
    {
        return $this->client->call(Client::METHOD_POST, '/distribution/apps/' . $appId . '/artifacts', \array_merge($this->headers(), [
            'content-type' => 'multipart/form-data',
        ], $headers), [
            'file' => new \CURLFile('data://application/octet-stream;base64,' . \base64_encode($content), 'application/octet-stream', $name),
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function uploadArtifact(string $appId, string $content, string $name): array
    {
        $size = \strlen($content);
        $artifactId = '';
        $responses = [];

        for ($start = 0; $start < $size; $start += self::CHUNK) {
            $end = \min($start + self::CHUNK, $size) - 1;
            $headers = ['content-range' => 'bytes ' . $start . '-' . $end . '/' . $size];
            if ($artifactId !== '') {
                $headers['x-appwrite-id'] = $artifactId;
            }

            $response = $this->createArtifact($appId, \substr($content, $start, $end - $start + 1), $name, $headers);
            $responses[] = $response;
            $artifactId = $response['body']['$id'] ?? '';
        }

        return $responses;
    }

    /**
     * @return array<string, mixed>
     */
    protected function getArtifact(string $appId, string $artifactId): array
    {
        return $this->client->call(Client::METHOD_GET, '/distribution/apps/' . $appId . '/artifacts/' . $artifactId, $this->headers());
    }

    /**
     * @param list<string> $queries
     * @return array<string, mixed>
     */
    protected function listArtifacts(string $appId, array $queries = []): array
    {
        return $this->client->call(Client::METHOD_GET, '/distribution/apps/' . $appId . '/artifacts', $this->headers(), [
            'queries' => $queries,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function downloadArtifact(string $appId, string $artifactId, string $range = ''): array
    {
        $headers = $this->headers();
        if ($range !== '') {
            $headers['range'] = $range;
        }

        return $this->client->call(Client::METHOD_GET, '/distribution/apps/' . $appId . '/artifacts/' . $artifactId . '/download', $headers);
    }

    /**
     * @return array<string, mixed>
     */
    protected function awaitArtifact(string $appId, string $artifactId): array
    {
        $artifact = [];
        $this->assertEventually(function () use ($appId, $artifactId, &$artifact): void {
            $response = $this->getArtifact($appId, $artifactId);
            $this->assertSame(200, $response['headers']['status-code']);
            $this->assertContains($response['body']['status'], ['ready', 'invalid']);
            $artifact = $response['body'];
        }, self::INSPECTION, 1_000);

        return $artifact;
    }

    /**
     * @return array<string, mixed>
     */
    protected function createKeystoreIdentity(Keystore $keystore): array
    {
        return $this->client->call(Client::METHOD_POST, '/distribution/signing-identities/android', $this->headers(), [
            'signingIdentityId' => ID::unique(),
            'name' => 'Keystore ' . \uniqid(),
            'keystore' => \base64_encode($keystore->archive),
            'storePassword' => $keystore->password,
            'keyAlias' => $keystore->alias,
            'keyPassword' => $keystore->password,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function deleteArtifact(string $appId, string $artifactId): array
    {
        return $this->client->call(Client::METHOD_DELETE, '/distribution/apps/' . $appId . '/artifacts/' . $artifactId, $this->headers());
    }
}
