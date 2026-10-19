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
                description: <<<EOT
                Begin Appwrite's Entire plugin installation to set up version control integration. This endpoint responds with a redirect URL to the plugin's installation page on entire.io, where you pick the Entire project and repositories to connect. The Entire plugin must be configured in your environment for this endpoint to work.
                EOT,
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

        $signingKey = System::getEnv('_APP_OPENSSL_KEY_V1', '');
        if (empty($signingKey)) {
            throw new Exception(Exception::GENERAL_SERVER_ERROR, 'Signing key is not configured. Please configure _APP_OPENSSL_KEY_V1 in .env file.');
        }

        // The callback is public and Entire performs no token exchange, so the
        // state carries an HMAC binding the redirect targets to this project.
        $state = [
            'projectId' => $projectId,
            'success' => $success,
            'failure' => $failure,
            'signature' => \hash_hmac('sha256', \json_encode([$projectId, $success, $failure]), $signingKey),
        ];

        // Entire expects an opaque state token, so the signed JSON travels
        // base64url-encoded and the callback decodes it. The callback takes
        // the encoded state, so that is what has to fit its limit.
        $state = \rtrim(\strtr(\base64_encode(\json_encode($state, JSON_UNESCAPED_SLASHES) ?: ''), '+/', '-_'), '=');
        if (\strlen($state) > APP_LIMIT_VCS_STATE) {
            throw new Exception(Exception::GENERAL_ARGUMENT_INVALID, 'Redirect URLs are too long to complete the installation. Please use shorter success and failure URLs.');
        }

        $protocol = System::getEnv('_APP_OPTIONS_FORCE_HTTPS') === 'disabled' ? 'http' : 'https';
        $callback = $protocol . '://' . ($platform['consoleHostname'] ?? '') . '/v1/vcs/entire/callback';

        // The permissions are registered on the plugin; the install page asks
        // the user only for the project and the repositories.
        /** @var \Utopia\VCS\Adapter\Git\Entire $vcs */
        $vcs = $vcsFactory->fromProvider('entire');
        $installUrl = $vcs->getInstallUrl(System::getEnv('_APP_VCS_ENTIRE_PLUGIN', ''), $callback, $state);

        $response
            ->addHeader('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
            ->addHeader('Pragma', 'no-cache')
            ->redirect($installUrl);
    }
}
