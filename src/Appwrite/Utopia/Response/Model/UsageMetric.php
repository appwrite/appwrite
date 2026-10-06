<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model as ResponseModel;

class UsageMetric extends ResponseModel
{
    public function __construct()
    {
        $this
            ->addRule('metric', [
                'type' => self::TYPE_STRING,
                'description' => 'Metric key this series describes.',
                'default' => '',
                'example' => 'files.storage',
            ])
            ->addRule('points', [
                'type' => Response::MODEL_USAGE_DATA_POINT,
                'description' => 'Data points in the requested order.',
                'default' => [],
                'array' => true,
            ]);
    }

    public function getName(): string
    {
        return Response::MODEL_USAGE_METRIC;
    }

    public function getType(): string
    {
        return Response::MODEL_USAGE_METRIC;
    }
}
