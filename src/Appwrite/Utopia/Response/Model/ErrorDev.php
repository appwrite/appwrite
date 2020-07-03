<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;

class ErrorDev extends Error
{
    public function __construct()
    {
        parent::__construct();
        
        $this
            ->addRule('file', [
                'type' => 'string',
                'description' => 'File path.',
                'example' => '/usr/code/vendor/utopia-php/framework/src/App.php',
            ])
            ->addRule('line', [
                'type' => 'integer',
                'description' => 'Line number.',
                'example' => 209,
            ])
            ->addRule('trace', [
                'type' => 'string',
                'description' => 'Error trace.',
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