<?php

declare(strict_types=1);

namespace Tests\Unit\Platform;

use Utopia\Cache\Adapter\None as NoCache;
use Utopia\Cache\Cache;
use Utopia\Database\Adapter\Memory;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Validator\Authorization;

final class CertificateDatabase extends Database
{
    /** @var array<array{string, string, array<string, mixed>}> */
    public array $writes = [];

    public function __construct()
    {
        parent::__construct(new Memory(), new Cache(new NoCache()));
        $authorization = new Authorization();
        $authorization->disable();
        $this->setAuthorization($authorization);
        $this->setDatabase('certificates')->setNamespace('test')->disableValidation()->setPreserveDates(true);
        $this->create();
        foreach (['rules', 'certificates', 'projects'] as $collection) {
            $this->createCollection($collection);
        }
        // Real adapters decode datetime attributes to ISO 8601 on reads.
        $this->createAttribute('certificates', 'updated', Database::VAR_DATETIME, 0, false);
    }

    public function updateDocument(string $collection, string $id, Document $document): Document
    {
        $this->writes[] = [$collection, $id, $document->getArrayCopy()];
        return parent::updateDocument($collection, $id, $document);
    }
}
