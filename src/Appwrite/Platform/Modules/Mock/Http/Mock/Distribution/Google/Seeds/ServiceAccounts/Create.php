<?php

namespace Appwrite\Platform\Modules\Mock\Http\Mock\Distribution\Google\Seeds\ServiceAccounts;

use Appwrite\Extend\Exception;
use Appwrite\Platform\Action;
use Appwrite\Platform\Modules\Mock\Http\Mock\Distribution\Google\Base;
use Appwrite\Utopia\Response;
use Utopia\Cache\Cache;
use Utopia\Emails\Validator\Email;
use Utopia\System\System;
use Utopia\Validator\Text;

class Create extends Base
{
    public static function getName(): string
    {
        return 'createDistributionGoogleSeedServiceAccount';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_POST)
            ->setHttpPath(self::SEEDS . '/service-accounts')
            ->desc('Seed Google service account')
            ->groups(['mock'])
            ->label('scope', 'public')
            ->label('docs', false)
            ->label('mock', true)
            ->param('email', '', new Email(), 'Service account email, the JWT assertion issuer.')
            ->param('publicKey', '', new Text(0), 'RSA public key in PEM format that verifies the account\'s JWT assertions.')
            ->inject('response')
            ->inject('cache')
            ->callback($this->action(...));
    }

    public function action(string $email, string $publicKey, Response $response, Cache $cache): void
    {
        $isDevelopment = System::getEnv('_APP_ENV', 'production') === 'development';

        if (!$isDevelopment) {
            throw new Exception(Exception::GENERAL_NOT_IMPLEMENTED);
        }

        $key = \openssl_pkey_get_public($publicKey);
        $details = $key === false ? false : \openssl_pkey_get_details($key);

        if ($details === false || $details['type'] !== OPENSSL_KEYTYPE_RSA) {
            throw new Exception(Exception::GENERAL_MOCK, 'Invalid RSA public key');
        }

        $cache->save($this->key(self::RESOURCE_ACCOUNT, $email), [
            'email' => $email,
            'publicKey' => $publicKey,
        ], '', self::TTL);

        $response
            ->setStatusCode(Response::STATUS_CODE_CREATED)
            ->json(['email' => $email]);
    }
}
