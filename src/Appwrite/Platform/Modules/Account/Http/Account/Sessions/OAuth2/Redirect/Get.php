<?php

namespace Appwrite\Platform\Modules\Account\Http\Account\Sessions\OAuth2\Redirect;

use Appwrite\Platform\Action;
use Appwrite\Platform\Modules\Account\Base;
use Utopia\Platform\Scope\HTTP;

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
            ->label('docs', false);
    }
}
