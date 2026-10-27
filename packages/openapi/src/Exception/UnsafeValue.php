<?php

declare(strict_types=1);

namespace Utopia\OpenAPI\Exception;

use UnexpectedValueException;
use Utopia\OpenAPI\Generation\Rule;

class UnsafeValue extends UnexpectedValueException implements OpenAPIException
{
    public function __construct(
        public readonly Rule $rule,
        public readonly string $value,
        public readonly string $location,
    ) {
        parent::__construct('Unsafe value ' . json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR) . " at {$location}");
    }
}
