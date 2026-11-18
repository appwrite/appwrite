<?php

namespace Appwrite\Platform\Modules\Mock\Http\Mock\Tests\General\OAuth2\IdToken;

use Appwrite\Auth\OIDC\Mock\SigningKey;
use Appwrite\Extend\Exception;
use Appwrite\Locking\Lock;
use Appwrite\Platform\Action;
use Appwrite\Utopia\Response;
use Utopia\Cache\Cache;
use Utopia\Platform\Scope\HTTP;
use Utopia\Validator\Text;

class Get extends Action
{
    use HTTP;

    public static function getName(): string
    {
        return 'getOAuth2IdToken';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/mock/tests/general/oauth2/id-token')
            ->desc('OAuth2 ID Token')
            ->groups(['mock'])
            ->label('scope', 'public')
            ->label('docs', false)
            ->label('mock', true)
            ->param('claims', '', new Text(4096, 0), 'JSON encoded ID token claims.')
            ->param('header', '', new Text(1024, 0), 'JSON encoded ID token header overrides.', true)
            ->inject('response')
            ->inject('cache')
            ->inject('lock')
            ->callback($this->action(...));
    }

    public function action(string $claims, string $header, Response $response, Cache $cache, Lock $lock): void
    {
        $claims = \json_decode($claims, true);
        $header = $header === '' ? [] : \json_decode($header, true);

        if (!\is_array($claims) || !\is_array($header)) {
            throw new Exception(Exception::GENERAL_MOCK, 'Invalid claims or header');
        }

        $key = (new SigningKey($cache, $lock))->get();

        // Header overrides let tests mint deliberately broken tokens (unknown kid, unsupported alg, ...)
        $header = \array_merge([
            'alg' => 'RS256',
            'kid' => SigningKey::getId($key),
            'typ' => 'JWT',
        ], $header);

        $headerEncoded = \rtrim(\strtr(\base64_encode(\json_encode($header)), '+/', '-_'), '=');
        $payloadEncoded = \rtrim(\strtr(\base64_encode(\json_encode($claims)), '+/', '-_'), '=');
        \openssl_sign($headerEncoded . '.' . $payloadEncoded, $signature, $key, OPENSSL_ALGO_SHA256);

        $response->json([
            'token' => $headerEncoded . '.' . $payloadEncoded . '.' . \rtrim(\strtr(\base64_encode($signature), '+/', '-_'), '='),
        ]);
    }
}
