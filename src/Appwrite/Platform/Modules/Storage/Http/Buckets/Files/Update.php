<?php

namespace Appwrite\Platform\Modules\Storage\Http\Buckets\Files;

use Appwrite\Event\Event;
use Appwrite\Extend\Exception;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Database\Documents\User;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Exception\NotFound as NotFoundException;
use Utopia\Database\Helpers\Permission;
use Utopia\Database\Helpers\Role;
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
            ->inject('authorization')
            ->callback($this->action(...));
    }

    public function action(
        string $bucketId,
        string $fileId,
        ?string $name,
        ?array $permissions,
        Response $response,
        Database $dbForProject,
        Event $queueForEvents,
        Authorization $authorization
    ) {
        $bucket = $authorization->skip(fn () => $dbForProject->getDocument('buckets', $bucketId));

        $isAPIKey = User::isApp($authorization->getRoles());
        $isPrivilegedUser = User::isPrivileged($authorization->getRoles());

        if ($bucket->isEmpty() || (!$bucket->getAttribute('enabled') && !$isAPIKey && !$isPrivilegedUser)) {
            throw new Exception(Exception::STORAGE_BUCKET_NOT_FOUND);
        }

        $fileSecurity = $bucket->getAttribute('fileSecurity', false);
        $validator = new Authorization(Database::PERMISSION_UPDATE);
        $valid = $validator->isValid($bucket->getUpdate());
        if (!$fileSecurity && !$valid) {
            throw new Exception(Exception::USER_UNAUTHORIZED);
        }

        // Read permission should not be required for update
        $file = $authorization->skip(fn () => $dbForProject->getDocument('bucket_' . $bucket->getSequence(), $fileId));

        if ($file->isEmpty()) {
            throw new Exception(Exception::STORAGE_FILE_NOT_FOUND);
        }

        // Map aggregate permissions into the multiple permissions they represent.
        $permissions = Permission::aggregate($permissions, [
            Database::PERMISSION_READ,
            Database::PERMISSION_UPDATE,
            Database::PERMISSION_DELETE,
        ]);

        // Users can only manage their own roles, API keys and Admin users can manage any
        $roles = $authorization->getRoles();
        if (!User::isApp($roles) && !User::isPrivileged($roles) && !\is_null($permissions)) {
            foreach (Database::PERMISSIONS as $type) {
                foreach ($permissions as $permission) {
                    $permission = Permission::parse($permission);
                    if ($permission->getPermission() != $type) {
                        continue;
                    }
                    $role = (new Role(
                        $permission->getRole(),
                        $permission->getIdentifier(),
                        $permission->getDimension()
                    ))->toString();
                    if (!$authorization->isRole($role)) {
                        throw new Exception(Exception::USER_UNAUTHORIZED, 'Permissions must be one of: (' . \implode(', ', $roles) . ')');
                    }
                }
            }
        }

        if (\is_null($permissions)) {
            $permissions = $file->getPermissions() ?? [];
        }

        $file->setAttribute('$permissions', $permissions);

        if (!is_null($name)) {
            $file->setAttribute('name', $name);
        }

        try {
            if ($fileSecurity && !$valid) {
                $file = $dbForProject->updateDocument('bucket_' . $bucket->getSequence(), $fileId, $file);
            } else {
                $file = $authorization->skip(fn () => $dbForProject->updateDocument('bucket_' . $bucket->getSequence(), $fileId, $file));
            }
        } catch (NotFoundException) {
            throw new Exception(Exception::STORAGE_BUCKET_NOT_FOUND);
        }

        $queueForEvents
            ->setParam('bucketId', $bucket->getId())
            ->setParam('fileId', $file->getId())
            ->setContext('bucket', $bucket)
        ;

        $response->dynamic($file, Response::MODEL_FILE);
    }
}
