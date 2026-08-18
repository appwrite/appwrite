<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;

class OAuth2Cursor extends OAuth2Base
{
    public array $conditions = [
        '$id' => 'cursor',
    ];

    public function getProviderLabel(): string
    {
        return 'Cursor';
    }

    public function getClientIdExample(): string
    {
        return 'app_01k5wz9v3rq8tembsy0d4hxnpc';
    }

    public function getClientSecretExample(): string
    {
        return '-----BEGIN PRIVATE KEY-----\nMC4CAQAwBQYDK2VwBCIEIAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA\n-----END PRIVATE KEY-----';
    }

    public function getClientIdLabel(): string
    {
        return 'app ID';
    }

    public function getClientSecretFieldName(): string
    {
        return 'privateKey';
    }

    public function getClientSecretLabel(): string
    {
        return 'private key';
    }

    public function getClientSecretDescription(): string
    {
        return parent::getClientSecretDescription() . ' Ed25519 key in PKCS#8 PEM format; only its public key is registered with Cursor.';
    }

    /**
     * Get Name
     *
     * @return string
     */
    public function getName(): string
    {
        return 'OAuth2Cursor';
    }

    /**
     * Get Type
     *
     * @return string
     */
    public function getType(): string
    {
        return Response::MODEL_OAUTH2_CURSOR;
    }
}
