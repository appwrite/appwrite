<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;

class PolicyPasswordRotation extends PolicyBase
{
    public array $conditions = [
        '$id' => 'password-rotation',
    ];

    public function __construct()
    {
        parent::__construct();

        $this
            ->addRule('enabled', [
                'type' => self::TYPE_BOOLEAN,
                'description' => 'Whether expired passwords require a reset before email/password sign-in.',
                'default' => false,
                'example' => true,
            ])
            ->addRule('maxAge', [
                'type' => self::TYPE_INTEGER,
                'description' => 'Maximum password age in seconds.',
                'default' => 31536000,
                'example' => 31536000,
            ]);
    }

    public function getName(): string
    {
        return 'Policy Password Rotation';
    }

    public function getType(): string
    {
        return Response::MODEL_POLICY_PASSWORD_ROTATION;
    }
}
