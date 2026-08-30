<?php

namespace Appwrite\Platform\Modules\VCS\Http\GitHub\Callback;

use Appwrite\Auth\OAuth2\Github as OAuth2Github;
use Appwrite\Extend\Exception;
use Appwrite\Platform\Permission as AppwritePermission;
use Appwrite\Utopia\Request;
use Appwrite\Utopia\Response;
use Appwrite\Vcs\Factory as VcsFactory;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Query;
use Utopia\Platform\Action;
use Utopia\Platform\Scope\HTTP;
use Utopia\System\System;
use Utopia\Validator\Text;

class Get extends Action
{
    use HTTP;
    use AppwritePermission;

    public static function getName()
    {
        return 'getVCSGitHubCallback';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/vcs/github/callback')
            ->desc('Get installation and authorization from GitHub app')
            ->groups(['api', 'vcs'])
            ->label('scope', 'public')
            ->label('error', APP_VIEWS_DIR . '/general/error.phtml')
            ->param('installation_id', '', new Text(256, 0), 'GitHub installation ID', true)
            ->param('setup_action', '', new Text(256, 0), 'GitHub setup action type', true)
            ->param('state', '', new Text(2048, 0), 'GitHub state. Contains info sent when starting authorization flow.', true)
            ->param('code', '', new Text(2048, 0), 'OAuth2 code. This is a temporary code that the will be later exchanged for an access token.', true)
            ->inject('vcsFactory')
            ->inject('project')
            ->inject('request')
            ->inject('response')
            ->inject('dbForPlatform')
            ->inject('platform')
            ->callback($this->action(...));
    }

