<?php

namespace Appwrite\Utopia\Database\Hook;

use Appwrite\Extend\Exception;
use Appwrite\Utopia\Database\Documents\User;
use Closure;
use Override;
use Utopia\Database\Collection;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Event;
use Utopia\Database\Hook\Queries;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;

/**
 * Points each join at the table of the collection it names, refusing unknown collections and, unless the caller is privileged, disabled ones.
 */
final class Joins implements Queries
{
    /** @var array<string, string> */
    private array $tables = [];

    /**
     * @param  Closure(): string  $notFound  The not-found exception type of the request being served
     */
    public function __construct(
        private readonly Database $dbForProject,
        private readonly Document $database,
        private readonly Authorization $authorization,
        private readonly User $user,
        private readonly Closure $notFound,
    ) {
    }

    #[Override]
    public function rewrite(Event $event, Collection $collection, array $queries): array
    {
        foreach ($queries as $index => $query) {
            $joined = $query->getAttribute();
            if ($joined === '' || !$query->getMethod()->isJoin()) {
                continue;
            }

            $table = $this->table($joined);
            if ($table !== $joined) {
                $queries[$index] = (clone $query)->setAttribute($table);
            }
        }

        return $queries;
    }

    private function table(string $joined): string
    {
        if (isset($this->tables[$joined])) {
            return $this->tables[$joined];
        }

        $related = $this->lookup($joined);
        if ($related->isEmpty() || (!$related->getAttribute('enabled', true) && !$this->isPrivileged())) {
            throw new Exception(($this->notFound)(), params: [$joined]);
        }

        $table = $this->prefix() . $related->getSequence();
        $this->tables[$joined] = $table;
        $this->tables[$table] = $table;

        return $table;
    }

    private function lookup(string $joined): Document
    {
        $registry = $this->registry();

        $related = $this->authorization->skip(
            fn (): Document => $this->dbForProject->getDocument($registry, $joined)
        );
        if (!$related->isEmpty()) {
            return $related;
        }

        $sequence = $this->sequence($joined);
        if ($sequence === null) {
            return new Document();
        }

        $found = $this->authorization->skip(
            fn (): array => $this->dbForProject->find($registry, [
                Query::equal('$sequence', [$sequence]),
                Query::limit(1),
            ])
        );

        return $found[0] ?? new Document();
    }

    private function sequence(string $table): ?string
    {
        $prefix = $this->prefix();
        if (!\str_starts_with($table, $prefix)) {
            return null;
        }

        $sequence = \substr($table, \strlen($prefix));

        return \ctype_digit($sequence) ? $sequence : null;
    }

    private function isPrivileged(): bool
    {
        $roles = $this->authorization->getRoles();

        return $this->user->isKey($roles) || $this->user->isPrivileged($roles);
    }

    private function registry(): string
    {
        return 'database_' . $this->database->getSequence();
    }

    private function prefix(): string
    {
        return $this->registry() . '_collection_';
    }
}
