<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;

class DetectionVariable extends Model
{
    public function __construct()
    {
        $this
            ->addRule('name', [
                'type' => self::TYPE_STRING,
                'description' => 'Name of environment variable',
                'default' => '',
                'example' => 'NODE_ENV',
            ])
            ->addRule('value', [
                'type' => self::TYPE_STRING,
                'description' => 'Value of environment variable',
                'default' => '',
                'example' => 'production',
            ]);
    }

    /**
     * Get Name
     *
     * @return string
     */
    public function getName(): string
    {
        return 'DetectionVariable';
    }

    /**
     * Get Type
     *
     * @return string
     */
    public function getType(): string
    {
        return Response::MODEL_DETECTION_VARIABLE;
    }
}
