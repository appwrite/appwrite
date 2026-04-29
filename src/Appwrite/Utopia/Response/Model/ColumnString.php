<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;

class ColumnString extends Column
{
    public function __construct()
    {
        parent::__construct();

        $this
            ->addRule('size', [
                'type' => self::TYPE_INTEGER,
                'description' => 'Column size.',
                'default' => 0,
                'example' => 128,
            ])
            ->addRule('default', [
                'type' => self::TYPE_STRING,
                'description' => 'Default value for column when not provided. Cannot be set when column is required.',
                'default' => null,
                'required' => false,
                'example' => 'default',
            ])
            ->addRule('encrypt', [
                'type' => self::TYPE_BOOLEAN,
                'description' => 'Defines whether this column is encrypted or not.',
                'default' => false,
                'required' => false,
                'example' => false,
            ])
        ;
    }

    public array $conditions = [
        'type' => self::TYPE_STRING,
    ];

    /**
     * Get Name
     *
     * @return string
     */
    public function getName(): string
    {
        return 'ColumnString';
    }

    /**
     * Get Type
     *
     * @return string
     */
    public function getType(): string
    {
        return Response::MODEL_COLUMN_STRING;
    }
}
