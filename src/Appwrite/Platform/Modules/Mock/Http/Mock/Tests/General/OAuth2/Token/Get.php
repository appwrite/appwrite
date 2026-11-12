<?php

namespace Appwrite\Platform\Modules\Mock\Http\Mock\Tests\General\OAuth2\Token;

use Appwrite\Extend\Exception;
use Appwrite\Platform\Action;
use Appwrite\Utopia\Response;
use Utopia\Platform\Scope\HTTP;
use Utopia\Validator\Text;
use Utopia\Validator\WhiteList;

class Get extends Action
{
    use HTTP;

    public static function getName(): string
    {
        return 'getOAuth2Token';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/mock/tests/general/oauth2/token')
            ->desc('OAuth2 Token')
            ->groups(['mock'])
            ->label('scope', 'public')
            ->label('docs', false)
            ->label('mock', true)
            ->param('client_id', '', new Text(100), 'OAuth2 Client ID.')
            ->param('client_secret', '', new Text(100), 'OAuth2 scope list.')
            ->param('grant_type', 'authorization_code', new WhiteList(['refresh_token', 'authorization_code']), 'OAuth2 Grant Type.', true)
            ->param('redirect_uri', '', fn ($redirectValidator) => $redirectValidator, 'URL to redirect back to your app after a successful login attempt.  Only URLs from hostnames in your project\'s platform list are allowed. This requirement helps to prevent an [open redirect](https://cheatsheetseries.owasp.org/cheatsheets/Unvalidated_Redirects_and_Forwards_Cheat_Sheet.html) attack against your project API.', true, ['redirectValidator'])
            ->param('code', '', new Text(100), 'OAuth2 state.', true)
            ->param('refresh_token', '', new Text(100), 'OAuth2 refresh token.', true)
            ->inject('response')
            ->callback($this->action(...));
    }

    public function action(string $client_id, string $client_secret, string $grantType, string $redirectURI, string $code, string $refreshToken, Response $response): void
    {
        $canonicalEmail = \str_starts_with($client_id, 'canonical-');

        if ($client_id != '1' && !$canonicalEmail) {
            throw new Exception(Exception::GENERAL_MOCK, 'Invalid client ID');
        }

        if ($client_secret != '123456') {
            throw new Exception(Exception::GENERAL_MOCK, 'Invalid client secret');
        }

        $responseJson = [
            'access_token' => $canonicalEmail ? $client_id : '123456',
            'refresh_token' => 'tuvwxyz',
            'expires_in' => 14400
        ];

        if ($grantType === 'authorization_code') {
            if ($code !== 'abcdef') {
                throw new Exception(Exception::GENERAL_MOCK, 'Invalid token');
            }

            $response->json($responseJson);
        } elseif ($grantType === 'refresh_token') {
            if ($refreshToken !== 'tuvwxyz') {
                throw new Exception(Exception::GENERAL_MOCK, 'Invalid refresh token');
            }

            $responseJson['access_token'] = $canonicalEmail ? $client_id : 'refreshed-123456';
            $response->json($responseJson);
        } else {
            throw new Exception(Exception::GENERAL_MOCK, 'Invalid grant type');
        }
    }
}
