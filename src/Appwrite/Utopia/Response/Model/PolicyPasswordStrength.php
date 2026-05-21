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
            ->addRule('minLength', [
                'type' => self::TYPE_INTEGER,
                'description' => 'Minimum password length required for user passwords.',
                'default' => 8,
                'example' => 12,
            ])
            ->addRule('requireUppercase', [
                'type' => self::TYPE_BOOLEAN,
                'description' => 'Whether passwords must include at least one uppercase letter.',
                'default' => false,
                'example' => true,
            ])
            ->addRule('requireLowercase', [
                'type' => self::TYPE_BOOLEAN,
                'description' => 'Whether passwords must include at least one lowercase letter.',
                'default' => false,
                'example' => true,
            ])
            ->addRule('requireNumber', [
                'type' => self::TYPE_BOOLEAN,
                'description' => 'Whether passwords must include at least one number.',
                'default' => false,
                'example' => true,
            ])
            ->addRule('requireSpecialChar', [
                'type' => self::TYPE_BOOLEAN,
                'description' => 'Whether passwords must include at least one special character.',
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
