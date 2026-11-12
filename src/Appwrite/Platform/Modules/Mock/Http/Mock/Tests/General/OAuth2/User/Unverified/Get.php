<?php

namespace Appwrite\Platform\Modules\Mock\Http\Mock\Tests\General\OAuth2\User\Unverified;

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
        return 'getOAuth2UserUnverified';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/mock/tests/general/oauth2/user-unverified')
            ->desc('OAuth2 User Unverified')
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
            $response->json([
                'id' => 2,
                'name' => 'User Name Unverified',
                'email' => 'useroauthunverified@localhost.test',
                'verified' => false,
            ]);

            return;
        }

        // Same `canonical-` handshake as the verified profile, so a test can pick the
        // address this provider claims without verifying it
        if (\str_starts_with($token, 'canonical-')) {
            $id = \substr($token, \strlen('canonical-'));

            $response->json([
                'id' => 'unverified-' . $id,
                'name' => 'User Name Unverified',
                // Any address only in development, where the e2e suite runs
                'email' => \str_contains($id, '@') && System::getEnv('_APP_ENV', 'development') === 'development' ? $id : 'oauth.' . $id . '@gmail.com',
                'verified' => false,
            ]);

            return;
        }

        throw new Exception(Exception::GENERAL_MOCK, 'Invalid token');
    }
}
