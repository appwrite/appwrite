<?php

namespace Appwrite\Platform\Modules\Teams\Http\Memberships;

use Appwrite\Event\Event;
use Appwrite\Extend\Exception;
use Appwrite\Platform\Action;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Database\Documents\User;
use Appwrite\Utopia\Request;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\Key;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Scope\HTTP;
use Utopia\Validator\ArrayList;

class Update extends Action
{
    use HTTP;

    public static function getName()
    {
        return 'updateTeamMembership';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_PATCH)
            ->setHttpPath('/v1/teams/:teamId/memberships/:membershipId')
            ->desc('Update team membership')
            ->groups(['api', 'teams'])
    ->label('event', 'teams.[teamId].memberships.[membershipId].update')
    ->label('scope', 'teams.write')
    ->label('audits.event', 'membership.update')
    ->label('audits.resource', 'team/{request.teamId}')
    ->label('sdk', new Method(
        namespace: 'teams',
        group: 'memberships',
        name: 'updateMembership',
        description: '/docs/references/teams/update-team-membership.md',
        auth: [AuthType::ADMIN, AuthType::SESSION, AuthType::KEY, AuthType::JWT],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_OK,
                model: Response::MODEL_MEMBERSHIP,
            )
        ]
    ))
    ->param('teamId', '', new UID(), 'Team ID.')
    ->param('membershipId', '', new UID(), 'Membership ID.')
    ->param('roles', [], new ArrayList(new Key(), APP_LIMIT_ARRAY_PARAMS_SIZE), 'An array of strings. Use this param to set the user\'s roles in the team. A role can be any string. Learn more about [roles and permissions](https://appwrite.io/docs/permissions). Maximum of ' . APP_LIMIT_ARRAY_PARAMS_SIZE . ' roles are allowed, each 32 characters long.', false, ['project'])
    ->inject('request')
    ->inject('response')
    ->inject('user')
    ->inject('project')
    ->inject('dbForProject')
    ->inject('authorization')
    ->inject('queueForEvents')
    ->callback($this->action(...));
    }

    public function action(string $teamId, string $membershipId, array $roles, Request $request, Response $response, Document $user, Document $project, Database $dbForProject, Authorization $authorization, Event $queueForEvents)
    {
        $team = $dbForProject->getDocument('teams', $teamId);
        if ($team->isEmpty()) {
            throw new Exception(Exception::TEAM_NOT_FOUND);
        }

        $membership = $dbForProject->getDocument('memberships', $membershipId);
        if ($membership->isEmpty()) {
            throw new Exception(Exception::MEMBERSHIP_NOT_FOUND);
        }

        $profile = $dbForProject->getDocument('users', $membership->getAttribute('userId'));
        if ($profile->isEmpty()) {
            throw new Exception(Exception::USER_NOT_FOUND);
        }

        $isPrivilegedUser = User::isPrivileged($authorization->getRoles());
        $isAppUser = User::isApp($authorization->getRoles());
        $isOwner = $authorization->hasRole('team:' . $team->getId() . '/owner');

        if ($project->getId() === 'console') {
            // Quick check: fetch up to 2 owners to determine if only one exists
            $ownersCount = $dbForProject->count(
                collection: 'memberships',
                queries: [
                    Query::contains('roles', ['owner']),
                    Query::equal('teamInternalId', [$team->getSequence()])
                ],
                max: 2
            );

            // Is the role change being requested by the user on their own membership?
            $isCurrentUserAnOwner =  $user->getSequence() === $membership->getAttribute('userInternalId');

            // Prevent role change if there's only one owner left,
            // the requester is that owner, and the new `$roles` no longer include 'owner'
            if ($ownersCount === 1 && $isOwner && $isCurrentUserAnOwner && !\in_array('owner', $roles)) {
                throw new Exception(Exception::MEMBERSHIP_DOWNGRADE_PROHIBITED, 'There must be at least one owner in the organization.');
            }
        }

        if (!$isOwner && !$isPrivilegedUser && !$isAppUser) { // Not owner, not admin, not app (server)
            throw new Exception(Exception::USER_UNAUTHORIZED, 'User is not allowed to modify roles');
        }

        /**
         * Update the roles
         */
        $membership->setAttribute('roles', $roles);
        $membership = $dbForProject->updateDocument('memberships', $membership->getId(), $membership);

        /**
         * Replace membership on profile
         */
        $dbForProject->purgeCachedDocument('users', $profile->getId());

        $queueForEvents
            ->setParam('userId', $profile->getId())
            ->setParam('teamId', $team->getId())
            ->setParam('membershipId', $membership->getId());

        $response->dynamic(
            $membership
                ->setAttribute('teamName', $team->getAttribute('name'))
                ->setAttribute('userName', $profile->getAttribute('name'))
                ->setAttribute('userEmail', $profile->getAttribute('email')),
            Response::MODEL_MEMBERSHIP
        );
    }
}
