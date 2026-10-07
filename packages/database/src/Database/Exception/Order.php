<?php

namespace Utopia\Database\Exception;

use Throwable;
use Utopia\Database\Exception;

class Order extends Exception
{
    protected ?string $attribute;
    public function __construct(string $message, int|string $code = 0, ?Throwable $previous = null, ?string $attribute = null)
    {
        $this->attribute = $attribute;
        parent::__construct($message, $code, $previous);
    }
    public function getAttribute(): ?string
    {
        return $this->attribute;
    }
}
