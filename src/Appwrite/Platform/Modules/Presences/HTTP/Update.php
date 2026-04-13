<?php

namespace Appwrite\Platform\Modules\Presences\HTTP;

use Appwrite\Extend\Exception;
use Appwrite\Platform\Modules\Presences\HTTP\Action as PresenceAction;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Database\Documents\User;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\Datetime as DatetimeValidator;
use Utopia\Database\Validator\Permissions;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Action;
use Utopia\Validator\JSON;
use Utopia\Validator\Nullable;
use Utopia\Validator\Text;

class Update extends PresenceAction
{
    public static function getName()
    {
        return 'updatePresence';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_PATCH)
            ->setHttpPath('/v1/presences/:presenceId')
            ->desc('Update presence')
            ->groups(['api', 'presences'])
            ->label('scope', 'users.write')
            ->label('sdk', new Method(
                namespace: 'presences',
                group: 'presences',
                name: 'update',
                description: 'Update a presence log by its unique ID.',
                auth: [AuthType::ADMIN, AuthType::KEY, AuthType::SESSION, AuthType::JWT],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_PRESENCE,
                    ),
                ],
            ))
            ->param('presenceId', '', fn (Database $dbForProject) => new UID($dbForProject->getAdapter()->getMaxUIDLength()), 'Presence unique ID.', false, ['dbForProject'])
            ->param('userId', null, new Nullable(new UID()), 'User ID.', true)
            ->param('status', null, new Nullable(new Text(Database::LENGTH_KEY)), 'Presence status.', true)
            ->param('expiry', null, new Nullable(new DatetimeValidator()), 'Presence expiry datetime.', true)
            ->param('metadata', null, new Nullable(new JSON()), 'Presence metadata object.', true)
            ->param('permissions', null, new Nullable(new Permissions(APP_LIMIT_ARRAY_PARAMS_SIZE, [Database::PERMISSION_READ, Database::PERMISSION_UPDATE, Database::PERMISSION_DELETE, Database::PERMISSION_WRITE])), 'An array of permissions strings. By default, only the current user is granted all permissions. [Learn more about permissions](https://appwrite.io/docs/permissions).', true)
            ->inject('response')
            ->inject('dbForProject')
            ->inject('user')
            ->inject('authorization')
            ->callback($this->action(...));
    }

    public function action(
        string $presenceId,
        ?string $userId,
        ?string $status,
        ?string $expiry,
        ?array $metadata,
        ?array $permissions,
        Response $response,
        Database $dbForProject,
        Document $user,
        Authorization $authorization
    ): void {
        $isAPIKey = User::isApp($authorization->getRoles());
        $isPrivilegedUser = User::isPrivileged($authorization->getRoles());

        if ($userId && !$isAPIKey && !$isPrivilegedUser) {
            throw new Exception(Exception::GENERAL_UNAUTHORIZED_SCOPE, 'userId is not allowed for non-API key and non-privileged users');
        }

        if (($isAPIKey || $isPrivilegedUser) && !$userId) {
            throw new Exception(Exception::GENERAL_BAD_REQUEST, 'userId is required for API key and privileged users');
        }

        $presence = $dbForProject->getDocument('presenceLogs', $presenceId);

        if ($presence->isEmpty()) {
            throw new Exception(Exception::DOCUMENT_NOT_FOUND, params: [$presenceId]);
        }

        $updateData = [];

        if ($userId !== null) {
            $updateData['userId'] = $userId;
        }

        if ($status !== null) {
            $updateData['status'] = $status;
        }

        if ($expiry !== null) {
            $updateData['expiry'] = $expiry;
        }

        if ($metadata !== null) {
            $updateData['metadata'] = $metadata;
        }

        $updates = new Document($updateData);

        if ($permissions !== null) {
            $this->setPermission($updates, $permissions, $user, $authorization);
        }

        if (empty($updateData) && $permissions === null) {
            $response->dynamic($presence, Response::MODEL_PRESENCE);
            return;
        }

        $presence = $dbForProject->updateDocument('presenceLogs', $presenceId, $updates);

        $response->dynamic($presence, Response::MODEL_PRESENCE);
    }
}
