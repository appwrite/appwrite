<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;

class PolicyDenyRealtimeGuests extends PolicyBase
{
    public array $conditions = [
        '$id' => 'deny-realtime-guests',
    ];

    public function __construct()
    {
        parent::__construct();

        $this->addRule('enabled', [
            'type' => self::TYPE_BOOLEAN,
            'description' => 'Whether guests are stopped from subscribing to realtime channels.',
            'default' => false,
            'example' => true,
        ]);
    }

    public function getName(): string
    {
        return 'Policy Deny Realtime Guests';
    }

    public function getType(): string
    {
        return Response::MODEL_POLICY_DENY_REALTIME_GUESTS;
    }
}
