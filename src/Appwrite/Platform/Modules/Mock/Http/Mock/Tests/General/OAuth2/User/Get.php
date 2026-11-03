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
            // A "#unverified" suffix reports the same user's email as unverified
            $verified = !\str_ends_with($id, '#unverified');
            if (!$verified) {
                $id = \substr($id, 0, -\strlen('#unverified'));
            }
            // A "<id>|<email>" value keeps the user ID while the email changes
            $address = $id;
            if (\str_contains($id, '|')) {
                [$id, $address] = \explode('|', $id, 2);
            }
            $user = [
                'id' => $id,
                'name' => 'Canonical Email User',
                // Any address only in development, where the e2e suite runs
                'email' => \str_contains($address, '@') && System::getEnv('_APP_ENV', 'development') === 'development' ? $address : 'oauth.' . $address . '@gmail.com',
                'verified' => $verified,
                'photo' => 'http://localhost/v1/mock/tests/general/oauth2/photo',
            ];
        } else {
            throw new Exception(Exception::GENERAL_MOCK, 'Invalid token');
        }

        $response->json($user);
    }
}
