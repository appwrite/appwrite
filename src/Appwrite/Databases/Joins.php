<?php

namespace Appwrite\Databases;

use Appwrite\Extend\Exception;
use Appwrite\Utopia\Database\Attribute;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\PermissionType;
use Utopia\Database\Query;
use Utopia\Database\RelationshipType;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\Authorization\Input;
use Utopia\Query\Method;

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
        $relationships = null;

        foreach ($queries as $query) {
            if (!$query->getMethod()->isJoin()) {
                continue;
            }

            $externalId = $query->getAttribute();
            if ($externalId !== '') {
                // A name in physical form is caller-supplied too, so it gets the same checks.
                $related = $this->collection($externalId, $prefix);

                if ($related->isEmpty() || (!$related->getAttribute('enabled', true) && !$this->privileged)) {
                    throw new Exception($this->notFound, params: [$externalId]);
                }

                if (!$this->privileged && !$this->isListable($related)) {
                    throw new Exception(Exception::USER_UNAUTHORIZED);
                }

                $query->setAttribute($prefix . $related->getSequence());
            }

            $relationships ??= Attribute::relationships($collection);
            $this->resolveColumns($query, $relationships);
        }

        return $queries;
    }

    private function isListable(Document $collection): bool
    {
        return (bool) $collection->getAttribute('documentSecurity', false)
            || $this->authorization->isValid(new Input(PermissionType::Read, $collection->getPermissionsByType(PermissionType::Read)));
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
    private function resolveColumns(Query $join, array $relationships): void
    {
        foreach ($join->getJoinOnQueries() as $condition) {
            if ($condition->getMethod() !== Method::On) {
                continue;
            }

            $twoWayKey = self::oneToManyTwoWayKey($condition, $relationships);
            if ($twoWayKey === null) {
                continue;
            }

            $values = $condition->getValues();
            $values[0] = Document::ID;
            $values[2] = $twoWayKey;
            $condition->setValues($values);
        }
    }

    /**
     * @param array<string, Document> $relationships
     */
    private static function oneToManyTwoWayKey(Query $condition, array $relationships): ?string
    {
        $left = $condition->getValues()[0] ?? null;
        if (!\is_string($left) || !isset($relationships[$left])) {
            return null;
        }

        $relationship = $relationships[$left];
        $options = $relationship->getAttribute('options', []);
        $relationType = $options['relationType'] ?? $relationship->getAttribute('relationType');
        $twoWayKey = $options['twoWayKey'] ?? $relationship->getAttribute('twoWayKey');

        if ($relationType !== RelationshipType::OneToMany->value || !\is_string($twoWayKey) || $twoWayKey === '') {
            return null;
        }

        return $twoWayKey;
    }
}
