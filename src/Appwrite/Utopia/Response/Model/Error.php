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
                'type' => 'string',
                'description' => 'Error message.',
                'example' => 'Not found',
            ])
            ->addRule('code', [
                'type' => 'string',
                'description' => 'Error code.',
                'example' => '404',
            ])
            ->addRule('version', [
                'type' => 'string',
                'description' => 'Server version number.',
                'example' => '1.0',
            ])
        ;
    }

    /**
     * Get Name
     * 
     * @return string
     */
    public function getName():string
    {
        return 'Error';
    }

    /**
     * Get Collection
     * 
     * @return string
     */
    public function getType():string
    {
        return Response::MODEL_ERROR;
    }
}