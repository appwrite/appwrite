<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;

class PolicyPasskey extends PolicyBase
{
    public array $conditions = [
        '$id' => 'passkey',
    ];

    public function __construct()
    {
        parent::__construct();

        $this
            ->addRule('rpId', [
                'type' => self::TYPE_STRING,
                'description' => 'Relying party ID passkeys are bound to. Empty until configured.',
                'default' => '',
                'example' => 'example.com',
            ])
            ->addRule('origins', [
                'type' => self::TYPE_STRING,
                'description' => 'Web origins passkeys work on, from the project\'s platforms on the relying party ID and a localhost web platform. Read-only.',
                'default' => [],
                'example' => ['https://app.example.com', 'http://localhost'],
                'array' => true,
            ]);
    }

    public function getName(): string
    {
        return 'Policy Passkey';
    }

    public function getType(): string
    {
        return Response::MODEL_POLICY_PASSKEY;
    }
}
