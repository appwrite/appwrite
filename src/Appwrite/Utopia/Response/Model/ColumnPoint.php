<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;

class ColumnPoint extends Column
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
                'example' => [0, 0]
            ])
        ;
    }

    public array $conditions = [
        'type' => 'point',
    ];

    /**
     * Get Name
     *
     * @return string
     */
    public function getName(): string
    {
        return 'ColumnPoint';
    }

    /**
     * Get Type
     *
     * @return string
     */
    public function getType(): string
    {
        return Response::MODEL_COLUMN_POINT;
    }
}
