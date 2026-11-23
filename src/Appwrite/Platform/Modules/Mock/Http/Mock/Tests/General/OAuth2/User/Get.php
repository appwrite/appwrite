<?php

namespace Appwrite\Platform\Modules\Mock\Http\Mock\Tests\General\OAuth2\User;

use Appwrite\Extend\Exception;
use Appwrite\Platform\Action;
use Appwrite\Utopia\Response;
use Utopia\Platform\Scope\HTTP;
use Utopia\System\System;
use Utopia\Validator\Text;

class Get extends Action
{
    use HTTP;

    public static function getName(): string
    {
        return 'getOAuth2User';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/mock/tests/general/oauth2/user')
            ->desc('OAuth2 User')
            ->groups(['mock'])
            ->label('scope', 'public')
            ->label('docs', false)
            ->param('token', '', new Text(100), 'OAuth2 Access Token.')
            ->inject('response')
            ->callback($this->action(...));
    }

    public function action(string $token, Response $response): void
    {
        if ($token === '123456') {
            $user = [
                'id' => 1,
                'name' => 'User Name',
                'email' => 'useroauth@localhost.test',
                'verified' => true,
                'photo' => 'http://localhost/v1/mock/tests/general/oauth2/photo',
            ];
        } elseif ($token === 'refreshed-123456') {
            $user = [
                'id' => 1,
                'name' => 'User Name',
                'email' => 'useroauth@localhost.test',
                'verified' => true,
                'photo' => 'http://localhost/v1/mock/tests/general/oauth2/photo-refreshed',
            ];
        } elseif (\str_starts_with($token, 'canonical-')) {
            $id = \substr($token, \strlen('canonical-'));
            $user = [
                'id' => $id,
                'name' => 'Canonical Email User',
                // Any address only in development, where the e2e suite runs
                'email' => \str_contains($id, '@') && System::getEnv('_APP_ENV', 'development') === 'development' ? $id : 'oauth.' . $id . '@gmail.com',
                'verified' => true,
                'photo' => 'http://localhost/v1/mock/tests/general/oauth2/photo',
            ];
        } else {
            throw new Exception(Exception::GENERAL_MOCK, 'Invalid token');
        }

        $response->json($user);
    }
}
