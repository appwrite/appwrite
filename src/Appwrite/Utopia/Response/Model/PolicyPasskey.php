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
                'description' => 'Web origins allowed to register and sign in with passkeys.',
                'default' => [],
                'example' => ['https://example.com'],
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
