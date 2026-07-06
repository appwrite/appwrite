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
use Appwrite\Usage\Context;
use Appwrite\Utopia\Database\Documents\User;
use Appwrite\Utopia\Request;
use Psr\Http\Message\ServerRequestInterface;
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
            ->label('scope', 'presences.write')
            ->label('event', 'presences.[presenceId].upsert')
            ->label('audits.event', 'presence.upsert')
            ->label('audits.resource', 'presence/{response.$id}')
            ->label('sdk', [
                // Client-side SDK: `userId` is not accepted (session callers should just upsert their own presence).
                new Method(
                    namespace: 'presences',
                    group: 'presences',
                    name: 'upsert',
                    desc: 'Upsert presence',
                    description: '/docs/references/presences/upsert.md',
                    auth: [AuthType::SESSION, AuthType::ADMIN],
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
                    name: 'upsert',
                    desc: 'Upsert presence',
                    description: '/docs/references/presences/upsert.md',
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
                        new Parameter('status', optional: false),
                        new Parameter('permissions', optional: true),
                        new Parameter('expiresAt', optional: true),
                        new Parameter('metadata', optional: true),
                    ],
                ),
            ])
            ->param('presenceId', '', fn (Database $dbForProject) => new UID($dbForProject->getAdapter()->getMaxUIDLength()), 'Presence unique ID.', false, ['dbForProject'])
            ->param('userId', null, new UID(), 'User ID.', true)
            ->param('status', '', new Text(Database::LENGTH_KEY), 'Presence status.', false)
            ->param('permissions', null, new Permissions(APP_LIMIT_ARRAY_PARAMS_SIZE, [Database::PERMISSION_READ, Database::PERMISSION_UPDATE, Database::PERMISSION_DELETE, Database::PERMISSION_WRITE]), 'An array of permissions strings. By default, only the current user is granted all permissions. [Learn more about permissions](https://appwrite.io/docs/permissions).', true)
            ->param('expiresAt', null, new DatetimeValidator(
                new \DateTime(),
                (new \DateTime())->modify('+30 days'),
                requireDateInFuture: true
            ), 'Presence expiry datetime.', true)
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
        ServerRequestInterface $request,
        Database $dbForProject,
        User $user,
        Authorization $authorization,
        Event $queueForEvents,
        Context $usage
    ): void {
        $isAPIKey = $user->isKey($authorization->getRoles());
        $isPrivilegedUser = $user->isPrivileged($authorization->getRoles());
        if ($userId && !$isAPIKey && !$isPrivilegedUser) {
            throw new Exception(Exception::GENERAL_UNAUTHORIZED_SCOPE, "userId is not allowed for non-API key and non-privileged users");
        }

        // API keys have no associated session user — they must target one explicitly
        if ($isAPIKey && !$userId) {
            throw new Exception(Exception::GENERAL_BAD_REQUEST, "userId is required for API key authentication");
        }
        $userInternalId = null;
        $resolvedUserId = $userId;
        if ($userId) {
            $fetchedUser = $dbForProject->getDocument('users', $userId);
            if ($fetchedUser->isEmpty()) {
                throw new Exception(Exception::USER_NOT_FOUND, params: [$userId]);
            }

            $userInternalId = (string) $fetchedUser->getSequence();
            $resolvedUserId = $fetchedUser->getId();
        } else {
            $userInternalId = $user->getSequence();
            $resolvedUserId = $user->getId();
        }

        if (empty($userInternalId)) {
            throw new Exception(Exception::GENERAL_SERVER_ERROR, 'Failed to resolve valid user internal ID.');
        }
        $isGraphQL = $request->getHeaderLine('x-appwrite-source') === 'graphql';

        $presenceData = [
            'userInternalId' => $userInternalId,
            'userId' => $resolvedUserId,
            'status' => $status,
            'source' => $isGraphQL ? 'graphql' : 'rest',
            'expiresAt' => $expiresAt ?? DateTime::addSeconds(new \DateTime(), 15 * 60),
            'metadata' => $metadata,
        ];

        $presenceState = new PresenceState();
        $presenceDocument = new Document($presenceData);
        $ownerOverride = $permissions === null && ($isAPIKey || $isPrivilegedUser)
            ? $resolvedUserId
            : null;
        $presenceState->setPermissions(
            $presenceDocument,
            $permissions,
            $user,
            $authorization,
            ownerOverride: $ownerOverride,
        );
        $presence = $presenceState->upsertForUser(
            $dbForProject,
            $presenceDocument,
            $presenceId,
            $userInternalId,
            fn () => $usage->addMetric(METRIC_USERS_PRESENCE, 1)
        );
        $queueForEvents->setParam('presenceId', $presence->getId());

        $response->dynamic($presence, Response::MODEL_PRESENCE);
    }
}
