<?php

namespace Utopia\Migration\Tests\Destinations;

use Override;
use Utopia\Database\Database as UtopiaDatabase;
use Utopia\Database\Document as UtopiaDocument;
use Utopia\Database\Exception as DatabaseException;
use Utopia\Database\PermissionType;
use Utopia\Database\Query;

/**
 * A project database whose end-of-run steps can be made to fail: the
 * `ready` write of chosen databases, and the attribute scan the overwrite
 * sweep runs for one chosen table.
 */
final class FailingCompletionDatabase extends UtopiaDatabase
{
    /** @var list<string> */
    public array $failReadyWrites = [];

    public ?UtopiaDocument $failAttributeScanOf = null;

    #[Override]
    public function updateDocument(string $collection, string $id, UtopiaDocument $document): UtopiaDocument
    {
        if (
            $collection === 'databases'
            && $document->getAttribute('status') === 'ready'
            && \in_array($id, $this->failReadyWrites, true)
        ) {
            throw new DatabaseException('ready status unavailable');
        }

        return parent::updateDocument($collection, $id, $document);
    }

    #[Override]
    public function find(string $collection, array $queries = [], PermissionType $forPermission = PermissionType::Read): array
    {
        if ($collection === 'attributes' && $this->failAttributeScanOf !== null && $this->scansTable($queries, $this->failAttributeScanOf)) {
            throw new DatabaseException('attribute scan unavailable');
        }

        return parent::find($collection, $queries, $forPermission);
    }

    /**
     * @param array<Query> $queries
     */
    private function scansTable(array $queries, UtopiaDocument $table): bool
    {
        $expected = [
            'databaseInternalId' => (string) $table->getAttribute('databaseInternalId'),
            'collectionInternalId' => (string) $table->getSequence(),
        ];
        $matched = [];
        foreach ($queries as $query) {
            $attribute = $query->getAttribute();
            if (isset($expected[$attribute]) && \in_array($expected[$attribute], \array_map(\strval(...), $query->getValues()), true)) {
                $matched[$attribute] = true;
            }
        }

        return \count($matched) === \count($expected);
    }
}
