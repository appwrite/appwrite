<?php

namespace Appwrite\Platform\Modules\VCS\Http\Callback;

use Appwrite\Auth\OAuth2;
use Appwrite\Extend\Exception;
use Appwrite\Platform\Action;
use Appwrite\Platform\Permission as AppwritePermission;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Query;
use Utopia\Platform\Scope\HTTP;
use Utopia\System\System;
use Utopia\Validator\Text;

/**
 * OAuth2 callback for OAuth2-based VCS providers. Exchanges the authorization
 * code for tokens and upserts a personal installation for the project carried
 * in the state parameter.
 */
abstract class Base extends Action
{
    use HTTP;
    use AppwritePermission;

    /**
     * Provider key in the `vcs` config registry.
     */
    abstract public static function getProvider(): string;

    /**
     * Provider display name, used in error messages.
     */
    abstract public static function getProviderName(): string;

    /**
     * Builds this provider's OAuth2 client, pointed at the server-side API
     * endpoint (token exchange is a server-to-server call, unlike Authorize's
     * browser-facing endpoint).
     */
    abstract protected function createOAuth2(string $callback): OAuth2;

    public function __construct()
    {
        $key = static::getProvider();

        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/vcs/' . $key . '/callback')
            ->desc('Get installation and authorization from ' . $key)
            ->groups(['api', 'vcs'])
            ->label('scope', 'public')
            ->label('error', APP_VIEWS_DIR . '/general/error.phtml')
            ->param('code', '', new Text(2048, 0), 'OAuth2 code. This is a temporary code that the will be later exchanged for an access token.', true)
            ->param('state', '', new Text(2048), 'OAuth2 state. Contains info sent when starting authorization flow.', true)
            ->inject('response')
            ->inject('dbForPlatform')
            ->inject('platform')
            ->callback($this->action(...));
    }

    public function action(
        string $code,
        string $state,
        Response $response,
        Database $dbForPlatform,
        array $platform
    ) {
        $key = static::getProvider();

        if (empty($state)) {
            throw new Exception(Exception::GENERAL_ARGUMENT_INVALID, 'Missing state parameter. Please restart the installation from the Appwrite Console.');
        }

        $state = \json_decode($state, true) ?? [];
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

        $state = \array_merge($defaultState, $state);
        $redirectSuccess = $state['success'] ?? '';
        $redirectFailure = $state['failure'] ?? '';

        if (empty($code)) {
            $this->failure($response, $redirectFailure, 'OAuth2 authorization code is missing.');
            return;
        }

        $callback = $protocol . '://' . $hostname . '/v1/vcs/' . $key . '/callback';
        $oauth2 = $this->createOAuth2($callback);

        $accessToken = $oauth2->getAccessToken($code);
        $refreshToken = $oauth2->getRefreshToken($code);
        $accessTokenExpiry = DateTime::addSeconds(new \DateTime(), \intval($oauth2->getAccessTokenExpiry($code)));

        if (empty($accessToken)) {
            $this->failure($response, $redirectFailure, 'Failed to exchange authorization code for an access token.');
            return;
        }

        $providerInstallationId = $oauth2->getUserID($accessToken);
        $owner = \method_exists($oauth2, 'getUserSlug') ? $oauth2->getUserSlug($accessToken) : '';

        if (empty($providerInstallationId) || empty($owner)) {
            $this->failure($response, $redirectFailure, 'Failed to get user information from ' . static::getProviderName() . '.');
            return;
        }

        $projectInternalId = $project->getSequence();

        $installation = $dbForPlatform->findOne('installations', [
            Query::equal('providerInstallationId', [$providerInstallationId]),
            Query::equal('projectInternalId', [$projectInternalId]),
            Query::equal('provider', [$key]),
        ]);

        if ($installation->isEmpty()) {
            $teamId = $project->getAttribute('teamId', '');

            $installation = $dbForPlatform->createDocument('installations', new Document([
                '$id' => ID::unique(),
                '$permissions' => $this->getPermissions($teamId, $projectId),
                'providerInstallationId' => $providerInstallationId,
                'projectId' => $projectId,
                'projectInternalId' => $projectInternalId,
                'provider' => $key,
                'organization' => $owner,
                'personal' => true,
                'personalRefreshToken' => $refreshToken,
                'personalAccessToken' => $accessToken,
                'personalAccessTokenExpiry' => $accessTokenExpiry,
            ]));
        } else {
            $installation = $dbForPlatform->updateDocument('installations', $installation->getId(), new Document([
                'organization' => $owner,
                'personal' => true,
                'personalRefreshToken' => $refreshToken,
                'personalAccessToken' => $accessToken,
                'personalAccessTokenExpiry' => $accessTokenExpiry,
            ]));
        }

        $response
            ->addHeader('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
            ->addHeader('Pragma', 'no-cache')
            ->redirect($redirectSuccess);
    }

    /**
     * Redirect back to the console with an error, or throw when no redirect is available.
     */
    protected function failure(Response $response, string $redirect, string $error, string $type = Exception::GENERAL_ARGUMENT_INVALID): void
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
