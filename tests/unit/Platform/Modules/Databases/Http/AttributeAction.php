<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\Modules\Databases\Http;

use Appwrite\Event\Event;
use Appwrite\Event\Publisher\Database as DatabasePublisher;
use Appwrite\Platform\Modules\Databases\Http\Databases\Collections\Attributes\Action;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Validator\Authorization;

final class AttributeAction extends Action
{
    public function __construct(
        private readonly string $databaseId,
        private readonly string $collectionId,
    ) {
    }

    #[\Override]
    protected function getResponseModel(): string
    {
        return Response::MODEL_ATTRIBUTE;
    }

    public function create(Document $attribute, Database $dbForProject, callable $getDatabasesDB, Response $response, DatabasePublisher $publisher, Event $queueForEvents, Authorization $authorization): Document
    {
        return $this->createAttribute($this->databaseId, $this->collectionId, $attribute, $response, $dbForProject, $getDatabasesDB, $publisher, $queueForEvents, $authorization);
    }

    /**
     * @param array<string, mixed> $arguments
     */
    public function update(string $key, Database $dbForProject, callable $getDatabasesDB, Event $queueForEvents, Authorization $authorization, string $type, array $arguments): Document
    {
        return $this->updateAttribute($this->databaseId, $this->collectionId, $key, $dbForProject, $getDatabasesDB, $queueForEvents, $authorization, $type, ...$arguments);
    }
}
