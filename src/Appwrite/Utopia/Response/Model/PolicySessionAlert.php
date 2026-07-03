<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;

class PolicySessionAlert extends PolicyBase
{
    public array $conditions = [
        '$id' => 'session-alert',
    ];

    public function __construct()
    {
        parent::__construct();

        $this->addRule('enabled', [
            'type' => self::TYPE_BOOLEAN,
            'description' => 'Whether session alert policy is enabled.',
            'default' => false,
            'example' => true,
        ]);
    }

    public function getName(): string
    {
        return 'Policy Session Alert';
    }

    public function getType(): string
    {
        return Response::MODEL_POLICY_SESSION_ALERT;
    }
}
