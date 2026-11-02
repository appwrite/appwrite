<?php

namespace Appwrite\Platform\Modules\Account\Http\Account\Sessions\OAuth2\Redirect;

use Appwrite\Platform\Action;
use Appwrite\Platform\Modules\Account\Base;
use Utopia\Config\Config;
use Utopia\Platform\Scope\HTTP;
use Utopia\Validator\Text;
use Utopia\Validator\WhiteList;

class Get extends Base
{
    use HTTP;

    public static function getName(): string
    {
        return 'getOAuth2Redirect';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/account/sessions/oauth2/:provider/redirect')
            ->desc('Get OAuth2 redirect')
            ->groups(['api', 'account', 'session'])
            ->label('session.allowActive', true)
            ->label('error', APP_VIEWS_DIR . '/general/error.phtml')
            ->label('event', 'users.[userId].sessions.[sessionId].create')
            ->label('scope', 'public')
            ->label('audits.event', 'session.create')
            ->label('audits.resource', 'user/{user.$id}')
            ->label('audits.userId', '{user.$id}')
            ->label('abuse-limit', 50)
            ->label('abuse-key', 'ip:{ip}')
            ->label('docs', false)
            ->param('provider', '', new WhiteList(\array_keys(Config::getParam('oAuthProviders')), true), 'OAuth2 provider.')
            ->param('code', '', new Text(2048, 0), 'OAuth2 code. This is a temporary code that the will be later exchanged for an access token.', true)
            // 512 more for the app's state: 256 printable ASCII chars, at most doubled by JSON escaping
            ->param('state', '', new Text(2048 + 512), 'OAuth2 state params.', true)
            ->param('error', '', new Text(2048, 0), 'Error code returned from the OAuth2 provider.', true)
            ->param('error_description', '', new Text(2048, 0), 'Human-readable text providing additional information about the error returned from the OAuth2 provider.', true)
            ->inject('request')
            ->inject('response')
            ->inject('project')
            ->inject('redirectValidator')
            ->inject('user')
            ->inject('dbForProject')
            ->inject('geo')
            ->inject('dbForPlatform')
            ->inject('queueForEvents')
            ->inject('store')
            ->inject('proofForPassword')
            ->inject('proofForToken')
            ->inject('plan')
            ->inject('domainVerification')
            ->inject('cookieDomain')
            ->inject('authorization')
            ->inject('platform')
            ->inject('clientForOAuth2')
            ->inject('mqtt')
            ->inject('queueForRealtime');
    }
}
