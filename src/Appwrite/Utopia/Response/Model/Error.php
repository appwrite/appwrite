<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;

class Error extends Model
{
    public function __construct()
    {
        $this
            ->addRule('message', [
                'type' => self::TYPE_STRING,
                'description' => 'Error message.',
                'default' => '',
                'example' => 'Not found',
            ])
            ->addRule('code', [
                'type' => self::TYPE_STRING,
                'description' => 'Error code.',
                'default' => '',
                'example' => '404',
            ])
            ->addRule('type', [
                'type' => self::TYPE_STRING,
                'description' => 'Error type. You can learn more about all the error types at https://appwrite.io/docs/error-codes#errorTypes',
                'default' => 'unknown',
                'example' => 'not_found',
            ])
            ->addRule('version', [
                'type' => self::TYPE_STRING,
                'description' => 'Server version number.',
                'default' => '',
                'example' => '1.0',
            ]);
    }

    /**
     * Get Name
     *
     * @return string
     */
    public function getName(): string
    {
        return 'Error';
    }

    /**
     * Get Type
     *
     * @return string
     */
    public function getType(): string
    {
        return Response::MODEL_ERROR;
    }
}
