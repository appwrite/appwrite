<?php

namespace Appwrite\Functions\Validator;

use Utopia\Validator\Text;

class Payload extends Text
{
    public function __construct(int $length, int $min = 1)
    {
        parent::__construct($length, $min);
    }

    public function getType(): string
    {
        return self::TYPE_STRING;
    }
}
