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
            ->addRule('$permissions', [
                'type' => Response::MODEL_PERMISSIONS,
                'description' => 'Document permissions.',
                'default' => new \stdClass,
                'example' => new \stdClass,
                'array' => false,
            ]);
    }
}
