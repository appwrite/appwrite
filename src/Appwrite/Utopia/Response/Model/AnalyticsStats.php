<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;

class AnalyticsStats extends Model
{
    public function __construct()
    {
        $this
            ->addRule('visitors', [
                'type' => self::TYPE_INTEGER,
                'description' => 'Unique visitors in the requested date range.',
                'default' => 0,
                'example' => 1234,
            ])
            ->addRule('pageviews', [
                'type' => self::TYPE_INTEGER,
                'description' => 'Total pageviews in the requested date range.',
                'default' => 0,
                'example' => 5678,
            ]);
    }

    public function getName(): string
    {
        return 'AnalyticsStats';
    }

    public function getType(): string
    {
        return Response::MODEL_ANALYTICS_STATS;
    }
}
