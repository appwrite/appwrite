<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model as ResponseModel;

class UsageGaugeList extends ResponseModel
{
    public function __construct()
    {
        $this
            ->addRule('interval', [
                'type' => self::TYPE_STRING,
                'description' => 'Requested interval, or an empty string for a flat aggregate.',
                'default' => '',
                'example' => '1h',
            ])
            ->addRule('metrics', [
                'type' => Response::MODEL_USAGE_METRIC,
                'description' => 'One series per requested gauge metric.',
                'default' => [],
                'array' => true,
            ]);
    }

    public function getName(): string
    {
        return Response::MODEL_USAGE_GAUGE_LIST;
    }

    public function getType(): string
    {
        return Response::MODEL_USAGE_GAUGE_LIST;
    }
}
