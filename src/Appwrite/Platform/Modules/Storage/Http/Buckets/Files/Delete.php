<?php

namespace Appwrite\Platform\Modules\Storage\Http\Buckets\Files;

use Appwrite\Event\Delete as DeleteEvent;
use Appwrite\Event\Event;
use Appwrite\Extend\Exception;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Action;
use Utopia\Platform\Scope\HTTP;

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
            ->param('bucketId', '', new UID(), 'Bucket unique ID.')
            ->param('fileId', '', new UID(), 'File ID.')
            ->inject('response')
            ->inject('dbForProject')
            ->inject('queueForDeletes')
            ->inject('queueForEvents')
            ->callback($this->action(...));
    }

    public function action(
        string $bucketId,
        string $fileId,
        Response $response,
        Database $dbForProject,
        DeleteEvent $queueForDeletes,
        Event $queueForEvents
    ) {
        $bucket = Authorization::skip(fn () => $dbForProject->getDocument('buckets', $bucketId));

        if ($bucket->isEmpty()) {
            throw new Exception(Exception::STORAGE_BUCKET_NOT_FOUND);
        }

        // Validate delete permission
        $validator = new Authorization(Database::PERMISSION_DELETE);
        $validBucketDelete = $validator->isValid($bucket->getDelete());
        $fileSecurity = $bucket->getAttribute('fileSecurity', false);

        if (!$validBucketDelete && !$fileSecurity) {
            throw new Exception(Exception::USER_UNAUTHORIZED);
        }

        // Fetch file based on security
        if ($fileSecurity && !$validBucketDelete) {
            $file = $dbForProject->getDocument('bucket_' . $bucket->getSequence(), $fileId);
        } else {
            $file = Authorization::skip(fn () => $dbForProject->getDocument('bucket_' . $bucket->getSequence(), $fileId));
        }

        if ($file->isEmpty()) {
            throw new Exception(Exception::STORAGE_FILE_NOT_FOUND);
        }

        if (!$dbForProject->deleteDocument('bucket_' . $bucket->getSequence(), $fileId)) {
            throw new Exception(Exception::GENERAL_SERVER_ERROR, 'Failed to remove file from DB');
        }

        $queueForDeletes
            ->setType(DELETE_TYPE_DOCUMENT)
            ->setDocument($file);

        $queueForEvents
            ->setParam('bucketId', $bucket->getId())
            ->setParam('fileId', $file->getId())
            ->setPayload($response->output($file, Response::MODEL_FILE));

        $response->noContent();
    }
}
