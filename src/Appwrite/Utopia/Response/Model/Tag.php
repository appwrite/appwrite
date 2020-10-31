<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;

class Tag extends Model
{
    public function __construct()
    {
        $this
            ->addRule('$id', [
                'type' => 'string',
                'description' => 'Tag ID.',
                'example' => '5e5ea5c16897e',
            ])
            ->addRule('functionId', [
                'type' => 'string',
                'description' => 'Function ID.',
                'example' => '5e5ea6g16897e',
            ])
            ->addRule('dateCreated', [
                'type' => 'integer',
                'description' => 'The tag creation date in Unix timestamp.',
                'example' => 1592981250,
            ])
            ->addRule('command', [
                'type' => 'string',
                'description' => 'The entrypoint command in use to execute the tag code.',
                'example' => 'enabled',
            ])
            ->addRule('size', [
                'type' => 'string',
                'description' => 'The code size in bytes.',
                'example' => 'python-3.8',
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
        return 'Tag';
    }

    /**
     * Get Collection
     * 
     * @return string
     */
    public function getType():string
    {
        return Response::MODEL_TAG;
    }
}