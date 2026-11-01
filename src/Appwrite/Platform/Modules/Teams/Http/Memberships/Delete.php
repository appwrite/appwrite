<?php

namespace Appwrite\Platform\Modules\Teams\Http\Memberships;

use Appwrite\Event\Event;
use Appwrite\Extend\Exception;
use Appwrite\Platform\Action;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Exception\Authorization as AuthorizationException;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Scope\HTTP;

class Delete extends Action
{
    use HTTP;

    public static function getName()
    {
        return 'deleteTeamMembership';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_DELETE)
            ->setHttpPath('/v1/teams/:teamId/memberships/:membershipId')
            ->desc('Delete team membership')
            ->groups(['api', 'teams'])
            ->label('event', 'teams.[teamId].memberships.[membershipId].delete')
            ->label('scope', 'teams.write')
            ->label('audits.event', 'membership.delete')
            ->label('audits.resource', 'team/{request.teamId}')
            ->label('sdk', new Method(
                namespace: 'teams',
                group: 'memberships',
                name: 'deleteMembership',
                description: '/docs/references/teams/delete-team-membership.md',
                auth: [AuthType::ADMIN, AuthType::SESSION, AuthType::KEY, AuthType::JWT],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_NOCONTENT,
                        model: Response::MODEL_NONE,
                    )
                ],
                contentType: ContentType::NONE
            ))
            ->param('teamId', '', new UID(), 'Team ID.')
            ->param('membershipId', '', new UID(), 'Membership ID.')
            ->inject('user')
            ->inject('project')
            ->inject('response')
            ->inject('dbForProject')
            ->inject('authorization')
            ->inject('queueForEvents')
            ->callback($this->action(...));
    }

    public function action(string $teamId, string $membershipId, Document $user, Document $project, Response $response, Database $dbForProject, Authorization $authorization, Event $queueForEvents)
    {
        $membership = $dbForProject->getDocument('memberships', $membershipId);
        if ($membership->isEmpty()) {
            throw new Exception(Exception::TEAM_INVITE_NOT_FOUND);
        }

        $profile = $dbForProject->getDocument('users', $membership->getAttribute('userId'));
        if ($profile->isEmpty()) {
            throw new Exception(Exception::USER_NOT_FOUND);
        }

        $team = $dbForProject->getDocument('teams', $teamId);
        if ($team->isEmpty()) {
            throw new Exception(Exception::TEAM_NOT_FOUND);
        }

        if ($membership->getAttribute('teamInternalId') !== $team->getSequence()) {
            throw new Exception(Exception::TEAM_MEMBERSHIP_MISMATCH);
        }

        $this->validate($profile, $team, $dbForProject);

        // An organization must keep a confirmed owner, whoever deletes the membership: a session, or an API key.
        // Counting after the delete, in the same transaction, keeps an unauthorized caller on the 401 path.
        $isConfirmedOwner = $membership->getAttribute('confirm', false)
            && \in_array('owner', $membership->getAttribute('roles', []), true);

        $dbForProject->withTransaction(function () use ($dbForProject, $membership, $project, $team, $isConfirmedOwner) {
            try {
                $dbForProject->deleteDocument('memberships', $membership->getId());
            } catch (AuthorizationException $exception) {
                throw new Exception(Exception::USER_UNAUTHORIZED);
            }

            if ($project->getId() !== 'console' || !$isConfirmedOwner) {
                return;
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
                // Other roles can neither add an owner nor delete the organization, so it would be left unmanageable
                throw new Exception(Exception::MEMBERSHIP_DELETION_PROHIBITED, 'There must be at least one owner in the organization.');
            }
        });

        $dbForProject->purgeCachedDocument('users', $profile->getId());

        // This membership is primary for the team, update the primary to next member.
        if ($team->getAttribute('userInternalId') === $membership->getAttribute('userInternalId')) {
            $successor = $dbForProject->findOne('memberships', [
                Query::equal('teamInternalId', [$team->getSequence()]),
                Query::equal('confirm', [true]),
            ]);

            if (!$successor->isEmpty()) {
                $team->setAttribute('userId', $successor->getAttribute('userId'));
                $team->setAttribute('userInternalId', $successor->getAttribute('userInternalId'));
                $dbForProject->updateDocument('teams', $team->getId(), new Document([
                    'userId' => $successor->getAttribute('userId'),
                    'userInternalId' => $successor->getAttribute('userInternalId'),
                ]));
            }
        }

        if ($membership->getAttribute('confirm')) { // Count only confirmed members
            $authorization->skip(fn () => $dbForProject->decreaseDocumentAttribute('teams', $team->getId(), 'total', 1, 0));
        }

        $queueForEvents
            ->setParam('teamId', $team->getId())
            ->setParam('userId', $profile->getId())
            ->setParam('membershipId', $membership->getId())
            ->setPayload($response->output($membership, Response::MODEL_MEMBERSHIP));

        $response->noContent();
    }

    protected function validate(Document $profile, Document $team, Database $dbForProject): void
    {
        return;
    }
}
