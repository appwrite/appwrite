<?php

namespace Appwrite\Platform\Modules\Storage\Http\Buckets\Files;

use Appwrite\Event\Event;
use Appwrite\Extend\Exception;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Helpers\Permission;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\Permissions;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Action;
use Utopia\Platform\Scope\HTTP;
use Utopia\Validator\Nullable;
use Utopia\Validator\Text;

class Update extends Action
{
    use HTTP;

    public static function getName()
    {
        return 'updateFile';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_PUT)
            ->setHttpPath('/v1/storage/buckets/:bucketId/files/:fileId')
            ->desc('Update file')
            ->groups(['api', 'storage'])
            ->label('scope', 'files.write')
            ->label('resourceType', RESOURCE_TYPE_BUCKETS)
            ->label('event', 'buckets.[bucketId].files.[fileId].update')
            ->label('audits.event', 'file.update')
            ->label('audits.resource', 'file/{response.$id}')
            ->label('sdk', new Method(
                namespace: 'storage',
                group: 'files',
                name: 'updateFile',
                description: '/docs/references/storage/update-file.md',
                auth: [AuthType::ADMIN, AuthType::SESSION, AuthType::KEY, AuthType::JWT],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_FILE,
                    )
                ]
            ))
            ->param('bucketId', '', new UID(), 'Bucket unique ID.')
            ->param('fileId', '', new UID(), 'File ID.')
            ->param('name', null, new Text(128), 'File name.', true)
            ->param('permissions', null, new Nullable(new Permissions(APP_LIMIT_ARRAY_PARAMS_SIZE)), 'An array of permission strings. By default, the current permissions are inherited. [Learn more about permissions](https://appwrite.io/docs/permissions).', true)
            ->inject('response')
            ->inject('dbForProject')
            ->inject('queueForEvents')
            ->callback($this->action(...));
    }

    public function action(
        string $bucketId,
        string $fileId,
        ?string $name,
        ?array $permissions,
        Response $response,
        Database $dbForProject,
        Event $queueForEvents
    ) {
        $bucket = Authorization::skip(fn () => $dbForProject->getDocument('buckets', $bucketId));

        if ($bucket->isEmpty()) {
            throw new Exception(Exception::STORAGE_BUCKET_NOT_FOUND);
        }

        $fileSecurity = $bucket->getAttribute('fileSecurity', false);

        $bucketUpdateValidator = new Authorization(Database::PERMISSION_UPDATE);
        $bucketUpdateValid = $bucketUpdateValidator->isValid($bucket->getUpdate());

        if (!$bucketUpdateValid && !$fileSecurity) {
            throw new Exception(Exception::USER_UNAUTHORIZED);
        }

        // Fetch file depending on fileSecurity & bucket permission
        if ($fileSecurity && !$bucketUpdateValid) {
            $file = $dbForProject->getDocument('bucket_' . $bucket->getSequence(), $fileId);
        } else {
            $file = Authorization::skip(fn () => $dbForProject->getDocument('bucket_' . $bucket->getSequence(), $fileId));
        }

        if ($file->isEmpty()) {
            throw new Exception(Exception::STORAGE_FILE_NOT_FOUND);
        }

        // Aggregate provided permissions with existing ones if null
        $permissions = Permission::aggregate($permissions ?? $file->getPermissions());

        $name ??= $file->getAttribute('name');

        $file = $dbForProject->updateDocument('bucket_' . $bucket->getSequence(), $fileId, $file
            ->setAttribute('name', $name)
            ->setAttribute('$permissions', $permissions));

        $queueForEvents
            ->setParam('bucketId', $bucket->getId())
            ->setParam('fileId', $file->getId());

        $response->dynamic($file, Response::MODEL_FILE);
    }
}
