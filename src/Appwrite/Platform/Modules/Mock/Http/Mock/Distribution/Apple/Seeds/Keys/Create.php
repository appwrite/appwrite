<?php

namespace Appwrite\Platform\Modules\Mock\Http\Mock\Distribution\Apple\Seeds\Keys;

use Appwrite\Extend\Exception;
use Appwrite\Platform\Action;
use Appwrite\Platform\Modules\Mock\Http\Mock\Distribution\Apple\Base;
use Appwrite\Utopia\Response;
use Utopia\Cache\Cache;
use Utopia\System\System;
use Utopia\Validator\Boolean;
use Utopia\Validator\Text;

class Create extends Base
{
    private const string CURVE = 'prime256v1';

    public static function getName(): string
    {
        return 'createDistributionAppleSeedKey';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_POST)
            ->setHttpPath(self::SEEDS . '/keys')
            ->desc('Seed App Store Connect API key')
            ->groups(['mock'])
            ->label('scope', 'public')
            ->label('docs', false)
            ->label('mock', true)
            ->param('keyId', '', new Text(64), 'API key ID, sent as the token `kid`.')
            ->param('issuerId', '', new Text(128), 'Issuer ID, expected as the token `iss`.')
            ->param('publicKey', '', new Text(4096), 'PEM encoded P-256 public key that verifies the token signature.')
            ->param('agreements', true, new Boolean(true), 'Whether the team has accepted the current program license agreement.', true)
            ->inject('response')
            ->inject('cache')
            ->callback($this->action(...));
    }

    public function action(string $keyId, string $issuerId, string $publicKey, bool $agreements, Response $response, Cache $cache): void
    {
        $isDevelopment = System::getEnv('_APP_ENV', 'production') === 'development';

        if (!$isDevelopment) {
            throw new Exception(Exception::GENERAL_NOT_IMPLEMENTED);
        }

        $key = \openssl_pkey_get_public($publicKey);
        $details = $key === false ? false : \openssl_pkey_get_details($key);

        if ($details === false || $details['type'] !== OPENSSL_KEYTYPE_EC || ($details['ec']['curve_name'] ?? '') !== self::CURVE) {
            throw new Exception(Exception::GENERAL_MOCK, 'Public key must be a PEM encoded P-256 key');
        }

        $cache->save(self::CACHE_KEYS . $keyId, [
            'keyId' => $keyId,
            'issuerId' => $issuerId,
            'publicKey' => $publicKey,
            'agreements' => $agreements,
        ]);

        $response
            ->setStatusCode(Response::STATUS_CODE_CREATED)
            ->json([
                'data' => [
                    'type' => 'apiKeys',
                    'id' => $keyId,
                    'attributes' => [
                        'issuerId' => $issuerId,
                        'agreements' => $agreements,
                    ],
                ],
            ]);
    }
}
