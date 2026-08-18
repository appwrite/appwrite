<?php

namespace Appwrite\Platform\Modules\VCS\Http\Origin\Authorize;

use Appwrite\Auth\OAuth2\Cursor as OAuth2Cursor;
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
        return 'getVCSOriginAuthorize';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/vcs/origin/authorize')
            ->desc('Create Origin app installation')
            ->groups(['api', 'vcs'])
            ->label('scope', 'vcs.read')
            ->label('error', APP_VIEWS_DIR . '/general/error.phtml')
            ->label('sdk', new Method(
                namespace: 'vcs',
                group: 'installations',
                name: 'createOriginInstallation',
                description: 'Begin Appwrite\'s Origin app installation to set up version control integration. This endpoint responds with a redirect URL to the Origin app\'s installation page on Cursor. The Origin app must be configured in your environment for this endpoint to work.',
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
        $clientId = System::getEnv('_APP_VCS_ORIGIN_CLIENT_ID');

        if (empty($clientId)) {
            throw new Exception(Exception::GENERAL_SERVER_ERROR, 'Origin client ID is not configured. Please configure VCS (Version Control System) variables in .env file.');
        }

        // The Cursor adapter expects its secret as a JSON object.
        $oauth2 = new OAuth2Cursor($clientId, \json_encode(['privateKey' => System::getEnv('_APP_VCS_ORIGIN_PRIVATE_KEY', '')]), '');
        $url = $oauth2->getLoginURL();

        // TODO: Temporary debug logging while the Origin integration is verified -- remove afterwards.
        Console::log('[ORIGIN DEBUG] Authorize for project "' . $project->getId() . '" (success: "' . $success . '", failure: "' . $failure . '")');
        Console::log('[ORIGIN DEBUG] Redirecting to install URL: ' . $url);

        $response
            ->addHeader('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
            ->addHeader('Pragma', 'no-cache')
            ->redirect($url);
    }
}
