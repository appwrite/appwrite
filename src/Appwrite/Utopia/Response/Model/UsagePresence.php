<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;

class UsagePresence extends Model
{
    public function __construct()
    {
        $this
            ->addRule('range', [
                'type' => self::TYPE_STRING,
                'description' => 'Time range of the usage stats.',
                'default' => '',
                'example' => '30d',
            ])
            ->addRule('usersOnlineTotal', [
                'type' => self::TYPE_INTEGER,
                'description' => 'Current total number of online users.',
                'default' => 0,
                'example' => 0,
            ])
            ->addRule('presences', [
                'type' => Response::MODEL_METRIC,
                'description' => 'Aggregated number of online users per period.',
                'default' => [],
                'example' => [],
                'array' => true,
            ]);
    }

    public function getName(): string
    {
        return 'UsagePresence';
    }

    public function getType(): string
    {
        return Response::MODEL_USAGE_PRESENCE;
    }
}
