<?php

namespace Appwrite\Platform\Modules\Teams\Http\Memberships;

use Appwrite\Auth\MFA\Type\TOTP;
use Appwrite\Extend\Exception;
use Appwrite\Platform\Action;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Database\Documents\User;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Scope\HTTP;

class Get extends Action
{
    use HTTP;

    public static function getName()
    {
        return 'getTeamMembership';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/teams/:teamId/memberships/:membershipId')
            ->desc('Get team membership')
            ->groups(['api', 'teams'])
            ->label('scope', 'teams.read')
            ->label('sdk', new Method(
                namespace: 'teams',
                group: 'memberships',
                name: 'getMembership',
                description: '/docs/references/teams/get-team-member.md',
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
            ->inject('response')
            ->inject('project')
            ->inject('dbForProject')
            ->inject('authorization')
            ->callback($this->action(...));
    }

    public function action(string $teamId, string $membershipId, Response $response, Document $project, Database $dbForProject, Authorization $authorization)
    {
        $team = $dbForProject->getDocument('teams', $teamId);

        if ($team->isEmpty()) {
            throw new Exception(Exception::TEAM_NOT_FOUND);
        }

        $membership = $dbForProject->getDocument('memberships', $membershipId);

        if ($membership->isEmpty() || empty($membership->getAttribute('userId'))) {
            throw new Exception(Exception::MEMBERSHIP_NOT_FOUND);
        }

        $membershipsPrivacy =  [
            'userName' => $project->getAttribute('auths', [])['membershipsUserName'] ?? true,
            'userEmail' => $project->getAttribute('auths', [])['membershipsUserEmail'] ?? true,
            'mfa' => $project->getAttribute('auths', [])['membershipsMfa'] ?? true,
        ];

        $roles = $authorization->getRoles();
        $isPrivilegedUser = User::isPrivileged($roles);
        $isAppUser = User::isApp($roles);

        $membershipsPrivacy = array_map(function ($privacy) use ($isPrivilegedUser, $isAppUser) {
            return $privacy || $isPrivilegedUser || $isAppUser;
        }, $membershipsPrivacy);

        $user = !empty(array_filter($membershipsPrivacy))
            ? $dbForProject->getDocument('users', $membership->getAttribute('userId'))
            : new Document();

        if ($membershipsPrivacy['mfa']) {
            $mfa = $user->getAttribute('mfa', false);

            if ($mfa) {
                $totp = TOTP::getAuthenticatorFromUser($user);
                $totpEnabled = $totp && $totp->getAttribute('verified', false);
                $emailEnabled = $user->getAttribute('email', false) && $user->getAttribute('emailVerification', false);
                $phoneEnabled = $user->getAttribute('phone', false) && $user->getAttribute('phoneVerification', false);

                if (!$totpEnabled && !$emailEnabled && !$phoneEnabled) {
                    $mfa = false;
                }
            }

            $membership->setAttribute('mfa', $mfa);
        }

        if ($membershipsPrivacy['userName']) {
            $membership->setAttribute('userName', $user->getAttribute('name'));
        }

        if ($membershipsPrivacy['userEmail']) {
            $membership->setAttribute('userEmail', $user->getAttribute('email'));
        }

        $membership->setAttribute('teamName', $team->getAttribute('name'));

        $response->dynamic($membership, Response::MODEL_MEMBERSHIP);
    }
}
