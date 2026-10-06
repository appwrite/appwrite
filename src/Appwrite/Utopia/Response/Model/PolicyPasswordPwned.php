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
                'description' => 'Whether passwords are checked against known data breaches and the result recorded on the user.',
                'default' => true,
                'example' => true,
            ])
            ->addRule('sessions', [
                'type' => self::TYPE_BOOLEAN,
                'description' => 'Whether a sign-in with a breached password is refused until the password is reset.',
                'default' => false,
                'example' => false,
            ])
            ->addRule('users', [
                'type' => self::TYPE_BOOLEAN,
                'description' => 'Whether a breached password is rejected when a user signs up or sets a new password.',
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
