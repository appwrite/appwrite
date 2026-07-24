<?php

namespace Appwrite\Event\Delivery;

use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Database\Exception\Duplicate;

final readonly class Receipt
{
    public const string COLLECTION = 'eventReceipts';

    public function __construct(
        private Database $database,
    ) {
    }

    public function isComplete(string $identity): bool
    {
        return $this->database->getAuthorization()->skip(
            fn (): bool => !$this->database->getDocument(self::COLLECTION, $identity)->isEmpty()
        );
    }

    public function complete(
        string $identity,
        string $projectId,
        string $projectInternalId,
        string $envelopeId,
        Sink $sink,
        string $targetId,
    ): void {
        try {
            $this->database->getAuthorization()->skip(
                fn () => $this->database->createDocument(self::COLLECTION, new Document([
                    '$id' => $identity,
                    'projectId' => $projectId,
                    'projectInternalId' => $projectInternalId,
                    'envelopeId' => $envelopeId,
                    'sink' => $sink->value,
                    'targetId' => $targetId,
                    'completedAt' => DateTime::now(),
                ]))
            );
        } catch (Duplicate) {
        }
    }
}
