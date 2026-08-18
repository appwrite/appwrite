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
use Utopia\Database\Document;
use Utopia\Platform\Scope\HTTP;
use Utopia\System\System;

class Get extends Action
{
    use HTTP;

    /**
     * Everything the integration touches: cloning sources, pushing templates,
     * deploying from pull requests, commenting on them, and reporting build
     * checks. repository:metadata:read is granted implicitly.
     */
    public const SCOPES = [
        'repository:contents:read',
        'repository:contents:write',
        'repository:pull_requests:read',
        'repository:pull_requests:write',
        'repository:pull_requests:reviews:read',
        'repository:pull_requests:reviews:write',
        'repository:checks:read',
        'repository:checks:write',
    ];

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
                description: '/docs/references/vcs/create-origin-installation.md',
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
            ->callback($this->action(...));
    }

    public function action(
        string $success,
        string $failure,
        Response $response,
        Document $project,
        array $platform,
        callable $vcsConfigured,
    ) {
        if (!$vcsConfigured('origin')) {
            throw new Exception(Exception::GENERAL_SERVER_ERROR, 'This endpoint is not implemented on this server. Please configure VCS (Version Control System) variables in .env file.');
        }

        $projectId = $project->getId();

        // The callback is public and Origin performs no token exchange, so the
        // state carries an HMAC binding the redirect targets to this project.
        $state = [
            'projectId' => $projectId,
            'success' => $success,
            'failure' => $failure,
            'signature' => \hash_hmac('sha256', \json_encode([$projectId, $success, $failure]), System::getEnv('_APP_OPENSSL_KEY_V1', '')),
        ];

        $protocol = System::getEnv('_APP_OPTIONS_FORCE_HTTPS') === 'disabled' ? 'http' : 'https';
        $callback = $protocol . '://' . ($platform['consoleHostname'] ?? '') . '/v1/vcs/origin/callback';

        // The Cursor adapter expects its secret as a JSON object; starting an
        // install only needs the app ID, so the key may be left out here.
        $oauth2 = new OAuth2Cursor(
            System::getEnv('_APP_VCS_ORIGIN_CLIENT_ID', ''),
            \json_encode(['privateKey' => System::getEnv('_APP_VCS_ORIGIN_PRIVATE_KEY', '')]),
            $callback,
            $state,
            self::SCOPES
        );

        $response
            ->addHeader('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
            ->addHeader('Pragma', 'no-cache')
            ->redirect($oauth2->getLoginURL());
    }
}
