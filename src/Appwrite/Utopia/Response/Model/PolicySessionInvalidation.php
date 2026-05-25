<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;

class PolicySessionInvalidation extends PolicyBase
{
    public array $conditions = [
        '$id' => 'session-invalidation',
    ];

    public function __construct()
    {
        parent::__construct();

        $this->addRule('enabled', [
            'type' => self::TYPE_BOOLEAN,
            'description' => 'Whether session invalidation policy is enabled.',
            'default' => true,
            'example' => true,
        ]);
    }

    public function getName(): string
    {
        return 'Policy Session Invalidation';
    }

    public function getType(): string
    {
        return Response::MODEL_POLICY_SESSION_INVALIDATION;
    }
}
