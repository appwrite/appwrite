<?php

namespace Appwrite\Utopia\Response\Model;

use Utopia\Database\Document;

class DocumentList extends BaseList
{
    public function filter(Document $document): Document
    {
        foreach ($document->getAttribute('documents', []) as $node) {
            $node->removeAttribute('$internalId');
        }

        return $document;
    }
}
