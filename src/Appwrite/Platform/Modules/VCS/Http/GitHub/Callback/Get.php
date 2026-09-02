<?php

namespace Appwrite\Platform\Modules\VCS\Http\GitHub\Callback;

use Appwrite\Auth\OAuth2\Github as OAuth2Github;
use Appwrite\Extend\Exception;
use Appwrite\Platform\Permission as AppwritePermission;
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
            ->param('state', '', new Text(4096, 0), 'GitHub state. Contains info sent when starting authorization flow.', true)
            ->param('code', '', new Text(2048, 0), 'OAuth2 code. This is a temporary code that the will be later exchanged for an access token.', true)
            ->inject('vcsFactory')
            ->inject('project')
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
        Response $response,
        Database $dbForPlatform,
        array $platform
    ) {
        if (empty($state)) {
            throw new Exception(Exception::GENERAL_ARGUMENT_INVALID, 'This installation was completed on GitHub, so it could not be connected to a project. Open your project\'s settings in the Appwrite Console and connect GitHub from there.');
        }

        $state = \json_decode($state, true) ?? [];
        $redirectFailure = $state['failure'] ?? '';
        $projectId = $state['projectId'] ?? '';

        $project = $dbForPlatform->getDocument('projects', $projectId);

        if ($project->isEmpty()) {
            $error = 'Project with the ID from state could not be found.';

            if (!empty($redirectFailure)) {
                $separator = \str_contains($redirectFailure, '?') ? '&' : '?';
                $response
                    ->addHeader('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
                    ->addHeader('Pragma', 'no-cache')
                    ->redirect($redirectFailure . $separator . \http_build_query(['error' => $error]));
                return;
            }

            throw new Exception(Exception::PROJECT_NOT_FOUND, $error);
        }

        $region = $project->getAttribute('region', 'default');
        $protocol = System::getEnv('_APP_OPTIONS_FORCE_HTTPS') === 'disabled' ? 'http' : 'https';
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
            // GitHub sends setup_action=install on a completed installation,
            // update from the app's Configure page, and request when a member
            // asked the owners for approval. install and update should always
            // carry an installation_id, so without one they mean the caller
            // lacked permission to install.
            $error = match ($setupAction) {
                'request' => 'Your request was sent to the organization owners. An owner must complete the installation from the Appwrite Console; approving the request on GitHub is not enough.',
                'install', 'update', '' => 'Installation of the Appwrite GitHub App on organization accounts is restricted to organization owners. As a member of the organization, you do not have the necessary permissions to install this GitHub App. Please contact the organization owner to create the installation from the Appwrite Console.',
                default => 'Unexpected setup action "' . $setupAction . '" received from GitHub. Please restart the installation from the Appwrite Console.',
            };

            if (!empty($redirectFailure)) {
                $separator = \str_contains($redirectFailure, '?') ? '&' : '?';
                $response
                    ->addHeader('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
                    ->addHeader('Pragma', 'no-cache')
                    ->redirect($redirectFailure . $separator . \http_build_query(['error' => $error]));
                return;
            }

            throw new Exception(Exception::GENERAL_ARGUMENT_INVALID, $error);
        }

        $response
            ->addHeader('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
            ->addHeader('Pragma', 'no-cache')
            ->redirect($redirectSuccess);
    }
}
