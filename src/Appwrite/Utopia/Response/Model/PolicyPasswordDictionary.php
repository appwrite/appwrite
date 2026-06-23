<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;

class PolicyPasswordDictionary extends PolicyBase
{
    public array $conditions = [
        '$id' => 'password-dictionary',
    ];

    public function __construct()
    {
        parent::__construct();

        $this->addRule('enabled', [
            'type' => self::TYPE_BOOLEAN,
            'description' => 'Whether password dictionary policy is enabled.',
            'default' => false,
            'example' => true,
        ]);
    }

    public function getName(): string
    {
        return 'Policy Password Dictionary';
    }

    public function getType(): string
    {
        return Response::MODEL_POLICY_PASSWORD_DICTIONARY;
    }
}
