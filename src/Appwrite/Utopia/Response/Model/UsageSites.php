<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;

class UsageSites extends UsageFunctions
{
    public function __construct()
    {
        parent::__construct();
        $this
            ->removeRule('functionsTotal')
            ->removeRule('functions')
            ->addRule('sitesTotal', [
                'type' => self::TYPE_INTEGER,
                'description' => 'Total aggregated number of sites.',
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
