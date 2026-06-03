<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;

class PolicyPasswordStrength extends PolicyBase
{
    public array $conditions = [
        '$id' => 'password-strength',
    ];

    public function __construct()
    {
        parent::__construct();

        $this
            ->addRule('min', [
                'type' => self::TYPE_INTEGER,
                'description' => 'Minimum password length required for user passwords.',
                'default' => 8,
                'example' => 12,
            ])
            ->addRule('uppercase', [
                'type' => self::TYPE_BOOLEAN,
                'description' => 'Whether passwords must include at least one uppercase letter.',
                'default' => false,
                'example' => true,
            ])
            ->addRule('lowercase', [
                'type' => self::TYPE_BOOLEAN,
                'description' => 'Whether passwords must include at least one lowercase letter.',
                'default' => false,
                'example' => true,
            ])
            ->addRule('number', [
                'type' => self::TYPE_BOOLEAN,
                'description' => 'Whether passwords must include at least one number.',
                'default' => false,
                'example' => true,
            ])
            ->addRule('symbols', [
                'type' => self::TYPE_BOOLEAN,
                'description' => 'Whether passwords must include at least one symbol.',
                'default' => false,
                'example' => true,
            ]);
    }

    public function getName(): string
    {
        return 'Policy Password Strength';
    }

    public function getType(): string
    {
        return Response::MODEL_POLICY_PASSWORD_STRENGTH;
    }
}
