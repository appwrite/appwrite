<?php

declare(strict_types=1);

namespace Tests\Unit\Databases;

use Utopia\Database\Database;
use Utopia\Database\Document;

final class TransactionStatusDatabase extends Database
{
    public int $failures = 0;

    #[\Override]
    public function updateDocument(string $collection, string $id, Document $document): Document
    {
        if ($this->failures > 0) {
            $this->failures--;
            throw new \RuntimeException('Project database unavailable');
        }

        return parent::updateDocument($collection, $id, $document);
    }
}
