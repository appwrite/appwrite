<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;

class Detection extends Model
{
    public function __construct()
    {
        $this
            ->addRule('runtime', [
                'type' => self::TYPE_STRING,
                'description' => 'Runtime',
                'default' => '',
                'example' => 'node',
            ]);
    }

    /**
     * Get Name
     *
     * @return string
     */
    public function getName(): string
    {
        return 'Detection';
    }

    /**
     * Get Type
     *
     * @return string
     */
    public function getType(): string
    {
        return Response::MODEL_DETECTION;
    }
}
