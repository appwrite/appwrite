<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;

class PasskeyChallenge extends Model
{
    public function __construct()
    {
        $this
            ->addRule('$id', [
                'type' => self::TYPE_STRING,
                'description' => 'Challenge ID. Pass it back with the credential to complete the ceremony.',
                'default' => '',
                'example' => 'bb8ea5c16897e',
            ])
            ->addRule('$createdAt', [
                'type' => self::TYPE_DATETIME,
                'description' => 'Challenge creation date in ISO 8601 format.',
                'default' => '',
                'example' => self::TYPE_DATETIME_EXAMPLE,
            ])
            ->addRule('passkeyId', [
                'type' => self::TYPE_STRING,
                'description' => 'ID of the passkey being registered. Empty for sign-in challenges.',
                'default' => '',
                'example' => '5e5ea5c16897e',
            ])
            ->addRule('expire', [
                'type' => self::TYPE_DATETIME,
                'description' => 'Challenge expiration date in ISO 8601 format.',
                'default' => '',
                'example' => self::TYPE_DATETIME_EXAMPLE,
            ])
            ->addRule('publicKey', [
                'type' => self::TYPE_JSON,
                'description' => 'WebAuthn options in JSON form. Pass them to `PublicKeyCredential.parseCreationOptionsFromJSON()` when registering, or `PublicKeyCredential.parseRequestOptionsFromJSON()` when signing in.',
                'default' => new \stdClass(),
                'example' => ['challenge' => 'PBN-3LnR1ZxSSpC0TgJsbUu92mcdgErmvtne916-yPs', 'rpId' => 'example.com', 'userVerification' => 'required'],
            ])
        ;
    }

    public function getName(): string
    {
        return 'Passkey Challenge';
    }

    public function getType(): string
    {
        return Response::MODEL_PASSKEY_CHALLENGE;
    }
}
