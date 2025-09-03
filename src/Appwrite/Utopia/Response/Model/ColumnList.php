<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;

class ColumnList extends Model
{
    public function __construct()
    {
        $this
            ->addRule('total', [
                'type' => self::TYPE_INTEGER,
                'description' => 'Total number of columns in the given table.',
                'default' => 0,
                'example' => 5,
            ])
            ->addRule('columns', [
                'type' => [
                    Response::MODEL_COLUMN_BOOLEAN,
                    Response::MODEL_COLUMN_INTEGER,
                    Response::MODEL_COLUMN_FLOAT,
                    Response::MODEL_COLUMN_EMAIL,
                    Response::MODEL_COLUMN_ENUM,
                    Response::MODEL_COLUMN_URL,
                    Response::MODEL_COLUMN_IP,
                    Response::MODEL_COLUMN_DATETIME,
                    Response::MODEL_COLUMN_RELATIONSHIP,
                    Response::MODEL_COLUMN_POINT,
                    Response::MODEL_COLUMN_LINE,
                    Response::MODEL_COLUMN_POLYGON,
                    Response::MODEL_COLUMN_STRING // needs to be last, since its condition would dominate any other string attribute
                ],
                'description' => 'List of columns.',
                'default' => [],
                'array' => true
            ])
        ;
    }

    /**
     * Get Name
     *
     * @return string
     */
    public function getName(): string
    {
        return 'Columns List';
    }

    /**
     * Get Type
     *
     * @return string
     */
    public function getType(): string
    {
        return Response::MODEL_COLUMN_LIST;
    }
}
