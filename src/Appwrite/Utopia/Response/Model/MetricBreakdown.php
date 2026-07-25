<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;

class MetricBreakdown extends Model
{
    public function __construct()
    {
        $this
            ->addRule('resourceId', [
                'type' => self::TYPE_STRING,
                'description' => 'Resource ID.',
                'default' => '',
                'example' => '5e5ea5c16897e',
                'required' => false,
            ])
            ->addRule('name', [
                'type' => self::TYPE_STRING,
                'description' => 'Resource name.',
                'default' => '',
                'example' => 'Documents',
            ])
            ->addRule('value', [
                'type' => self::TYPE_INTEGER,
                'description' => 'The value of this metric at the timestamp.',
                'default' => 0,
                'example' => 1,
            ])
            ->addRule('estimate', [
                'type' => self::TYPE_FLOAT,
                'description' => 'The estimated value of this metric at the end of the period.',
                'default' => 0,
                'example' => 1,
                'required' => false,
            ]);
    }

    /**
     * Get Name
     *
     * @return string
     */
    public function getName(): string
    {
        return 'Metric Breakdown';
    }

    /**
     * Get Collection
     *
     * @return string
     */
    public function getType(): string
    {
        return Response::MODEL_METRIC_BREAKDOWN;
    }
}
