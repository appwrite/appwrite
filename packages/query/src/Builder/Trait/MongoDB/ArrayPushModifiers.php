<?php

namespace Utopia\Query\Builder\Trait\MongoDB;

use Utopia\Query\Builder\MongoDB\UpdateOperator;

trait ArrayPushModifiers
{
    /**
     * @param  array<mixed>  $values
     * @param  array<string, int>|null  $sort
     */
    #[\Override]
    public function pushEach(string $field, array $values, ?int $position = null, ?int $slice = null, ?array $sort = null): static
    {
        $this->validateFieldName($field);
        $modifier = ['values' => \array_values($values)];
        if ($position !== null) {
            $modifier['position'] = $position;
        }
        if ($slice !== null) {
            $modifier['slice'] = $slice;
        }
        if ($sort !== null) {
            $modifier['sort'] = $sort;
        }
        // Stored under Push with an '__each' marker wrapper so buildUpdate()
        // can distinguish modifier-form entries from plain push values.
        $this->updateOperations[UpdateOperator::Push->value][$field] = ['__each' => $modifier];

        return $this;
    }
}
