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
            ->addRule('users.count', [
                'type' => Response::MODEL_METRIC_LIST,
                'description' => 'Aggregated stats for total number of users.',
                'default' => [],
                'example' => new \stdClass,
                'array' => true
            ])
            ->addRule('users.create', [
                'type' => Response::MODEL_METRIC_LIST,
                'description' => 'Aggregated stats for users created.',
                'default' => [],
                'example' => new \stdClass,
                'array' => true
            ])
            ->addRule('users.read', [
                'type' => Response::MODEL_METRIC_LIST,
                'description' => 'Aggregated stats for users read.',
                'default' => [],
                'example' => new \stdClass,
                'array' => true
            ])
            ->addRule('users.update', [
                'type' => Response::MODEL_METRIC_LIST,
                'description' => 'Aggregated stats for users updated.',
                'default' => [],
                'example' => new \stdClass,
                'array' => true
            ])
            ->addRule('users.delete', [
                'type' => Response::MODEL_METRIC_LIST,
                'description' => 'Aggregated stats for users deleted.',
                'default' => [],
                'example' => new \stdClass,
                'array' => true
            ])
            ->addRule('sessions.create', [
                'type' => Response::MODEL_METRIC_LIST,
                'description' => 'Aggregated stats for sessions created.',
                'default' => [],
                'example' => new \stdClass,
                'array' => true
            ])
            ->addRule('sessions.provider.create', [
                'type' => Response::MODEL_METRIC_LIST,
                'description' => 'Aggregated stats for sessions created for a provider ( email, anonymous or oauth2 ).',
                'default' => [],
                'example' => new \stdClass,
                'array' => true
            ])
            ->addRule('sessions.delete', [
                'type' => Response::MODEL_METRIC_LIST,
                'description' => 'Aggregated stats for sessions deleted.',
                'default' => [],
                'example' => new \stdClass,
                'array' => true
            ])
        ;
    }

    /**
     * Get Name
     *
     * @return string
     */
    public function getName():string
    {
        return 'UsageUsers';
    }

    /**
     * Get Type
     *
     * @return string
     */
    public function getType():string
    {
        return Response::MODEL_USAGE_USERS;
    }
}
