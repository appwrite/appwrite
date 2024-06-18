<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;

class WebauthnLoginChallenge extends Model
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
            ->addRule('rpId', [
                'type' => self::TYPE_STRING,
                'description' => 'Relying Party ID.',
                'default' => '',
                'example' => 'localhost',
            ])
            ->addRule('challenge', [
                'type' => self::TYPE_STRING,
                'description' => 'Base64 encoded challenge.',
                'default' => '',
                'example' => 'a1b2c3d4',
                'array' => false
            ])
            ->addRule('timeout', [
                'type' => self::TYPE_INTEGER,
                'description' => 'Challenge expiration date in seconds.',
                'default' => '',
                'example' => 60000,
            ])
            ->addRule('allowCredentials', [
                'type' => self::TYPE_JSON,
                'description' => 'List of allowed credentials.',
                'default' => [],
                'example' => [
                    [
                        'type' => 'public-key',
                        'id' => 'a1b2c3d4',
                    ],
                ],
                'array' => true
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
        return 'WebauthnLoginChallenge';
    }

    /**
     * Get Type
     *
     * @return string
     */
    public function getType(): string
    {
        return Response::MODEL_WEBAUTHN_LOGIN_CHALLENGE;
    }
}
