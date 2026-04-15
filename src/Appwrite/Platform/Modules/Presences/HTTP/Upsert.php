<?php

namespace Appwrite\Platform\Modules\Presences\HTTP;

use Appwrite\Extend\Exception;
use Appwrite\ID;
use Appwrite\Platform\Modules\Presences\HTTP\Action as PresenceAction;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Database\Documents\User;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\Datetime as DatetimeValidator;
use Utopia\Database\Validator\Permissions;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Action;
use Utopia\Platform\Scope\HTTP;
use Utopia\Validator\JSON;
use Utopia\Validator\Nullable;
use Utopia\Validator\Text;

class Upsert extends PresenceAction
{
    use HTTP;

    public static function getName()
    {
        return 'upsertPresence';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_PUT)
            ->setHttpPath('/v1/presences/:presenceId')
            ->desc('Upsert presence')
            ->groups(['api', 'presences'])
            ->label('scope', 'users.write')
            ->label('sdk', new Method(
                namespace: 'presences',
                group: 'presences',
                name: 'upsert',
                description: 'Create or update a presence log by its unique ID.',
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
            ->param('status', '', new Text(Database::LENGTH_KEY), 'Presence status.', false)
            ->param('permissions', null, new Nullable(new Permissions(APP_LIMIT_ARRAY_PARAMS_SIZE, [Database::PERMISSION_READ, Database::PERMISSION_UPDATE, Database::PERMISSION_DELETE, Database::PERMISSION_WRITE])), 'An array of permissions strings. By default, only the current user is granted all permissions. [Learn more about permissions](https://appwrite.io/docs/permissions).', true)
            // TODO: what shall be the min and max date here
            ->param('expiry', null, new Nullable(new DatetimeValidator(requireDateInFuture: true)), 'Presence expiry datetime.', true)
            ->param('metadata', [], new JSON(), 'Presence metadata object.', true)
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
        ?array $permissions,
        ?string $expiry,
        array $metadata,
        Response $response,
        Database $dbForProject,
        User $user,
        Authorization $authorization
    ): void {
        $isAPIKey = $user->isApp($authorization->getRoles());
        $isPrivilegedUser = $user->isPrivileged($authorization->getRoles());
        if ($userId && !$isAPIKey && !$isPrivilegedUser) {
            throw new Exception(Exception::GENERAL_UNAUTHORIZED_SCOPE, "userId is not allowed for non-API key and non-privileged users");
        }

        if (($isAPIKey || $isPrivilegedUser) && !$userId) {
            throw new Exception(Exception::GENERAL_BAD_REQUEST, "userId is required for API key and privileged users");
        }
        $userInternalId = null;
        $resolvedUserId = $userId;
        if (!$isAPIKey && !$isPrivilegedUser) {
            $userInternalId = $user->getSequence();
            $resolvedUserId = $user->getId();
        } else {
            $fetchedUser = $dbForProject->getDocument('users', $userId);
            if ($fetchedUser->isEmpty()) {
                throw new Exception(Exception::USER_NOT_FOUND, params: [$userId]);
            }

            $userInternalId = $fetchedUser->getSequence();
            $resolvedUserId = $fetchedUser->getId();
        }

        $presenceData = [
            'userInternalId' => $userInternalId,
            'userId' => $resolvedUserId,
            'status' => $status,
            'source' => 'rest',
            'expiry' => $expiry ?? DateTime::addSeconds(new \DateTime(), 15 * 60),
            // TODO: finding a way to find hostname
            // 'hostname' => $hostname,
            'metadata' => $metadata,
        ];

        $presenceDocument = new Document($presenceData);
        $this->setPermission($presenceDocument, $permissions, $user, $authorization);

        // inside transaction as realtime also do get -> update/create
        $presence = $dbForProject->withTransaction(function () use ($dbForProject, $resolvedUserId, $presenceId, $presenceDocument) {
            $existingPresence = $dbForProject->findOne('presenceLogs', [
                Query::equal('userId', [$resolvedUserId]),
            ]);

            if ($existingPresence->isEmpty()) {
                $presenceId = $presenceId === 'unique()' ? ID::unique() : $presenceId;
                $presenceDocument->setAttribute('$id', $presenceId);
                return $dbForProject->createDocument('presenceLogs', $presenceDocument);
            }

            return $dbForProject->updateDocument('presenceLogs', $existingPresence->getId(), $presenceDocument);
        });

        $response->dynamic($presence, Response::MODEL_PRESENCE);
    }
}
