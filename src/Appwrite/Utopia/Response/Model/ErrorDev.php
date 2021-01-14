<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;

class ErrorDev extends Error
{
    /**
     * @var bool
     */
    protected $public = false;

    public function __construct()
    {
        parent::__construct();
        
        $this
            ->addRule('file', [
                'type' => self::TYPE_STRING,
                'description' => 'File path.',
                'default' => '',
                'example' => '/usr/code/vendor/utopia-php/framework/src/App.php',
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
                'example' => [
                    ''
                ],
            ])
        ;
    }

    /**
     * Get Collection
     * 
     * @return string
     */
    public function getType():string
    {
        return Response::MODEL_ERROR_DEV;
    }
}