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
    /**
     * @param array<string, mixed> $metadata
     */
    public function platform(int $timeout = 0, int $maxQueryValues = 0, array $metadata = []): Database;

    public function provisioning(Document $project): Database;
}
