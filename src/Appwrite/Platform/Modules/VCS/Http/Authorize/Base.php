<?php

namespace Appwrite\Platform\Modules\VCS\Http\Authorize;

use Appwrite\Auth\OAuth2;
use Appwrite\Extend\Exception;
use Appwrite\Platform\Action;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\MethodType;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Appwrite\Vcs\Factory;
use Utopia\Database\Document;
use Utopia\Platform\Scope\HTTP;
use Utopia\System\System;

/**
 * Installation entry point for OAuth2-based VCS providers. Redirects to the
 * provider's authorization page; the matching Callback action completes the
 * flow. App-based providers (GitHub) have their own installation flow.
 */
abstract class Base extends Action
{
    use HTTP;

    /**
     * Provider key in the `vcs` config registry.
     */
    abstract public static function getProvider(): string;

    /**
     * Provider display name, used in SDK method names and error messages.
     */
    abstract public static function getProviderName(): string;

    /**
     * Builds this provider's OAuth2 client, pointed at the browser-facing
     * endpoint (which may differ from the server-side API endpoint in
     * containerized setups) since the login URL is opened by the browser.
     */
    abstract protected function createOAuth2(string $callback, array $state): OAuth2;

    public function __construct()
    {
        $key = static::getProvider();
        $name = static::getProviderName();

        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/vcs/' . $key . '/authorize')
            ->desc('Create ' . $name . ' installation')
            ->groups(['api', 'vcs'])
            ->label('scope', 'vcs.read')
            ->label('error', APP_VIEWS_DIR . '/general/error.phtml')
            ->label('sdk', new Method(
                namespace: 'vcs',
                group: 'installations',
                name: 'create' . $name . 'Installation',
                description: '/docs/references/vcs/create-' . $key . '-installation.md',
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
            ->inject('vcsFactory')
            ->inject('response')
            ->inject('project')
            ->inject('platform')
            ->callback($this->action(...));
    }

    public function action(
        string $success,
        string $failure,
        Factory $vcsFactory,
        Response $response,
        Document $project,
        array $platform
    ) {
        $key = static::getProvider();

        if (!$vcsFactory->isConfigured($key)) {
            throw new Exception(Exception::GENERAL_SERVER_ERROR, static::getProviderName() . ' provider is not configured. Please configure VCS (Version Control System) variables in .env file.');
        }

        $protocol = System::getEnv('_APP_OPTIONS_FORCE_HTTPS') === 'disabled' ? 'http' : 'https';
        $hostname = $platform['consoleHostname'] ?? '';
        $callback = $protocol . '://' . $hostname . '/v1/vcs/' . $key . '/callback';

        // The callback endpoint is public, so it verifies this signature
        // before trusting the projectId and redirect URLs in state.
        $oauth2 = $this->createOAuth2($callback, [
            'projectId' => $project->getId(),
            'success' => $success,
            'failure' => $failure,
            'signature' => \hash_hmac('sha256', \json_encode([$project->getId(), $success, $failure]), System::getEnv('_APP_OPENSSL_KEY_V1', '')),
        ]);

        $response
            ->addHeader('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
            ->addHeader('Pragma', 'no-cache')
            ->redirect($oauth2->getLoginURL());
    }
}
