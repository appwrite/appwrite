<?php

namespace Utopia\Query\Hook;

use Utopia\Query\Hook;

interface Write extends Hook
{
    /**
     * Decorate a row before it's written to any table.
     *
     * @param array<string, mixed> $row The row data to write
     * @param array<string, mixed> $metadata Context about the document (e.g. tenant, permissions)
     * @return array<string, mixed> The decorated row
     */
    public function decorateRow(array $row, array $metadata = []): array;

    /**
     * Execute after rows are created in a table.
     *
     * @param list<array<string, mixed>> $metadata Context for each created document
     */
    public function afterCreate(string $table, array $metadata, mixed $context): void;

    /**
     * Execute after a row is updated.
     *
     * @param array<string, mixed> $metadata Context about the updated document
     */
    public function afterUpdate(string $table, array $metadata, mixed $context): void;

    /**
     * Execute after rows are updated in batch.
     *
     * @param array<string, mixed> $updateData The update payload
     * @param list<array<string, mixed>> $metadata Context for each updated document
     */
    public function afterBatchUpdate(string $table, array $updateData, array $metadata, mixed $context): void;

    /**
     * Execute after rows are deleted.
     *
     * @param list<string> $ids The IDs of deleted rows
     */
    public function afterDelete(string $table, array $ids, mixed $context): void;
}
