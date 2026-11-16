<?php

namespace Appwrite\Platform\Modules\Mock\Http\Mock\Tests\General\OAuth2\User\NoEmail;

use Appwrite\Extend\Exception;
use Appwrite\Platform\Action;
use Appwrite\Utopia\Response;
use Utopia\Platform\Scope\HTTP;
use Utopia\Validator\Text;

class Get extends Action
{
    use HTTP;

    public static function getName(): string
    {
        return 'getOAuth2UserNoEmail';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/mock/tests/general/oauth2/user-no-email')
            ->desc('OAuth2 User Without Email')
            ->groups(['mock'])
            ->label('scope', 'public')
            ->label('docs', false)
            ->param('token', '', new Text(100), 'OAuth2 Access Token.')
            ->inject('response')
            ->callback($this->action(...));
    }

    public function action(string $token, Response $response): void
    {
        if ($token != '123456') {
            throw new Exception(Exception::GENERAL_MOCK, 'Invalid token');
        }

        $response->json([
            'id' => 3,
            'name' => 'User Name NoEmail',
        ]);
    }
}
