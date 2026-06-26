<?php

namespace Appwrite\Platform\Modules\Presences\HTTP;

use Appwrite\Event\Event;
use Appwrite\Extend\Exception;
use Appwrite\Platform\Action as PlatformAction;
use Appwrite\Presences\State as PresenceState;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Parameter;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Database\Documents\User;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Database\Exception\Conflict as ConflictException;
use Utopia\Database\Exception\Duplicate;
use Utopia\Database\Exception\Structure as StructureException;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\Datetime as DatetimeValidator;
use Utopia\Database\Validator\Permissions;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Action;
use Utopia\Validator\Boolean;
use Utopia\Validator\JSON;
use Utopia\Validator\Text;

class Update extends PlatformAction
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
            ->label('scope', 'presences.write')
            ->label('event', 'presences.[presenceId].update')
            ->label('audits.event', 'presence.update')
            ->label('audits.resource', 'presence/{response.$id}')
            ->label('usage.resource', 'presence/{response.$id}')
            ->label('sdk', [
                // Client-side SDK: `userId` is not accepted (session callers can only update their own presence).
                new Method(
                    namespace: 'presences',
                    group: 'presences',
                    name: 'update',
                    desc: 'Update presence',
                    description: '/docs/references/presences/update.md',
                    auth: [AuthType::SESSION, AuthType::ADMIN],
                    responses: [
                        new SDKResponse(
                            code: Response::STATUS_CODE_OK,
                            model: Response::MODEL_PRESENCE,
                        ),
                    ],
                    parameters: [
                        new Parameter('presenceId', optional: false),
                        new Parameter('status', optional: true),
                        new Parameter('expiresAt', optional: true),
                        new Parameter('metadata', optional: true),
                        new Parameter('permissions', optional: true),
                        new Parameter('purge', optional: true),
                    ],
                ),
                // Server-side SDK: `userId` is required when authenticating with API keys/JWT.
                new Method(
                    namespace: 'presences',
                    group: 'presences',
                    name: 'update',
                    desc: 'Update presence',
                    description: '/docs/references/presences/update.md',
                    auth: [AuthType::KEY, AuthType::JWT],
                    responses: [
                        new SDKResponse(
                            code: Response::STATUS_CODE_OK,
                            model: Response::MODEL_PRESENCE,
                        ),
                    ],
                    parameters: [
                        new Parameter('presenceId', optional: false),
                        new Parameter('userId', optional: false),
                        new Parameter('status', optional: true),
                        new Parameter('expiresAt', optional: true),
                        new Parameter('metadata', optional: true),
                        new Parameter('permissions', optional: true),
                        new Parameter('purge', optional: true),
                    ],
                ),
            ])
            ->param('presenceId', '', fn (Database $dbForProject) => new UID($dbForProject->getAdapter()->getMaxUIDLength()), 'Presence unique ID.', false, ['dbForProject'])
            ->param('userId', null, new UID(), 'User ID.', true)
            ->param('status', null, new Text(Database::LENGTH_KEY), 'Presence status.', true)
            ->param('expiresAt', null, new DatetimeValidator(
                new \DateTime(),
                (new \DateTime())->modify('+30 days'),
                requireDateInFuture: true
            ), 'Presence expiry datetime.', true)
            ->param('metadata', null, new JSON(), 'Presence metadata object.', true)
            ->param('permissions', null, new Permissions(APP_LIMIT_ARRAY_PARAMS_SIZE, [Database::PERMISSION_READ, Database::PERMISSION_UPDATE, Database::PERMISSION_DELETE, Database::PERMISSION_WRITE]), 'An array of permissions strings. By default, only the current user is granted all permissions. [Learn more about permissions](https://appwrite.io/docs/permissions).', true)
            ->param('purge', false, new Boolean(true), 'When true, purge cached responses used by list presences endpoint.', true)
            ->inject('response')
            ->inject('dbForProject')
            ->inject('user')
            ->inject('authorization')
            ->inject('queueForEvents')
            ->callback($this->action(...));
    }

    public function action(
        string $presenceId,
        ?string $userId,
        ?string $status,
        ?string $expiresAt,
        ?array $metadata,
        ?array $permissions,
        bool $purge,
        Response $response,
        Database $dbForProject,
        User $user,
        Authorization $authorization,
        Event $queueForEvents
    ): void {
        $presenceState = new PresenceState();
        $isAPIKey = $user->isKey($authorization->getRoles());
        $isPrivilegedUser = $user->isPrivileged($authorization->getRoles());

        if ($userId && !$isAPIKey && !$isPrivilegedUser) {
            throw new Exception(Exception::GENERAL_UNAUTHORIZED_SCOPE, 'userId is not allowed for non-API key and non-privileged users');
        }

        $presence = $dbForProject->getDocument('presenceLogs', $presenceId);

        if ($presence->isEmpty()) {
            throw new Exception(Exception::PRESENCE_NOT_FOUND, params: [$presenceId]);
        }

        $presenceExpiresAt = $presence->getAttribute('expiresAt');
        if (!empty($presenceExpiresAt) && DateTime::formatTz($presenceExpiresAt) < DateTime::formatTz(DateTime::now())) {
            throw new Exception(Exception::PRESENCE_NOT_FOUND, params: [$presenceId]);
        }

        $updateData = [];

        if ($userId !== null) {
            $updateData['userId'] = $userId;
            $userDoc = $dbForProject->getDocument('users', $userId);
            if ($userDoc->isEmpty()) {
                throw new Exception(Exception::USER_NOT_FOUND, params: [$userId]);
            }
            $updateData['userInternalId'] = $userDoc->getSequence();
        }

        if ($status !== null) {
            $updateData['status'] = $status;
        }

        if ($expiresAt !== null) {
            $updateData['expiresAt'] = $expiresAt;
        }

        if ($metadata !== null) {
            $updateData['metadata'] = $metadata;
        }

        $updates = new Document($updateData);

        if ($permissions !== null) {
            $presenceState->setPermissions($updates, $permissions, $user, $authorization);
        } elseif ($userId !== null && $userId !== $presence->getAttribute('userId')) {
            $presenceState->setPermissions($updates, null, $user, $authorization, ownerOverride: $userId);
        }

        if (empty($updateData) && $permissions === null) {
            if ($purge) {
                $presenceState->purgeListCache($dbForProject);
            }
            $response->dynamic($presence, Response::MODEL_PRESENCE);
            return;
        }

        try {
            $presence = $dbForProject->updateDocument('presenceLogs', $presenceId, $updates);
        } catch (Duplicate $e) {
            throw new Exception(Exception::PRESENCE_ALREADY_EXISTS, params: [$presenceId], previous: $e);
        } catch (StructureException $e) {
            throw new Exception(Exception::DOCUMENT_INVALID_STRUCTURE, $e->getMessage(), previous: $e);
        } catch (ConflictException $e) {
            throw new Exception(Exception::DOCUMENT_UPDATE_CONFLICT, $e->getMessage(), previous: $e);
        }

        if ($purge) {
            $presenceState->purgeListCache($dbForProject);
        }

        $queueForEvents->setParam('presenceId', $presence->getId());

        $response->dynamic($presence, Response::MODEL_PRESENCE);
    }
}
