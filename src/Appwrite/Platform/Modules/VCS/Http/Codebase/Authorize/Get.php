<?php

namespace Appwrite\Platform\Modules\VCS\Http\Codebase\Authorize;

use Appwrite\Extend\Exception;
use Appwrite\Platform\Action;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\MethodType;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Console;
use Utopia\Database\Document;
use Utopia\Platform\Scope\HTTP;
use Utopia\System\System;

class Get extends Action
{
    use HTTP;

    public static function getName()
    {
        return 'getVCSCodebaseAuthorize';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/vcs/codebase/authorize')
            ->desc('Create Codebase app installation')
            ->groups(['api', 'vcs'])
            ->label('scope', 'vcs.read')
            ->label('error', APP_VIEWS_DIR . '/general/error.phtml')
            ->label('sdk', new Method(
                namespace: 'vcs',
                group: 'installations',
                name: 'createCodebaseInstallation',
                description: 'Begin Appwrite\'s Codebase app installation to set up version control integration. This endpoint responds with a redirect URL to the Codebase app\'s installation page on Cursor. The Codebase app must be configured in your environment for this endpoint to work.',
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
            ->callback($this->action(...));
    }

    public function action(
        string $success,
        string $failure,
        Response $response,
        Document $project,
        array $platform
    ) {
        $clientId = System::getEnv('_APP_VCS_CODEBASE_CLIENT_ID');

        if (empty($clientId)) {
            throw new Exception(Exception::GENERAL_SERVER_ERROR, 'Codebase client ID is not configured. Please configure VCS (Version Control System) variables in .env file.');
        }

        // The callback endpoint is public and Codebase performs no token
        // exchange, so the callback verifies this signature before trusting
        // the projectId and redirect URLs in state.
        $state = \json_encode([
            'projectId' => $project->getId(),
            'success' => $success,
            'failure' => $failure,
            'signature' => \hash_hmac('sha256', \json_encode([$project->getId(), $success, $failure]), System::getEnv('_APP_OPENSSL_KEY_V1', '')),
        ]);

        $protocol = System::getEnv('_APP_OPTIONS_FORCE_HTTPS') === 'disabled' ? 'http' : 'https';
        $hostname = $platform['consoleHostname'] ?? '';

        $url = 'https://cursor.com/codebase/apps/install?' . \http_build_query([
            'client_id' => $clientId,
            'state' => $state,
            'redirect_uri' => $protocol . '://' . $hostname . '/v1/vcs/codebase/callback',
        ]);

        // TODO: Temporary debug logging while the Codebase integration is verified -- remove afterwards.
        Console::log('[CODEBASE DEBUG] Authorize for project "' . $project->getId() . '" (success: "' . $success . '", failure: "' . $failure . '")');
        Console::log('[CODEBASE DEBUG] Redirecting to install URL: ' . $url);

        $response
            ->addHeader('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
            ->addHeader('Pragma', 'no-cache')
            ->redirect($url);
    }
}
