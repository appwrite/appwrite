<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;

class PlatformList extends Model
{
    public function __construct()
    {
        $this
            ->addRule('total', [
                'type' => self::TYPE_INTEGER,
                'description' => 'Total number of platforms in the given project.',
                'default' => 0,
                'example' => 5,
            ])
            ->addRule('platforms', [
                'type' => [
                    Response::MODEL_PLATFORM_WEB,
                    Response::MODEL_PLATFORM_APPLE,
                    Response::MODEL_PLATFORM_ANDROID,
                    Response::MODEL_PLATFORM_WINDOWS,
                    Response::MODEL_PLATFORM_LINUX,
                ],
                'description' => 'List of platforms.',
                'default' => [],
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
        return 'Platforms List';
    }

    /**
     * Get Type
     *
     * @return string
     */
    public function getType(): string
    {
        return Response::MODEL_PLATFORM_LIST;
    }
}
