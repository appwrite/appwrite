<?php

namespace Appwrite\Utopia\Database\Hook;

use Appwrite\Utopia\Database\Validator\CustomId;
use Override;
use Utopia\Database\Document;
use Utopia\Database\Hook\Nested;
use Utopia\Database\Id;

/**
 * A nested related document without an ID, or with the `unique()` placeholder clients send, gets a generated ID.
 */
final readonly class UniqueId implements Nested
{
    #[Override]
    public function prepare(Document $collection, Document $document): Document
    {
        if (\in_array($document->getId(), ['', CustomId::UNIQUE], true)) {
            $document->setAttribute(Document::ID, Id::unique());
        }

        return $document;
    }
}
