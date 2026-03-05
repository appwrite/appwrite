<?php

namespace Appwrite\Platform\Modules\Teams\Http\Teams;

use Appwrite\Event\Event;
use Appwrite\Extend\Exception;
use Appwrite\Platform\Action;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Database\Documents\User;
use Appwrite\Utopia\Database\Validator\CustomId;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Database\Exception\Duplicate;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Helpers\Permission;
use Utopia\Database\Helpers\Role;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\Key;
use Utopia\Platform\Scope\HTTP;
use Utopia\Validator\ArrayList;
use Utopia\Validator\Text;

class Create extends Action
{
    use HTTP;

    public static function getName()
    {
        return 'createTeam';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_POST)
            ->setHttpPath('/v1/teams')
            ->desc('Create team')
            ->groups(['api', 'teams'])
            ->label('event', 'teams.[teamId].create')
            ->label('scope', 'teams.write')
            ->label('audits.event', 'team.create')
            ->label('audits.resource', 'team/{response.$id}')
            ->label('sdk', new Method(
                namespace: 'teams',
                group: 'teams',
                name: 'create',
                description: '/docs/references/teams/create-team.md',
                auth: [AuthType::ADMIN, AuthType::SESSION, AuthType::KEY, AuthType::JWT],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_CREATED,
                        model: Response::MODEL_TEAM,
                    )
                ]
            ))
            ->param('teamId', '', new CustomId(), 'Team ID. Choose a custom ID or generate a random ID with `ID.unique()`. Valid chars are a-z, A-Z, 0-9, period, hyphen, and underscore. Can\'t start with a special char. Max length is 36 chars.')
            ->param('name', null, new Text(128), 'Team name. Max length: 128 chars.')
            ->param('roles', ['owner'], new ArrayList(new Key(), APP_LIMIT_ARRAY_PARAMS_SIZE), 'Array of strings. Use this param to set the roles in the team for the user who created it. The default role is **owner**. A role can be any string. Learn more about [roles and permissions](https://appwrite.io/docs/permissions). Maximum of ' . APP_LIMIT_ARRAY_PARAMS_SIZE . ' roles are allowed, each 32 characters long.', true)
            ->inject('response')
            ->inject('user')
            ->inject('dbForProject')
            ->inject('authorization')
            ->inject('queueForEvents')
            ->callback($this->action(...));
    }

    public function action(string $teamId, string $name, array $roles, Response $response, Document $user, Database $dbForProject, Authorization $authorization, Event $queueForEvents)
    {
        $isPrivilegedUser = User::isPrivileged($authorization->getRoles());
        $isAppUser = User::isApp($authorization->getRoles());

        $teamId = $teamId == 'unique()' ? ID::unique() : $teamId;

        try {
            $team = $authorization->skip(fn () => $dbForProject->createDocument('teams', new Document([
                '$id' => $teamId,
                '$permissions' => [
                    Permission::read(Role::team($teamId)),
                    Permission::update(Role::team($teamId, 'owner')),
                    Permission::delete(Role::team($teamId, 'owner')),
                ],
                'labels' => [],
                'name' => $name,
                'total' => ($isPrivilegedUser || $isAppUser) ? 0 : 1,
                'prefs' => new \stdClass(),
                'search' => implode(' ', [$teamId, $name]),
            ])));
        } catch (Duplicate $th) {
            throw new Exception(Exception::TEAM_ALREADY_EXISTS);
        }

        if (!$isPrivilegedUser && !$isAppUser) { // Don't add user on server mode
            if (!\in_array('owner', $roles)) {
                $roles[] = 'owner';
            }

            $membershipId = ID::unique();
            $membership = new Document([
                '$id' => $membershipId,
                '$permissions' => [
                    Permission::read(Role::user($user->getId())),
                    Permission::read(Role::team($team->getId())),
                    Permission::update(Role::user($user->getId())),
                    Permission::update(Role::team($team->getId(), 'owner')),
                    Permission::delete(Role::user($user->getId())),
                    Permission::delete(Role::team($team->getId(), 'owner')),
                ],
                'userId' => $user->getId(),
                'userInternalId' => $user->getSequence(),
                'teamId' => $team->getId(),
                'teamInternalId' => $team->getSequence(),
                'roles' => $roles,
                'invited' => DateTime::now(),
                'joined' => DateTime::now(),
                'confirm' => true,
                'secret' => '',
                'search' => implode(' ', [$membershipId, $user->getId()])
            ]);

            $membership = $dbForProject->createDocument('memberships', $membership);
            $dbForProject->purgeCachedDocument('users', $user->getId());
        }

        $queueForEvents->setParam('teamId', $team->getId());

        if (!empty($user->getId())) {
            $queueForEvents->setParam('userId', $user->getId());
        }

        $response
            ->setStatusCode(Response::STATUS_CODE_CREATED)
            ->dynamic($team, Response::MODEL_TEAM);
    }
}
