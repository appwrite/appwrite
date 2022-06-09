<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;

class HealthTime extends Model
{
    public function __construct()
    {
        $this
            ->addRule('remoteTime', [
                'type' => self::TYPE_INTEGER,
                'description' => 'Current unix timestamp on trustful remote server.',
                'default' => 0,
                'example' => 1639490751,
            ])
            ->addRule('localTime', [
                'type' => self::TYPE_INTEGER,
                'description' => 'Current unix timestamp of local server where Appwrite runs.',
                'default' => 0,
                'example' => 1639490844,
            ])
            ->addRule('diff', [
                'type' => self::TYPE_INTEGER,
                'description' => 'Difference of unix remote and local timestamps in milliseconds.',
                'default' => 0,
                'example' => 93,
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
        return 'Health Time';
    }

    /**
     * Get Type
     *
     * @return string
     */
    public function getType(): string
    {
        return Response::MODEL_HEALTH_TIME;
    }
}
