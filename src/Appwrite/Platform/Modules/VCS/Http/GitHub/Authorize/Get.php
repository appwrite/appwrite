<?php

namespace Appwrite\Platform\Modules\VCS\Http\GitHub\Authorize;

use Appwrite\Extend\Exception;
use Appwrite\Platform\Action;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\MethodType;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Request;
use Appwrite\Utopia\Response;
use Utopia\Database\Document;
use Utopia\Domains\Domain;
use Utopia\Platform\Scope\HTTP;
use Utopia\System\System;

class Get extends Action
{
    use HTTP;

    public static function getName()
    {
        return 'getVCSGitHubAuthorize';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/vcs/github/authorize')
            ->desc('Create GitHub app installation')
            ->groups(['api', 'vcs'])
            ->label('scope', 'vcs.read')
            ->label('error', APP_VIEWS_DIR . '/general/error.phtml')
            ->label('sdk', new Method(
                namespace: 'vcs',
                group: 'installations',
                name: 'createGitHubInstallation',
                description: '/docs/references/vcs/create-github-installation.md',
                auth: [AuthType::ADMIN],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_MOVED_PERMANENTLY,
                        model: Response::MODEL_NONE,
                    )
                ],
                contentType: ContentType::HTML,
                type: MethodType::WEBAUTH,
                // Preview SDK builds show the whole surface, so they do not hide.
                hide: System::getEnv('_APP_SDK_PREVIEW', 'disabled') !== 'enabled',
            ))
            ->param('success', '', fn ($redirectValidator) => $redirectValidator, 'URL to redirect back to console after a successful installation attempt.', true, ['redirectValidator'])
            ->param('failure', '', fn ($redirectValidator) => $redirectValidator, 'URL to redirect back to console after a failed installation attempt.', true, ['redirectValidator'])
            ->inject('request')
            ->inject('response')
            ->inject('project')
            ->inject('platform')
            ->callback($this->action(...));
    }

    public function action(
        string $success,
        string $failure,
        Request $request,
        Response $response,
        Document $project,
        array $platform
    ) {
        $signingKey = System::getEnv('_APP_OPENSSL_KEY_V1', '');

        if (empty($signingKey)) {
            throw new Exception(Exception::GENERAL_SERVER_ERROR, 'Signing key is not configured. Please configure _APP_OPENSSL_KEY_V1 in .env file.');
        }

        // The callback endpoint is public, so it verifies this signature
        // before trusting the projectId and redirect URLs in state.
        $state = \json_encode([
            'projectId' => $project->getId(),
            'success' => $success,
            'failure' => $failure,
            'signature' => \hash_hmac('sha256', \json_encode([$project->getId(), $success, $failure]), $signingKey),
        ]);

        if (\strlen($state) > APP_LIMIT_VCS_STATE) {
            throw new Exception(Exception::GENERAL_ARGUMENT_INVALID, 'Redirect URLs are too long to complete the installation. Please use shorter success and failure URLs.');
        }

        $appName = System::getEnv('_APP_VCS_GITHUB_APP_NAME');
        $protocol = System::getEnv('_APP_OPTIONS_FORCE_HTTPS') === 'disabled' ? 'http' : 'https';
        $hostname = $platform['consoleHostname'] ?? '';

        if (empty($appName)) {
            throw new Exception(Exception::GENERAL_SERVER_ERROR, 'GitHub App name is not configured. Please configure VCS (Version Control System) variables in .env file.');
        }

        $url = "https://github.com/apps/$appName/installations/new?" . \http_build_query([
            'state' => $state,
            'redirect_uri' => $protocol . '://' . $hostname . "/v1/vcs/github/callback"
        ]);

        // When the app is already installed on the chosen account, GitHub sends
        // the user to that installation's settings, and saving there returns
        // setup_action=update without state. Mirror state into a cookie the
        // callback can fall back to. The callback can only read the project
        // through the console session, so the cookie gets the same reach: the
        // registrable domain with root sessions, else the console host and its
        // subdomains, which covers regional hosts.
        $host = \parse_url('//' . $hostname, PHP_URL_HOST) ?: '';
        $domain = match (true) {
            \in_array($host, ['', 'localhost'], true), \filter_var($host, FILTER_VALIDATE_IP) !== false => null,
            System::getEnv('_APP_CONSOLE_ROOT_SESSION', 'disabled') === 'enabled' => '.' . ((new Domain($host))->getRegisterable() ?: $host),
            default => '.' . $host,
        };

        // With another project's connection still pending in this browser, the
        // callback could not tell which one GitHub finished, so keep neither.
        $pending = \json_decode($request->getCookie(COOKIE_NAME_GITHUB_STATE, ''), true);
        $conflict = ($pending['projectId'] ?? $project->getId()) !== $project->getId();

        $response
            ->addCookie(
                COOKIE_NAME_GITHUB_STATE,
                $conflict ? '' : $state,
                $conflict ? \time() - 3600 : \time() + COOKIE_EXPIRY_GITHUB_STATE,
                COOKIE_PATH_GITHUB_STATE,
                $domain,
                $protocol === 'https',
                true,
                Response::COOKIE_SAMESITE_LAX
            )
            ->addHeader('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
            ->addHeader('Pragma', 'no-cache')
            ->redirect($url);
    }
}
