<?php

namespace Appwrite\Platform\Modules\Teams\Http\Memberships\Status;

use Appwrite\Extend\Exception;
use Appwrite\Platform\Action;
use Appwrite\Utopia\Request;
use Appwrite\Utopia\Response;
use Appwrite\Utopia\View;
use Utopia\Auth\Proofs\Token;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Scope\HTTP;
use Utopia\System\System;
use Utopia\Validator\Text;

class Get extends Action
{
    use HTTP;

    public static function getName(): string
    {
        return 'getTeamMembershipStatusView';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/teams/:teamId/memberships/:membershipId/status')
            ->desc('Get team membership status confirmation view')
            ->groups(['web', 'teams'])
            ->label('scope', 'public')
            ->param('teamId', '', new UID(), 'Team ID.')
            ->param('membershipId', '', new UID(), 'Membership ID.')
            ->param('userId', '', new UID(), 'User ID.')
            ->param('secret', '', new Text(256), 'Secret key.')
            ->param('redirectUrl', '', fn ($redirectValidator) => $redirectValidator, 'URL to redirect the user back to your app from the invitation email.', false, ['redirectValidator'])
            ->inject('request')
            ->inject('response')
            ->inject('dbForProject')
            ->inject('authorization')
            ->inject('project')
            ->inject('platform')
            ->inject('proofForToken')
            ->callback($this->action(...));
    }

    public function action(
        string $teamId,
        string $membershipId,
        string $userId,
        string $secret,
        string $redirectUrl,
        Request $request,
        Response $response,
        Database $dbForProject,
        Authorization $authorization,
        Document $project,
        array $platform,
        Token $proofForToken
    ): void {
        $membership = $dbForProject->getDocument('memberships', $membershipId);

        if ($membership->isEmpty()) {
            throw new Exception(Exception::MEMBERSHIP_NOT_FOUND);
        }

        $team = $authorization->skip(fn () => $dbForProject->getDocument('teams', $teamId));

        if ($team->isEmpty()) {
            throw new Exception(Exception::TEAM_NOT_FOUND);
        }

        if ($membership->getAttribute('teamInternalId') !== $team->getSequence()) {
            throw new Exception(Exception::TEAM_MEMBERSHIP_MISMATCH);
        }

        if (!$proofForToken->verify($secret, $membership->getAttribute('secret'))) {
            throw new Exception(Exception::TEAM_INVALID_SECRET);
        }

        if ($userId !== $membership->getAttribute('userId')) {
            throw new Exception(Exception::TEAM_INVITE_MISMATCH, 'Invite does not belong to current user');
        }

        $protocol = System::getEnv('_APP_OPTIONS_FORCE_HTTPS') === 'disabled' ? 'http' : 'https';
        $host = $platform['consoleHostname'] ?? $request->getHostname();
        $port = $request->getPort();
        $callbackBase = $protocol . '://' . $host;
        if ($protocol === 'https' && $port !== '443') {
            $callbackBase .= ':' . $port;
        } elseif ($protocol === 'http' && $port !== '80') {
            $callbackBase .= ':' . $port;
        }

        $fallbackUrl = empty($redirectUrl) ? $callbackBase . '/console' : $redirectUrl;

        // If already confirmed, redirect immediately to fallbackUrl
        if ($membership->getAttribute('confirm') === true) {
            $response->redirect($fallbackUrl);
            return;
        }

        $projectName = $project->isEmpty() ? 'Console' : $project->getAttribute('name', 'Appwrite');

        $view = new View(__DIR__ . '/../../../../../../../../app/views/general/invitation.phtml');
        $view
            ->setParam('projectName', $projectName)
            ->setParam('teamName', $team->getAttribute('name'))
            ->setParam('redirectUrl', $fallbackUrl)
            ->setParam('membershipId', $membershipId)
            ->setParam('teamId', $teamId)
            ->setParam('userId', $userId)
            ->setParam('secret', $secret)
            ->setParam('project', $project)
            ->setParam('callbackBase', $callbackBase);

        $response->html($view->render());
    }
}
