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

        $this
            ->addRule('userId', [
                'type' => self::TYPE_BOOLEAN,
                'description' => 'Whether passwords containing the user ID are blocked.',
                'default' => false,
                'example' => true,
            ])
            ->addRule('userEmail', [
                'type' => self::TYPE_BOOLEAN,
                'description' => 'Whether passwords containing the user email (or local part) are blocked.',
                'default' => false,
                'example' => true,
            ])
            ->addRule('userName', [
                'type' => self::TYPE_BOOLEAN,
                'description' => 'Whether passwords containing the user name are blocked.',
                'default' => false,
                'example' => true,
            ])
            ->addRule('userPhone', [
                'type' => self::TYPE_BOOLEAN,
                'description' => 'Whether passwords containing the user phone number are blocked.',
                'default' => false,
                'example' => true,
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
