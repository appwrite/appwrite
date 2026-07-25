<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;

class PolicyUserLimit extends PolicyBase
{
    public array $conditions = [
        '$id' => 'user-limit',
    ];

    public function __construct()
    {
        parent::__construct();

        $this->addRule('total', [
            'type' => self::TYPE_INTEGER,
            'description' => 'Maximum number of users allowed in the project. A value of 0 means the policy is disabled.',
            'default' => 0,
            'example' => 100,
        ]);
    }

    public function getName(): string
    {
        return 'Policy User Limit';
    }

    public function getType(): string
    {
        return Response::MODEL_POLICY_USER_LIMIT;
    }
}
