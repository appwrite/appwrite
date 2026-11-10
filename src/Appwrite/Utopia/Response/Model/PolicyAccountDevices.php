<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;

class PolicyAccountDevices extends PolicyBase
{
    public array $conditions = [
        '$id' => 'account-devices',
    ];

    public function __construct()
    {
        parent::__construct();

        $this->addRule('enabled', [
            'type' => self::TYPE_BOOLEAN,
            'description' => 'Whether account devices are enabled. When disabled, new account devices can no longer be created.',
            'default' => true,
            'example' => true,
        ]);
    }

    public function getName(): string
    {
        return 'Policy Account Devices';
    }

    public function getType(): string
    {
        return Response::MODEL_POLICY_ACCOUNT_DEVICES;
    }
}
