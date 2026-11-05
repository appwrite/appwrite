<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;

class PolicyAccountSwitching extends PolicyBase
{
    public array $conditions = [
        '$id' => 'account-switching',
    ];

    public function __construct()
    {
        parent::__construct();

        $this->addRule('enabled', [
            'type' => self::TYPE_BOOLEAN,
            'description' => 'Whether account switching is enabled. When disabled, clients can no longer be created.',
            'default' => true,
            'example' => true,
        ]);
    }

    public function getName(): string
    {
        return 'Policy Account Switching';
    }

    public function getType(): string
    {
        return Response::MODEL_POLICY_ACCOUNT_SWITCHING;
    }
}
