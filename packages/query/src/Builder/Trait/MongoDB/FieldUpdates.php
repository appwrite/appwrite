<?php

namespace Utopia\Query\Builder\Trait\MongoDB;

use Utopia\Query\Builder\MongoDB\UpdateOperator;

trait FieldUpdates
{
    #[\Override]
    public function rename(string $oldField, string $newField): static
    {
        $this->validateFieldName($oldField);
        $this->validateFieldName($newField);
        $this->setUpdateField(UpdateOperator::Rename, $oldField, $newField);

        return $this;
    }

    #[\Override]
    public function multiply(string $field, int|float $factor): static
    {
        $this->validateFieldName($field);
        $this->setUpdateField(UpdateOperator::Multiply, $field, $factor);

        return $this;
    }

    #[\Override]
    public function popFirst(string $field): static
    {
        $this->validateFieldName($field);
        $this->setUpdateField(UpdateOperator::Pop, $field, -1);

        return $this;
    }

    #[\Override]
    public function popLast(string $field): static
    {
        $this->validateFieldName($field);
        $this->setUpdateField(UpdateOperator::Pop, $field, 1);

        return $this;
    }

    /**
     * @param  array<mixed>  $values
     */
    #[\Override]
    public function pullAll(string $field, array $values): static
    {
        $this->validateFieldName($field);
        $this->setUpdateField(UpdateOperator::PullAll, $field, $values);

        return $this;
    }

    #[\Override]
    public function updateMin(string $field, mixed $value): static
    {
        $this->validateFieldName($field);
        $this->setUpdateField(UpdateOperator::Min, $field, $value);

        return $this;
    }

    #[\Override]
    public function updateMax(string $field, mixed $value): static
    {
        $this->validateFieldName($field);
        $this->setUpdateField(UpdateOperator::Max, $field, $value);

        return $this;
    }

    #[\Override]
    public function currentDate(string $field, string $type = 'date'): static
    {
        $this->validateFieldName($field);
        $this->setUpdateField(UpdateOperator::CurrentDate, $field, ['$type' => $type]);

        return $this;
    }
}
