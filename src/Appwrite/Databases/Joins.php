<?php

namespace Appwrite\Databases;

use Appwrite\Extend\Exception;
use Appwrite\Utopia\Database\Attribute;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\PermissionType;
use Utopia\Database\Query;
use Utopia\Database\RelationType;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\Authorization\Input;

/**
 * A join may read a collection only as listing it directly would: enabled, and readable at collection level or
 * through document security.
 */
final readonly class Joins
{
    public function __construct(
        private Database $dbForProject,
        private Document $database,
        private Authorization $authorization,
        private bool $privileged,
        private string $notFound,
    ) {
    }

    /**
     * @param array<Query> $queries
     * @return array<Query>
     */
    public function resolve(array $queries, Document $collection): array
    {
        $prefix = 'database_' . $this->database->getSequence() . '_collection_';
        $relationships = Attribute::relationships($collection);

        foreach ($queries as $query) {
            if (!$query->getMethod()->isJoin()) {
                continue;
            }

            $externalId = $query->getAttribute();
            if ($externalId !== '') {
                // A name already in physical form is still caller-supplied: both entry points
                // resolve freshly parsed queries exactly once, so nothing legitimately arrives
                // pre-resolved. Passing it through skipped the enabled and permission checks
                // below, which let a caller join a disabled collection by its sequence.
                $related = $this->collection($externalId, $prefix);

                if ($related->isEmpty() || (!$related->getAttribute('enabled', true) && !$this->privileged)) {
                    throw new Exception($this->notFound, params: [$externalId]);
                }

                if (!$this->privileged && !$this->isListable($related)) {
                    throw new Exception(Exception::USER_UNAUTHORIZED);
                }

                $query->setAttribute($prefix . $related->getSequence());
            }

            $this->resolveColumns($query, $relationships);
        }

        return $queries;
    }

    private function isListable(Document $collection): bool
    {
        return (bool) $collection->getAttribute('documentSecurity', false)
            || $this->authorization->isValid(new Input(PermissionType::Read, $collection->getRead()));
    }

    private function collection(string $externalId, string $prefix): Document
    {
        $registry = 'database_' . $this->database->getSequence();

        if (!\str_starts_with($externalId, $prefix)) {
            return $this->authorization->skip(
                fn () => $this->dbForProject->getDocument($registry, $externalId)
            );
        }

        $sequence = \substr($externalId, \strlen($prefix));
        if ($sequence === '' || !\ctype_digit($sequence)) {
            return new Document();
        }

        $found = $this->authorization->skip(
            fn () => $this->dbForProject->find($registry, [
                Query::equal('$sequence', [$sequence]),
                Query::limit(1),
            ])
        );

        return $found[0] ?? new Document();
    }

    /**
     * @param array<string, Document> $relationships
     */
    private function resolveColumns(Query $query, array $relationships): void
    {
        $values = $query->getValues();
        if (\count($values) < 3 || !\is_string($values[0]) || !isset($relationships[$values[0]])) {
            return;
        }

        $relationship = $relationships[$values[0]];
        $options = $relationship->getAttribute('options', []);
        $relationType = $options['relationType'] ?? $relationship->getAttribute('relationType');
        $twoWayKey = $options['twoWayKey'] ?? $relationship->getAttribute('twoWayKey');

        if ($relationType !== RelationType::OneToMany->value) {
            return;
        }

        if (!\is_string($twoWayKey) || $twoWayKey === '') {
            return;
        }

        $values[0] = Document::ID;
        $values[2] = $twoWayKey;
        $query->setValues($values);
    }
}
