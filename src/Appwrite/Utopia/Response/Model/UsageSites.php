<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;

class UsageSites extends Model
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
            ->addRule('sitesTotal', [
                'type' => self::TYPE_INTEGER,
                'description' => 'Total aggregated number of sites.',
                'default' => 0,
                'example' => 0,
            ])
            ->addRule('deploymentsTotal', [
                'type' => self::TYPE_INTEGER,
                'description' => 'Total aggregated number of sites deployments.',
                'default' => 0,
                'example' => 0,
            ])
            ->addRule('deploymentsStorageTotal', [
                'type' => self::TYPE_INTEGER,
                'description' => 'Total aggregated sum of sites deployment storage.',
                'default' => 0,
                'example' => 0,
            ])
            ->addRule('buildsTotal', [
                'type' => self::TYPE_INTEGER,
                'description' => 'Total aggregated number of sites build.',
                'default' => 0,
                'example' => 0,
            ])
            ->addRule('buildsStorageTotal', [
                'type' => self::TYPE_INTEGER,
                'description' => 'total aggregated sum of sites build storage.',
                'default' => 0,
                'example' => 0,
            ])
            ->addRule('buildsTimeTotal', [
                'type' => self::TYPE_INTEGER,
                'description' => 'Total aggregated sum of sites build compute time.',
                'default' => 0,
                'example' => 0,
            ])
            ->addRule('buildsMbSecondsTotal', [
                'type' => self::TYPE_INTEGER,
                'description' => 'Total aggregated sum of sites build mbSeconds.',
                'default' => 0,
                'example' => 0,
            ])
            ->addRule('sites', [
                'type' => Response::MODEL_METRIC,
                'description' => 'Aggregated number of sites per period.',
                'default' => 0,
                'example' => 0,
                'array' => true
            ])
            ->addRule('deployments', [
                'type' => Response::MODEL_METRIC,
                'description' => 'Aggregated number of sites deployment per period.',
                'default' => [],
                'example' => [],
                'array' => true
            ])
            ->addRule('deploymentsStorage', [
                'type' => Response::MODEL_METRIC,
                'description' => 'Aggregated number of sites deployment storage per period.',
                'default' => [],
                'example' => [],
                'array' => true
            ])
            ->addRule('builds', [
                'type' => Response::MODEL_METRIC,
                'description' => 'Aggregated number of sites build per period.',
                'default' => [],
                'example' => [],
                'array' => true
            ])
            ->addRule('buildsStorage', [
                'type' => Response::MODEL_METRIC,
                'description' => 'Aggregated sum of sites build storage per period.',
                'default' => [],
                'example' => [],
                'array' => true
            ])
            ->addRule('buildsTime', [
                'type' => Response::MODEL_METRIC,
                'description' => 'Aggregated sum of sites build compute time per period.',
                'default' => [],
                'example' => [],
                'array' => true
            ])
            ->addRule('buildsMbSeconds', [
                'type' => Response::MODEL_METRIC,
                'description' => 'Aggregated sum of sites build mbSeconds per period.',
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
        return 'UsageSites';
    }

    /**
     * Get Type
     *
     * @return string
     */
    public function getType(): string
    {
        return Response::MODEL_USAGE_SITES;
    }
}
