<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;

class PolicyPasswordHistory extends PolicyBase
{
    public array $conditions = [
        '$id' => 'password-history',
    ];

    public function __construct()
    {
        parent::__construct();

        $this->addRule('total', [
            'type' => self::TYPE_INTEGER,
            'description' => 'Password history length. A value of 0 means the policy is disabled.',
            'default' => 0,
            'example' => 5,
        ]);
    }

    public function getName(): string
    {
        return 'Policy Password History';
    }

    public function getType(): string
    {
        return Response::MODEL_POLICY_PASSWORD_HISTORY;
    }
}
