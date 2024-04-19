<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Auth\MFA\Type;
use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;

class MFAFactors extends Model
{
    public function __construct()
    {
        $this
            ->addRule(Type::TOTP, [
                'type' => self::TYPE_BOOLEAN,
                'description' => 'TOTP',
                'default' => false,
                'example' => true
            ])
            ->addRule(Type::PHONE, [
                'type' => self::TYPE_BOOLEAN,
                'description' => 'Phone',
                'default' => false,
                'example' => true
            ])
            ->addRule(Type::EMAIL, [
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
        return 'MFAFactors';
    }

    /**
     * Get Type
     *
     * @return string
     */
    public function getType(): string
    {
        return Response::MODEL_MFA_FACTORS;
    }
}
