<?php

namespace Appwrite\Platform\Modules\Presences\HTTP;

use Appwrite\Databases\PresenceState;
use Appwrite\Event\Event;
use Appwrite\Extend\Exception;
use Appwrite\Platform\Action as PlatformAction;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Parameter;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Usage\Context;
use Appwrite\Utopia\Database\Documents\User;
use Appwrite\Utopia\Request;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\Datetime as DatetimeValidator;
use Utopia\Database\Validator\Permissions;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Action;
use Utopia\Platform\Scope\HTTP;
use Utopia\Validator\JSON;
use Utopia\Validator\Nullable;
use Utopia\Validator\Text;

class Upsert extends PlatformAction
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
            ->label('scope', 'presences.read')
            ->label('event', 'presences.[presenceId].upsert')
            ->label('audits.event', 'presence.upsert')
            ->label('audits.resource', 'presence/{response.$id}')
            ->label('sdk', [
                // Client-side SDK: `userId` is not accepted (session callers should just upsert their own presence).
                new Method(
                    namespace: 'presences',
                    group: 'presences',
                    name: 'upsertPresence',
                    description: 'Create or update a presence log by its unique ID.',
                    auth: [AuthType::SESSION],
                    responses: [
                        new SDKResponse(
                            code: Response::STATUS_CODE_OK,
                            model: Response::MODEL_PRESENCE,
                        ),
                    ],
                    parameters: [
                        new Parameter('presenceId', optional: false),
                        new Parameter('status', optional: false),
                        new Parameter('permissions', optional: true),
                        new Parameter('expiresAt', optional: true),
                        new Parameter('metadata', optional: true),
                    ],
                ),
                // Server-side SDK: `userId` is required when authenticating with API keys/JWT.
                new Method(
                    namespace: 'presences',
                    group: 'presences',
                    name: 'upsertPresence',
                    description: 'Create or update a presence log by its unique ID.',
                    auth: [AuthType::KEY, AuthType::JWT, AuthType::ADMIN],
                    responses: [
                        new SDKResponse(
                            code: Response::STATUS_CODE_OK,
                            model: Response::MODEL_PRESENCE,
                        ),
                    ],
                    parameters: [
                        new Parameter('presenceId', optional: false),
                        new Parameter('userId', optional: false),
                        new Parameter('status', optional: false),
                        new Parameter('permissions', optional: true),
                        new Parameter('expiresAt', optional: true),
                        new Parameter('metadata', optional: true),
                    ],
                ),
            ])
            ->param('presenceId', '', fn (Database $dbForProject) => new UID($dbForProject->getAdapter()->getMaxUIDLength()), 'Presence unique ID.', false, ['dbForProject'])
            ->param('userId', null, new Nullable(new UID()), 'User ID.', true)
            ->param('status', '', new Text(Database::LENGTH_KEY), 'Presence status.', false)
            ->param('permissions', null, new Nullable(new Permissions(APP_LIMIT_ARRAY_PARAMS_SIZE, [Database::PERMISSION_READ, Database::PERMISSION_UPDATE, Database::PERMISSION_DELETE, Database::PERMISSION_WRITE])), 'An array of permissions strings. By default, only the current user is granted all permissions. [Learn more about permissions](https://appwrite.io/docs/permissions).', true)
            // TODO: what shall be the min and max date here
            ->param('expiresAt', null, new Nullable(new DatetimeValidator(requireDateInFuture: true)), 'Presence expiry datetime.', true)
            ->param('metadata', [], new JSON(), 'Presence metadata object.', true)
            ->inject('response')
            ->inject('request')
            ->inject('dbForProject')
            ->inject('user')
            ->inject('authorization')
            ->inject('queueForEvents')
            ->inject('usage')
            ->callback($this->action(...));
    }

    public function action(
        string $presenceId,
        ?string $userId,
        ?string $status,
        ?array $permissions,
        ?string $expiresAt,
        array $metadata,
        Response $response,
        Request $request,
        Database $dbForProject,
        User $user,
        Authorization $authorization,
        Event $queueForEvents,
        Context $usage
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
        $isGraphQL = $request->getHeader('x-appwrite-source') === 'graphql';

        $presenceData = [
            'userInternalId' => $userInternalId,
            'userId' => $resolvedUserId,
            'status' => $status,
            'source' => $isGraphQL ? 'graphql' : 'rest',
            'expiresAt' => $expiresAt ?? DateTime::addSeconds(new \DateTime(), 15 * 60),
            // TODO: finding a way to find hostname
            // 'hostname' => $hostname,
            'metadata' => $metadata,
        ];

        $presenceState = new PresenceState();
        $presenceDocument = new Document($presenceData);
        $presenceState->setPermissions($presenceDocument, $permissions, $user, $authorization);
        $presence = $presenceState->upsertForUser(
            $dbForProject,
            $presenceDocument,
            $presenceId,
            $resolvedUserId,
            fn () => $usage->addMetric(METRIC_USERS_PRESENCE, 1)
        );
        $queueForEvents->setParam('presenceId', $presence->getId());

        $response->dynamic($presence, Response::MODEL_PRESENCE);
    }
}
