<?php

namespace Appwrite\Platform\Modules\VCS\Http\Gitea\Callback;

use Appwrite\Auth\OAuth2\Gitea as OAuth2Gitea;
use Appwrite\Extend\Exception;
use Appwrite\Platform\Permission as AppwritePermission;
use Appwrite\Utopia\Response;
use Appwrite\Vcs\VcsFactory;
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
        return 'getVCSGiteaCallback';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/vcs/gitea/callback')
            ->desc('Get installation and authorization from Gitea')
            ->groups(['api', 'vcs'])
            ->label('scope', 'public')
            ->label('error', APP_VIEWS_DIR . '/general/error.phtml')
            ->param('code', '', new Text(2048, 0), 'OAuth2 code.', true)
            ->param('state', '', new Text(2048), 'State containing project info.', true)
            ->inject('project')
            ->inject('response')
            ->inject('dbForPlatform')
            ->inject('platform')
            ->callback($this->action(...));
    }

    public function action(
        string $code,
        string $state,
        Document $project,
        Response $response,
        Database $dbForPlatform,
        array $platform
    ) {
        if (empty($state)) {
            throw new Exception(Exception::GENERAL_ARGUMENT_INVALID, 'Missing state parameter.');
        }

        $state = \json_decode($state, true);
        $redirectFailure = $state['failure'] ?? '';
        $projectId = $state['projectId'] ?? '';

        $project = $dbForPlatform->getDocument('projects', $projectId);

        if ($project->isEmpty()) {
            $error = 'Project with the ID from state could not be found.';

            if (!empty($redirectFailure)) {
                $separator = \str_contains($redirectFailure, '?') ? '&' : ':';
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

        $state = \array_merge($defaultState, $state ?? []);
        $redirectSuccess = $state['success'] ?? '';
        $redirectFailure = $state['failure'] ?? '';

        if (empty($code)) {
            $error = 'OAuth2 authorization code is missing.';

            if (!empty($redirectFailure)) {
                $separator = \str_contains($redirectFailure, '?') ? '&' : ':';
                $response
                    ->addHeader('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
                    ->addHeader('Pragma', 'no-cache')
                    ->redirect($redirectFailure . $separator . \http_build_query(['error' => $error]));
                return;
            }

            throw new Exception(Exception::GENERAL_ARGUMENT_INVALID, $error);
        }

        $endpoint = VcsFactory::getEndpoint('gitea');
        $clientId = VcsFactory::getClientId('gitea');
        $clientSecret = VcsFactory::getClientSecret('gitea');

        $oauth2 = new OAuth2Gitea($clientId, $clientSecret, '', [], [], $endpoint);

        $accessToken = $oauth2->getAccessToken($code) ?? '';
        $refreshToken = $oauth2->getRefreshToken($code) ?? '';
        $accessTokenExpiry = DateTime::addSeconds(new \DateTime(), \intval($oauth2->getAccessTokenExpiry($code)));

        $owner = $oauth2->getUserSlug($accessToken) ?? '';

        if (empty($owner)) {
            $error = 'Failed to get user information from Gitea.';

            if (!empty($redirectFailure)) {
                $separator = \str_contains($redirectFailure, '?') ? '&' : ':';
                $response
                    ->addHeader('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
                    ->addHeader('Pragma', 'no-cache')
                    ->redirect($redirectFailure . $separator . \http_build_query(['error' => $error]));
                return;
            }

            throw new Exception(Exception::GENERAL_ARGUMENT_INVALID, $error);
        }

        $projectInternalId = $project->getSequence();

        // Use the OAuth2 user ID as the providerInstallationId for Gitea
        $providerInstallationId = $oauth2->getUserID($accessToken);

        $installation = $dbForPlatform->findOne('installations', [
            Query::equal('providerInstallationId', [$providerInstallationId]),
            Query::equal('projectInternalId', [$projectInternalId]),
            Query::equal('provider', ['gitea']),
        ]);

        if ($installation->isEmpty()) {
            $teamId = $project->getAttribute('teamId', '');

            $installation = new Document([
                '$id' => ID::unique(),
                '$permissions' => $this->getPermissions($teamId, $projectId),
                'providerInstallationId' => $providerInstallationId,
                'projectId' => $projectId,
                'projectInternalId' => $projectInternalId,
                'provider' => 'gitea',
                'organization' => $owner,
                'personal' => true,
                'personalRefreshToken' => $refreshToken,
                'personalAccessToken' => $accessToken,
                'personalAccessTokenExpiry' => $accessTokenExpiry,
            ]);

            $installation = $dbForPlatform->createDocument('installations', $installation);
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
}
