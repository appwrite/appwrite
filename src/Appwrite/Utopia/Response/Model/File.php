<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;

class File extends Model
{
    public function __construct()
    {
        //return $value->getArrayCopy(['$id', '$permissions', 'name', 'dateCreated', 'signature', 'mimeType', 'sizeOriginal']);
        $this
            ->addRule('$id', [
                'type' => 'string',
                'description' => 'File ID.',
                'example' => '5e5ea5c16897e',
            ])
            ->addRule('$permissions', [
                'type' => Response::MODEL_PERMISSIONS,
                'description' => 'File permissions.',
                'example' => [],
                'array' => false,
            ])
            ->addRule('name', [
                'type' => 'string',
                'description' => 'File name.',
                'default' => '',
                'example' => 'Pink.png',
            ])
            ->addRule('dateCreated', [
                'type' => 'integer',
                'description' => 'File creation date in Unix timestamp.',
                'example' => 1592981250,
            ])
            ->addRule('signature', [
                'type' => 'string',
                'description' => 'File MD5 signature.',
                'default' => false,
                'example' => '5d529fd02b544198ae075bd57c1762bb',
            ])
            ->addRule('mimeType', [
                'type' => 'string',
                'description' => 'File mime type.',
                'default' => '',
                'example' => 'image/png',
            ])
            ->addRule('sizeOriginal', [
                'type' => 'integer',
                'description' => 'File original size in bytes.',
                'default' => false,
                'example' => true,
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
        return 'File';
    }

    /**
     * Get Collection
     * 
     * @return string
     */
    public function getType():string
    {
        return Response::MODEL_FILE;
    }
}