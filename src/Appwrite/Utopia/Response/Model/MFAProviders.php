<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;

class MFAProviders extends Model
{
    public function __construct()
    {
        $this
            ->addRule('totp', [
                'type' => self::TYPE_BOOLEAN,
                'description' => 'TOTP',
                'default' => false,
                'example' => true
            ])
            ->addRule('hotp', [
                'type' => self::TYPE_BOOLEAN,
                'description' => 'HOTP',
                'default' => false,
                'example' => true
            ])
            ->addRule('phone', [
                'type' => self::TYPE_BOOLEAN,
                'description' => 'Phone',
                'default' => false,
                'example' => true
            ])
            ->addRule('email', [
                'type' => self::TYPE_BOOLEAN,
                'description' => 'Email',
                'default' => false,
                'example' => true
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
        return 'MFAProviders';
    }

    /**
     * Get Type
     *
     * @return string
     */
    public function getType(): string
    {
        return Response::MODEL_MFA_PROVIDERS;
    }
}
