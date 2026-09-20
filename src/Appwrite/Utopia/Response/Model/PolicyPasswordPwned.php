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

        $this
            ->addRule('enabled', [
                'type' => self::TYPE_BOOLEAN,
                'description' => 'Whether password pwned policy is enabled.',
                'default' => true,
                'example' => true,
            ])
            ->addRule('sessions', [
                'type' => self::TYPE_BOOLEAN,
                'description' => 'Whether passwords are checked when a session is created.',
                'default' => false,
                'example' => false,
            ])
            ->addRule('users', [
                'type' => self::TYPE_BOOLEAN,
                'description' => 'Whether users signing in with a breached password are blocked until they reset it. Only applies when sessions are checked.',
                'default' => false,
                'example' => false,
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
