<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;

/**
 * Document list response — adds an optional `explain` field to the base list
 * shape so listDocuments can return the captured query plan alongside the
 * documents when the caller passes `explain: true`. Empty by default.
 */
class DocumentList extends BaseList
{
    public function __construct()
    {
        parent::__construct('Documents List', Response::MODEL_DOCUMENT_LIST, 'documents', Response::MODEL_DOCUMENT);

        $this->addRule('explain', [
            'type' => Response::MODEL_QUERY_PLAN_ENTRY,
            'description' => 'Captured query plans for each physical read this listDocuments call issued. Empty unless the request set `explain: true`. Internal storage details are stripped.',
            'default' => [],
            'array' => true,
            'required' => false,
        ]);
    }
}
