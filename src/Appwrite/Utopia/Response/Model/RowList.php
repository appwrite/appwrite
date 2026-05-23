<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;

/**
 * Row list response — adds an optional `explain` field to the base list shape
 * so listRows can return the captured query plan alongside the rows when the
 * caller passes `explain: true`. Empty by default.
 */
class RowList extends BaseList
{
    public function __construct()
    {
        parent::__construct('Rows List', Response::MODEL_ROW_LIST, 'rows', Response::MODEL_ROW);

        $this->addRule('explain', [
            'type' => Response::MODEL_QUERY_PLAN_ENTRY,
            'description' => 'Captured query plans for each physical read this listRows call issued. Empty unless the request set `explain: true`. Internal storage details are stripped.',
            'default' => [],
            'array' => true,
            'required' => false,
        ]);
    }
}
