<?php

namespace Appwrite\Platform\Modules\VCS\Http\Authorization;

use Appwrite\Extend\Exception;
use Appwrite\Platform\Action;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\MethodType;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Database\Document;
use Utopia\Platform\Scope\HTTP;
use Utopia\System\System;

class Get extends Action
{
    use HTTP;

    public static function getName()
    {
        return 'getGitHubAppAuthorization';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/vcs/github/authorize')
            ->desc('Create GitHub app installation')
            ->groups(['api', 'vcs'])
            ->label('scope', 'vcs.read')
            ->label('error', __DIR__ . '/../../views/general/error.phtml')
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
                hide: true,
            ))
            ->param('success', '', fn ($redirectValidator) => $redirectValidator, 'URL to redirect back to console after a successful installation attempt.', true, ['redirectValidator'])
            ->param('failure', '', fn ($redirectValidator) => $redirectValidator, 'URL to redirect back to console after a failed installation attempt.', true, ['redirectValidator'])
            ->inject('response')
            ->inject('project')
            ->inject('platform')
            ->callback($this->action(...));
    }

    public function action(
        string $success,
        string $failure,
        Response $response,
        Document $project,
        array $platform
    ) {
        $state = \json_encode([
            'projectId' => $project->getId(),
            'success' => $success,
            'failure' => $failure,
        ]);

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

        $response
            ->addHeader('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
            ->addHeader('Pragma', 'no-cache')
            ->redirect($url);
    }
}