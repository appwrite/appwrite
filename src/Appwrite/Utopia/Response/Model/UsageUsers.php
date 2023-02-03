<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;

/**
 * Class UsageUsers
 *
 * Model class representing a usage statistics for users and sessions.
 */
class UsageUsers extends Model
{
    /**
     * Constant representing the type string.
     *
     * @var string
     */
    const TYPE_STRING = 'string';
    const TYPE_METRIC = 'metric';

    public function __construct()
    {
        $commonProperties = [
            'type' => self::TYPE_METRIC,
            'description' => '',
            'default' => [],
            'example' => [],
            'array' => true
        ];

        $this
            ->addRule('range', [
                'type' => self::TYPE_STRING,
                'description' => 'The time range of the usage stats.',
                'default' => '',
                'example' => '30d',
            ])
            ->addRule('usersCount', $commonProperties + [
                'description' => 'Aggregated stats for total number of users.',
            ])
            ->addRule('usersCreate', $commonProperties + [
                'description' => 'Aggregated stats for users created.',
            ])
            ->addRule('usersRead', $commonProperties + [
                'description' => 'Aggregated stats for users read.',
            ])
            ->addRule('usersUpdate', $commonProperties + [
                'description' => 'Aggregated stats for users updated.',
            ])
            ->addRule('usersDelete', $commonProperties + [
                'description' => 'Aggregated stats for users deleted.',
            ])
            ->addRule('sessionsCreate', $commonProperties + [
                'description' => 'Aggregated stats for sessions created.',
            ])
            ->addRule('sessionsProviderCreate', $commonProperties + [
                'description' => 'Aggregated stats for sessions created for a provider ( email, anonymous or oauth2 ).',
            ])
            ->addRule('sessionsDelete', $commonProperties + [
                'description' => 'Aggregated stats for sessions deleted.',
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
        return static::class;
    }

    /**
     * Get Type
     *
     @return string
    */
    public function getType(): string
    {
        return Response::MODEL_USAGE_USERS;
    }
}
