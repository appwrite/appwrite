<?php

declare(strict_types=1);

namespace Utopia\Validator\Tests;

/**
 * A consumer written before the rename, typed only against the old name.
 */
final readonly class OldNamesConsumer
{
    public function __construct(public \Utopia\Validator $validator)
    {
    }

    public function check(mixed $value): bool
    {
        return $this->validator->isValid($value);
    }
}
