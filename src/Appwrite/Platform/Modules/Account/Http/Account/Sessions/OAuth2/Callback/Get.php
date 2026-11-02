<?php

namespace Appwrite\Platform\Modules\Account\Http\Account\Sessions\OAuth2\Callback;

use Appwrite\Platform\Action;
use Utopia\Platform\Scope\HTTP;

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
            ->label('docs', false);
    }
}
