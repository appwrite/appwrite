<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;

class ErrorDev extends Error
{
    /**
     * @var bool
     */
    protected bool $public = false;

    public function __construct()
    {
        parent::__construct();

        $this
            ->addRule('file', [
                'type' => self::TYPE_STRING,
                'description' => 'File path.',
                'default' => '',
                'example' => '/usr/code/vendor/utopia-php/framework/src/Http/Http.php',
            ])
            ->addRule('line', [
                'type' => self::TYPE_INTEGER,
                'description' => 'Line number.',
                'default' => 0,
                'example' => 209,
            ])
            ->addRule('trace', [
                'type' => self::TYPE_STRING,
                'description' => 'Error trace.',
                'default' => [],
                'example' => '',
                'array' => true,
            ])
        ;
    }

    /**
     * Get Type
     *
     * @return string
     */
    public function getType(): string
    {
        return Response::MODEL_ERROR_DEV;
    }
}
