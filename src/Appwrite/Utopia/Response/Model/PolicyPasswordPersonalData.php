<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;

class PolicyPasswordPersonalData extends PolicyBase
{
    public array $conditions = [
        '$id' => 'password-personal-data',
    ];

    public function __construct()
    {
        parent::__construct();

        $this->addRule('enabled', [
            'type' => self::TYPE_BOOLEAN,
            'description' => 'Whether password personal data policy is enabled.',
            'default' => false,
            'example' => true,
        ]);

        $this->addRule('denyList', [
            'type' => self::TYPE_STRING,
            'description' => 'Custom terms blocked by case-insensitive substring matching when enabled.',
            'default' => [],
            'example' => ['appwrite'],
            'array' => true,
        ]);
    }

    public function getName(): string
    {
        return 'Policy Password Personal Data';
    }

    public function getType(): string
    {
        return Response::MODEL_POLICY_PASSWORD_PERSONAL_DATA;
    }
}
