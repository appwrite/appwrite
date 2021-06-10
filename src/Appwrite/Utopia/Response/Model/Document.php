<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;

class Document extends Any
{
    /**
     * Get Name
     * 
     * @return string
     */
    public function getName(): string
    {
        return 'Document';
    }

    /**
     * Get Collection
     * 
     * @return string
     */
    public function getType(): string
    {
        return Response::MODEL_DOCUMENT;
    }

    public function __construct()
    {
        $this
            ->addRule('$id', [
                'type' => self::TYPE_STRING,
                'description' => 'Document ID.',
                'default' => '',
                'example' => '5e5ea5c16897e',
            ])
            ->addRule('$collection', [
                'type' => self::TYPE_STRING,
                'description' => 'Collection ID.',
                'default' => '',
                'example' => '5e5ea5c15117e',
            ])
            ->addRule('$read', [
                'type' => self::TYPE_STRING,
                'description' => 'Document read permissions.',
                'default' => '',
                'example' => '',
                'array' => true,
            ])
            ->addRule('$write', [
                'type' => self::TYPE_STRING,
                'description' => 'Document write permissions.',
                'default' => '',
                'example' => '',
                'array' => true,
            ])
        ;
    }
}
