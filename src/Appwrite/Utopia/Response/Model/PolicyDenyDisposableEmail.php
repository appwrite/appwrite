<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;

class PolicyDenyDisposableEmail extends PolicyBase
{
    public array $conditions = [
        '$id' => 'deny-disposable-email',
    ];

    public function __construct()
    {
        parent::__construct();

        $this->addRule('enabled', [
            'type' => self::TYPE_BOOLEAN,
            'description' => 'Whether the deny disposable email policy is enabled.',
            'default' => false,
            'example' => true,
        ]);
    }

    public function getName(): string
    {
        return 'Policy Deny Disposable Email';
    }

    public function getType(): string
    {
        return Response::MODEL_POLICY_DENY_DISPOSABLE_EMAIL;
    }
}
