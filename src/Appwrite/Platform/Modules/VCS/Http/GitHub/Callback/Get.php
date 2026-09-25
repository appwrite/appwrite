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
use Utopia\Database\Validator\Authorization;
use Utopia\Domains\Domain;
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
            ->inject('request')
            ->inject('response')
            ->inject('dbForPlatform')
            ->inject('authorization')
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
        Authorization $authorization,
        array $platform
    ) {
        $cookie = $request->getCookie(COOKIE_NAME_GITHUB_STATE, '');

        // GitHub drops state when the flow ends on an existing installation's
        // settings page (setup_action=update), so fall back to the copy
        // Authorize left in the cookie. The signature below still applies.
        $fromCookie = empty($state) && $setupAction === 'update' && !empty($cookie);

        if ($fromCookie) {
            $state = $cookie;
        }

        if (empty($state)) {
            throw new Exception(Exception::GENERAL_ARGUMENT_INVALID, $setupAction === 'request'
                ? 'Your request was sent to the organization owners. An owner must complete the installation from the Appwrite Console; approving the request on GitHub is not enough.'
                : 'GitHub did not say which project this installation is for, so it could not be connected. Open your project\'s settings in the Appwrite Console and connect GitHub again.');
        }

        $state = \json_decode($state, true) ?? [];
        $redirectFailure = $state['failure'] ?? '';
        $projectId = $state['projectId'] ?? '';

        // One shot: once this project's flow ends, its cookie must not carry a
        // later one. Another project's pending cookie is left alone.
        if ((\json_decode($cookie, true)['projectId'] ?? null) === $projectId) {
            $protocol = System::getEnv('_APP_OPTIONS_FORCE_HTTPS') === 'disabled' ? 'http' : 'https';
            $host = \parse_url('//' . ($platform['consoleHostname'] ?? ''), PHP_URL_HOST) ?: '';
            $domain = match (true) {
                \in_array($host, ['', 'localhost'], true), \filter_var($host, FILTER_VALIDATE_IP) !== false => null,
                System::getEnv('_APP_CONSOLE_ROOT_SESSION', 'disabled') === 'enabled' => '.' . ((new Domain($host))->getRegisterable() ?: $host),
                default => '.' . $host,
            };

            $response->addCookie(
                COOKIE_NAME_GITHUB_STATE,
                '',
                \time() - 3600,
                COOKIE_PATH_GITHUB_STATE,
                $domain,
                $protocol === 'https',
                true,
                Response::COOKIE_SAMESITE_LAX
            );
        }

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

        $defaultState = [
            'success' => ($platform['consoleUrl'] ?? '') . "/projects/$projectId/settings",
            'failure' => ($platform['consoleUrl'] ?? '') . "/projects/$projectId/settings",
        ];

        $redirectSuccess = empty($state['success']) ? $defaultState['success'] : $state['success'];
        $redirectFailure = empty($state['failure']) ? $defaultState['failure'] : $state['failure'];

        // Create / Update installation
        if (!empty($providerInstallationId)) {
            // State proves which project started the flow, not which
            // installation it ended on: installation_id is a plain query
            // parameter. Only link an installation the GitHub user who just
            // authorized can access.
            if (empty($code)) {
                $error = 'GitHub did not return an authorization code, so access to this installation could not be verified. Please restart the installation from the Appwrite Console.';
                $separator = \str_contains($redirectFailure, '?') ? '&' : '?';
                $response
                    ->addHeader('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
                    ->addHeader('Pragma', 'no-cache')
                    ->redirect($redirectFailure . $separator . \http_build_query(['error' => $error]));
                return;
            }

            // The cookie is sent on any top-level navigation to this URL, so it
            // cannot vouch for the installation_id and code beside it. From the
            // cookie, only relink an installation already linked to a project
            // this user can read, or to one in this project's organization.
            // Members scoped to one project cannot read its siblings, hence the
            // skip.
            if ($fromCookie) {
                $projectIds = \array_map(
                    fn (Document $installation) => $installation->getAttribute('projectId'),
                    $authorization->skip(fn () => $dbForPlatform->find('installations', [
                        Query::equal('providerInstallationId', [$providerInstallationId]),
                        Query::equal('provider', ['github']),
                        Query::select(['projectId']),
                        Query::limit(APP_DATABASE_QUERY_MAX_VALUES),
                    ]))
                );

                $linked = !empty($projectIds) && (
                    !$dbForPlatform->findOne('projects', [
                        Query::equal('$id', $projectIds),
                    ])->isEmpty()
                    || !$authorization->skip(fn () => $dbForPlatform->findOne('projects', [
                        Query::equal('$id', $projectIds),
                        Query::equal('teamInternalId', [$project->getAttribute('teamInternalId')]),
                    ]))->isEmpty()
                );

                if (!$linked) {
                    $error = 'This GitHub installation is not connected to any project you can access, so it could not be linked. Ask someone who already uses it in one of their projects to connect GitHub for this project.';
                    $separator = \str_contains($redirectFailure, '?') ? '&' : '?';
                    $response
                        ->addHeader('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
                        ->addHeader('Pragma', 'no-cache')
                        ->redirect($redirectFailure . $separator . \http_build_query(['error' => $error]));
                    return;
                }
            }

            $oauth2 = new OAuth2Github(System::getEnv('_APP_VCS_GITHUB_CLIENT_ID', ''), System::getEnv('_APP_VCS_GITHUB_CLIENT_SECRET', ''), "");

            $accessToken = $oauth2->getAccessToken($code);
            $refreshToken = $oauth2->getRefreshToken($code);
            $accessTokenExpiry = DateTime::addSeconds(new \DateTime(), \intval($oauth2->getAccessTokenExpiry($code)));

            if (!\in_array($providerInstallationId, $oauth2->getInstallationIds($accessToken), true)) {
                $error = 'Your GitHub account does not have access to this installation. If the organization uses SAML single sign-on, sign in to it on GitHub first, then restart the installation from the Appwrite Console.';
                $separator = \str_contains($redirectFailure, '?') ? '&' : '?';
                $response
                    ->addHeader('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
                    ->addHeader('Pragma', 'no-cache')
                    ->redirect($redirectFailure . $separator . \http_build_query(['error' => $error]));
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
