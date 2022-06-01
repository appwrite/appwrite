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
            ->addRule('usersCount', [
                'type' => Response::MODEL_METRIC_LIST,
                'description' => 'Aggregated stats for total number of users.',
                'default' => [],
                'example' => new \stdClass(),
                'array' => true
            ])
            ->addRule('usersCreate', [
                'type' => Response::MODEL_METRIC_LIST,
                'description' => 'Aggregated stats for users created.',
                'default' => [],
                'example' => new \stdClass(),
                'array' => true
            ])
            ->addRule('usersRead', [
                'type' => Response::MODEL_METRIC_LIST,
                'description' => 'Aggregated stats for users read.',
                'default' => [],
                'example' => new \stdClass(),
                'array' => true
            ])
            ->addRule('usersUpdate', [
                'type' => Response::MODEL_METRIC_LIST,
                'description' => 'Aggregated stats for users updated.',
                'default' => [],
                'example' => new \stdClass(),
                'array' => true
            ])
            ->addRule('usersDelete', [
                'type' => Response::MODEL_METRIC_LIST,
                'description' => 'Aggregated stats for users deleted.',
                'default' => [],
                'example' => new \stdClass(),
                'array' => true
            ])
            ->addRule('sessionsCreate', [
                'type' => Response::MODEL_METRIC_LIST,
                'description' => 'Aggregated stats for sessions created.',
                'default' => [],
                'example' => new \stdClass(),
                'array' => true
            ])
            ->addRule('sessionsProviderCreate', [
                'type' => Response::MODEL_METRIC_LIST,
                'description' => 'Aggregated stats for sessions created for a provider ( email, anonymous or oauth2 ).',
                'default' => [],
                'example' => new \stdClass(),
                'array' => true
            ])
            ->addRule('sessionsDelete', [
                'type' => Response::MODEL_METRIC_LIST,
                'description' => 'Aggregated stats for sessions deleted.',
                'default' => [],
                'example' => new \stdClass(),
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
