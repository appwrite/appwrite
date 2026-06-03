<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;

class PolicyMembershipPrivacy extends PolicyBase
{
    public array $conditions = [
        '$id' => 'membership-privacy',
    ];

    public function __construct()
    {
        parent::__construct();

        $this
            ->addRule('userId', [
                'type' => self::TYPE_BOOLEAN,
                'description' => 'Whether user ID is visible in memberships.',
                'default' => false,
                'example' => true,
            ])
            ->addRule('userEmail', [
                'type' => self::TYPE_BOOLEAN,
                'description' => 'Whether user email is visible in memberships.',
                'default' => false,
                'example' => true,
            ])
            ->addRule('userPhone', [
                'type' => self::TYPE_BOOLEAN,
                'description' => 'Whether user phone is visible in memberships.',
                'default' => false,
                'example' => true,
            ])
            ->addRule('userName', [
                'type' => self::TYPE_BOOLEAN,
                'description' => 'Whether user name is visible in memberships.',
                'default' => false,
                'example' => true,
            ])
            ->addRule('userMFA', [
                'type' => self::TYPE_BOOLEAN,
                'description' => 'Whether user MFA status is visible in memberships.',
                'default' => false,
                'example' => true,
            ]);
    }

    public function getName(): string
    {
        return 'Policy Membership Privacy';
    }

    public function getType(): string
    {
        return Response::MODEL_POLICY_MEMBERSHIP_PRIVACY;
    }
}
