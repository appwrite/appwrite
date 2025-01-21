<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;

class UsageSite extends Model
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
            ->addRule('deploymentsTotal', [
                'type' => self::TYPE_INTEGER,
                'description' => 'Total aggregated number of site deployments.',
                'default' => 0,
                'example' => 0,
            ])
            ->addRule('deploymentsStorageTotal', [
                'type' => self::TYPE_INTEGER,
                'description' => 'Total aggregated sum of site deployments storage.',
                'default' => 0,
                'example' => 0,
            ])
            ->addRule('buildsTotal', [
                'type' => self::TYPE_INTEGER,
                'description' => 'Total aggregated number of site builds.',
                'default' => 0,
                'example' => 0,
            ])
            ->addRule('buildsStorageTotal', [
                'type' => self::TYPE_INTEGER,
                'description' => 'total aggregated sum of site builds storage.',
                'default' => 0,
                'example' => 0,
            ])
            ->addRule('buildsTimeTotal', [
                'type' => self::TYPE_INTEGER,
                'description' => 'Total aggregated sum of site builds compute time.',
                'default' => 0,
                'example' => 0,
            ])
            ->addRule('buildsMbSecondsTotal', [
                'type' => self::TYPE_INTEGER,
                'description' => 'Total aggregated sum of site builds mbSeconds.',
                'default' => 0,
                'example' => 0,
            ])
            ->addRule('deployments', [
                'type' => Response::MODEL_METRIC,
                'description' => 'Aggregated number of site deployments per period.',
                'default' => [],
                'example' => [],
                'array' => true
            ])
            ->addRule('deploymentsStorage', [
                'type' => Response::MODEL_METRIC,
                'description' => 'Aggregated number of site deployments storage per period.',
                'default' => [],
                'example' => [],
                'array' => true
            ])
            ->addRule('builds', [
                'type' => Response::MODEL_METRIC,
                'description' => 'Aggregated number of site builds per period.',
                'default' => [],
                'example' => [],
                'array' => true
            ])
            ->addRule('buildsStorage', [
                'type' => Response::MODEL_METRIC,
                'description' => 'Aggregated sum of site builds storage per period.',
                'default' => [],
                'example' => [],
                'array' => true
            ])
            ->addRule('buildsTime', [
                'type' => Response::MODEL_METRIC,
                'description' => 'Aggregated sum of site builds compute time per period.',
                'default' => [],
                'example' => [],
                'array' => true
            ])
            ->addRule('buildsMbSeconds', [
                'type' => Response::MODEL_METRIC,
                'description' => 'Aggregated number of site builds mbSeconds per period.',
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
        return 'UsageSite';
    }

    /**
     * Get Type
     *
     * @return string
     */
    public function getType(): string
    {
        return Response::MODEL_USAGE_SITE;
    }
}
