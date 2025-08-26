<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;

class ColumnIP extends Column
{
    public function __construct()
    {
        parent::__construct();

        $this
            ->addRule('key', [
                'type' => self::TYPE_STRING,
                'description' => 'Column Key.',
                'default' => '',
                'example' => 'ipAddress',
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
                'default' => APP_DATABASE_ATTRIBUTE_IP,
                'example' => APP_DATABASE_ATTRIBUTE_IP,
            ])
            ->addRule('default', [
                'type' => self::TYPE_STRING,
                'description' => 'Default value for column when not provided. Cannot be set when column is required.',
                'default' => null,
                'required' => false,
                'example' => '192.0.2.0',
            ])
        ;
    }

    public array $conditions = [
        'type' => self::TYPE_STRING,
        'format' => \APP_DATABASE_ATTRIBUTE_IP
    ];

    /**
     * Get Name
     *
     * @return string
     */
    public function getName(): string
    {
        return 'ColumnIP';
    }

    /**
     * Get Type
     *
     * @return string
     */
    public function getType(): string
    {
        return Response::MODEL_COLUMN_IP;
    }
}
