<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;

class PolicyDenyAliasedEmail extends PolicyBase
{
    public array $conditions = [
        '$id' => 'deny-aliased-email',
    ];

    public function __construct()
    {
        parent::__construct();

        $this->addRule('enabled', [
            'type' => self::TYPE_BOOLEAN,
            'description' => 'Whether the deny aliased email policy is enabled.',
            'default' => false,
            'example' => true,
        ]);
    }

    public function getName(): string
    {
        return 'Policy Deny Aliased Email';
    }

    public function getType(): string
    {
        return Response::MODEL_POLICY_DENY_ALIASED_EMAIL;
    }
}
