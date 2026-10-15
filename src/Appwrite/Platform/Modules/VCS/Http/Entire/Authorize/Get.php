<?php

namespace Appwrite\Platform\Modules\VCS\Http\Entire\Authorize;

use Appwrite\Extend\Exception;
use Appwrite\Platform\Action;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\MethodType;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Appwrite\Vcs\Factory as VcsFactory;
use Utopia\Database\Document;
use Utopia\Platform\Scope\HTTP;
use Utopia\System\System;

class Get extends Action
{
    use HTTP;

    public static function getName()
    {
        return 'getVCSEntireAuthorize';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/vcs/entire/authorize')
            ->desc('Create Entire plugin installation')
            ->groups(['api', 'vcs'])
            ->label('scope', 'vcs.read')
            ->label('error', APP_VIEWS_DIR . '/general/error.phtml')
            ->label('sdk', new Method(
                namespace: 'vcs',
                group: 'installations',
                name: 'createEntireInstallation',
                description: '/docs/references/vcs/create-entire-installation.md',
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
            ->inject('response')
            ->inject('project')
            ->inject('platform')
            ->inject('vcsConfigured')
            ->inject('vcsFactory')
            ->callback($this->action(...));
    }

    public function action(
        string $success,
        string $failure,
        Response $response,
        Document $project,
        array $platform,
        callable $vcsConfigured,
        VcsFactory $vcsFactory,
    ) {
        if (!$vcsConfigured('entire')) {
            throw new Exception(Exception::GENERAL_SERVER_ERROR, 'This endpoint is not implemented on this server. Please configure VCS (Version Control System) variables in .env file.');
        }

        $projectId = $project->getId();

        // The callback is public and Entire performs no token exchange, so the
        // state carries an HMAC binding the redirect targets to this project.
        $state = [
            'projectId' => $projectId,
            'success' => $success,
            'failure' => $failure,
            'signature' => \hash_hmac('sha256', \json_encode([$projectId, $success, $failure]), System::getEnv('_APP_OPENSSL_KEY_V1', '')),
        ];

        if (\strlen((string) \json_encode($state)) > APP_LIMIT_VCS_STATE) {
            throw new Exception(Exception::GENERAL_ARGUMENT_INVALID, 'Redirect URLs are too long to complete the installation. Please use shorter success and failure URLs.');
        }

        $protocol = System::getEnv('_APP_OPTIONS_FORCE_HTTPS') === 'disabled' ? 'http' : 'https';
        $callback = $protocol . '://' . ($platform['consoleHostname'] ?? '') . '/v1/vcs/entire/callback';

        // The permissions are registered on the plugin; the install page asks
        // the user only for the project and the repositories.
        /** @var \Utopia\VCS\Adapter\Git\Entire $vcs */
        $vcs = $vcsFactory->fromProvider('entire');
        $installUrl = $vcs->getInstallUrl(
            System::getEnv('_APP_VCS_ENTIRE_PLUGIN', ''),
            $callback,
            \json_encode($state) ?: ''
        );

        $response
            ->addHeader('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
            ->addHeader('Pragma', 'no-cache')
            ->redirect($installUrl);
    }
}
