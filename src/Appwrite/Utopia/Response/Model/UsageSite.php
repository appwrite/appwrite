<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;

class UsageSite extends UsageFunction
{
    public function __construct()
    {
        parent::__construct();
        $this
            ->addRule('requestsTotal', [
                'type' => self::TYPE_INTEGER,
                'description' => 'Total aggregated number of sites.',
                'default' => 0,
                'example' => 0,
            ])
            ->addRule('requests', [
                'type' => Response::MODEL_METRIC,
                'description' => 'Aggregated number of sites per period.',
                'default' => 0,
                'example' => 0,
                'array' => true
            ])
            ->addRule('inboundTotal', [
                'type' => self::TYPE_INTEGER,
                'description' => 'Total aggregated number of sites.',
                'default' => 0,
                'example' => 0,
            ])
            ->addRule('inbound', [
                'type' => Response::MODEL_METRIC,
                'description' => 'Aggregated number of sites per period.',
                'default' => 0,
                'example' => 0,
                'array' => true
            ])
            ->addRule('outboundTotal', [
                'type' => self::TYPE_INTEGER,
                'description' => 'Total aggregated number of sites.',
                'default' => 0,
                'example' => 0,
            ])
            ->addRule('outbound', [
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
