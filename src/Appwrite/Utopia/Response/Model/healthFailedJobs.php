<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;

class HealthFailedJobs extends Model
{
    public function __construct()
    {
        $this
            ->addRule('failed', [
                'type' => self::TYPE_INTEGER,
                'description' => 'Number of failed jobs in the queue',
                'default' => '',
                'example' => '0.15.0',
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
        return 'Health Failed Jobs';
    }

    /**
     * Get Type
     *
     * @return string
     */
    public function getType(): string
    {
        return Response::MODEL_HEALTH_FAILED_JOBS;
    }
}
