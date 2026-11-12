<?php

declare(strict_types=1);

namespace Appwrite\Screenshots;

class Exception extends \Exception
{
    private const int FORBIDDEN = 403;

    private const int INSUFFICIENT_STORAGE = 507;

    private const array PERMANENT_CODES = [self::FORBIDDEN, self::INSUFFICIENT_STORAGE];

    private const string UNROUTABLE = 'is not publicly routable';

    public function isPermanent(): bool
    {
        return \in_array($this->getCode(), self::PERMANENT_CODES, true)
            || \str_contains($this->getMessage(), self::UNROUTABLE);
    }
}
