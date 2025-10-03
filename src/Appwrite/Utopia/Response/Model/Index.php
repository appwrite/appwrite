<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;

class Index extends Model
{
    public function __construct()
    {
        $this
            ->addRule('key', [
                'type' => self::TYPE_STRING,
                'description' => 'Index Key.',
                'default' => '',
                'example' => 'index1',
            ])
            ->addRule('type', [
                'type' => self::TYPE_STRING,
                'description' => 'Index type.',
                'default' => '',
                'example' => 'primary',
            ])
            ->addRule('status', [
                'type' => self::TYPE_STRING,
                'description' => 'Index status. Possible values: `available`, `processing`, `deleting`, `stuck`, or `failed`',
                'default' => '',
                'example' => 'available',
            ])
            ->addRule('error', [
                'type' => self::TYPE_STRING,
                'description' => 'Error message. Displays error generated on failure of creating or deleting an index.',
                'default' => '',
                'example' => 'string',
            ])
            ->addRule('attributes', [
                'type' => self::TYPE_STRING,
                'description' => 'Index attributes.',
                'default' => [],
                'example' => [],
                'array' => true,
            ])
            ->addRule('orders', [
                'type' => self::TYPE_STRING,
                'description' => 'Index orders.',
                'default' => [],
                'example' => [],
                'array' => true,
                'required' => false,
            ])
            ->addRule('$createdAt', [
                'type' => self::TYPE_DATETIME,
                'description' => 'Index creation date in ISO 8601 format.',
                'default' => '',
                'example' => self::TYPE_DATETIME_EXAMPLE,
            ])
            ->addRule('$updatedAt', [
                'type' => self::TYPE_DATETIME,
                'description' => 'Index update date in ISO 8601 format.',
                'default' => '',
                'example' => self::TYPE_DATETIME_EXAMPLE,
            ]);
    }

    /**
     * Get Name
     */
    public function getName(): string
    {
        return 'Index';
    }

    /**
     * Get Collection
     */
    public function getType(): string
    {
        return Response::MODEL_INDEX;
    }
}
