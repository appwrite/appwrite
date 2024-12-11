<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;

class HealthStatus extends Model
{
    public function __construct()
    {
        $this
            ->addRule('name', [
                'type' => self::TYPE_STRING,
                'description' => 'Name of the service.',
                'default' => '',
                'example' => 'database',
            ])
            ->addRule('ping', [
                'type' => self::TYPE_INTEGER,
                'description' => 'Duration in milliseconds how long the health check took.',
                'default' => 0,
                'example' => 128,
            ])
            ->addRule('status', [
                'type' => self::TYPE_STRING,
                'description' => 'Service status. Possible values can are: `pass`, `fail`',
                'default' => '',
                'example' => 'pass',
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
        return 'Health Status';
    }

    /**
     * Get Type
     *
     * @return string
     */
    public function getType(): string
    {
        return Response::MODEL_HEALTH_STATUS;
    }
}
