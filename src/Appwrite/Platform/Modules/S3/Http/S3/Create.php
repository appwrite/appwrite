<?php

namespace Appwrite\Platform\Modules\S3\Http\S3;

use Appwrite\Event\Event;
use Appwrite\Event\Publisher\Audit as AuditPublisher;
use Appwrite\Event\Publisher\Delete as DeletePublisher;
use Appwrite\Event\Publisher\Func as FunctionPublisher;
use Appwrite\Extend\Exception as AppwriteException;
use Appwrite\Functions\EventProcessor;
use Appwrite\Platform\Modules\S3\Requests\CompleteMultipartUpload;
use Appwrite\Platform\Modules\S3\Responses\S3Xml;
use Appwrite\Utopia\Database\Documents\User;
use Appwrite\Utopia\Response;
use Utopia\Cache\Cache;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Http\Adapter\Swoole\Request;
use Utopia\Lock\Distributed;
use Utopia\Lock\Exception\Contention;
use Utopia\Platform\Action;
use Utopia\Storage\Device;

class Create extends Base
{
    public static function getName(): string
    {
        return 's3Create';
    }

    public function __construct()
    {
        parent::__construct(Action::HTTP_REQUEST_METHOD_PUT);
        $this->setHttpMethods([Action::HTTP_REQUEST_METHOD_PUT, Action::HTTP_REQUEST_METHOD_POST]);
    }

