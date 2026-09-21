<?php

namespace Appwrite\Platform\Modules\S3\Http\S3;

use Appwrite\Event\Event;
use Appwrite\Event\Publisher\Audit as AuditPublisher;
use Appwrite\Event\Publisher\Delete as DeletePublisher;
use Appwrite\Event\Publisher\Func as FunctionPublisher;
use Appwrite\Extend\Exception as AppwriteException;
use Appwrite\Functions\EventProcessor;
use Appwrite\Platform\Modules\S3\Responses\S3Xml;
use Appwrite\Utopia\Database\Documents\User;
use Appwrite\Utopia\Response;
use Utopia\Cache\Cache;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Query;
use Utopia\Http\Adapter\Swoole\Request;
use Utopia\Platform\Action;
use Utopia\Storage\Device;

class Delete extends Base
{
    public static function getName(): string
    {
        return 's3Delete';
    }

    public function __construct()
    {
        parent::__construct(Action::HTTP_REQUEST_METHOD_DELETE);
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

        if ($key === '') {
            $this->authorize($request, $project, $team, $user, ['buckets.write']);
            $bucket = $this->bucket($dbForProject, $bucketId);

            $files = $dbForProject->getAuthorization()->skip(fn () => $dbForProject->find('bucket_' . $bucket->getSequence(), [Query::limit(1)]));
            if ($files !== []) {
                $this->sendXml($response, S3Xml::error('BucketNotEmpty', 'The bucket you tried to delete is not empty.', $request->getURI()), Response::STATUS_CODE_CONFLICT);
                return;
            }

            $dbForProject->getAuthorization()->skip(fn () => $dbForProject->deleteCollection('bucket_' . $bucket->getSequence()));
            $dbForProject->getAuthorization()->skip(fn () => $dbForProject->deleteDocument('buckets', $bucketId));
            $this->queueBucketEvent($queueForEvents, $response, $bucket, 'delete');
            $this->enqueueAudit($publisherForAudits, $request, $project, $user, 'bucket.delete', $bucket, 'bucket');
            $response->noContent();
            return;
        }

        $this->authorize($request, $project, $team, $user, ['files.write']);
        $bucket = $this->bucket($dbForProject, $bucketId);

        if ($this->isFolderMarker($key)) {
            $response->noContent();
            return;
        }

        if ($this->query($request, 'uploadId') !== '') {
            $uploadId = $this->query($request, 'uploadId');
            // Abort races in-flight part uploads for the same upload; take the
            // upload's critical section so a late part cannot resurrect state
            // that was just purged or upload into an aborted device staging.
            $locks($this->multipartLockKey($project, $uploadId), 600, function () use ($cache, $project, $deviceForFiles, $bucket, $uploadId, $bucketId, $key): void {
                $this->deleteMultipartUpload($cache, $project, $deviceForFiles, $bucket, $this->multipartUploadForBucket($cache, $project, $uploadId, $bucketId, $key));
            }, timeout: 120.0);
            $response->noContent();
            return;
        }

        $file = $locks($this->objectLockKey($project, $bucket, $key), 600, function () use ($dbForProject, $deviceForFiles, $bucket, $key): ?Document {
            $file = $this->findObject($dbForProject, $bucket, $key);
            if ($file === null) {
                return null;
            }
            if (!$deviceForFiles->delete($file->getAttribute('path', ''))) {
                throw new AppwriteException(AppwriteException::GENERAL_SERVER_ERROR, 'Failed to delete object.');
            }
            $dbForProject->getAuthorization()->skip(fn () => $dbForProject->deleteDocument('bucket_' . $bucket->getSequence(), $file->getId()));

            return $file;
        }, timeout: 120.0);
        if ($file !== null) {
            $this->enqueueFileCacheDelete($publisherForDeletes, $queueForEvents, $bucket, $file);
            $this->queueFileEvent($queueForEvents, $response, $bucket, $file, 'delete');
            $this->enqueueAudit($publisherForAudits, $request, $project, $user, 'file.delete', $file, 'file');
        }
        $response->noContent();
    }
}
