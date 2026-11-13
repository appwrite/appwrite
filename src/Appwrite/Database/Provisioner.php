<?php

namespace Appwrite\Database;

use Utopia\Database\Database;
use Utopia\Database\Document;

/**
 * The database-factory contract shared actions depend on. Cloud swaps in its own
 * factory, which is not a subclass of this one, so routes it inherits must type
 * the contract rather than the implementation.
 */
interface Provisioner
{
    public function provisioning(Document $project): Database;

    /**
     * The database row with its DSN pointed at where the database lives now. Cloud moves dedicated
     * databases between servers and keeps the live endpoint elsewhere; here the row is already current.
     */
    public function overlayLiveDedicatedDsn(
        Document $databaseDocument,
        Document $project,
        int $timeout = 0,
        int $maxQueryValues = 0,
        bool $cache = true,
    ): Document;

    public function tenant(
        Document $databaseDocument,
        Document $project,
        int $timeout = 0,
        int $maxQueryValues = 0,
        array $metadata = [],
        bool $preserveDates = false,
    ): Database;
}
