<?php

namespace Appwrite\Platform\Modules\VCS\Http\GitHub\Callback;

use Appwrite\Auth\OAuth2\Github as OAuth2Github;
use Appwrite\Extend\Exception;
use Appwrite\Platform\Permission as AppwritePermission;
use Appwrite\Utopia\Database\Documents\User;
use Appwrite\Utopia\Response;
use Appwrite\Vcs\Factory as VcsFactory;
use Appwrite\Vcs\State;
use Utopia\Client\Adapter\Curl\Client as CurlAdapter;
use Utopia\Client\Client;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Database\Exception\Duplicate;
use Utopia\Database\Id;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;
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
            ->param('state', '', new Text(APP_LIMIT_VCS_STATE, 0), 'GitHub state. Contains info sent when starting authorization flow.', true)
            ->param('code', '', new Text(2048, 0), 'OAuth2 code. This is a temporary code that the will be later exchanged for an access token.', true)
            ->inject('vcsFactory')
            ->inject('project')
            ->inject('response')
            ->inject('dbForPlatform')
            ->inject('platform')
            ->inject('user')
            ->inject('authorization')
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
        User $user,
        Authorization $authorization
    ) {
        if (empty($state)) {
            throw new Exception(Exception::GENERAL_ARGUMENT_INVALID, 'This installation was completed on GitHub, so it could not be connected to a project. Open your project\'s settings in the Appwrite Console and connect GitHub from there.');
        }

        // This endpoint is public -- without verifying the signature the
        // Authorize action put in state, anyone could pass an arbitrary
        // projectId here and attach an installation to another project.
        $signingKey = System::getEnv('_APP_OPENSSL_KEY_V1', '');

        if (empty($signingKey)) {
            throw new Exception(Exception::GENERAL_SERVER_ERROR, 'Signing key is not configured. Please configure _APP_OPENSSL_KEY_V1 in .env file.');
        }

        $state = State::verify($state, $signingKey);

        if ($state === null) {
            throw new Exception(Exception::GENERAL_ARGUMENT_INVALID, 'Invalid state parameter. Please restart the installation from the Appwrite Console.');
        }

        $projectId = $state->projectId;
        $settings = ($platform['consoleUrl'] ?? '') . "/projects/$projectId/settings";
        $redirectSuccess = $state->success ?: $settings;
        $redirectFailure = $state->failure ?: $settings;

        if ($state->isExpired()) {
            $this->failure($response, $redirectFailure, 'The installation request has expired. Please restart the installation from the Appwrite Console.');
            return;
        }

        if ($user->isEmpty() || $user->getId() !== $state->userId) {
            $this->failure($response, $redirectFailure, 'The installation was started by a different Appwrite account. Please sign in with that account, or restart the installation from the Appwrite Console.');
            return;
        }

        // Each state completes one installation; ids are capped at 36 characters
        try {
            $authorization->skip(fn () => $dbForPlatform->createDocument('vcsCommentLocks', new Document([
                '$id' => 'vcs-state-' . \substr(\hash('sha256', $state->nonce), 0, 26),
            ])));
        } catch (Duplicate) {
            $this->failure($response, $redirectFailure, 'This installation request was already used. Please restart the installation from the Appwrite Console.');
            return;
        }

        $project = $dbForPlatform->getDocument('projects', $projectId);

        if ($project->isEmpty()) {
            $this->failure($response, $state->failure, 'Project with the ID from state could not be found.', Exception::PROJECT_NOT_FOUND);
            return;
        }

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
                // github.com is the operator's configured provider, not a user's choice
                $oauth2 = new OAuth2Github(new Client(new CurlAdapter()), System::getEnv('_APP_VCS_GITHUB_CLIENT_ID', ''), System::getEnv('_APP_VCS_GITHUB_CLIENT_SECRET', ''), "");

                $accessToken = $oauth2->getAccessToken($code);
                $refreshToken = $oauth2->getRefreshToken($code);
                $accessTokenExpiry = DateTime::addSeconds(new \DateTime(), \intval($oauth2->getAccessTokenExpiry($code)));

                $personalSlug = $oauth2->getUserSlug($accessToken);
                $personal = $personalSlug === $owner;
            }

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

            $this->failure($response, $redirectFailure, $error);
            return;
        }

        $response
            ->addHeader('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
            ->addHeader('Pragma', 'no-cache')
            ->redirect($redirectSuccess);
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
