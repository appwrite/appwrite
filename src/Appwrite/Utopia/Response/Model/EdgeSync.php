<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;

class EdgeSync extends Model
{
    public function __construct()
    {

        $this
            ->addRule('keys', [
                'type' => self::TYPE_STRING,
                'description' => 'Cache keys array to be purged.',
                'default' => '',
                'example' => '["cache-console:_metadata:users", "cache-console:_metadata:buckets"]',
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
        return 'EdgeSync';
    }

    /**
     * Get Type
     *
     * @return string
     */
    public function getType(): string
    {
        return Response::MODEL_EDGE_SYNC;
    }
}
