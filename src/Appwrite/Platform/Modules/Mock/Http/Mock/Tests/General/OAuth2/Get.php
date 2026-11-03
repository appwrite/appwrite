<?php

namespace Appwrite\Platform\Modules\Mock\Http\Mock\Tests\General\OAuth2;

use Appwrite\Platform\Action;
use Appwrite\Utopia\Response;
use Utopia\Platform\Scope\HTTP;
use Utopia\Validator\Text;

class Get extends Action
{
    use HTTP;

    public static function getName(): string
    {
        return 'getOAuth2';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/mock/tests/general/oauth2')
            ->desc('OAuth Login')
            ->groups(['mock'])
            ->label('scope', 'public')
            ->label('docs', false)
            ->label('mock', true)
            ->param('client_id', '', new Text(100), 'OAuth2 Client ID.')
            ->param('redirect_uri', '', fn ($redirectValidator) => $redirectValidator, 'URL to redirect back to your app after a failed login attempt.  Only URLs from hostnames in your project\'s platform list are allowed. This requirement helps to prevent an [open redirect](https://cheatsheetseries.owasp.org/cheatsheets/Unvalidated_Redirects_and_Forwards_Cheat_Sheet.html) attack against your project API.', true, ['redirectValidator']) // Important to deny an open redirect attack
            ->param('scope', '', new Text(100), 'OAuth2 scope list.')
            ->param('state', '', new Text(1024), 'OAuth2 state.')
            ->inject('response')
            ->callback($this->action(...));
    }

    public function action(string $client_id, string $redirectURI, string $scope, string $state, Response $response): void
    {
        $response->redirect($redirectURI . '?' . \http_build_query(['code' => 'abcdef', 'state' => $state]));
    }
}