    protected function route(Request $request, Response $response, Database $dbForProject, Document $project, Document $team, User $user, Device $deviceForFiles, callable $locks, Cache $cache, Event $queueForEvents, DeletePublisher $publisherForDeletes, AuditPublisher $publisherForAudits, Event $queueForRealtime, FunctionPublisher $publisherForFunctions, Event $queueForWebhooks, EventProcessor $eventProcessor): void
    {
        [$bucketId, $key] = $this->parts($request);
        if ($bucketId === '') {
            throw new AppwriteException(AppwriteException::GENERAL_ARGUMENT_INVALID, 'Bucket is required.');
        }

        if ($this->unsupportedSubresource($request)) {
            $this->authorize($request, $project, $team, $user, $key === '' ? ['buckets.write'] : ['files.write']);
            $this->sendXml($response, S3Xml::error('NotImplemented', 'This S3 feature is not supported by Appwrite Storage.', $request->getURI()), Response::STATUS_CODE_NOT_IMPLEMENTED);
            return;
        }

        if ($this->hasQuery($request, 'acl')) {
            $this->authorize($request, $project, $team, $user, $key === '' ? ['buckets.write'] : ['files.write']);
            $bucket = $this->bucket($dbForProject, $bucketId);
            if ($key !== '') {
                $this->getObject($dbForProject, $bucket, $key);
            }
            $response->setStatusCode(Response::STATUS_CODE_OK)->send('');
            return;
        }

        if ($request->getMethod() === Action::HTTP_REQUEST_METHOD_POST && $key === '' && $this->hasQuery($request, 'delete')) {
            $this->authorize($request, $project, $team, $user, ['files.write']);
            $bucket = $this->bucket($dbForProject, $bucketId);
            $payload = $this->requestBody($request);
            $deleted = [];
            foreach ($this->deleteObjectKeys($payload) as $objectKey) {
                [$file, $removed] = $locks($this->objectLockKey($project, $bucket, $objectKey), 600, function () use ($dbForProject, $deviceForFiles, $bucket, $objectKey): array {
                    $file = $this->findObject($dbForProject, $bucket, $objectKey);
                    if ($file === null) {
                        return [null, true];
                    }

                    $path = $file->getAttribute('path', '');
                    if ($path !== '' && !$deviceForFiles->delete($path)) {
                        // Storage delete failed; keep the DB document so the
                        // object remains reachable and report no deletion.
                        return [$file, false];
                    }
                    $dbForProject->getAuthorization()->skip(fn () => $dbForProject->deleteDocument('bucket_' . $bucket->getSequence(), $file->getId()));

                    return [$file, true];
                }, timeout: 120.0);

                if (!$removed) {
                    continue;
                }
                if ($file !== null) {
                    $this->enqueueFileCacheDelete($publisherForDeletes, $queueForEvents, $bucket, $file);
                    $this->enqueueAudit($publisherForAudits, $request, $project, $user, 'file.delete', $file, 'file');
                    $this->triggerFileEvent($queueForEvents, $queueForRealtime, $publisherForFunctions, $queueForWebhooks, $eventProcessor, $dbForProject, $response, $bucket, $file, 'delete');
                }
                $deleted[] = $objectKey;
            }

            $this->sendXml($response, S3Xml::deleteObjects($deleted, $this->deleteQuiet($payload)));
            return;
        }

        if ($request->getMethod() === Action::HTTP_REQUEST_METHOD_POST && $key !== '' && $this->hasQuery($request, 'uploads')) {
            $this->authorize($request, $project, $team, $user, ['files.write']);
            if ($this->isFolderMarker($key)) {
                $this->sendXml($response, S3Xml::error('NotImplemented', 'S3 folders are not supported by Appwrite Storage.', $request->getURI()), Response::STATUS_CODE_NOT_IMPLEMENTED);
                return;
            }
            $bucket = $this->bucket($dbForProject, $bucketId);
            $uploadId = \bin2hex(\random_bytes(16));
            $this->createMultipartUpload($locks, $dbForProject, $cache, $project, $deviceForFiles, $bucket, $key, $uploadId, $request->getHeaderLine('content-type', 'application/octet-stream'), $this->requestObjectMetadata($request));
            $this->sendXml($response, S3Xml::initiateMultipart($bucketId, $key, $uploadId));
            return;
        }

        if ($request->getMethod() === Action::HTTP_REQUEST_METHOD_PUT && $key !== '' && $this->query($request, 'uploadId') !== '' && $this->query($request, 'partNumber') !== '') {
            $this->authorize($request, $project, $team, $user, ['files.write']);
            $bucket = $this->bucket($dbForProject, $bucketId);
            $partNumber = (int) $this->query($request, 'partNumber');
            $uploadId = $this->query($request, 'uploadId');

            $body = $this->requestBody($request);
            $copySource = $request->getHeaderLine('x-amz-copy-source');
            if ($copySource !== '') {
                // Copy reads the source bytes, so the key must also hold files.read.
                $this->authorize($request, $project, $team, $user, ['files.read']);
                $copySource = \rawurldecode(\ltrim($copySource, '/'));
                [$sourceBucketId, $sourceKey] = \array_pad(\explode('/', $copySource, 2), 2, '');
                $sourceBucket = $this->bucket($dbForProject, $sourceBucketId);
                $sourceFile = $this->getObject($dbForProject, $sourceBucket, $sourceKey);
                $this->validateCopySize($bucket, $sourceFile);
                if (\preg_match('/^bytes=(\d+)-(\d+)$/', $request->getHeaderLine('x-amz-copy-source-range'), $matches) === 1) {
                    $start = (int) $matches[1];
                    $end = (int) $matches[2];
                    $sourceSize = (int) $sourceFile->getAttribute('sizeOriginal', 0);
                    if ($start > $end || $start >= $sourceSize) {
                        throw new AppwriteException(AppwriteException::STORAGE_INVALID_RANGE);
                    }
                    $end = \min($end, $sourceSize - 1);
                    $body = (string) $deviceForFiles->read($sourceFile->getAttribute('path', ''), $start, $end - $start + 1);
                } else {
                    $body = (string) $deviceForFiles->read($sourceFile->getAttribute('path', ''));
                }
            }

            $etag = \md5($body);
            $lockKey = $this->multipartLockKey($project, $uploadId);
            $locks($lockKey . ':part:' . $partNumber, 600, function (Distributed $partLock) use ($locks, $lockKey, $cache, $project, $deviceForFiles, $bucket, $uploadId, $bucketId, $key, $partNumber, $body, $etag): void {
                $upload = $locks($lockKey, 600, function () use ($cache, $project, $deviceForFiles, $bucket, $uploadId, $bucketId, $key, $partNumber, $body, $etag): ?array {
                    $upload = $this->multipartUploadForBucket($cache, $project, $uploadId, $bucketId, $key);
                    if (empty($upload['metadata']['device']['uploadId'])) {
                        // Local chunks share a directory: keep transfer and abort serialized.
                        $upload = $this->uploadMultipartPart($cache, $project, $deviceForFiles, $bucket, $upload, $partNumber, $body, $etag);
                        $this->saveMultipartUpload($cache, $project, $upload);
                        return null;
                    }
                    return $upload;
                }, timeout: 120.0);

                if ($upload !== null) {
                    if (!$partLock->refresh()) {
                        throw new Contention('Part lease lost before transfer');
                    }
                    $transferred = $this->uploadMultipartPart($cache, $project, $deviceForFiles, $bucket, $upload, $partNumber, $body, $etag);
                    if (!$partLock->isHeld()) {
                        throw new Contention('Part lease lost after transfer');
                    }
                    $locks($lockKey, 600, function () use ($cache, $project, $uploadId, $bucketId, $key, $partNumber, $transferred, $partLock): void {
                        if (!$partLock->refresh()) {
                            throw new Contention('Part lease lost before recording completion');
                        }
                        // Abort removes the state. Never recreate it from the transfer's snapshot.
                        $current = $this->multipartUploadForBucket($cache, $project, $uploadId, $bucketId, $key);
                        $current['parts'][$partNumber] = $transferred['parts'][$partNumber];
                        $current['metadata']['device']['parts'][$partNumber] = $transferred['metadata']['device']['parts'][$partNumber];
                        $current['metadata']['device']['chunks'] = \count($current['metadata']['device']['parts']);
                        $this->saveMultipartUpload($cache, $project, $current);
                    }, timeout: 120.0);
                }
            }, timeout: 120.0);
            if ($copySource !== '') {
                $this->sendXml($response, S3Xml::copyPart($etag));
                return;
            }
            $response
                ->setStatusCode(Response::STATUS_CODE_OK)
                ->addHeader('ETag', '"' . $etag . '"')
                ->send('');
            return;
        }

        if ($request->getMethod() === Action::HTTP_REQUEST_METHOD_POST && $key !== '' && $this->query($request, 'uploadId') !== '') {
            $this->authorize($request, $project, $team, $user, ['files.write']);
            $bucket = $this->bucket($dbForProject, $bucketId);
            // Part receipts commit before their 200 response. Completion selects
            // only acknowledged parts, then publishes under the object lock.
            $selected = CompleteMultipartUpload::parts($this->requestBody($request));
            $upload = $this->multipartUploadForBucket($cache, $project, $this->query($request, 'uploadId'), $bucketId, $key);
            [$file, $event] = $this->completeMultipartUpload($locks, $dbForProject, $cache, $project, $publisherForDeletes, $deviceForFiles, $bucket, $upload, $selected);
            if ($event !== null) {
                $this->queueFileEvent($queueForEvents, $response, $bucket, $file, $event);
                $this->enqueueAudit($publisherForAudits, $request, $project, $user, 'file.' . $event, $file, 'file');
            }
            $this->sendXml($response, S3Xml::completeMultipart($bucketId, $key, $file->getAttribute('signature', '')));
            return;
        }

        if ($key === '') {
            $this->authorize($request, $project, $team, $user, ['buckets.write']);
            $this->createBucket($dbForProject, $bucketId);
            $bucket = $this->bucket($dbForProject, $bucketId);
            $this->queueBucketEvent($queueForEvents, $response, $bucket, 'create');
            $this->enqueueAudit($publisherForAudits, $request, $project, $user, 'bucket.create', $bucket, 'bucket');
            $response->setStatusCode(Response::STATUS_CODE_OK)->send('');
            return;
        }

        $this->authorize($request, $project, $team, $user, ['files.write']);
        $bucket = $this->bucket($dbForProject, $bucketId);
        if ($this->isFolderMarker($key)) {
            if ($request->getHeaderLine('x-amz-copy-source') !== '') {
                $this->sendXml($response, S3Xml::error('NotImplemented', 'Copying to an S3 folder marker is not supported.', $request->getURI()), Response::STATUS_CODE_NOT_IMPLEMENTED);
                return;
            }
            $this->objectPath($key . '.folder');
            $response->setStatusCode(Response::STATUS_CODE_OK)->send('');
            return;
        }

        $copySource = $request->getHeaderLine('x-amz-copy-source');
        if ($copySource !== '') {
            // Copy reads the source bytes, so the key must also hold files.read.
            $this->authorize($request, $project, $team, $user, ['files.read']);
            $copySource = \rawurldecode(\ltrim($copySource, '/'));
            [$sourceBucketId, $sourceKey] = \array_pad(\explode('/', $copySource, 2), 2, '');
            $sourceBucket = $this->bucket($dbForProject, $sourceBucketId);
            $sourceFile = $this->getObject($dbForProject, $sourceBucket, $sourceKey);
            $this->validateCopySize($bucket, $sourceFile);
            $metadata = $sourceFile->getAttribute('metadata', []);
            if (\strtoupper($request->getHeaderLine('x-amz-metadata-directive')) === 'REPLACE') {
                $metadata = $this->requestObjectMetadata($request);
            }
            [$file, $event] = $this->putObjectLocked($locks, $dbForProject, $project, $publisherForDeletes, $deviceForFiles, $bucket, $key, (string) $deviceForFiles->read($sourceFile->getAttribute('path', '')), $sourceFile->getAttribute('mimeType', 'application/octet-stream'), $metadata);
            $this->queueFileEvent($queueForEvents, $response, $bucket, $file, $event);
            $this->enqueueAudit($publisherForAudits, $request, $project, $user, 'file.' . $event, $file, 'file');
            $this->sendXml($response, S3Xml::copyObject($file->getAttribute('signature', ''), $file->getUpdatedAt() ?: $file->getCreatedAt()));
            return;
        }

        [$file, $event] = $this->putObjectLocked($locks, $dbForProject, $project, $publisherForDeletes, $deviceForFiles, $bucket, $key, $this->requestBody($request), $request->getHeaderLine('content-type', 'application/octet-stream'), $this->requestObjectMetadata($request));
        $this->queueFileEvent($queueForEvents, $response, $bucket, $file, $event);
        $this->enqueueAudit($publisherForAudits, $request, $project, $user, 'file.' . $event, $file, 'file');
        $response
            ->setStatusCode(Response::STATUS_CODE_OK)
            ->addHeader('ETag', '"' . $file->getAttribute('signature', '') . '"');
        $sse = (string) ($file->getAttribute('metadata', [])['serverSideEncryption'] ?? '');
        if ($sse !== '') {
            $response->addHeader('x-amz-server-side-encryption', $sse);
        }
        $response->send('');
    }
}
