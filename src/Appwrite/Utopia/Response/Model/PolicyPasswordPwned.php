<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;

class PolicyPasswordPwned extends PolicyBase
{
    public array $conditions = [
        '$id' => 'password-pwned',
    ];

    public function __construct()
    {
        parent::__construct();

        $this->addRule('enabled', [
            'type' => self::TYPE_BOOLEAN,
            'description' => 'Whether password pwned policy is enabled.',
            'default' => false,
            'example' => true,
        ]);
    }

    public function getName(): string
    {
        return 'Policy Password Pwned';
    }

    public function getType(): string
    {
        return Response::MODEL_POLICY_PASSWORD_PWNED;
    }
}
