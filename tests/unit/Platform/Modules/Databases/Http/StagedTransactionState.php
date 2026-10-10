<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\Modules\Databases\Http;

use Appwrite\Databases\TransactionState;
use Utopia\Database\Document;

final class StagedTransactionState extends TransactionState
{
    public function __construct(private readonly Document $staged)
    {
    }

    #[\Override]
    public function getDocument(Document $database, string $collectionId, string $documentId, ?string $transactionId = null, array $queries = []): Document
    {
        return new Document($this->staged->getArrayCopy());
    }

    #[\Override]
    public function listDocuments(Document $database, string $collectionId, ?string $transactionId = null, array $queries = []): array
    {
        return [new Document($this->staged->getArrayCopy())];
    }

    #[\Override]
    public function countDocuments(Document $database, string $collectionId, ?string $transactionId = null, array $queries = []): int
    {
        return 1;
    }
}
