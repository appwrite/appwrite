<?php

namespace Appwrite\Utopia\Database\Documents;

use Utopia\Database\Document;

class TransactionLog extends Document
{
    public function getRequestTimestamp(): \DateTime
    {
        return new \DateTime($this->getAttribute('documentUpdatedAt') ?? $this->getCreatedAt());
    }
}
