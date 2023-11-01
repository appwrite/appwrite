<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;

class UsageUsers extends Model
{
    public function __construct()
    {
        $this
            ->addRule('range', [
                'type' => self::TYPE_STRING,
                'description' => 'The time range of the usage stats.',
                'default' => '',
                'example' => '30d',
            ])
            ->addRule('usersTotal', [
                'type' => Response::MODEL_METRIC,
                'description' => 'Aggregated total statistics of users.',
                'default' => [],
                'example' => [],
                'array' => true
            ])

            ->addRule('sessionsTotal', [
                'type' => Response::MODEL_METRIC,
                'description' => 'Aggregated total statistics sessions created.',
                'default' => [],
                'example' => [],
                'array' => true
            ])
            ->addRule('users', [
                'type' => Response::MODEL_METRIC,
                'description' => 'Aggregated statistics of users per period.',
                'default' => [],
                'example' => [],
                'array' => true
            ])

            ->addRule('sessions', [
                'type' => Response::MODEL_METRIC,
                'description' => 'Aggregated statistics sessions created per period.',
                'default' => [],
                'example' => [],
                'array' => true
            ])
        ;
    }

    /**
     * Get Name
     *
     * @return string
     */
    public function getName(): string
    {
        return 'UsageUsers';
    }

    /**
     * Get Type
     *
     * @return string
     */
    public function getType(): string
    {
        return Response::MODEL_USAGE_USERS;
    }
}
