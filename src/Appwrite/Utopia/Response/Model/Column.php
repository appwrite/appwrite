<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;

class Column extends Model
{
    public function __construct()
    {
        $this
            ->addRule('key', [
                'type' => self::TYPE_STRING,
                'description' => 'Column Key.',
                'default' => '',
                'example' => 'fullName',
            ])
            ->addRule('type', [
                'type' => self::TYPE_STRING,
                'description' => 'Column type.',
                'default' => '',
                'example' => 'string',
            ])
            ->addRule('status', [
                'type' => self::TYPE_ENUM,
                'description' => 'Column status. Possible values: `available`, `processing`, `deleting`, `stuck`, or `failed`',
                'default' => '',
                'example' => 'available',
                'enum' => ['available', 'processing', 'deleting', 'stuck', 'failed'],
            ])
            ->addRule('error', [
                'type' => self::TYPE_STRING,
                'description' => 'Error message. Displays error generated on failure of creating or deleting an column.',
                'default' => '',
                'example' => 'string',
            ])
            ->addRule('required', [
                'type' => self::TYPE_BOOLEAN,
                'description' => 'Is column required?',
                'default' => false,
                'example' => true,
            ])
            ->addRule('array', [
                'type' => self::TYPE_BOOLEAN,
                'description' => 'Is column an array?',
                'default' => false,
                'required' => false,
                'example' => false,
            ])
            ->addRule('$createdAt', [
                'type' => self::TYPE_DATETIME,
                'description' => 'Column creation date in ISO 8601 format.',
                'default' => '',
                'example' => self::TYPE_DATETIME_EXAMPLE,
            ])
            ->addRule('$updatedAt', [
                'type' => self::TYPE_DATETIME,
                'description' => 'Column update date in ISO 8601 format.',
                'default' => '',
                'example' => self::TYPE_DATETIME_EXAMPLE,
            ]);
    }

    public array $conditions = [];

    /**
     * Get Name
     *
     * @return string
     */
    public function getName(): string
    {
        return 'Column';
    }

    /**
     * Get Collection
     *
     * @return string
     */
    public function getType(): string
    {
        return Response::MODEL_COLUMN;
    }
}