    public function action(
        string $providerInstallationId,
        string $setupAction,
        string $state,
        string $code,
        VcsFactory $vcsFactory,
        Document $project,
        Request $request,
        Response $response,
        Database $dbForPlatform,
        array $platform
    ) {
        $protocol = System::getEnv('_APP_OPTIONS_FORCE_HTTPS') === 'disabled' ? 'http' : 'https';

        // GitHub only echoes state back when it finishes through the redirect URI.
        // Flows that end on the app's setup URL instead -- an organisation member
        // requesting owner approval, or an owner approving that request -- arrive
        // here with no state, so fall back to the cookie Authorize left behind.
        $cookie = $request->getCookie(COOKIE_NAME_VCS_STATE, '');

        if (!empty($cookie)) {
            $state = empty($state) ? $cookie : $state;

            // One shot: a leftover cookie must never attach a later installation
            // to the project this browser happened to start from.
            $response->addCookie(
                COOKIE_NAME_VCS_STATE,
                '',
                \time() - 3600,
                COOKIE_PATH_VCS_STATE,
                null,
                $protocol === 'https',
                true,
                Response::COOKIE_SAMESITE_LAX
            );
        }

        if (empty($state)) {
            throw new Exception(Exception::GENERAL_ARGUMENT_INVALID, 'Missing state parameter. Please restart the installation from the Appwrite Console.');
        }

        $state = \json_decode($state, true) ?? [];
        $redirectFailure = $state['failure'] ?? '';
        $projectId = $state['projectId'] ?? '';

        // This endpoint is public -- without verifying the signature the
        // Authorize action put in state, anyone could pass an arbitrary
        // projectId here and attach an installation to another project.
        $signature = \hash_hmac('sha256', \json_encode([$projectId, $state['success'] ?? '', $redirectFailure]), System::getEnv('_APP_OPENSSL_KEY_V1', ''));
        if (!\hash_equals($signature, $state['signature'] ?? '')) {
            throw new Exception(Exception::GENERAL_ARGUMENT_INVALID, 'Invalid state parameter. Please restart the installation from the Appwrite Console.');
        }

        $project = $dbForPlatform->getDocument('projects', $projectId);

        if ($project->isEmpty()) {
            $error = 'Project with the ID from state could not be found.';

            if (empty($redirectFailure)) {
                throw new Exception(Exception::PROJECT_NOT_FOUND, $error);
            }

            $separator = \str_contains($redirectFailure, '?') ? '&' : '?';
            $response
                ->addHeader('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
                ->addHeader('Pragma', 'no-cache')
                ->redirect($redirectFailure . $separator . \http_build_query(['error' => $error]));
            return;
        }

        $region = $project->getAttribute('region', 'default');
        $hostname = $platform['consoleHostname'] ?? '';

        $defaultState = [
            'success' => $protocol . '://' . $hostname . "/console/project-$region-$projectId/settings/git-installations",
            'failure' => $protocol . '://' . $hostname . "/console/project-$region-$projectId/settings/git-installations",
        ];

        $redirectSuccess = empty($state['success']) ? $defaultState['success'] : $state['success'];
        $redirectFailure = empty($state['failure']) ? $defaultState['failure'] : $state['failure'];

        // Create / Update installation
        if (!empty($providerInstallationId)) {
            $vcs = $vcsFactory->fromInstallation(new Document([
                'provider' => 'github',
                'providerInstallationId' => $providerInstallationId,
            ]));
            $owner = $vcs->getOwnerName($providerInstallationId);

            $projectInternalId = $project->getSequence();

            $installation = $dbForPlatform->findOne('installations', [
                Query::equal('providerInstallationId', [$providerInstallationId]),
                Query::equal('projectInternalId', [$projectInternalId]),
                Query::equal('provider', ['github'])
            ]);

            $personal = false;
            $refreshToken = null;
            $accessToken = null;
            $accessTokenExpiry = null;

            if (!empty($code)) {
                $oauth2 = new OAuth2Github(System::getEnv('_APP_VCS_GITHUB_CLIENT_ID', ''), System::getEnv('_APP_VCS_GITHUB_CLIENT_SECRET', ''), "");

                $accessToken = $oauth2->getAccessToken($code);
                $refreshToken = $oauth2->getRefreshToken($code);
                $accessTokenExpiry = DateTime::addSeconds(new \DateTime(), \intval($oauth2->getAccessTokenExpiry($code)));

                $personalSlug = $oauth2->getUserSlug($accessToken);
                $personal = $personalSlug === $owner;
            }

            if ($installation->isEmpty()) {
                $teamId = $project->getAttribute('teamId', '');

                $installation = new Document([
                    '$id' => ID::unique(),
                    '$permissions' => $this->getPermissions($teamId, $projectId),
                    'providerInstallationId' => $providerInstallationId,
                    'projectId' => $projectId,
                    'projectInternalId' => $projectInternalId,
                    'provider' => 'github',
                    'organization' => $owner,
                    'personal' => $personal,
                    'personalRefreshToken' => $refreshToken,
                    'personalAccessToken' => $accessToken,
                    'personalAccessTokenExpiry' => $accessTokenExpiry,
                ]);

                $installation = $dbForPlatform->createDocument('installations', $installation);
            } else {
                $installation = $installation
                    ->setAttribute('organization', $owner)
                    ->setAttribute('personal', $personal)
                    ->setAttribute('personalRefreshToken', $refreshToken)
                    ->setAttribute('personalAccessToken', $accessToken)
                    ->setAttribute('personalAccessTokenExpiry', $accessTokenExpiry);
                $installation = $dbForPlatform->updateDocument('installations', $installation->getId(), new Document([
                    'organization' => $installation->getAttribute('organization'),
                    'personal' => $installation->getAttribute('personal'),
                    'personalRefreshToken' => $installation->getAttribute('personalRefreshToken'),
                    'personalAccessToken' => $installation->getAttribute('personalAccessToken'),
                    'personalAccessTokenExpiry' => $installation->getAttribute('personalAccessTokenExpiry'),
                ]));
            }
        } else {
            $error = $setupAction === 'request'
                ? 'Your installation request was sent to the organization owners for approval. Installing the Appwrite GitHub App on an organization requires an owner, so ask one of them to create the installation from the Appwrite Console.'
                : 'Installation of the Appwrite GitHub App on organization accounts is restricted to organization owners. As a member of the organization, you do not have the necessary permissions to install this GitHub App. Please contact the organization owner to create the installation from the Appwrite Console.';

            if (empty($redirectFailure)) {
                throw new Exception(Exception::GENERAL_ARGUMENT_INVALID, $error);
            }

            $separator = \str_contains($redirectFailure, '?') ? '&' : '?';
            $response
                ->addHeader('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
                ->addHeader('Pragma', 'no-cache')
                ->redirect($redirectFailure . $separator . \http_build_query(['error' => $error]));
            return;
        }

        $response
            ->addHeader('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
            ->addHeader('Pragma', 'no-cache')
            ->redirect($redirectSuccess);
    }
}
