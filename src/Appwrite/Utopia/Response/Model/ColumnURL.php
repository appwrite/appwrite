<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;

class ColumnURL extends Column
{
    public function __construct()
    {
        parent::__construct();

        $this
            ->addRule('key', [
                'type' => self::TYPE_STRING,
                'description' => 'Column Key.',
                'default' => '',
                'example' => 'githubUrl',
            ])
            ->addRule('type', [
                'type' => self::TYPE_STRING,
                'description' => 'Column type.',
                'default' => '',
                'example' => 'string',
            ])
            ->addRule('format', [
                'type' => self::TYPE_STRING,
                'description' => 'String format.',
                'default' => APP_DATABASE_ATTRIBUTE_URL,
                'example' => APP_DATABASE_ATTRIBUTE_URL,
            ])
            ->addRule('default', [
                'type' => self::TYPE_STRING,
                'description' => 'Default value for column when not provided. Cannot be set when column is required.',
                'default' => null,
                'required' => false,
                'example' => 'https://example.com',
            ])
        ;
    }

    public array $conditions = [
        'type' => self::TYPE_STRING,
        'format' => \APP_DATABASE_ATTRIBUTE_URL
    ];

    /**
     * Get Name
     *
     * @return string
     */
    public function getName(): string
    {
        return 'ColumnURL';
    }

    /**
     * Get Type
     *
     * @return string
     */
    public function getType(): string
    {
        return Response::MODEL_COLUMN_URL;
    }
}
