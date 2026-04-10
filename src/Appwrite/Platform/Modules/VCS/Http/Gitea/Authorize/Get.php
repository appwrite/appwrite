<?php

namespace Appwrite\Platform\Modules\VCS\Http\Gitea\Authorize;

use Appwrite\Extend\Exception;
use Appwrite\Platform\Action;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\MethodType;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Appwrite\Vcs\VcsFactory;
use Utopia\Database\Document;
use Utopia\Platform\Scope\HTTP;
use Utopia\System\System;

class Get extends Action
{
    use HTTP;

    public static function getName()
    {
        return 'getVCSGiteaAuthorize';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/vcs/gitea/authorize')
            ->desc('Create Gitea installation')
            ->groups(['api', 'vcs'])
            ->label('scope', 'vcs.read')
            ->label('error', APP_VIEWS_DIR . '/general/error.phtml')
            ->label('sdk', new Method(
                namespace: 'vcs',
                group: 'installations',
                name: 'createGiteaInstallation',
                description: '/docs/references/vcs/create-gitea-installation.md',
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
        $endpoint = VcsFactory::getEndpoint('gitea');
        $browserEndpoint = VcsFactory::getBrowserEndpoint('gitea');
        $clientId = VcsFactory::getClientId('gitea');

        if (empty($endpoint) || empty($clientId)) {
            throw new Exception(Exception::GENERAL_SERVER_ERROR, 'Gitea VCS is not configured. Please configure _APP_VCS_GITEA_ENDPOINT and _APP_VCS_GITEA_CLIENT_ID environment variables.');
        }

        $protocol = System::getEnv('_APP_OPTIONS_FORCE_HTTPS') === 'disabled' ? 'http' : 'https';
        $hostname = $platform['consoleHostname'] ?? '';

        $state = \json_encode([
            'projectId' => $project->getId(),
            'success' => $success,
            'failure' => $failure,
        ]);

        $url = rtrim($browserEndpoint, '/') . '/login/oauth/authorize?' . \http_build_query([
            'client_id' => $clientId,
            'redirect_uri' => $protocol . '://' . $hostname . '/v1/vcs/gitea/callback',
            'response_type' => 'code',
            'scope' => 'read:user write:repository read:organization',
            'state' => $state,
        ]);

        $response
            ->addHeader('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
            ->addHeader('Pragma', 'no-cache')
            ->redirect($url);
    }
}
