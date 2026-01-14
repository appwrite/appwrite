<?php

namespace Appwrite\Platform\Modules\Storage\Http\Buckets\Files;

use Appwrite\Event\Delete as DeleteEvent;
use Appwrite\Event\Event;
use Appwrite\Extend\Exception;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Database\Documents\User;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Exception\NotFound as NotFoundException;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Action;
use Utopia\Platform\Scope\HTTP;
use Utopia\Storage\Device;

class Delete extends Action
{
    use HTTP;

    public static function getName()
    {
        return 'deleteFile';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_DELETE)
            ->setHttpPath('/v1/storage/buckets/:bucketId/files/:fileId')
            ->desc('Delete file')
            ->groups(['api', 'storage'])
            ->label('scope', 'files.write')
            ->label('resourceType', RESOURCE_TYPE_BUCKETS)
            ->label('event', 'buckets.[bucketId].files.[fileId].delete')
            ->label('audits.event', 'file.delete')
            ->label('audits.resource', 'file/{request.fileId}')
            ->label('abuse-key', 'ip:{ip},method:{method},url:{url},userId:{userId}')
            ->label('abuse-limit', APP_LIMIT_WRITE_RATE_DEFAULT)
            ->label('abuse-time', APP_LIMIT_WRITE_RATE_PERIOD_DEFAULT)
            ->label('sdk', new Method(
                namespace: 'storage',
                group: 'files',
                name: 'deleteFile',
                description: '/docs/references/storage/delete-file.md',
                auth: [AuthType::ADMIN, AuthType::SESSION, AuthType::KEY, AuthType::JWT],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_NOCONTENT,
                        model: Response::MODEL_NONE,
                    )
                ],
                contentType: ContentType::NONE
            ))
            ->param('bucketId', '', new UID(), 'Storage bucket unique ID. You can create a new storage bucket using the Storage service [server integration](https://appwrite.io/docs/server/storage#createBucket).')
            ->param('fileId', '', new UID(), 'File ID.')
            ->inject('response')
            ->inject('dbForProject')
            ->inject('queueForEvents')
            ->inject('deviceForFiles')
            ->inject('queueForDeletes')
            ->callback($this->action(...));
    }

    public function action(
        string $bucketId,
        string $fileId,
        Response $response,
        Database $dbForProject,
        Event $queueForEvents,
        Device $deviceForFiles,
        DeleteEvent $queueForDeletes,
    ) {
        $bucket = Authorization::skip(fn () => $dbForProject->getDocument('buckets', $bucketId));

        $isAPIKey = User::isApp(Authorization::getRoles());
        $isPrivilegedUser = User::isPrivileged(Authorization::getRoles());

        if ($bucket->isEmpty() || (!$bucket->getAttribute('enabled') && !$isAPIKey && !$isPrivilegedUser)) {
            throw new Exception(Exception::STORAGE_BUCKET_NOT_FOUND);
        }

        $fileSecurity = $bucket->getAttribute('fileSecurity', false);
        $validator = new Authorization(Database::PERMISSION_DELETE);
        $valid = $validator->isValid($bucket->getDelete());
        if (!$fileSecurity && !$valid) {
            throw new Exception(Exception::USER_UNAUTHORIZED);
        }

        // Read permission should not be required for delete
        $file = Authorization::skip(fn () => $dbForProject->getDocument('bucket_' . $bucket->getSequence(), $fileId));

        if ($file->isEmpty()) {
            throw new Exception(Exception::STORAGE_FILE_NOT_FOUND);
        }

        // Make sure we don't delete the file before the document permission check occurs
        if ($fileSecurity && !$valid && !$validator->isValid($file->getDelete())) {
            throw new Exception(Exception::USER_UNAUTHORIZED);
        }

        $deviceDeleted = false;
        if ($file->getAttribute('chunksTotal') !== $file->getAttribute('chunksUploaded')) {
            $deviceDeleted = $deviceForFiles->abort(
                $file->getAttribute('path'),
                ($file->getAttribute('metadata', [])['uploadId'] ?? '')
            );
        } else {
            $deviceDeleted = $deviceForFiles->delete($file->getAttribute('path'));
        }

        if ($deviceDeleted) {
            $queueForDeletes
                ->setType(DELETE_TYPE_CACHE_BY_RESOURCE)
                ->setResourceType('bucket/' . $bucket->getId())
                ->setResource('file/' . $fileId)
            ;

            try {
                if ($fileSecurity && !$valid) {
                    $deleted = $dbForProject->deleteDocument('bucket_' . $bucket->getSequence(), $fileId);
                } else {
                    $deleted = Authorization::skip(fn () => $dbForProject->deleteDocument('bucket_' . $bucket->getSequence(), $fileId));
                }
            } catch (NotFoundException) {
                throw new Exception(Exception::STORAGE_BUCKET_NOT_FOUND);
            }

            if (!$deleted) {
                throw new Exception(Exception::GENERAL_SERVER_ERROR, 'Failed to remove file from DB');
            }
        } else {
            throw new Exception(Exception::GENERAL_SERVER_ERROR, 'Failed to delete file from device');
        }

        $queueForEvents
            ->setParam('bucketId', $bucket->getId())
            ->setParam('fileId', $file->getId())
            ->setContext('bucket', $bucket)
            ->setPayload($response->output($file, Response::MODEL_FILE))
        ;

        $response->noContent();
    }
}
