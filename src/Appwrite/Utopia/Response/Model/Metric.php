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
            ->addRule('timestamp', [
                'type' => self::TYPE_INTEGER,
                'description' => 'The UNIX timestamp at which this metric was aggregated.',
                'default' => 0,
                'example' => 1592981250
            ])
        ;
    }

    /**
     * Get Name
     * 
     * @return string
     */
    public function getName():string
    {
        return 'Metric';
    }

    /**
     * Get Collection
     * 
     * @return string
     */
    public function getType():string
    {
        return Response::MODEL_METRIC;
    }
}