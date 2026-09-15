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
            ->addRule('endpoint', [
                'type' => self::TYPE_STRING,
                'description' => 'Custom endpoint of a Have I Been Pwned compatible range API. Empty when the server default is used.',
                'default' => '',
                'example' => 'https://api.pwnedpasswords.com/range',
            ])
            ->addRule('threshold', [
                'type' => self::TYPE_INTEGER,
                'description' => 'Minimum number of known breaches a password must appear in before it is rejected.',
                'default' => 1,
                'example' => 1,
            ])
            ->addRule('sessions', [
                'type' => self::TYPE_BOOLEAN,
                'description' => 'Whether passwords are checked when a session is created.',
                'default' => false,
                'example' => false,
            ])
            ->addRule('forceReset', [
                'type' => self::TYPE_BOOLEAN,
                'description' => 'Whether signing in with a breached password is blocked until the password is reset. Only applies when sessions are checked.',
                'default' => false,
                'example' => false,
            ])
            ->addRule('failClosed', [
                'type' => self::TYPE_BOOLEAN,
                'description' => 'Whether passwords are rejected when the breach service cannot be reached. When false, the check is skipped instead.',
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
