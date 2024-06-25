<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;

class Specs extends Model
{
    public function __construct()
    {
        $this
            ->addRule('cpus', [
                'type' => self::TYPE_STRING,
                'description' => 'Amount of CPU cores available.',
                'default' => '',
                'example' => [1, 2, 4, 8],
                'array' => true,
            ])
            ->addRule('memory', [
                'type' => self::TYPE_STRING,
                'description' => 'Amount of memory available in MB.',
                'default' => '',
                'example' => [512, 1024, 2048, 4096, 8192, 16384],
                'array' => true,
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
        return 'Specs';
    }

    /**
     * Get Type
     *
     * @return string
     */
    public function getType(): string
    {
        return Response::MODEL_SPECS;
    }
}
