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
            ->param('roles', [], new ArrayList(new Key(maxLength: APP_LIMIT_ROLE_LENGTH), APP_LIMIT_ARRAY_PARAMS_SIZE), 'An array of strings. Use this param to set the user\'s roles in the team. A role can be any string. Learn more about [roles and permissions](https://appwrite.io/docs/permissions). Maximum of ' . APP_LIMIT_ARRAY_PARAMS_SIZE . ' roles are allowed, each ' . APP_LIMIT_ROLE_LENGTH . ' characters long.', false, ['project'], example: '["editor"]')
            ->inject('request')
            ->inject('response')
            ->inject('user')
            ->inject('project')
            ->inject('dbForProject')
            ->inject('authorization')
            ->inject('queueForEvents')
            ->callback($this->action(...));
    }

    public function action(string $teamId, string $membershipId, array $roles, Request $request, Response $response, User $user, Document $project, Database $dbForProject, Authorization $authorization, Event $queueForEvents)
    {
        $team = $dbForProject->getDocument('teams', $teamId);
        if ($team->isEmpty()) {
            throw new Exception(Exception::TEAM_NOT_FOUND);
        }

        $membership = $dbForProject->getDocument('memberships', $membershipId);
        if ($membership->isEmpty()) {
            throw new Exception(Exception::MEMBERSHIP_NOT_FOUND);
        }

        if ($membership->getAttribute('teamInternalId') !== $team->getSequence()) {
            throw new Exception(Exception::TEAM_MEMBERSHIP_MISMATCH);
        }

        $profile = $dbForProject->getDocument('users', $membership->getAttribute('userId'));
        if ($profile->isEmpty()) {
            throw new Exception(Exception::USER_NOT_FOUND);
        }

        $isPrivilegedUser = $user->isPrivileged($authorization->getRoles());
        $isAppUser = $user->isKey($authorization->getRoles());
        $isOwner = $authorization->hasRole('team:' . $team->getId() . '/owner');

        // Console organization developer/admin become bare privileged roles when
        // X-Appwrite-Organization is applied. Those roles must not authorize
        // membership role changes; only the team owner or an API key may.
        if (!$isOwner && !$isAppUser && ($project->getId() === 'console' || !$isPrivilegedUser)) {
            throw new Exception(Exception::USER_UNAUTHORIZED, 'User is not allowed to modify roles');
        }

        // An organization must keep a confirmed owner, whoever makes the change: a session, or an API key.
        // Locking the team first serializes concurrent changes to its owners; counting after the update,
        // in the same transaction, sees this change and refuses it as a whole.
        $isDemotingConfirmedOwner = $project->getId() === 'console'
            && $membership->getAttribute('confirm', false)
            && \in_array('owner', $membership->getAttribute('roles', []), true)
            && !\in_array('owner', $roles, true);

        $membership = $dbForProject->withTransaction(function () use ($dbForProject, $membership, $roles, $team, $isDemotingConfirmedOwner) {
            if ($isDemotingConfirmedOwner) {
                $dbForProject->getDocument('teams', $team->getId(), forUpdate: true);
            }

            $membership = $dbForProject->updateDocument('memberships', $membership->getId(), new Document(['roles' => $roles]));

            if (!$isDemotingConfirmedOwner) {
                return $membership;
            }

            $owners = $dbForProject->count(
                collection: 'memberships',
                queries: [
                    Query::containsAny('roles', ['owner']),
                    Query::equal('teamInternalId', [$team->getSequence()]),
                    Query::equal('confirm', [true]),
                ],
                max: 1
            );

            if ($owners === 0) {
                throw new Exception(Exception::MEMBERSHIP_DOWNGRADE_PROHIBITED, 'There must be at least one owner in the organization.');
            }

            return $membership;
        });

        /**
         * Replace membership on profile
         */
        $dbForProject->purgeCachedDocument('users', $profile->getId());

        $queueForEvents
            ->setParam('userId', $profile->getId())
            ->setParam('teamId', $team->getId())
            ->setParam('membershipId', $membership->getId());

        // The privacy policy only hides other members, a member always sees their own details
        $auths = $project->getAttribute('auths', []);
        $isVisible = $isPrivilegedUser || $isAppUser
            || ($user->getSequence() !== null && $membership->getAttribute('userInternalId') === $user->getSequence());

        $response->dynamic(
            $membership
                ->setAttribute('teamName', $team->getAttribute('name'))
                ->setAttribute('userName', $isVisible || ($auths['membershipsUserName'] ?? true) ? $profile->getAttribute('name') : '')
                ->setAttribute('userEmail', $isVisible || ($auths['membershipsUserEmail'] ?? true) ? $profile->getAttribute('email') : ''),
            Response::MODEL_MEMBERSHIP
        );
    }
}
