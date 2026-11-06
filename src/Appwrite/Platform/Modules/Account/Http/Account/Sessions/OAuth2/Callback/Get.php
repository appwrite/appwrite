<?php

namespace Appwrite\Platform\Modules\Account\Http\Account\Sessions\OAuth2\Callback;

use Appwrite\Platform\Action;
use Appwrite\Utopia\Request;
use Appwrite\Utopia\Response;
use Utopia\Config\Config;
use Utopia\Platform\Scope\HTTP;
use Utopia\System\System;
use Utopia\Validator\Text;
use Utopia\Validator\WhiteList;

class Get extends Action
{
    use HTTP;

    public static function getName(): string
    {
        return 'getOAuth2Callback';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/account/sessions/oauth2/callback/:provider/:projectId')
            ->desc('Get OAuth2 callback')
            ->groups(['account'])
            ->label('error', APP_VIEWS_DIR . '/general/error.phtml')
            ->label('scope', 'public')
            ->label('docs', false)
            ->param('projectId', '', new Text(1024), 'Project ID.')
            ->param('provider', '', new WhiteList(\array_keys(Config::getParam('oAuthProviders')), true), 'OAuth2 provider.')
            ->param('code', '', new Text(2048, 0), 'OAuth2 code. This is a temporary code that the will be later exchanged for an access token.', true)
            // 512 more for the app's state: 256 printable ASCII chars, at most doubled by JSON escaping
            ->param('state', '', new Text(2048 + 512), 'Login state params.', true)
            ->param('error', '', new Text(2048, 0), 'Error code returned from the OAuth2 provider.', true)
            ->param('error_description', '', new Text(2048, 0), 'Human-readable text providing additional information about the error returned from the OAuth2 provider.', true)
            ->inject('request')
            ->inject('response')
            ->callback($this->action(...));
    }

    public function action(
        string $projectId,
        string $provider,
        string $code,
        string $state,
        string $error,
        string $error_description,
        Request $request,
        Response $response,
    ): void {
        $protocol = System::getEnv('_APP_OPTIONS_FORCE_HTTPS') === 'disabled' ? 'http' : 'https';
        $port = $request->getPort();
        $callbackBase = $protocol . '://' . $request->getHostname();
        if ($protocol === 'https' && $port !== '443') {
            $callbackBase .= ':' . $port;
        } elseif ($protocol === 'http' && $port !== '80') {
            $callbackBase .= ':' . $port;
        }

        $params = $request->getParams();
        $params['project'] = $projectId;
        unset($params['projectId']);

        $response
            ->addHeader('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
            ->addHeader('Pragma', 'no-cache')
            ->redirect($callbackBase . '/v1/account/sessions/oauth2/' . $provider . '/redirect?'
                . \http_build_query($params));
    }
}
