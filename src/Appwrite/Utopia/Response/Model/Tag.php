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
                'type' => self::TYPE_STRING,
                'description' => 'Tag ID.',
                'default' => '',
                'example' => '5e5ea5c16897e',
            ])
            ->addRule('functionId', [
                'type' => self::TYPE_STRING,
                'description' => 'Function ID.',
                'default' => '',
                'example' => '5e5ea6g16897e',
            ])
            ->addRule('dateCreated', [
                'type' => self::TYPE_INTEGER,
                'description' => 'The tag creation date in Unix timestamp.',
                'default' => 0,
                'example' => 1592981250,
            ])
            ->addRule('name', [
                'type' => self::TYPE_STRING,
                'description' => 'Function Name.',
                'default' => '',
                'example' => 'Hello World Tag',
            ])
            ->addRule('command', [
                'type' => self::TYPE_STRING,
                'description' => 'The entrypoint command in use to execute the tag code.',
                'default' => '',
                'example' => 'enabled',
            ])
            ->addRule('size', [
                'type' => self::TYPE_STRING,
                'description' => 'The code size in bytes.',
                'default' => '',
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