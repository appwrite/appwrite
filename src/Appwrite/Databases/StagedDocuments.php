<?php

namespace Appwrite\Databases;

use Utopia\Database\Document;

final readonly class StagedDocuments
{
    /**
     * @param array<string, Document> $documents The staged documents a read sees, by ID
     * @param array<string, bool> $matches Whether each of them matches the read's filters
     * @param array<string, int> $pending The staging position of those not stored yet
     * @param array<string, true> $storedMatches Staged IDs whose stored row matches the read's filters
     */
    public function __construct(
        public array $documents,
        public array $matches,
        public array $pending,
        public array $storedMatches,
    ) {
    }
}
