<?php

namespace Appwrite\Platform\Modules\Databases\Http\Databases\Collections\Documents;

use Appwrite\Extend\Exception;
use Utopia\Platform\Action as UtopiaAction;

abstract class Action extends UtopiaAction
{
    /**
     * @var string|null The current context (either 'row' or 'document')
     */
    private ?string $context = DATABASE_DOCUMENTS_CONTEXT;

    /**
     * Get the response model used in the SDK and HTTP responses.
     */
    abstract protected function getResponseModel(): string;

    /**
     * Get the response model used in the SDK and HTTP responses for bulk action.
     */
    abstract protected function getBulkResponseModel(): string;

    /**
     * Set the context to either `row` or `document`.
     *
     * @throws \InvalidArgumentException If the context is invalid.
     */
    final protected function setContext(string $context): void
    {
        if (!\in_array($context, [DATABASE_ROWS_CONTEXT, DATABASE_DOCUMENTS_CONTEXT], true)) {
            throw new \InvalidArgumentException("Invalid context '$context'. Use `DATABASE_ROWS_CONTEXT` or `DATABASE_DOCUMENTS_CONTEXT`");
        }

        $this->context = $context;
    }

    /**
     * Get the plural of the given name.
     *
     * Used for endpoints with multiple sdk methods.
     */
    final protected function getBulkActionName(string $name): string
    {
        return "{$name}s";
    }

    /**
     * Get the current context.
     */
    final protected function getContext(): string
    {
        return $this->context;
    }

    /**
     * Returns true if current context is Collections API.
     */
    final protected function isCollectionsAPI(): bool
    {
        // rows in tables api context
        // documents in collections api context
        return $this->getContext() === DATABASE_DOCUMENTS_CONTEXT;
    }

    /**
     * Get the SDK group name for the current action.
     *
     * Can be used for XList operations as well!
     */
    final protected function getSdkGroup(): string
    {
        return $this->isCollectionsAPI() ? 'documents' : 'rows';
    }

    /**
     * Get the SDK namespace for the current action.
     */
    final protected function getSdkNamespace(): string
    {
        return $this->isCollectionsAPI() ? 'collections' : 'tables';
    }

    /**
     * Get the correct attribute/column structure context for errors.
     */
    final protected function getStructureContext(): string
    {
        return $this->isCollectionsAPI() ? 'attributes' : 'columns';
    }

    /**
     * Get the appropriate parent level not found exception.
     */
    final protected function getParentNotFoundException(): string
    {
        return $this->isCollectionsAPI()
            ? Exception::COLLECTION_NOT_FOUND
            : Exception::TABLE_NOT_FOUND;
    }

    /**
     * Get the appropriate not found exception.
     */
    final protected function getNotFoundException(): string
    {
        return $this->isCollectionsAPI()
            ? Exception::DOCUMENT_NOT_FOUND
            : Exception::ROW_NOT_FOUND;
    }

    /**
     * Get the appropriate already exists exception.
     */
    final protected function getDuplicateException(): string
    {
        return $this->isCollectionsAPI()
            ? Exception::DOCUMENT_ALREADY_EXISTS
            : Exception::ROW_ALREADY_EXISTS;
    }

    /**
     * Get the appropriate conflict exception.
     */
    final protected function getConflictException(): string
    {
        return $this->isCollectionsAPI()
            ? Exception::DOCUMENT_UPDATE_CONFLICT
            : Exception::ROW_UPDATE_CONFLICT;
    }

    /**
     * Get the appropriate delete restricted exception.
     */
    final protected function getRestrictedException(): string
    {
        return $this->isCollectionsAPI()
            ? Exception::DOCUMENT_DELETE_RESTRICTED
            : Exception::ROW_DELETE_RESTRICTED;
    }

    /**
     * Get the correct invalid structure message.
     */
    final protected function getInvalidStructureException(): string
    {
        return $this->isCollectionsAPI()
            ? Exception::DOCUMENT_INVALID_STRUCTURE
            : Exception::ROW_INVALID_STRUCTURE;
    }

    /**
     * Get the appropriate missing data exception.
     */
    final protected function getMissingDataException(): string
    {
        return $this->isCollectionsAPI()
            ? Exception::DOCUMENT_MISSING_DATA
            : Exception::ROW_MISSING_DATA;
    }

    /**
     * Get the appropriate missing payload exception.
     */
    final protected function getMissingPayloadException(): string
    {
        return $this->isCollectionsAPI()
            ? Exception::DOCUMENT_MISSING_PAYLOAD
            : Exception::ROW_MISSING_PAYLOAD;
    }

    /**
     * Get the correct collections context for Events queue.
     */
    final protected function getCollectionsEventsContext(): string
    {
        return $this->isCollectionsAPI() ? 'collection' : 'table';
    }
}
