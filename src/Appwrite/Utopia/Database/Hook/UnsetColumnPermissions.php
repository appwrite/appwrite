<?php

namespace Appwrite\Utopia\Database\Hook;

use Override;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Event;
use Utopia\Database\Hook\Decorator;

/**
 * Drop "columnPermissions" from a collection definition this version does not declare.
 *
 * The column is added to the metadata tables before the release that declares it, so for the
 * length of that rollout a definition read back carries an attribute the library does not know:
 * it holds the metadata schema in code, reads are SELECT *, and nothing filters a row against
 * the declared attributes. The value reaches the document, and the next write of that definition
 * is refused as an unknown attribute. Metadata is also the one collection whose unchanged values
 * are not exempt from that check, so the value cannot even ride along untouched.
 *
 * A decorator rather than a decode step because a definition served from cache returns before
 * decoding, and this runs on that path too.
 *
 * Temporary. Remove it once the release that declares the column has rolled out everywhere, and
 * before any collection can be given a column-scoped grant -- a version that drops the value
 * falls back to the row's own permissions, which are the same grants widened to every column.
 */
final readonly class UnsetColumnPermissions implements Decorator
{
    /**
     * The key as the later release names it, spelled out because this version's library has no
     * constant for a column it does not declare.
     */
    private const string COLUMN_PERMISSIONS = 'columnPermissions';

    #[Override]
    public function decorate(Event $event, Document $collection, Document $document): Document
    {
        if ($collection->getId() === Database::METADATA) {
            $document->removeAttribute(self::COLUMN_PERMISSIONS);
        }

        return $document;
    }
}
