<?php

namespace Appwrite\Platform\Modules\VCS\Http\GitHub\Callback;

use Appwrite\Auth\OAuth2\Github as OAuth2Github;
use Appwrite\Extend\Exception;
use Appwrite\Platform\Permission as AppwritePermission;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Query;
use Utopia\Fetch\Client;
use Utopia\Platform\Action;
use Utopia\Platform\Scope\HTTP;
use Utopia\System\System;
use Utopia\Validator\Text;
use Utopia\VCS\Adapter\Git\GitHub;

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
            ->param('state', '', new Text(2048), 'GitHub state. Contains info sent when starting authorization flow.', true)
            ->param('code', '', new Text(2048, 0), 'OAuth2 code. This is a temporary code that the will be later exchanged for an access token.', true)
            ->inject('gitHub')
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
        GitHub $github,
        Document $project,
        Response $response,
        Database $dbForPlatform,
        array $platform
    ) {
        if (empty($state)) {
            $error = 'Installation requests from organisation members for the Appwrite GitHub App are currently unsupported. To proceed with the installation, login to the Appwrite Console and install the GitHub App.';
            throw new Exception(Exception::GENERAL_ARGUMENT_INVALID, $error);
        }

        $state = \json_decode($state, true);
        $redirectFailure = $state['failure'] ?? '';
        $projectId = $state['projectId'] ?? '';

        $project = $dbForPlatform->getDocument('projects', $projectId);

        if ($project->isEmpty()) {
            $this->failure($response, $redirectFailure, 'Project with the ID from state could not be found.', Exception::PROJECT_NOT_FOUND);
            return;
        }

        $region = $project->getAttribute('region', 'default');
        $protocol = System::getEnv('_APP_OPTIONS_FORCE_HTTPS') === 'disabled' ? 'http' : 'https';
        $hostname = $platform['consoleHostname'] ?? '';

        $defaultState = [
            'success' => $protocol . '://' . $hostname . "/console/project-$region-$projectId/settings/git-installations",
            'failure' => $protocol . '://' . $hostname . "/console/project-$region-$projectId/settings/git-installations",
        ];

        $state = \array_merge($defaultState, $state ?? []);

        $redirectSuccess = $state['success'] ?? '';
        $redirectFailure = $state['failure'] ?? '';

        // Create / Update installation
        if (!empty($providerInstallationId)) {
            if (empty($code)) {
                $this->failure($response, $redirectFailure, 'GitHub did not return an authorization code, so the installation could not be verified. Please restart the installation from the Appwrite Console.');
                return;
            }

            $oauth2 = new OAuth2Github(System::getEnv('_APP_VCS_GITHUB_CLIENT_ID', ''), System::getEnv('_APP_VCS_GITHUB_CLIENT_SECRET', ''), '');

            try {
                $accessToken = $oauth2->getAccessToken($code);
                $refreshToken = $oauth2->getRefreshToken($code);
                $accessTokenExpiry = DateTime::addSeconds(new \DateTime(), \intval($oauth2->getAccessTokenExpiry($code)));
            } catch (\Throwable) {
                $this->failure($response, $redirectFailure, 'Failed to exchange the GitHub authorization code. Please restart the installation from the Appwrite Console.');
                return;
            }

            if (empty($accessToken)) {
                $this->failure($response, $redirectFailure, 'Failed to exchange the GitHub authorization code. Please restart the installation from the Appwrite Console.');
                return;
            }

            if (!$this->canAccess($accessToken, $providerInstallationId)) {
                $this->failure($response, $redirectFailure, 'Your GitHub account does not have access to this installation. Please restart the installation from the Appwrite Console.');
                return;
            }

            try {
                $privateKey = System::getEnv('_APP_VCS_GITHUB_PRIVATE_KEY');
                $githubAppId = System::getEnv('_APP_VCS_GITHUB_APP_ID');
                $github->initializeVariables($providerInstallationId, $privateKey, $githubAppId);
                $owner = $github->getOwnerName($providerInstallationId);
                $personal = $oauth2->getUserSlug($accessToken) === $owner;
            } catch (\Throwable) {
                $this->failure($response, $redirectFailure, 'Failed to read the installation details from GitHub. Please restart the installation from the Appwrite Console.');
                return;
            }

            $projectInternalId = $project->getSequence();

            $installation = $dbForPlatform->findOne('installations', [
                Query::equal('providerInstallationId', [$providerInstallationId]),
                Query::equal('projectInternalId', [$projectInternalId])
            ]);

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
                $installation = $dbForPlatform->updateDocument('installations', $installation->getId(), new Document([
                    'organization' => $owner,
                    'personal' => $personal,
                    'personalRefreshToken' => $refreshToken,
                    'personalAccessToken' => $accessToken,
                    'personalAccessTokenExpiry' => $accessTokenExpiry,
                ]));
            }
        } else {
            $error = 'Installation of the Appwrite GitHub App on organization accounts is restricted to organization owners. As a member of the organization, you do not have the necessary permissions to install this GitHub App. Please contact the organization owner to create the installation from the Appwrite console.';

            $this->failure($response, $redirectFailure, $error);
            return;
        }

        $response
            ->addHeader('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
            ->addHeader('Pragma', 'no-cache')
            ->redirect($redirectSuccess);
    }

    /**
     * Whether the installation is one the authorizing GitHub user can access.
     */
    private function canAccess(string $accessToken, string $providerInstallationId): bool
    {
        $client = new Client();
        $client
            ->addHeader('accept', 'application/vnd.github+json')
            ->addHeader('authorization', 'Bearer ' . $accessToken)
            ->setUserAgent('Appwrite');

        $page = 1;

        do {
            try {
                $response = $client->fetch(
                    url: 'https://api.github.com/user/installations',
                    method: Client::METHOD_GET,
                    query: [
                        'per_page' => 100,
                        'page' => $page,
                    ],
                );
            } catch (\Throwable) {
                return false;
            }

            if ($response->getStatusCode() !== 200) {
                return false;
            }

            $body = \json_decode((string) $response->getBody(), true);
            $installations = \is_array($body['installations'] ?? null) ? $body['installations'] : [];

            foreach ($installations as $installation) {
                if ((string) ($installation['id'] ?? '') === $providerInstallationId) {
                    return true;
                }
            }

            $page++;
        } while (\count($installations) === 100);

        return false;
    }

    /**
     * Redirect back to the console with an error, or throw when no redirect is available.
     */
    private function failure(Response $response, string $redirect, string $error, string $type = Exception::GENERAL_ARGUMENT_INVALID): void
    {
        if (empty($redirect)) {
            throw new Exception($type, $error);
        }

        $separator = \str_contains($redirect, '?') ? '&' : '?';
        $response
            ->addHeader('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
            ->addHeader('Pragma', 'no-cache')
            ->redirect($redirect . $separator . \http_build_query(['error' => $error]));
    }
}
