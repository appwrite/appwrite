<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;

class PolicySessionDuration extends PolicyBase
{
    public array $conditions = [
        '$id' => 'session-duration',
    ];

    public function __construct()
    {
        parent::__construct();

        $this->addRule('duration', [
            'type' => self::TYPE_INTEGER,
            'description' => 'Session duration in seconds.',
            'default' => TOKEN_EXPIRATION_LOGIN_LONG,
            'example' => 3600,
        ]);
    }

    public function getName(): string
    {
        return 'Policy Session Duration';
    }

    public function getType(): string
    {
        return Response::MODEL_POLICY_SESSION_DURATION;
    }
}
