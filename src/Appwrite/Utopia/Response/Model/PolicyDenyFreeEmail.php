<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;

class PolicyDenyFreeEmail extends PolicyBase
{
    public array $conditions = [
        '$id' => 'deny-free-email',
    ];

    public function __construct()
    {
        parent::__construct();

        $this->addRule('enabled', [
            'type' => self::TYPE_BOOLEAN,
            'description' => 'Whether the deny free email policy is enabled.',
            'default' => false,
            'example' => true,
        ]);
    }

    public function getName(): string
    {
        return 'Policy Deny Free Email';
    }

    public function getType(): string
    {
        return Response::MODEL_POLICY_DENY_FREE_EMAIL;
    }
}
