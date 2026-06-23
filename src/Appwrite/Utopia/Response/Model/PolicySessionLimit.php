<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;

class PolicySessionLimit extends PolicyBase
{
    public array $conditions = [
        '$id' => 'session-limit',
    ];

    public function __construct()
    {
        parent::__construct();

        $this->addRule('total', [
            'type' => self::TYPE_INTEGER,
            'description' => 'Maximum number of sessions allowed per user. A value of 0 means the policy is disabled.',
            'default' => 0,
            'example' => 10,
        ]);
    }

    public function getName(): string
    {
        return 'Policy Session Limit';
    }

    public function getType(): string
    {
        return Response::MODEL_POLICY_SESSION_LIMIT;
    }
}
