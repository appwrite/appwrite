<?php

namespace Utopia\Database\Validator\Query;

use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Query;
use Utopia\Database\Validator\UID;

class Cursor extends Base
{
    public function __construct(private readonly int $maxLength = Database::MAX_UID_DEFAULT_LENGTH)
    {
    }

    /**
     * Is valid.
     *
     * Returns true if method is cursorBefore or cursorAfter and value is not null
     *
     * Otherwise, returns false
     *
     * @param Query $value
     * @return bool
     */
    public function isValid($value): bool
    {
        if (!$value instanceof Query) {
            return false;
        }

        $method = $value->getMethod();

        if ($method === Query::TYPE_CURSOR_AFTER || $method === Query::TYPE_CURSOR_BEFORE) {
            $cursor = $value->getValue();

            if ($cursor instanceof Document) {
                $cursor = $cursor->getId();
            }

            $validator = new UID($this->maxLength);
            if ($validator->isValid($cursor)) {
                return true;
            }
            $this->message = 'Invalid cursor: ' . $validator->getDescription();
            return false;
        }

        return false;
    }

    public function getMethodType(): string
    {
        return self::METHOD_TYPE_CURSOR;
    }
}
