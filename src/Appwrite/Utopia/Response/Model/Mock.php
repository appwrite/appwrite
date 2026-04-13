<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;

class Mock extends Model
{
    public function __construct()
    {
        $this
            ->addRule('result', [
                'type' => self::TYPE_STRING,
                'description' => 'Result message.',
                'default' => '',
                'example' => 'Success',
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
        return 'Mock';
    }

    /**
     * Get Type
     *
     * @return string
     */
    public function getType(): string
    {
        return Response::MODEL_MOCK;
    }
}
