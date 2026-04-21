<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;

class ColumnLine extends Column
{
    public function __construct()
    {
        parent::__construct();

        $this
            ->addRule('default', [
                'type' => self::TYPE_ARRAY,
                'description' => 'Default value for column when not provided. Cannot be set when column is required.',
                'default' => null,
                'required' => false,
                'example' => [[0, 0], [1, 1]]
            ])
            ->addRule('notes', [
                'type' => self::TYPE_STRING,
                'description' => 'Notes for the column.',
                'default' => null,
                'required' => false,
                'example' => 'Used for storing user names',
            ])
        ;
    }

    public array $conditions = [
        'type' => 'linestring',
    ];

    /**
     * Get Name
     *
     * @return string
     */
    public function getName(): string
    {
        return 'ColumnLine';
    }

    /**
     * Get Type
     *
     * @return string
     */
    public function getType(): string
    {
        return Response::MODEL_COLUMN_LINE;
    }
}
