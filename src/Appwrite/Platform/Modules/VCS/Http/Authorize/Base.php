<?php

namespace Appwrite\Platform\Modules\VCS\Http\Authorize;

use Appwrite\Extend\Exception;
use Appwrite\Platform\Action;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\MethodType;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Appwrite\Vcs\Provider;
use Appwrite\Vcs\Resolver;
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

    public function __construct()
    {
        $key = static::getProvider();
        $name = Provider::fromKey($key)->getName();

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
            ->inject('vcs')
            ->inject('response')
            ->inject('project')
            ->inject('platform')
            ->callback($this->action(...));
    }

    public function action(
        string $success,
        string $failure,
        Resolver $vcs,
        Response $response,
        Document $project,
        array $platform
    ) {
        $provider = $vcs->getProvider(static::getProvider());

        if (!$provider->isConfigured()) {
            throw new Exception(Exception::GENERAL_SERVER_ERROR, $provider->getName() . ' provider is not configured. Please configure VCS (Version Control System) variables in .env file.');
        }

        $protocol = System::getEnv('_APP_OPTIONS_FORCE_HTTPS') === 'disabled' ? 'http' : 'https';
        $hostname = $platform['consoleHostname'] ?? '';
        $callback = $protocol . '://' . $hostname . '/v1/vcs/' . $provider->getKey() . '/callback';

        $oauth2 = $provider->createOAuth2($callback, [
            'projectId' => $project->getId(),
            'success' => $success,
            'failure' => $failure,
        ]);

        // The authorization page is opened by the browser, which may reach the
        // provider on a different host than the server-side API endpoint.
        if (\method_exists($oauth2, 'setEndpoint')) {
            $oauth2->setEndpoint($provider->getBrowserEndpoint());
        }

        $response
            ->addHeader('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
            ->addHeader('Pragma', 'no-cache')
            ->redirect($oauth2->getLoginURL());
    }
}
