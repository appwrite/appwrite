<?php

namespace Appwrite\Platform\Modules\Deployments\Http\Deployments\Artifacts\Build;

use Appwrite\Event\Message\Build as BuildMessage;
use Appwrite\Event\Publisher\Build as BuildPublisher;
use Appwrite\Extend\Exception;
use Appwrite\Utopia\Response;
use OpenRuntimes\Orchestrator\Enum\CallbackEvent;
use Utopia\Cache\Cache;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Http\Adapter\Swoole\Request;
use Utopia\Lock\Exception\Contention as LockContention;
use Utopia\Platform\Action as UtopiaAction;
use Utopia\Storage\Device;

abstract class Action extends UtopiaAction
{
    protected function uploadBuildArtifact(
        string $deploymentId,
        Document $project,
        Document $resource,
        Request $request,
        Response $response,
        Database $dbForProject,
        Device $deviceForBuilds,
        BuildPublisher $publisherForBuilds,
        Cache $cache,
        callable $locks
    ): void {
        $path = $deviceForBuilds->getPath($deploymentId . '.tar.gz');
        $contentRange = $request->getHeader('content-range');
        $chunk = 1;
        $chunks = 1;
        $fileSize = null;

        if (!empty($contentRange)) {
            $start = $request->getContentRangeStart();
            $end = $request->getContentRangeEnd();
            $fileSize = $request->getContentRangeSize();

            if (\is_null($start) || \is_null($end) || \is_null($fileSize) || $end >= $fileSize) {
                throw new Exception(Exception::STORAGE_INVALID_CONTENT_RANGE);
            }

            $chunks = (int) \ceil($fileSize / APP_LIMIT_UPLOAD_CHUNK_SIZE);
            $chunk = (int) ($start / APP_LIMIT_UPLOAD_CHUNK_SIZE) + 1;
        }

        $tmp = \tempnam(\sys_get_temp_dir(), 'appwrite-build-');
        if ($tmp === false) {
            throw new Exception(Exception::GENERAL_SERVER_ERROR, 'Failed creating temporary build artifact file.');
        }

        try {
            if (\file_put_contents($tmp, $request->getRawPayload()) === false) {
                throw new Exception(Exception::GENERAL_SERVER_ERROR, 'Failed writing build artifact chunk.');
            }

            $fileSize ??= \filesize($tmp);
            if ($fileSize === false) {
                throw new Exception(Exception::GENERAL_SERVER_ERROR, 'Failed reading build artifact chunk.');
            }

            $metadata = ['content_type' => 'application/gzip'];
            $cacheKey = 'build-artifact:' . $project->getId() . ':' . $deploymentId;
            $cacheTtl = 60 * 60 * 24;
            $lockKey = 'builds:artifact:' . $project->getId() . ':' . $deploymentId;
            $completed = false;

            try {
                $locks($lockKey, 600, function () use ($cache, $cacheKey, $cacheTtl, $dbForProject, $deploymentId, &$chunks, $deviceForBuilds, &$metadata, $path, $response, &$completed): void {
                    $deployment = $dbForProject->getAuthorization()->skip(fn () => $dbForProject->getDocument('deployments', $deploymentId));
                    if (!empty($deployment->getAttribute('buildPath', ''))) {
                        $response
                            ->setStatusCode(Response::STATUS_CODE_ACCEPTED)
                            ->json([
                                'path' => $deployment->getAttribute('buildPath'),
                                'size' => $deployment->getAttribute('buildSize', 0),
                            ]);

                        $completed = true;
                        return;
                    }

                    $stored = $cache->load($cacheKey, $cacheTtl);
                    if (\is_array($stored)) {
                        $metadata = \array_merge($stored, $metadata);
                        if (isset($stored['parts']) || isset($metadata['parts'])) {
                            $parts = $stored['parts'] ?? [];
                            foreach (($metadata['parts'] ?? []) as $part => $value) {
                                $parts[(int) $part] = $value;
                            }
                            \ksort($parts);

                            $metadata['parts'] = $parts;
                            $metadata['chunks'] = \count($parts);
                        }

                        $chunks = (int) ($stored['chunksTotal'] ?? $chunks);
                    }

                    $deviceForBuilds->prepareUpload($path, 'application/gzip', $chunks, $metadata);
                    $metadata['chunksTotal'] = $chunks;
                    $cache->save($cacheKey, $metadata);
                }, timeout: 120.0);
            } catch (LockContention) {
                $response->addHeader('Retry-After', '5');
                throw new Exception(Exception::GENERAL_RATE_LIMIT_EXCEEDED, 'Build artifact upload is busy. Try again.');
            }

            if ($completed) {
                return;
            }

            $chunksUploaded = $deviceForBuilds->uploadChunk($tmp, $path, $chunk, $chunks, $metadata);
            if (empty($chunksUploaded)) {
                throw new Exception(Exception::GENERAL_SERVER_ERROR, 'Failed storing build artifact chunk.');
            }

            try {
                $locks($lockKey, 600, function () use ($cache, $cacheKey, $cacheTtl, &$chunks, $chunksUploaded, $dbForProject, $deploymentId, $deviceForBuilds, $fileSize, &$metadata, $path, $project, $publisherForBuilds, $resource, $response): void {
                    $deployment = $dbForProject->getAuthorization()->skip(fn () => $dbForProject->getDocument('deployments', $deploymentId));
                    if (!empty($deployment->getAttribute('buildPath', ''))) {
                        $cache->purge($cacheKey);
                        $response
                            ->setStatusCode(Response::STATUS_CODE_ACCEPTED)
                            ->json([
                                'path' => $deployment->getAttribute('buildPath'),
                                'size' => $deployment->getAttribute('buildSize', 0),
                            ]);

                        return;
                    }

                    $stored = $cache->load($cacheKey, $cacheTtl);
                    if (\is_array($stored)) {
                        $metadata = \array_merge($stored, $metadata);
                        if (isset($stored['parts']) || isset($metadata['parts'])) {
                            $parts = $stored['parts'] ?? [];
                            foreach (($metadata['parts'] ?? []) as $part => $value) {
                                $parts[(int) $part] = $value;
                            }
                            \ksort($parts);

                            $metadata['parts'] = $parts;
                            $metadata['chunks'] = \count($parts);
                        }

                        $chunks = (int) ($stored['chunksTotal'] ?? $chunks);
                    }

                    $metadata['chunksTotal'] = $chunks;
                    $chunksUploaded = \max((int) ($metadata['chunks'] ?? 0), $chunksUploaded);

                    if ($chunksUploaded === $chunks) {
                        $deviceForBuilds->finalizeUpload($path, $chunks, $metadata);

                        $size = $deviceForBuilds->getFileSize($path);
                        $deployment = $dbForProject->getAuthorization()->skip(fn () => $dbForProject->updateDocument('deployments', $deploymentId, new Document([
                            'buildPath' => $path,
                            'buildSize' => $size,
                            'totalSize' => $deployment->getAttribute('sourceSize', 0) + $size,
                        ])));

                        $cache->purge($cacheKey);

                        $publisherForBuilds->enqueue(new BuildMessage(
                            project: $project,
                            resource: $resource,
                            deployment: $deployment,
                            type: BUILD_TYPE_ORCHESTRATOR_EVENT,
                            event: [
                                'type' => CallbackEvent::Artifact->value,
                                'data' => [
                                    'artifactId' => 'upload',
                                    'status' => 'success',
                                ],
                            ],
                        ));

                        $response
                            ->setStatusCode(Response::STATUS_CODE_ACCEPTED)
                            ->json([
                                'path' => $path,
                                'size' => $size,
                            ]);

                        return;
                    }

                    $metadata['chunks'] = $chunksUploaded;
                    $cache->save($cacheKey, $metadata);

                    $response
                        ->setStatusCode(Response::STATUS_CODE_ACCEPTED)
                        ->json([
                            'path' => $path,
                            'size' => $fileSize,
                            'chunksTotal' => $chunks,
                            'chunksUploaded' => $chunksUploaded,
                        ]);
                }, timeout: 120.0);
            } catch (LockContention) {
                $response->addHeader('Retry-After', '5');
                throw new Exception(Exception::GENERAL_RATE_LIMIT_EXCEEDED, 'Build artifact upload is busy. Try again.');
            }
        } finally {
            @\unlink($tmp);
        }
    }
}
