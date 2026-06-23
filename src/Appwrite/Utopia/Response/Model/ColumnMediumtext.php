<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;

class ColumnMediumtext extends Column
{
    public function __construct()
    {
        parent::__construct();

        $this
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
        'type' => 'mediumtext',
    ];

    /**
     * Get Name
     *
     * @return string
     */
    public function getName(): string
    {
        return 'ColumnMediumtext';
    }

    /**
     * Get Type
     *
     * @return string
     */
    public function getType(): string
    {
        return Response::MODEL_COLUMN_MEDIUMTEXT;
    }
}
