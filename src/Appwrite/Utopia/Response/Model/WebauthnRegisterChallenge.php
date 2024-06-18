<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;

class WebauthnRegisterChallenge extends Model
{
    public function __construct()
    {
        $this
            ->addRule('$id', [
                'type' => self::TYPE_STRING,
                'description' => 'Challenge ID.',
                'default' => '',
                'example' => '5e5ea5c16897e',
            ])
            ->addRule('userId', [
                'type' => self::TYPE_STRING,
                'description' => 'User ID.',
                'default' => '',
                'example' => '5e5ea5c16897e',
            ])
            ->addRule('rp', [
                'type' => self::TYPE_JSON,
                'description' => 'The relying party information.',
                'default' => '',
                'example' => [
                    'id' => 'localhost',
                    'name' => 'Appwrite',
                ]
            ])
            ->addRule('user', [
                'type' => self::TYPE_JSON,
                'description' => 'The user entity information.',
                'default' => '',
                'example' => [
                    'id' => '5e5ea5c16897e',
                    'name' => 'John Doe',
                    'displayName' => 'John',
                ]
            ])
            ->addRule('challenge', [
                'type' => self::TYPE_STRING,
                'description' => 'Base64 encoded challenge.',
                'default' => '',
                'example' => 'a1b2c3d4',
                'array' => false
            ])
            ->addRule('pubKeyCredParams', [
                'type' => self::TYPE_STRING,
                'description' => 'Public key credential parameters.',
                'default' => '',
                'example' => [
                    [
                        'type' => 'public-key'
                    ]
                ]
            ])
            ->addRule('timeout', [
                'type' => self::TYPE_INTEGER,
                'description' => 'Challenge expiration date in seconds.',
                'default' => '',
                'example' => 60000,
            ])
        ;
    }

    /**
     * Get Name
     *
     * @return string
     */
    public function getName(): string
    {
        return 'WebauthnRegisterChallenge';
    }

    /**
     * Get Type
     *
     * @return string
     */
    public function getType(): string
    {
        return Response::MODEL_WEBAUTHN_REGISTER_CHALLENGE;
    }
}
