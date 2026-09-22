<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;

class PolicyDenyCorporateEmail extends PolicyBase
{
    public array $conditions = [
        '$id' => 'deny-corporate-email',
    ];

    public function __construct()
    {
        parent::__construct();

        $this->addRule('enabled', [
            'type' => self::TYPE_BOOLEAN,
            'description' => 'Whether the deny non-corporate email policy is enabled.',
            'default' => false,
            'example' => true,
        ]);
    }

    public function getName(): string
    {
        return 'Policy Deny Corporate Email';
    }

    public function getType(): string
    {
        return Response::MODEL_POLICY_DENY_CORPORATE_EMAIL;
    }
}
