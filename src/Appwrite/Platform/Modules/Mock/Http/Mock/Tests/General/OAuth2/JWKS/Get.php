<?php

namespace Appwrite\Platform\Modules\Mock\Http\Mock\Tests\General\OAuth2\JWKS;

use Appwrite\Auth\OIDC\Mock\SigningKey;
use Appwrite\Locking\Lock;
use Appwrite\Platform\Action;
use Appwrite\Utopia\Response;
use Utopia\Cache\Cache;
use Utopia\Platform\Scope\HTTP;

class Get extends Action
{
    use HTTP;

    public static function getName(): string
    {
        return 'getOAuth2JWKS';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/mock/tests/general/oauth2/jwks')
            ->desc('OAuth2 JWKS')
            ->groups(['mock'])
            ->label('scope', 'public')
            ->label('docs', false)
            ->label('mock', true)
            ->inject('response')
            ->inject('cache')
            ->inject('lock')
            ->callback($this->action(...));
    }

    public function action(Response $response, Cache $cache, Lock $lock): void
    {
        $key = (new SigningKey($cache, $lock))->get();
        $details = \openssl_pkey_get_details($key);

        $response->json([
            'keys' => [[
                'kty' => 'RSA',
                'use' => 'sig',
                'alg' => 'RS256',
                'kid' => SigningKey::getId($key),
                'n' => \rtrim(\strtr(\base64_encode($details['rsa']['n']), '+/', '-_'), '='),
                'e' => \rtrim(\strtr(\base64_encode($details['rsa']['e']), '+/', '-_'), '='),
            ]],
        ]);
    }
}
