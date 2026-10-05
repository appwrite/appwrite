<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Auth\MFA\Type;
use Appwrite\Utopia\Response;

class PolicyMFAFactors extends PolicyBase
{
    public array $conditions = [
        '$id' => 'mfa-factors',
    ];

    public function __construct()
    {
        parent::__construct();

        $this
            ->addRule(Type::TOTP, [
                'type' => self::TYPE_BOOLEAN,
                'description' => 'Whether TOTP can be used to complete an MFA challenge.',
                'default' => true,
                'example' => true,
            ])
            ->addRule(Type::EMAIL, [
                'type' => self::TYPE_BOOLEAN,
                'description' => 'Whether email can be used to complete an MFA challenge.',
                'default' => true,
                'example' => true,
            ])
            ->addRule(Type::PHONE, [
                'type' => self::TYPE_BOOLEAN,
                'description' => 'Whether phone (SMS) can be used to complete an MFA challenge.',
                'default' => true,
                'example' => true,
            ])
            ->addRule(Type::CUSTOM, [
                'type' => self::TYPE_BOOLEAN,
                'description' => 'Whether the custom factor can be used to complete an MFA challenge.',
                'default' => false,
                'example' => true,
            ]);
    }

    public function getName(): string
    {
        return 'Policy MFA Factors';
    }

    public function getType(): string
    {
        return Response::MODEL_POLICY_MFA_FACTORS;
    }
}
