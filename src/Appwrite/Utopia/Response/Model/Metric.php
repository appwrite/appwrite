<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;

class Metric extends Model
{
    public function __construct()
    {
        $this
            ->addRule('value', [
                'type' => self::TYPE_INTEGER,
                'description' => 'The value of this metric at the timestamp.',
                'default' => -1,
                'example' => 1,
            ])
            ->addRule('date', [
                'type' => self::TYPE_DATETIME,
                'description' => 'The date at which this metric was aggregated in ISO 8601 format.',
                'default' => '',
                'example' => self::TYPE_DATETIME_EXAMPLE,
            ]);
    }

    /**
     * Get Name
     *
     * @return string
     */
    public function getName(): string
    {
        return 'Metric';
    }

    /**
     * Get Collection
     *
     * @return string
     */
    public function getType(): string
    {
        return Response::MODEL_METRIC;
    }
}
