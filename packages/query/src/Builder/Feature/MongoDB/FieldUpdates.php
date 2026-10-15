<?php

namespace Utopia\Query\Builder\Feature\MongoDB;

interface FieldUpdates
{
    public function rename(string $oldField, string $newField): static;

    public function multiply(string $field, int|float $factor): static;

    public function popFirst(string $field): static;

    public function popLast(string $field): static;

    /**
     * @param array<mixed> $values
     */
    public function pullAll(string $field, array $values): static;

    public function updateMin(string $field, mixed $value): static;

    public function updateMax(string $field, mixed $value): static;

    public function currentDate(string $field, string $type = 'date'): static;
}
