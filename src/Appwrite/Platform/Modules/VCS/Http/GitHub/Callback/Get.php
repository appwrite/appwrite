<?php

namespace Appwrite\Platform\Modules\VCS\Http\GitHub\Callback;

use Appwrite\Auth\OAuth2\Github as OAuth2Github;
use Appwrite\Extend\Exception;
use Appwrite\Platform\Permission as AppwritePermission;
use Appwrite\Utopia\Response;
use Appwrite\Vcs\Factory as VcsFactory;
use Psr\Http\Client\ClientExceptionInterface;
use Utopia\Client\Client;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Database\Id;
use Utopia\Database\Query;
use Utopia\Platform\Action;
use Utopia\Platform\Scope\HTTP;
use Utopia\Psr7\Header;
use Utopia\Psr7\Method;
use Utopia\Psr7\Request\Factory as RequestFactory;
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
            ->param('state', '', new Text(APP_LIMIT_VCS_STATE, 0), 'GitHub state. Contains info sent when starting authorization flow.', true)
            ->param('code', '', new Text(2048, 0), 'OAuth2 code. This is a temporary code that the will be later exchanged for an access token.', true)
            ->inject('vcsFactory')
            ->inject('project')
            ->inject('response')
            ->inject('dbForPlatform')
            ->inject('platform')
            ->inject('clientForOAuth2')
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
        array $platform,
        Client $clientForOAuth2
    ) {
        if (empty($state)) {
            throw new Exception(Exception::GENERAL_ARGUMENT_INVALID, 'This installation was completed on GitHub, so it could not be connected to a project. Open your project\'s settings in the Appwrite Console and connect GitHub from there.');
        }

        $state = \json_decode($state, true) ?? [];
        $redirectFailure = $state['failure'] ?? '';
        $projectId = $state['projectId'] ?? '';

        // This endpoint is public -- without verifying the signature the
        // Authorize action put in state, anyone could pass an arbitrary
        // projectId here and attach an installation to another project.
        $signingKey = System::getEnv('_APP_OPENSSL_KEY_V1', '');

        if (empty($signingKey)) {
            throw new Exception(Exception::GENERAL_SERVER_ERROR, 'Signing key is not configured. Please configure _APP_OPENSSL_KEY_V1 in .env file.');
        }

        $signature = \hash_hmac('sha256', \json_encode([$projectId, $state['success'] ?? '', $redirectFailure]), $signingKey);
        if (!\hash_equals($signature, \is_string($state['signature'] ?? null) ? $state['signature'] : '')) {
            throw new Exception(Exception::GENERAL_ARGUMENT_INVALID, 'Invalid state parameter. Please restart the installation from the Appwrite Console.');
        }

        $project = $dbForPlatform->getDocument('projects', $projectId);

        if ($project->isEmpty()) {
            $this->failure($response, $redirectFailure, 'Project with the ID from state could not be found.', Exception::PROJECT_NOT_FOUND);
            return;
        }

        $defaultState = [
            'success' => ($platform['consoleUrl'] ?? '') . "/projects/$projectId/settings",
            'failure' => ($platform['consoleUrl'] ?? '') . "/projects/$projectId/settings",
        ];

        $redirectSuccess = empty($state['success']) ? $defaultState['success'] : $state['success'];
        $redirectFailure = empty($state['failure']) ? $defaultState['failure'] : $state['failure'];

        if (!empty($providerInstallationId)) {
            if (empty($code)) {
                $this->failure($response, $redirectFailure, 'GitHub did not return an authorization code, so the installation could not be verified. Please restart the installation from the Appwrite Console.');
                return;
            }

            $oauth2 = new OAuth2Github($clientForOAuth2, System::getEnv('_APP_VCS_GITHUB_CLIENT_ID', ''), System::getEnv('_APP_VCS_GITHUB_CLIENT_SECRET', ''), '');

            try {
                $accessToken = $oauth2->getAccessToken($code);
                $refreshToken = $oauth2->getRefreshToken($code);
                $accessTokenExpiry = DateTime::addSeconds(new \DateTime(), \intval($oauth2->getAccessTokenExpiry($code)));
            } catch (Exception) {
                $this->failure($response, $redirectFailure, 'Failed to exchange the GitHub authorization code. Please restart the installation from the Appwrite Console.');
                return;
            }

            if (!$this->canAccess($clientForOAuth2, $accessToken, $providerInstallationId)) {
                $this->failure($response, $redirectFailure, 'Your GitHub account does not have access to this installation. Please restart the installation from the Appwrite Console.');
                return;
            }

            $vcs = $vcsFactory->fromInstallation(new Document([
                'provider' => 'github',
                'providerInstallationId' => $providerInstallationId,
            ]));
            $owner = $vcs->getOwnerName($providerInstallationId);
            $personal = $oauth2->getUserSlug($accessToken) === $owner;

            $projectInternalId = $project->getSequence();

            $installation = $dbForPlatform->findOne('installations', [
                Query::equal('providerInstallationId', [$providerInstallationId]),
                Query::equal('projectInternalId', [$projectInternalId]),
                Query::equal('provider', ['github'])
            ]);

            if ($installation->isEmpty()) {
                $teamId = $project->getAttribute('teamId', '');

                $installation = new Document([
                    '$id' => Id::unique(),
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
    private function canAccess(Client $client, string $accessToken, string $providerInstallationId): bool
    {
        $factory = new RequestFactory();
        $page = 1;

        do {
            try {
                $response = $client->sendRequest($factory->query(Method::GET, 'https://api.github.com/user/installations', [
                    'per_page' => 100,
                    'page' => $page,
                ], [
                    Header::ACCEPT => 'application/vnd.github+json',
                    Header::AUTHORIZATION => 'Bearer ' . $accessToken,
                    Header::USER_AGENT => 'Appwrite',
                ]));
            } catch (ClientExceptionInterface) {
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
