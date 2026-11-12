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
use Utopia\Database\PermissionType;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\Authorization\Input;
use WeakMap;

/**
 * Points each join at the table of the collection whose public ID it names in the current database. Unless the caller
 * is privileged, a collection it may not join is refused exactly as one that does not exist.
 */
final class Joins implements Queries
{
    /** @var array<string, Document> */
    private array $collections = [];

    /** @var WeakMap<Query, string> */
    private WeakMap $resolved;

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
        $this->resolved = new WeakMap();
    }

    #[Override]
    public function rewrite(Event $event, Collection $collection, array $queries): array
    {
        foreach ($queries as $index => $query) {
            $joined = $query->getAttribute();
            if ($joined === '' || !$query->getMethod()->isJoin() || ($this->resolved[$query] ?? null) === $joined) {
                continue;
            }

            $table = $this->table($joined);
            $resolved = $table === $joined ? $query : (clone $query)->setAttribute($table);
            $this->resolved[$resolved] = $table;
            $queries[$index] = $resolved;
        }

        return $queries;
    }

    private function table(string $joined): string
    {
        $related = $this->collections[$joined] ??= $this->authorization->skip(
            fn (): Document => $this->dbForProject->getDocument($this->registry(), $joined)
        );

        if (!$this->isJoinable($related)) {
            throw new Exception(($this->notFound)(), params: [$joined]);
        }

        return $this->registry() . '_collection_' . $related->getSequence();
    }

    private function isJoinable(Document $related): bool
    {
        if ($related->isEmpty()) {
            return false;
        }

        if ($this->isPrivileged()) {
            return true;
        }

        if (!$related->getAttribute('enabled', true)) {
            return false;
        }

        return $related->getAttribute('documentSecurity', false)
            || $this->authorization->isValid(new Input(PermissionType::Read, $related->getPermissionsByType(PermissionType::Read)));
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
}
